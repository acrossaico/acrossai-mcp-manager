<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Cron
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Cron;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Cron_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Remove a persisted custom schedule. Built-in WordPress schedules (hourly,
 * twicedaily, daily, weekly) and schedules added by other plugins are
 * untouched — this only removes entries we wrote into our own option.
 */
class Delete_Cron_Schedule extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cron/delete-cron-schedule',
			'args' => array(
				'label'               => __( 'Delete Custom Schedule', 'acrossai-abilities-manager' ),
				'description'         => __( 'Remove a custom schedule previously registered by cron-create-schedule. Built-in and plugin-defined schedules are not affected.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cron',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'name' => array( 'type' => 'string' ),
					),
					'required'             => array( 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'name'    => array( 'type' => 'string' ),
						'removed' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'cron',
						'sub_group'       => 'delete',
						'sub_group_label' => __( 'Delete Cron Jobs', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$name = sanitize_key( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			return array(
				'success' => false,
				'message' => __( 'name is required.', 'acrossai-abilities-manager' ),
			);
		}

		$custom = Cron_Helpers::get_custom();
		if ( ! isset( $custom[ $name ] ) ) {
			return array(
				'success' => true,
				'name'    => $name,
				'removed' => false,
				/* translators: %s: schedule name */
				'message' => sprintf( __( 'No custom schedule "%s" to remove.', 'acrossai-abilities-manager' ), $name ),
			);
		}

		Cron_Helpers::remove_custom( $name );

		return array(
			'success' => true,
			'name'    => $name,
			'removed' => true,
			/* translators: %s: schedule name */
			'message' => sprintf( __( 'Removed custom schedule "%s".', 'acrossai-abilities-manager' ), $name ),
		);
	}
}
