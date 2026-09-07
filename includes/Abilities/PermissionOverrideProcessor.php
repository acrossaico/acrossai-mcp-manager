<?php
/**
 * Feature 030 — per-server ability permission_callback override.
 *
 * Hooks the WP core `wp_register_ability_args` filter at priority 999999
 * — strictly higher than sibling `acrossai-abilities-manager`
 * (`AcrossAI_Ability_Override_Processor::boot()` @ P100000) and this
 * plugin's own `CallbackReplacer` (@ P10) — and wraps every ability's
 * `permission_callback` in a closure that returns `true` unconditionally
 * WHEN AND ONLY WHEN all six defensive layers documented in
 * `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS` hold:
 *
 *   1. Operator explicitly toggled `override_abilities_permission = 1`
 *      on the target server row via a `manage_options`-gated, per-server
 *      nonce'd admin form (see `Settings::handle_save_permission_override`).
 *   2. The in-flight request is for an MCP server (CurrentServerHolder
 *      populated). Non-MCP callers — WP admin, other REST namespaces,
 *      WP-CLI — fall through to the original callback unchanged.
 *   3. The current-request server matches the row whose override is ON
 *      (per-server, not site-wide).
 *   4. The ability slug is actually exposed to this server via the
 *      `wp_acrossai_mcp_server_abilities` junction table
 *      (`ExposureResolver::resolve_row_only()` gate — renamed in F082 per SEC-001).
 *   5. The operator saw + acknowledged a warning banner + native
 *      `confirm()` prompt on the admin form before saving.
 *   6. The scope narrows to `permission_callback` only — other filter
 *      hooks (F015 access control, F017/F020 gates) still run their
 *      own logic.
 *
 * Per-request static cache mirrors the F017 `ExposureResolver::resolve_row_only()`
 * shape — one row lookup per unique `server_id` per request. Cache is
 * cleared by the companion `rest_post_dispatch` / `shutdown` hook wired
 * in `Main::define_admin_hooks()` symmetric with `CurrentServerHolder`.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide singleton. Public methods are hook callbacks; wire in
 * `Includes\Main::define_admin_hooks()` per A1.
 *
 * @since 0.1.0
 */
final class PermissionOverrideProcessor {

	/**
	 * Filter priority for the callback wrap. Load-bearing invariant per
	 * `DEC-F030-PERMISSION-CALLBACK-OPERATOR-OPT-IN-BYPASS` — MUST beat
	 * sibling acrossai-abilities-manager's P100000 injector.
	 */
	public const PRIORITY = 999999;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Per-request cache: server_id (int) → server row (object|null).
	 * Cleared by `clear_request_cache()` on `rest_post_dispatch` / `shutdown`.
	 *
	 * @var array<int, object|null>
	 */
	private static array $server_row_cache = array();

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance(). Hook wiring lives in Main.php per A1.
	 */
	private function __construct() {}

	/**
	 * Filter callback for `wp_register_ability_args` @ priority 999999.
	 *
	 * Wraps every ability's `permission_callback` in a closure that fires at
	 * ability-call time and consults the current MCP server context. The
	 * original callback is captured via `use ($original)` so the closure can
	 * fall through unchanged when the six defensive layers do not hold.
	 *
	 * @param array<string, mixed> $args The ability registration args.
	 * @param string               $slug The ability slug being registered.
	 * @return array<string, mixed>
	 */
	public function inject_override( array $args, string $slug ): array {
		$original = $args['permission_callback'] ?? null;

		// SEC — closure MUST accept variadic args and forward them to the
		// original callback via `self::call_original( $original, $callback_args )`.
		// The prior `static function ()` signature silently dropped the caller's
		// args, so callbacks that inspect input (e.g. Execute::check_permission
		// reading `$input['ability_name']`) returned WP_Error unconditionally,
		// which `call_original` then coerced to `true` — a bypass affecting
		// every ability. See PermissionOverrideProcessorTest for the regression.
		$args['permission_callback'] = static function ( ...$callback_args ) use ( $slug, $original ) {
			// Layer 2 — no MCP server in flight → fall through.
			$server_id = CurrentServerHolder::instance()->get_server_id();
			if ( null === $server_id ) {
				return self::call_original( $original, $callback_args );
			}

			// Layer 1 + 3 — read the server row (per-request cached).
			$row = self::get_server_row( $server_id );
			if ( null === $row || 0 === (int) $row->override_abilities_permission ) {
				return self::call_original( $original, $callback_args );
			}

			// Layer 4 — only overwrite abilities exposed to this server.
			//
			// F030 INTENTIONALLY passes empty `$meta` here — this is a
			// scoped exception to DEC-ABILITY-OVERRIDE-RESOLUTION captured
			// as DEC-F030-EXPLICIT-EXPOSURE-ONLY.
			//
			// Rationale: `ExposureResolver::resolve_row_only()`'s canonical semantic is
			// "row exists → row wins; no row → `meta.mcp.public` fallback". By
			// passing empty `$meta`, F030 collapses the fallback to `false` —
			// meaning the operator-opt-in bypass ONLY applies to abilities the
			// operator has EXPLICITLY toggled ON in the Abilities tab (i.e.
			// abilities with a row in `wp_acrossai_mcp_server_abilities`).
			// Globally-public abilities (`meta.mcp.public = true` with no
			// junction row) are NOT bypassed by F030 even when the override
			// flag is ON.
			//
			// Why safer: an unconditional `permission_callback → true` should
			// require operator opt-in per-ability, not inherit implicit
			// visibility from a plugin author's `meta.mcp.public = true`
			// declaration. The narrower scope keeps the six-layer defensive
			// gating meaningful.
			// F082 review-gate: this MUST stay on ExposureResolver::resolve_row_only(),
			// NOT resolve_effective(). F030's bypass is a row-existence probe;
			// widening it to honour server policy would silently widen the
			// permission-callback bypass to every ability the moment an
			// operator clicks Enable All on a server. See
			// docs/planings-tasks/082-per-server-ability-policy-defaults.md
			// (CONSTRAINTS + TASK-9 F030 regression fence).
			if ( ! ExposureResolver::resolve_row_only( $server_id, $slug, array() ) ) {
				return self::call_original( $original, $callback_args );
			}

			// All layers hold — operator opted in, request scope matches,
			// ability is exposed. Return unconditional allow.
			return true;
		};

		return $args;
	}

	/**
	 * Look up the server row with per-request cache.
	 *
	 * @param int $server_id MCP server PK.
	 * @return object|null   The server row, or null when the row does not exist.
	 */
	private static function get_server_row( int $server_id ): ?object {
		if ( array_key_exists( $server_id, self::$server_row_cache ) ) {
			return self::$server_row_cache[ $server_id ];
		}

		$rows = MCPServerQuery::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		$row  = empty( $rows ) ? null : $rows[0];

		self::$server_row_cache[ $server_id ] = $row;
		return $row;
	}

	/**
	 * Invoke the original permission_callback and return its result. When the
	 * original is null or non-callable, return `false` — matches the WP
	 * Abilities API deny-by-default semantics for missing callbacks.
	 *
	 * SEC — `WP_Error` returns MUST propagate unchanged. `(bool) $wp_error_object`
	 * evaluates to `true` in PHP (all objects cast to true), which would silently
	 * convert a deny into an allow at the vendor's
	 * `if ( true !== $permission )` check in ToolsHandler::call_tool.
	 *
	 * @param mixed        $original The original permission_callback captured by the closure.
	 * @param array<mixed> $args     Args forwarded from the wrapping closure. Passed through
	 *                               so callbacks that read their input (e.g.
	 *                               Execute::check_permission) see the ability's real args.
	 * @return bool|\WP_Error
	 */
	private static function call_original( $original, array $args = array() ) {
		if ( ! is_callable( $original ) ) {
			return false;
		}
		$result = call_user_func_array( $original, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (bool) $result;
	}

	/**
	 * Callback for `rest_post_dispatch` @ priority 999 and `shutdown` @ 999.
	 * Symmetric with `CurrentServerHolder::clear()` — the closures capture
	 * $server_id from CurrentServerHolder, so their cache lifetime MUST match
	 * to prevent stale reads across requests in long-lived PHP processes
	 * (Roadrunner, FrankenPHP) — same shape as A17 shutdown-safety-net.
	 *
	 * @param mixed $passthrough Hook payload; returned unchanged.
	 * @return mixed
	 */
	public function clear_request_cache( $passthrough = null ) {
		self::$server_row_cache = array();
		return $passthrough;
	}

	/**
	 * Test-only cache reset. Not part of the public F030 API.
	 *
	 * @internal
	 * @return void
	 */
	public static function _reset_cache_for_tests(): void { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Deliberate test-only marker; mirrors ExposureResolver::_reset_cache_for_tests() (F017/F030 naming contract).
		self::$server_row_cache = array();
	}
}
