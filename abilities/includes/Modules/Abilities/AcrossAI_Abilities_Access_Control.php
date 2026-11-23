<?php
/**
 * Abilities access-control integration wrapper.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities;

use WPBoilerplate\AccessControl\AccessControlManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities access-control integration wrapper.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Access_Control {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Plugin-scoped provider filter tag.
	 *
	 * @var string
	 */
	const PROVIDERS_FILTER = 'acrossai_abilities_access_control_providers';

	/**
	 * Per-consumer table slug (wpb-access-control v2+).
	 *
	 * Drives: `{prefix}abilities_access_control` table, `wpb_ac_abilities_db_version`
	 * schema option, `wpb_ac_abilities` cache group, `/wpb-ac/v1/abilities/...` REST namespace.
	 * Must match `^[a-z0-9_]{1,32}$` (validated upstream at construction time).
	 *
	 * @var string
	 */
	const TABLE_SLUG = 'abilities';

	/**
	 * Access-control manager instance.
	 *
	 * @var AccessControlManager|null
	 */
	private $manager = null;

	/**
	 * Return singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Boot the access-control manager.
	 *
	 * @return void
	 */
	public function boot_manager(): void {
		if ( ! $this->is_available() || $this->manager instanceof AccessControlManager ) {
			return;
		}

		$this->manager = new AccessControlManager( self::PROVIDERS_FILTER, self::TABLE_SLUG );
	}

	/**
	 * Register the library REST routes when available.
	 *
	 * @return void
	 */
	public function register_rest_api(): void {
		$manager = $this->get_manager();

		if ( null === $manager ) {
			return;
		}

		$manager->register_rest_api();
	}

	/**
	 * Check whether the access-control library is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( AccessControlManager::class );
	}

	/**
	 * Return the manager instance when available.
	 *
	 * @return AccessControlManager|null
	 */
	public function get_manager(): ?AccessControlManager {
		if ( ! $this->manager instanceof AccessControlManager ) {
			$this->boot_manager();
		}

		return $this->manager;
	}

	/**
	 * Register a notice in the shared AcrossAI notices collection when the
	 * wpb-access-control library is absent.
	 *
	 * Hooked to the `acrossai_notices` filter provided by
	 * `acrossai-co/main-menu` 0.0.30+. The notice surfaces on the AcrossAI →
	 * Notices submenu (rendered by `NoticesPageRenderer`) and as a summary
	 * banner on every other admin page (`SummaryNoticeEmitter`). Ensures
	 * admins are aware that per-ability rules are inactive (fail-open, per
	 * DEC-PERM-CB) without adding a raw wp-admin banner. (SAC-01 / MEDIUM-03)
	 *
	 * @since 0.1.0
	 * @param array $notices Current notice records keyed 0..N; see main-menu Notices class.
	 * @return array Notice list with the library-missing entry appended when applicable.
	 */
	public function register_library_notice( $notices ) {
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		if ( $this->is_available() ) {
			return $notices;
		}

		$notices[] = array(
			'id'      => 'wpb_access_control_missing',
			'title'   => __( 'Access-control library is not loaded', 'acrossai-abilities-manager' ),
			'message' => sprintf(
				/* translators: %s: library class name */
				__( 'The wpb-access-control library (%s) is not loaded. Per-ability access-control rules are inactive and all ability checks will pass (fail-open). Install or activate the library to enforce saved rules.', 'acrossai-abilities-manager' ),
				'WPBoilerplate\\AccessControl\\AccessControlManager'
			),
			'type'    => 'warning',
			'source'  => __( 'AcrossAI Abilities Manager', 'acrossai-abilities-manager' ),
		);

		return $notices;
	}
}
