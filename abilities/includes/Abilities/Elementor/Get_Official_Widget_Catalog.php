<?php
/**
 * Feature 067 — return Elementor's canonical widget catalog.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Guidance_Catalog;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return the seeded Elementor widget catalog (Basic / Pro / Theme /
 * WooCommerce). Uses a 12-hour transient over the Guidance_Catalog data.
 */
class Get_Official_Widget_Catalog extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/get-official-widget-catalog',
			'args' => array(
				'label'               => __( 'Get Elementor Official Widget Catalog', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the canonical Elementor widget catalog (Basic / Pro / Theme / WooCommerce). Uses a 12-hour transient over the seeded catalog.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'category' => array( 'type' => 'string', 'enum' => Guidance_Catalog::CATEGORIES ),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'widgets'  => array( 'type' => 'array' ),
						'count'    => array( 'type' => 'integer' ),
						'message'  => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
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
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$category = isset( $input['category'] ) ? sanitize_key( (string) $input['category'] ) : '';
		$widgets  = Guidance_Catalog::widget_catalog( $category );

		return array(
			'success' => true,
			'widgets' => $widgets,
			'count'   => count( $widgets ),
			/* translators: 1: count, 2: category */
			'message' => '' !== $category
				? sprintf( __( 'Returned %1$d widgets in category "%2$s".', 'acrossai-abilities-manager' ), count( $widgets ), $category )
				: sprintf( __( 'Returned %d widgets across all categories.', 'acrossai-abilities-manager' ), count( $widgets ) ),
		);
	}
}
