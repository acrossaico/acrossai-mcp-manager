<?php
/**
 * Feature 134 — REST surface behind the Database settings tab.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Rest
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Rest;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Database_Core_Table_Allowlist;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Db_Policy_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Rest\AcrossAI_Abilities_Rest_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the database access policy for the settings tab.
 *
 * Mirrors {@see File_Manager_Settings_Controller}: same namespace, same permission callback, same
 * read-one / save-one shape, so the React panels on both tabs are the same code with a different
 * base. The difference is that the whole policy is one row rather than four, so there is one
 * `policy` route rather than one per control — see {@see Db_Policy_Guard::OPTION} for why.
 *
 * A `tables` route is added that the file manager has no equivalent of: a denylist is only usable if
 * you can see what there is to deny, and asking an operator to type physical table names from memory
 * is how a denylist ends up with a typo in it and silently covering nothing.
 *
 * @since 0.0.45
 */
final class Database_Settings_Controller {

	/**
	 * Singleton.
	 *
	 * @since 0.0.45
	 * @var   self|null
	 */
	private static $instance = null;

	/**
	 * @since  0.0.45
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Base URL fragment for this feature's routes.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const REST_BASE = 'database-settings';

	/**
	 * Register routes on rest_api_init.
	 *
	 * @since  0.0.45
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( AcrossAI_Abilities_Rest_Controller::instance(), 'check_permission' );

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/policy',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_policy' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_policy' ),
					'permission_callback' => $permission,
					'args'                => array(
						'mode'          => array(
							'type' => 'string',
							'enum' => Db_Policy_Guard::modes(),
						),
						'denied_tables' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'max_rows'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 10000,
						),
						'max_seconds'   => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 300,
						),
						'redact_values' => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/tables',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tables' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * The current policy, plus what the UI needs to render the controls.
	 *
	 * `always_denied` and `defaults` come back with it so the panel can mark the permanently-denied
	 * tables as locked and offer a reset without hardcoding either, which would drift.
	 *
	 * @since  0.0.45
	 * @return \WP_REST_Response
	 */
	public function get_policy(): \WP_REST_Response {
		Db_Policy_Guard::flush();

		return new \WP_REST_Response(
			array(
				'policy'        => Db_Policy_Guard::policy(),
				'modes'         => Db_Policy_Guard::modes(),
				'always_denied' => Db_Policy_Guard::always_denied(),
				'defaults'      => Db_Policy_Guard::defaults(),
			),
			200
		);
	}

	/**
	 * Replace the policy.
	 *
	 * Merges onto the stored policy rather than replacing it wholesale, so a panel that only knows
	 * about one control cannot silently reset the others to their defaults — the trap AIOSEO's
	 * `sanitizeAndSave()` walks into, documented at length in Feature 132's writer.
	 *
	 * Returns the policy as STORED, read back through the guard, so the UI renders what is actually
	 * enforced rather than what it just sent. A mode the sanitiser rejected comes back as
	 * `read-only`, and the operator sees that immediately.
	 *
	 * @since  0.0.45
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_policy( \WP_REST_Request $request ): \WP_REST_Response {
		$stored = Db_Policy_Guard::policy();
		$patch  = array();

		foreach ( array( 'mode', 'denied_tables', 'max_rows', 'max_seconds', 'redact_values' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$patch[ $key ] = $request->get_param( $key );
			}
		}

		$saved = Db_Policy_Guard::save( array_merge( $stored, $patch ) );

		return new \WP_REST_Response(
			array(
				'policy'        => $saved,
				'modes'         => Db_Policy_Guard::modes(),
				'always_denied' => Db_Policy_Guard::always_denied(),
				'defaults'      => Db_Policy_Guard::defaults(),
				'saved'         => true,
			),
			200
		);
	}

	/**
	 * Every table on this site, with whether it is currently reachable.
	 *
	 * Asks the database rather than guessing from `$wpdb` properties, because the interesting tables
	 * for a denylist are the ones core knows nothing about — a membership plugin's, a form plugin's
	 * submissions. Each row says whether it is a WordPress core table, whether the policy denies it
	 * now, and whether that denial is permanent.
	 *
	 * @since  0.0.45
	 * @return \WP_REST_Response
	 */
	public function get_tables(): \WP_REST_Response {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- an admin-only listing of table names; there is no core API for it.
		$names = $wpdb->get_col( 'SHOW TABLES' );
		// Built from the allowlist's own two public methods rather than a convenience accessor it does
		// not have. An earlier draft called a `physical_names()` that does not exist, behind a
		// `method_exists` guard — which would have left every `core` flag false and the listing quietly
		// wrong rather than failing.
		$core = array();

		foreach ( Database_Core_Table_Allowlist::all_keys() as $key ) {
			$physical = Database_Core_Table_Allowlist::resolve( (string) $key );

			if ( '' !== $physical ) {
				$core[] = $physical;
			}
		}

		$rows = array();

		foreach ( (array) $names as $name ) {
			$name    = (string) $name;
			$denied  = Db_Policy_Guard::assert_table( $name );
			$locked  = false;

			// Exact match against the resolved physical names, not a suffix: locking anything merely
			// ending in `_users` permanently caught unrelated tables with no way to unlock them.
			foreach ( Db_Policy_Guard::always_denied() as $denied ) {
				if ( strtolower( $name ) === strtolower( $denied ) ) {
					$locked = true;
					break;
				}
			}

			$rows[] = array(
				'name'    => $name,
				'core'    => in_array( $name, $core, true ),
				'denied'  => is_wp_error( $denied ),
				'locked'  => $locked,
				'prefix'  => 0 === strpos( $name, (string) $wpdb->prefix ),
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return new \WP_REST_Response(
			array(
				'tables' => $rows,
				'total'  => count( $rows ),
				'prefix' => (string) $wpdb->prefix,
			),
			200
		);
	}
}
