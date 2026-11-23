<?php
/**
 * Runtime registration processor for database-managed abilities.
 *
 * Hooks into wp_abilities_api_init at priority 10.
 * Fetches all source=db, status=publish rows and registers each via
 * wp_register_ability() using a nested `meta` registry args structure.
 *
 * Security contract:
 *   - Execution gate: is_user_logged_in() (FR-017 — authenticated users only).
 *   - php_code: static-closure wrapping; per-invocation Throwable isolation (PD-002).
 *   - wp_remote_post: HTTPS-only; no redirects; 30 s timeout cap; no caller header
 *     propagation (PD-002).
 *   - Invalid rows are skipped without aborting the full registration pass (FR-015).
 *   - Registry args use nested `meta` key, never flat top-level (BUG-FLAT-ARGS-PATH).
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Query;
use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Row;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Abilities_Formatter;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Registers published database-managed abilities into the WordPress Abilities API.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Processor {

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Abilities_Processor|null
	 */
	protected static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Abilities_Processor
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Register published DB abilities into the runtime registry.
	 *
	 * Called by the wp_abilities_api_init hook (wired in includes/Main.php at P10).
	 * Skips any row that is missing required identity data or has an invalid slug.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$rows = AcrossAI_Abilities_Query::instance()->published_db_abilities();

		foreach ( $rows as $row ) {
			if ( ! $this->is_row_registrable( $row ) ) {
				continue;
			}

			$args                        = AcrossAI_Abilities_Formatter::build_registry_args( $row );
			$args['execute_callback']    = $this->build_execute_callback( $row );
			$args['permission_callback'] = array( $this, 'execution_permission_callback' );

			\wp_register_ability( $row->ability_slug, $args );
		}
	}

	/**
	 * Permission callback for runtime execution of database-managed abilities.
	 *
	 * Restricts execution to authenticated (logged-in) users only (FR-017).
	 *
	 * @since  0.1.0
	 * @return bool
	 */
	public function execution_permission_callback(): bool {
		return is_user_logged_in();
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Determine whether a row has the minimum required data to register.
	 *
	 * The method skips rows with an empty slug, empty label (for UI clarity),
	 * empty category (required by the WP Abilities API), or empty description
	 * (required for all published DB abilities since Feature 013).
	 *
	 * @since  0.1.0
	 * @param  AcrossAI_Abilities_Row $row Row to check.
	 * @return bool
	 */
	private function is_row_registrable( AcrossAI_Abilities_Row $row ): bool {
		if ( '' === $row->ability_slug ) {
			return false;
		}
		if ( '' === trim( (string) $row->label ) ) {
			return false;
		}
		if ( '' === trim( (string) $row->category ) ) {
			return false;
		}
		if ( '' === trim( (string) $row->description ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Build a callable execute_callback for the given row's callback_type.
	 *
	 * @since  0.1.0
	 * @param  AcrossAI_Abilities_Row $row Ability row.
	 * @return callable
	 */
	private function build_execute_callback( AcrossAI_Abilities_Row $row ): callable {
		switch ( $row->callback_type ) {
			case 'filter_hook':
				return $this->make_filter_hook_callback( $row );

			case 'wp_remote_post':
				return $this->make_wp_remote_post_callback( $row );

			case 'registered_callback':
				/*
				 * Security boundary: only version-controlled plugin/theme code may register
				 * callables via this filter. The DB row stores only a sanitized key; it never
				 * stores or executes arbitrary PHP. $input is caller-controlled (untrusted) —
				 * registered callbacks must treat it as untrusted input.
				 *
				 * @see DEC-PLUGIN-CHECK-PRODUCTION-SURFACE in docs/memory/DECISIONS.md
				 */
				$callbacks = apply_filters( 'acrossai_abilities_registered_callbacks', array() );
				$callback  = isset( $row->callback_config['callback'] )
					? sanitize_key( (string) $row->callback_config['callback'] )
					: '';
				if ( ! isset( $callbacks[ $callback ] ) || ! is_callable( $callbacks[ $callback ] ) ) {
					return static function () {
						return new \WP_Error( 'unsupported_callback_type', 'Unsupported ability callback type.' );
					};
				}
				$safe_callback = $callbacks[ $callback ];
				return static function ( $input ) use ( $safe_callback ) {
					return call_user_func( $safe_callback, $input );
				};

			case 'php_code':
				return static function () {
					return new \WP_Error( 'unsupported_callback_type', 'Unsupported ability callback type.' );
				};

			case 'noop':
			default:
				return static function () {
					return array();
				};
		}
	}

	/**
	 * Build a filter_hook execute callback.
	 *
	 * @since  0.1.0
	 * @param  AcrossAI_Abilities_Row $row Ability row.
	 * @return callable
	 */
	private function make_filter_hook_callback( AcrossAI_Abilities_Row $row ): callable {
		$hook_name = isset( $row->callback_config['hook_name'] )
			? (string) $row->callback_config['hook_name']
			: '';

		return static function ( $input ) use ( $hook_name ) {
			if ( '' === $hook_name ) {
				return array();
			}
			return apply_filters( 'acrossai_ability_execute_' . $hook_name, array(), $input );
		};
	}

	/**
	 * Build a wp_remote_post execute callback (PD-002 hardening).
	 *
	 * HTTPS-only, no redirects, 30 s timeout cap, no caller header propagation.
	 *
	 * @since  0.1.0
	 * @param  AcrossAI_Abilities_Row $row Ability row.
	 * @return callable
	 */
	private function make_wp_remote_post_callback( AcrossAI_Abilities_Row $row ): callable {
		$url     = isset( $row->callback_config['url'] ) ? (string) $row->callback_config['url'] : '';
		$timeout = isset( $row->callback_config['timeout'] ) ? (int) $row->callback_config['timeout'] : 15;

		// Enforce 30 s maximum (PD-002) and minimum of 1.
		$timeout = min( 30, max( 1, $timeout ) );

		return static function ( $input ) use ( $url, $timeout ) {
			if ( '' === $url || strpos( $url, 'https://' ) !== 0 ) {
				return new \WP_Error( 'ability_exec_error', 'Invalid or non-HTTPS URL.' );
			}

			$response = wp_remote_post(
				$url,
				array(
					'body'        => wp_json_encode( $input ),
					'headers'     => array( 'Content-Type' => 'application/json' ),
					'timeout'     => $timeout,
					'redirection' => 0,   // No redirects (PD-002).
					'sslverify'   => true,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			return is_array( $decoded ) ? $decoded : array( 'raw' => $body );
		};
	}
}
