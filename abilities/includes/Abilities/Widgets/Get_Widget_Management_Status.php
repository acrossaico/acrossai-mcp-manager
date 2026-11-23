<?php
/**
 * Feature 111 — Get Widget Management Status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Widgets
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Widgets;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Widget_Repository;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * widgets/get-widget-management-status — Get Widget Management Status.
 */
class Get_Widget_Management_Status extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'widgets/get-widget-management-status',
			'args' => array(
				'label'               => __( 'Get Widget Management Status', 'acrossai-abilities-manager' ),
				'description'         => __( 'How widgets are managed on this site and whether they will render: which editor manages them, whether the theme is a block theme, whether the Classic Widgets plugin is active, and crucially how many sidebars the active theme registers. A block theme usually registers none, in which case widgets left over from a previous theme still exist and still hold their settings but appear nowhere on the site. Those are reported as orphaned sidebars. Start here when widget changes are not showing up.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-widgets',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_theme_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
					),
					'required'             => array(  ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'status' => array( 'type' => 'object' ),
						'error_code' => array( 'type' => 'string' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'widgets',
						'sub_group_label' => __( 'Widgets', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Where a caller usually goes next.
	 *
	 * @return array<int, array<string, string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'widgets/list-sidebars',
				'reason' => __( 'The sidebars the active theme registers, with their names and markup.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'plugins/activate-plugin',
				'reason' => __( 'Classic Widgets restores the classic widget screens; it has no settings of its own, so activating it is the whole of its configuration.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Run.
	 *
	 * @param  array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$status = Widget_Repository::management_status();

		$message = $status['block_editor_manages_widgets']
			? __( 'Widgets are managed by the block editor.', 'acrossai-abilities-manager' )
			: __( 'Widgets are managed by the classic widget screens.', 'acrossai-abilities-manager' );

		if ( 0 === $status['registered_sidebars'] ) {
			$message .= ' ' . __( 'This theme registers no sidebars, so classic widgets have nowhere to appear.', 'acrossai-abilities-manager' );
		}

		if ( ! empty( $status['orphaned_sidebars'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated sidebar ids */
				__( 'Widgets are still stored against %s, which no active theme registers — they will not render.', 'acrossai-abilities-manager' ),
				implode( ', ', $status['orphaned_sidebars'] )
			);
		}

		return array(
			'success' => true,
			'status'  => $status,
			'message' => $message,
		);
	}
}
