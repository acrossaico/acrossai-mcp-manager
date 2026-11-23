<?php
/**
 * Site maintenance mode — remove the .maintenance marker and clear
 * the refresh cron scheduled by Set_Site_Maintenance_Mode.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteHealth
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteHealth;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivate WordPress core maintenance mode. Idempotent — safe to call
 * whether or not the marker is currently present.
 */
class Unset_Site_Maintenance_Mode extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'site-health/unset-site-maintenance-mode',
			'args' => array(
				'label'               => __( 'Unset Site Maintenance Mode', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deactivate WordPress core maintenance mode: delete the ABSPATH/.maintenance marker and clear the refresh cron. Idempotent — safe to call when maintenance mode is already inactive.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-site-health',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'was_active' => array( 'type' => 'boolean' ),
						'active'     => array( 'type' => 'boolean' ),
						'message'    => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'maintenance',
						'sub_group_label' => __( 'Maintenance', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
				),
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$marker     = ABSPATH . '.maintenance';
		$was_active = file_exists( $marker );

		if ( $was_active ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- symmetric with Set_Site_Maintenance_Mode::write_marker().
			$removed = @unlink( $marker );
			if ( ! $removed && file_exists( $marker ) ) {
				return array(
					'success'    => false,
					'was_active' => true,
					'active'     => true,
					'message'    => __( 'Could not remove the .maintenance marker file. Check filesystem permissions.', 'acrossai-abilities-manager' ),
					'error_code' => 'marker_delete_failed',
				);
			}
		}

		delete_option( Set_Site_Maintenance_Mode::EXPIRY_OPTION );
		wp_clear_scheduled_hook( Set_Site_Maintenance_Mode::CRON_HOOK );

		return array(
			'success'    => true,
			'was_active' => $was_active,
			'active'     => false,
			'message'    => $was_active
				? __( 'Maintenance mode deactivated; marker removed and refresh cron cleared.', 'acrossai-abilities-manager' )
				: __( 'Maintenance mode was already inactive.', 'acrossai-abilities-manager' ),
		);
	}
}
