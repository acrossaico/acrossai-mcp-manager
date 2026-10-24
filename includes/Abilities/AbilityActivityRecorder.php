<?php
/**
 * Records ability executions into the activity log (Feature 096).
 *
 * TWO capture paths, neither of which raises the WordPress floor.
 *
 * 1. **Core hooks, when the version provides them.** WordPress core
 *    instruments `WP_Ability::execute()` directly, and those hooks fire for
 *    EVERY caller — MCP, the REST abilities route, WP-CLI, the admin UI. No
 *    callback wrapping and no `CallbackReplacer` trickery: core already owns
 *    this seam, so wrapping would duplicate it and still miss non-MCP callers.
 *
 * 2. **Our own bracket, always.** `Base_Toolset_Ability::do_execute()` and
 *    `Abilities\Execute` are plugin-owned choke points through which every
 *    MCP-originated ability call passes. Both delegate to
 *    `CaptureContext::around()`.
 *
 * **Why path 2 is load-bearing rather than belt-and-braces.** Verified against
 * WordPress 6.9's own source (`class-wp-ability.php:615-654`):
 *
 *   - A permission denial returns `WP_Error` BEFORE `wp_before_execute_ability`
 *     fires, so no hook sees it at all.
 *   - A `WP_Error` from the callback early-returns at line 634, so
 *     `wp_after_execute_ability` never fires.
 *   - Output-validation failure early-returns at line 640, likewise.
 *
 * So on 6.9 the guaranteed hook pair records SUCCESSES and is blind to every
 * failure and every denial. A failure-blind log is worse than no log, because
 * it reads as "nothing went wrong". The bracket is what closes that for
 * MCP-originated calls; non-MCP callers on 6.9/7.0 degrade to success-only,
 * and the UI says so rather than implying full coverage.
 *
 * MUST NEVER THROW: this runs inside the execution path of every ability on
 * the site.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder as ActivityRecorder;
use AcrossAI_MCP_Manager\Includes\Observability\CaptureContext;
use AcrossAI_MCP_Manager\Includes\Observability\ConnectionContext;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Ability capture. Hook wiring lives in Main.php per A1.
 */
final class AbilityActivityRecorder {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Start times by ability name, in microseconds.
	 *
	 * @var array<string, float>
	 */
	private array $started = array();

	/**
	 * Ability runs already recorded this request, keyed on name + parent.
	 *
	 * The re-entry guard. On WordPress 7.1+ both capture paths observe the same
	 * MCP-originated call, and without this it would produce two rows for one
	 * execution. Keyed on name AND parent because the same ability legitimately
	 * runs twice in one request under different dispatchers.
	 *
	 * @var array<string, bool>
	 */
	private array $recorded = array();

	/** Singleton accessor. */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/** Private — wiring lives in Main.php per A1. */
	private function __construct() {}

	/**
	 * Does this WordPress provide the richer 7.1 ability hooks?
	 *
	 * Feature-detected, never version-sniffed: a backport or a plugin-provided
	 * polyfill should count, and `has_action()` cannot help because nothing has
	 * necessarily hooked them yet. `wp_ability_invoked` is the marker — it is
	 * the one that fires for every call regardless of outcome.
	 */
	public static function has_rich_hooks(): bool {
		global $wp_version;

		return isset( $wp_version ) && version_compare( (string) $wp_version, '7.1', '>=' );
	}

	/**
	 * Is ability logging switched on?
	 *
	 * Checked on every hook rather than at registration, so toggling the
	 * setting takes effect without a reload.
	 */
	private function enabled(): bool {
		return (bool) get_option( ActivityLogConstants::OPTION_ABILITY_LOGGING, ActivityLogConstants::DEFAULT_ABILITY_LOGGING );
	}

	// --- Path 2: the always-on bracket ---

	/**
	 * Record one ability run observed through our own bracket.
	 *
	 * Called by `CaptureContext::around()`'s callers after the work completes,
	 * with the outcome they already determined. This is the only path that sees
	 * failures and denials on WordPress 6.9.
	 *
	 * @param string               $ability  Ability name.
	 * @param string               $status   success | error | denied | invalid_input.
	 * @param array<string, mixed> $details Optional error_code, error_message, output, input.
	 * @param float                $duration Elapsed milliseconds.
	 */
	public function record_bracketed( string $ability, string $status, array $details = array(), float $duration = 0.0 ): void {
		try {
			if ( ! $this->enabled() || '' === $ability ) {
				return;
			}

			$parent_id = CaptureContext::instance()->activity_id();

			if ( $this->already_recorded( $ability, $parent_id ) ) {
				return;
			}

			$this->write( $ability, $status, $details, $duration, $parent_id, 'mcp' );
		} catch ( \Throwable $e ) {
			$this->swallow( $e );
		}
	}

	// --- Path 1: core hooks ---

	/**
	 * `wp_before_execute_ability` — start the timer.
	 *
	 * On 6.9 this fires AFTER the permission check, so its presence means the
	 * call was authorised and reached the callback.
	 *
	 * @param mixed $ability_name Ability name.
	 * @param mixed $input        Ability input (unused).
	 */
	public function on_before_execute( $ability_name = '', $input = null ): void {
		try {
			unset( $input );

			if ( ! $this->enabled() || ! is_string( $ability_name ) || '' === $ability_name ) {
				return;
			}

			$this->started[ $ability_name ] = microtime( true );
		} catch ( \Throwable $e ) {
			$this->swallow( $e );
		}
	}

	/**
	 * `wp_after_execute_ability` — a completed, successful run.
	 *
	 * Only successes reach here on every supported version; see the class
	 * docblock for why.
	 *
	 * @param mixed $ability_name Ability name.
	 * @param mixed $input        Ability input.
	 * @param mixed $result       Ability result.
	 */
	public function on_after_execute( $ability_name = '', $input = null, $result = null ): void {
		try {
			if ( ! $this->enabled() || ! is_string( $ability_name ) || '' === $ability_name ) {
				return;
			}

			$parent_id = CaptureContext::instance()->activity_id();

			if ( $this->already_recorded( $ability_name, $parent_id ) ) {
				return;
			}

			$this->write(
				$ability_name,
				'success',
				array(
					'input'  => $input,
					'output' => $result,
				),
				$this->elapsed( $ability_name ),
				$parent_id,
				$this->invoked_via( $parent_id )
			);
		} catch ( \Throwable $e ) {
			$this->swallow( $e );
		}
	}

	/**
	 * `wp_ability_execute_result` (WordPress 7.1+) — sees `WP_Error`.
	 *
	 * The hook the 6.9 pair lacks. Registered only when the version provides
	 * it, and it must return `$result` untouched.
	 *
	 * @param mixed $result       Ability result, possibly WP_Error.
	 * @param mixed $ability_name Ability name.
	 * @param mixed $input        Ability input.
	 * @return mixed The result, unchanged.
	 */
	public function on_execute_result( $result = null, $ability_name = '', $input = null ) {
		try {
			if ( ! $this->enabled() || ! is_string( $ability_name ) || '' === $ability_name ) {
				return $result;
			}

			$parent_id = CaptureContext::instance()->activity_id();

			if ( $this->already_recorded( $ability_name, $parent_id ) ) {
				return $result;
			}

			$is_error = function_exists( 'is_wp_error' ) && is_wp_error( $result );

			$this->write(
				$ability_name,
				$is_error ? 'error' : 'success',
				array(
					'input'         => $input,
					'output'        => $is_error ? null : $result,
					'error_code'    => $is_error ? (string) $result->get_error_code() : '',
					'error_message' => $is_error ? (string) $result->get_error_message() : '',
				),
				$this->elapsed( $ability_name ),
				$parent_id,
				$this->invoked_via( $parent_id )
			);
		} catch ( \Throwable $e ) {
			$this->swallow( $e );
		}

		return $result;
	}

	/**
	 * `wp_ability_permission_result` (WordPress 7.1+) — a refused call.
	 *
	 * A refusal is a row worth having: "nobody called it" and "it was called
	 * and denied" are different facts, and only one of them is a misconfigured
	 * access rule.
	 *
	 * @param mixed $permission   Whether permission was granted.
	 * @param mixed $ability_name Ability name.
	 * @param mixed $input        Ability input.
	 * @return mixed The permission result, unchanged.
	 */
	public function on_permission_result( $permission = null, $ability_name = '', $input = null ) {
		try {
			$denied = ( false === $permission )
				|| ( function_exists( 'is_wp_error' ) && is_wp_error( $permission ) );

			if ( ! $denied || ! $this->enabled() || ! is_string( $ability_name ) || '' === $ability_name ) {
				return $permission;
			}

			$parent_id = CaptureContext::instance()->activity_id();

			$this->write(
				$ability_name,
				'denied',
				array(
					'input'         => $input,
					'error_message' => is_wp_error( $permission ) ? (string) $permission->get_error_message() : '',
				),
				0.0,
				$parent_id,
				$this->invoked_via( $parent_id )
			);
		} catch ( \Throwable $e ) {
			$this->swallow( $e );
		}

		return $permission;
	}

	/**
	 * `wp_ability_validate_input` (WordPress 7.1+) — rejected input.
	 *
	 * @param mixed $valid        Validation result.
	 * @param mixed $ability_name Ability name.
	 * @param mixed $input        Ability input.
	 * @return mixed The validation result, unchanged.
	 */
	public function on_validate_input( $valid = null, $ability_name = '', $input = null ) {
		try {
			$invalid = function_exists( 'is_wp_error' ) && is_wp_error( $valid );

			if ( ! $invalid || ! $this->enabled() || ! is_string( $ability_name ) || '' === $ability_name ) {
				return $valid;
			}

			$parent_id = CaptureContext::instance()->activity_id();

			$this->write(
				$ability_name,
				'invalid_input',
				array(
					'input'         => $input,
					'error_code'    => (string) $valid->get_error_code(),
					'error_message' => (string) $valid->get_error_message(),
				),
				0.0,
				$parent_id,
				$this->invoked_via( $parent_id )
			);
		} catch ( \Throwable $e ) {
			$this->swallow( $e );
		}

		return $valid;
	}

	// --- Shared ---

	/**
	 * Write one ability event.
	 *
	 * @param string               $ability     Ability name.
	 * @param string               $status      Outcome.
	 * @param array<string, mixed> $details input / output / error_code / error_message.
	 * @param float                $duration    Elapsed milliseconds.
	 * @param int                  $parent_id   Request event id, or 0.
	 * @param string               $invoked_via Which surface called it.
	 */
	private function write( string $ability, string $status, array $details, float $duration, int $parent_id, string $invoked_via ): void {
		$input  = $details['input'] ?? null;
		$output = $details['output'] ?? null;

		$child_id = ActivityRecorder::record_ability(
			array(
				'parent_id'          => $parent_id,
				'event_source'       => ActivityLogConstants::SOURCE_MCP,
				'server_id'          => 0,
				'invoked_via'        => $invoked_via,
				'component_type'     => 'ability',
				'component_name'     => $ability,
				'component_category' => $this->category_for( $ability ),
				'status'             => $status,
				'error_code'         => isset( $details['error_code'] ) ? (string) $details['error_code'] : '',
				'error_message'      => isset( $details['error_message'] ) ? mb_substr( (string) $details['error_message'], 0, 500 ) : '',
				'duration_ms'        => $duration > 0 ? (int) round( $duration ) : null,
				'user_id'            => get_current_user_id(),
				'ip_address'         => ConnectionContext::ip_address(),
				'input_preview'      => $this->preview( $input ),
				// Unlike a request event, this one CAN be filled: the end hook
				// carries the result. That asymmetry is why one table works.
				'output_preview'     => $this->preview( $output ),
				'input_bytes'        => $this->bytes( $input ),
				'output_bytes'       => $this->bytes( $output ),
			)
		);

		// Hand the id to the capture context so the request event can adopt it
		// once it knows its own. The parent does not exist yet — it is written
		// at the end of the request, after every ability it ran — which is why
		// `parent_id` above is still 0 here and cannot be anything else.
		//
		// Unconditional: whether this turns out to have an MCP parent is not
		// knowable at this point, and a context that is never asked to adopt
		// simply drops the list at `shutdown`.
		CaptureContext::instance()->remember_child( $child_id );

		$this->recorded[ $this->key( $ability, $parent_id ) ] = true;
		unset( $this->started[ $ability ] );
	}

	/**
	 * Which surface invoked this ability.
	 *
	 * A SEPARATE axis from `connection_type`: one is how a credential arrived,
	 * the other is which surface called. An ability run from WP-CLI has no
	 * connection type at all.
	 *
	 * @param int $parent_id Request event id, or 0.
	 */
	private function invoked_via( int $parent_id ): string {
		if ( $parent_id > 0 || CaptureContext::instance()->is_armed() ) {
			return 'mcp';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'admin';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return 'admin';
		}

		return 'unknown';
	}

	/**
	 * Registered category for an ability, or ''.
	 *
	 * @param string $ability Ability name.
	 */
	private function category_for( string $ability ): string {
		// `wp_has_ability()` FIRST. Core's registry emits a _doing_it_wrong
		// notice when `wp_get_ability()` is handed an unregistered name, and a
		// logging path must never do that: an ability can legitimately be
		// absent from the registry by the time we record it — renamed,
		// deactivated, or published by a sibling plugin that has since
		// deregistered it. Without the guard every such write emits a core
		// notice from inside code that is supposed to be invisible.
		//
		// This is the guard `Includes\Abilities\Execute` already uses; caught
		// by test, after I failed to follow the house pattern.
		if ( ! function_exists( 'wp_get_ability' ) || ! function_exists( 'wp_has_ability' ) ) {
			return '';
		}

		if ( ! wp_has_ability( $ability ) ) {
			return '';
		}

		$object = wp_get_ability( $ability );

		if ( ! $object ) {
			return '';
		}

		// `WP_Ability::get_category(): string` — a hard return type in core
		// (class-wp-ability.php:380). An earlier draft here guarded for a
		// category OBJECT as well; PHPStan correctly flagged that branch as
		// unreachable, and core confirms it never happens.
		return $object->get_category();
	}

	/**
	 * Elapsed milliseconds since the start hook, or 0.
	 *
	 * @param string $ability Ability name.
	 */
	private function elapsed( string $ability ): float {
		if ( ! isset( $this->started[ $ability ] ) ) {
			return 0.0;
		}

		return ( microtime( true ) - $this->started[ $ability ] ) * 1000;
	}

	/**
	 * Re-entry key.
	 *
	 * @param string $ability   Ability name.
	 * @param int    $parent_id Request event id, or 0.
	 */
	private function key( string $ability, int $parent_id ): string {
		return $ability . '#' . $parent_id;
	}

	/**
	 * Has this ability+parent pair already been recorded this request?
	 *
	 * @param string $ability   Ability name.
	 * @param int    $parent_id Request event id, or 0.
	 */
	private function already_recorded( string $ability, int $parent_id ): bool {
		return isset( $this->recorded[ $this->key( $ability, $parent_id ) ] );
	}

	/**
	 * Sanitised, truncated, filterable preview of a value.
	 *
	 * @param mixed $value Input or output.
	 * @return string|null
	 */
	private function preview( $value ) {
		if ( null === $value ) {
			return null;
		}

		$encoded = wp_json_encode( $value );

		if ( ! is_string( $encoded ) ) {
			return null;
		}

		$preview  = mb_substr( $encoded, 0, 2000 );
		$filtered = apply_filters( ActivityLogConstants::FILTER_INPUT_PREVIEW, $preview, array() );

		if ( null === $filtered || '' === $filtered ) {
			return null;
		}

		return is_string( $filtered ) ? $filtered : $preview;
	}

	/**
	 * Byte length of a value's JSON encoding.
	 *
	 * @param mixed $value Input or output.
	 * @return int|null
	 */
	private function bytes( $value ) {
		if ( null === $value ) {
			return null;
		}

		$encoded = wp_json_encode( $value );

		return is_string( $encoded ) ? strlen( $encoded ) : null;
	}

	/**
	 * Swallow a capture failure.
	 *
	 * @param \Throwable $e The failure.
	 */
	private function swallow( \Throwable $e ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- FR-013: capture must never break the ability it observes.
		error_log( '[acrossai-mcp] AbilityActivityRecorder failed: ' . $e->getMessage() );
	}

	/**
	 * Drop per-request state. Wired to the same lifecycle as the holders.
	 *
	 * @param mixed $passthrough Value to return unchanged.
	 * @return mixed
	 */
	public function clear( $passthrough = null ) {
		$this->started  = array();
		$this->recorded = array();

		return $passthrough;
	}
}
