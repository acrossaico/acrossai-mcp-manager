<?php
/**
 * Feature 067 — return Elementor.com pattern & layout guidance.
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
 * Return the pattern & layout guidance catalog from Guidance_Catalog.
 */
class Get_Official_Pattern_Guidance extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/get-official-pattern-guidance',
			'args' => array(
				'label'               => __( 'Get Elementor Pattern Guidance', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return canonical Elementor.com pattern and layout guidance (widgets, patterns, layouts). Grounded in Elementor documentation.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'topic' => array( 'type' => 'string', 'enum' => array( 'widgets', 'patterns', 'layouts' ) ),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'guidance'       => array( 'type' => 'object' ),
						'source_policy'  => array( 'type' => 'string' ),
						'guidance_basis' => array( 'type' => 'string' ),
						'message'        => array( 'type' => 'string' ),
						'error_code'     => array( 'type' => 'string' ),
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
		$topic    = isset( $input['topic'] ) ? sanitize_key( (string) $input['topic'] ) : '';
		$guidance = Guidance_Catalog::pattern_guidance( $topic );

		return array(
			'success'        => true,
			'guidance'       => $guidance,
			'source_policy'  => (string) ( $guidance['source_policy'] ?? '' ),
			'guidance_basis' => (string) ( $guidance['guidance_basis'] ?? '' ),
			'message'        => '' !== $topic
				? sprintf( /* translators: %s: topic */ __( 'Returned guidance for topic "%s".', 'acrossai-abilities-manager' ), $topic )
				: __( 'Returned full guidance catalog.', 'acrossai-abilities-manager' ),
		);
	}
}
