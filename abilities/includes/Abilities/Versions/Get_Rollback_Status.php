<?php
/**
 * Feature 136 — what version targeting is available on this site.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Versions
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Versions;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Wp_Rollback_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether a plugin or theme can be re-versioned here, and the limits before they bite.
 *
 * Deliberately works with WP Rollback absent — on such a site this ability is the thing that says
 * so, and names what to install. Answering "not available" is more useful than not existing.
 *
 * @since 0.0.45
 */
class Get_Rollback_Status extends Base_Version_Ability {

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function slug(): string {
		return 'get-rollback-status';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Rollback Status', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report whether this site can move a plugin or theme onto a chosen version: whether WP Rollback is installed and loaded, which asset types it supports, and the two limits worth knowing before trying — it covers plugins and themes but never WordPress core, and its release history comes from WordPress.org, so premium and privately-hosted assets have none unless this site holds a local archive of an earlier version. Read-only.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function tab_group(): string {
		return 'wp-rollback';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function sub_group(): string {
		return 'versions-read';
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		return array();
	}

	/**
	 * @since  0.0.45
	 * @return array<int,string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array(
			'host_plugin'      => array( 'type' => 'object' ),
			'supported_types'  => array( 'type' => 'array' ),
			'covers_core'      => array( 'type' => 'boolean' ),
			'version_source'   => array( 'type' => 'string' ),
			'limits'           => array( 'type' => 'array' ),
		);
	}

	/**
	 * @since  0.0.45
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.45
	 * @param  array<string,mixed> $input Unused.
	 * @return array<string,mixed>
	 */
	protected function run( array $input ) {
		$active = Wp_Rollback_Guard::is_active();

		return array(
			'host_plugin'     => (object) array(
				'name'      => 'WP Rollback',
				'slug'      => Wp_Rollback_Guard::PLUGIN_SLUG,
				'file'      => Wp_Rollback_Guard::PLUGIN_FILE,
				'active'    => $active,
				'directory' => 'https://wordpress.org/plugins/' . Wp_Rollback_Guard::PLUGIN_SLUG . '/',
			),
			'supported_types' => array( 'plugin', 'theme' ),
			// Stated rather than implied: WP Rollback's own route enum is ['plugin','theme'] and it
			// ships no CoreRollback subsystem. A caller asking to downgrade core needs a different
			// answer, and finding that out here costs nothing.
			'covers_core'     => false,
			'version_source'  => 'wordpress.org',
			'limits'          => array(
				__( 'Plugins and themes only. WordPress core is not supported by WP Rollback — use the core pinning abilities or core/rollback-wp-core.', 'acrossai-abilities-manager' ),
				__( 'Release history comes from WordPress.org. A premium or privately-hosted asset has none there, and can only be rolled back to a version this site already holds a local archive of — which requires WP Rollback, the only thing here that does.', 'acrossai-abilities-manager' ),
				__( 'Only assets already installed can be re-versioned; this does not install something new.', 'acrossai-abilities-manager' ),
			),
			'message'         => $active
				? __( 'Plugins and themes can be moved onto any version WordPress.org publishes, via versions/rollback-plugin and versions/rollback-theme. WP Rollback is also active, so versions it has archived locally can be restored too — including for assets the directory does not host.', 'acrossai-abilities-manager' )
				: __( 'Plugins and themes can be moved onto any version WordPress.org publishes, via versions/rollback-plugin and versions/rollback-theme. These drive WordPress core\'s own upgrader and need no host plugin. WP Rollback is not active; installing wordpress.org/plugins/wp-rollback/ adds local archives of previously installed versions, which are the only source for an asset the directory does not host.', 'acrossai-abilities-manager' ),
		);
	}
}
