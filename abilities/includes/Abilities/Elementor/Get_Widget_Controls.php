<?php
/**
 * Feature 067 — return an Elementor widget's control schema.
 *
 * The single most-important ability in the Elementor suite: enables
 * schema discovery for any registered widget (free + Pro + third-party)
 * at runtime, so clients can compose valid add-widget / update-element
 * payloads without hard-coded per-widget wrappers.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Widget_Controls;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return the schema-safe control summary for any registered Elementor
 * widget type. Optional case-insensitive search filter across
 * name/label/description/section/type.
 */
class Get_Widget_Controls extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/get-widget-controls',
			'args' => array(
				'label'               => __( 'Get Elementor Widget Controls', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the schema-safe summary of the native Elementor controls exposed by a widget type on the current site. Use this before authoring add-widget or update-element calls to discover valid setting keys and types.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'widget_type' => array(
							'type'        => 'string',
							'description' => 'Elementor widget slug (e.g. "heading", "nav-menu", "posts", "mega-menu").',
						),
						'search'      => array(
							'type'        => 'string',
							'description' => 'Optional case-insensitive filter matched against control name, label, description, section, and type.',
						),
					),
					'required'             => array( 'widget_type' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'     => array( 'type' => 'boolean' ),
						'widget_type' => array( 'type' => 'string' ),
						'count'       => array( 'type' => 'integer' ),
						'controls'    => array( 'type' => 'array' ),
						'message'     => array( 'type' => 'string' ),
						'error_code'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-guidance',
						'sub_group_label' => __( 'Discovery & Guidance', 'acrossai-abilities-manager' ),
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
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$elementor_check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $elementor_check ) ) {
			return array(
				'success'    => false,
				'message'    => $elementor_check->get_error_message(),
				'error_code' => (string) $elementor_check->get_error_code(),
			);
		}

		$widget_type = isset( $input['widget_type'] ) ? sanitize_key( (string) $input['widget_type'] ) : '';
		$search      = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';

		if ( '' === $widget_type ) {
			return array(
				'success'    => false,
				'message'    => __( 'widget_type is required.', 'acrossai-abilities-manager' ),
				'error_code' => 'invalid_widget_type',
			);
		}

		$widget = Widget_Controls::get_type( $widget_type );
		if ( null === $widget ) {
			return array(
				'success'     => false,
				'widget_type' => $widget_type,
				'message'     => __( 'Widget type not found on this site.', 'acrossai-abilities-manager' ),
				'error_code'  => 'invalid_widget_type',
			);
		}

		$controls  = method_exists( $widget, 'get_controls' ) ? $widget->get_controls() : array();
		$controls  = is_array( $controls ) ? $controls : array();
		$summaries = Widget_Controls::summarize( $controls, $search );

		return array(
			'success'     => true,
			'widget_type' => $widget_type,
			'count'       => count( $summaries ),
			'controls'    => $summaries,
			/* translators: 1: control count, 2: widget slug */
			'message'     => sprintf( __( 'Returned %1$d controls for widget "%2$s".', 'acrossai-abilities-manager' ), count( $summaries ), $widget_type ),
		);
	}
}
