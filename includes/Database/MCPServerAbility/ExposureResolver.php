<?php
/**
 * Feature 017 (+ F082 extension) — ability exposure resolver.
 *
 * Two public entry points, each with a name that broadcasts its semantics:
 *
 *   - `resolve_row_only( $server_id, $ability_slug, $meta ): bool`
 *     Row-in-wp_acrossai_mcp_server_abilities wins → else `meta.mcp.public`.
 *     **F030's `PermissionOverrideProcessor::should_bypass()` is the ONLY
 *     production caller.** It passes empty `$meta` as a row-existence probe.
 *     Widening this method to consult the server-level policy would silently
 *     widen the F030 permission-callback bypass to every ability on any
 *     `policy='expose'` server. DO NOT WIDEN — add or update
 *     `resolve_effective()` instead. Merge-blocker regression fence at
 *     `tests/phpunit/Database/MCPServerAbility/ExposureResolverTest::test_resolve_row_only_still_row_only_for_f030()`.
 *     (Renamed from `resolve()` in F082 per SEC-001 Option A — the row-only
 *     semantics now live in the method name itself so any widening is
 *     grep-visible and fail-loud on rename.)
 *
 *   - `resolve_effective( $server_id, $ability_slug, $meta ): bool`
 *     Three-tier priority (F082): row-in-table → server-level policy on
 *     `MCPServer.abilities_default_policy` (values: `expose` | `hide` |
 *     `per-ability`) → `meta.mcp.public` fallback. Every consumer that wants
 *     the operator-authored default policy honoured uses this method.
 *     Consumers: `AbilityExposureGate` (F017 call-time gate, priority 20),
 *     `AbilitiesController::get_abilities()` (advertisement-time REST),
 *     `AbilitiesController::post_abilities()` was/now snapshots (per-pair
 *     action fire), `AbilityDiscovery` (F026 advertisement-time enumeration),
 *     `AbilityHelpers::apply_exposure_filter` (F026 composer, per D24),
 *     `QuickConnectController` (advertisement-time count for UI).
 *
 * Both entry points use per-request static caches keyed by
 * `"{$server_id}:{$ability_slug}"`. `resolve_effective()` also caches the
 * server-level policy lookup by `$server_id`. All three caches reset in
 * `reset_request_cache()` (test alias: `_reset_cache_for_tests()`).
 *
 * A11 pure-service exception (no singleton, no ctor).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerAbility
 * @since      0.1.0 (F017)
 * @since      0.1.0 (F082 — added `resolve_effective()`; renamed the row-only
 *                    method from `resolve()` to `resolve_row_only()` per SEC-001)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless resolver for ability exposure — row-only and three-tier variants.
 *
 * @since 0.1.0
 */
final class ExposureResolver {

	/**
	 * Per-request cache for row-only resolution. Reset naturally at
	 * end-of-request; no persistence.
	 *
	 * @var array<string, bool>
	 */
	private static array $cache = array();

	/**
	 * Per-request cache for effective (three-tier) resolution.
	 *
	 * @since 0.1.0 (F082)
	 * @var array<string, bool>
	 */
	private static array $effective_cache = array();

	/**
	 * Per-request cache for server-level policy lookups.
	 *
	 * @since 0.1.0 (F082)
	 * @var array<int, string>
	 */
	private static array $policy_cache = array();

	/**
	 * Row-only exposure — the F030-critical semantics. See file docblock for
	 * the invariant this method locks in.
	 *
	 * @since 0.1.0 (F017 — as `resolve()`, renamed 2026-09-05 per SEC-001)
	 *
	 * @param int    $server_id    MCP server id (references acrossai_mcp_servers.id).
	 * @param string $ability_slug \WP_Ability::get_name() output.
	 * @param array  $meta         The ability's `get_meta()` output — read to
	 *                             extract `meta[mcp][public]` for the fallback.
	 * @return bool True when the ability is effectively exposed on this server
	 *              via a row OR the meta fallback (server-level policy NOT
	 *              consulted — that's `resolve_effective()`'s job).
	 */
	public static function resolve_row_only( int $server_id, string $ability_slug, array $meta ): bool {
		$key = $server_id . ':' . $ability_slug;
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		$rows = Query::instance()->query(
			array(
				'server_id'    => $server_id,
				'ability_slug' => $ability_slug,
				'number'       => 1,
			)
		);

		if ( ! empty( $rows ) ) {
			// B18 defense — $wpdb returns TINYINT as string; cast to bool.
			$result = (bool) $rows[0]->is_exposed;
		} else {
			$result = ! empty( $meta['mcp']['public'] );
		}

		self::$cache[ $key ] = $result;
		return $result;
	}

	/**
	 * Effective exposure — three-tier priority (F082).
	 *
	 * Priority order:
	 *   1. Explicit row in `wp_acrossai_mcp_server_abilities` — highest.
	 *      `(bool) $row->is_exposed` wins over everything.
	 *   2. Server-level policy on `MCPServer.abilities_default_policy`:
	 *      - 'expose'  → true  (operator wants every ability exposed by default)
	 *      - 'hide'    → false (operator wants every ability hidden by default)
	 *      - other/'per-ability' → fall through to priority 3.
	 *   3. `meta.mcp.public` fallback (same as row-only path).
	 *
	 * @since 0.1.0 (F082)
	 *
	 * @param int    $server_id    MCP server id.
	 * @param string $ability_slug \WP_Ability::get_name() output.
	 * @param array  $meta         The ability's `get_meta()` output.
	 * @return bool
	 */
	public static function resolve_effective( int $server_id, string $ability_slug, array $meta ): bool {
		$key = $server_id . ':' . $ability_slug;
		if ( array_key_exists( $key, self::$effective_cache ) ) {
			return self::$effective_cache[ $key ];
		}

		// Priority 1: explicit row wins over everything.
		$rows = Query::instance()->query(
			array(
				'server_id'    => $server_id,
				'ability_slug' => $ability_slug,
				'number'       => 1,
			)
		);
		if ( ! empty( $rows ) ) {
			self::$effective_cache[ $key ] = (bool) $rows[0]->is_exposed;
			return self::$effective_cache[ $key ];
		}

		// Priority 2: server-level policy.
		$policy = self::server_policy( $server_id );
		if ( 'expose' === $policy ) {
			self::$effective_cache[ $key ] = true;
			return true;
		}
		if ( 'hide' === $policy ) {
			self::$effective_cache[ $key ] = false;
			return false;
		}

		// Priority 3: per-ability meta fallback (same as resolve_row_only).
		self::$effective_cache[ $key ] = ! empty( $meta['mcp']['public'] );
		return self::$effective_cache[ $key ];
	}

	/**
	 * Look up the server-level default policy with per-request cache.
	 *
	 * @since 0.1.0 (F082)
	 *
	 * @param int $server_id MCP server id.
	 * @return string One of `'per-ability' | 'expose' | 'hide'`. Falls back to
	 *                `'per-ability'` when the server row is missing or the
	 *                column value is not in the allowed set (safe default —
	 *                matches pre-F082 behaviour).
	 */
	private static function server_policy( int $server_id ): string {
		if ( array_key_exists( $server_id, self::$policy_cache ) ) {
			return self::$policy_cache[ $server_id ];
		}

		$rows = MCPServerQuery::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);

		$policy = 'per-ability';
		if ( ! empty( $rows ) && ! empty( $rows[0]->abilities_default_policy ) ) {
			$candidate = (string) $rows[0]->abilities_default_policy;
			if ( in_array( $candidate, array( 'expose', 'hide', 'per-ability' ), true ) ) {
				$policy = $candidate;
			}
		}

		self::$policy_cache[ $server_id ] = $policy;
		return $policy;
	}

	/**
	 * Reset all three per-request caches.
	 *
	 * Production callers (F082 `PolicyTransition`, per-pair upsert snapshots
	 * in `AbilitiesController::post_abilities()`) use this after writes so
	 * post-change reads within the same request see fresh values; tests use
	 * it between cases. Renamed from the test-only-sounding
	 * `_reset_cache_for_tests()` once production code came to depend on it
	 * (2026-09-06 architecture-review R2); that name survives below as a
	 * delegating alias because F017's test contract pins it.
	 *
	 * @since 0.1.0 (F082)
	 * @return void
	 */
	public static function reset_request_cache(): void {
		self::$cache           = array();
		self::$effective_cache = array();
		self::$policy_cache    = array();
	}

	/**
	 * Back-compat test alias for {@see reset_request_cache()}.
	 *
	 * F017's test contract pins this exact name (companion brief CONSTRAINT:
	 * do not rename) — kept as a delegating alias; new code, production or
	 * test, should call `reset_request_cache()`.
	 *
	 * @internal
	 * @return void
	 */
	public static function _reset_cache_for_tests(): void { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Deliberate test-only alias; F017 tests depend on this exact name (companion brief CONSTRAINT: do not rename).
		self::reset_request_cache();
	}
}
