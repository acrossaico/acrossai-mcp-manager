<?php
/**
 * Feature 067 — search templates by pattern keywords.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Template_Query;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

class Find_Template_For_Pattern extends Ability_Definition {

	protected function ability(): array {
		return array(
			'name' => 'elementor/find-template-for-pattern',
			'args' => array(
				'label'               => __( 'Find Elementor Template For Pattern', 'acrossai-abilities-manager' ),
				'description'         => __( 'Rank saved Elementor templates by keyword match (title / template_type / widget-types present in content). Use before raw authoring to reuse existing patterns.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'pattern_keywords' => array( 'type' => 'string' ),
						'template_type'    => array( 'type' => 'string' ),
						'limit'            => array( 'type' => 'integer', 'default' => 5 ),
					),
					'required'   => array( 'pattern_keywords' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'   => array( 'type' => 'boolean' ),
						'templates' => array( 'type' => 'array' ),
						'count'     => array( 'type' => 'integer' ),
						'message'   => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-templates', 'sub_group_label' => __( 'Templates & Theme Builder', 'acrossai-abilities-manager' ) ),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$keywords      = (string) ( $input['pattern_keywords'] ?? '' );
		$template_type = (string) ( $input['template_type'] ?? '' );
		$limit         = (int) ( $input['limit'] ?? 5 );

		if ( '' === trim( $keywords ) ) {
			return array( 'success' => false, 'message' => __( 'pattern_keywords is required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_payload' );
		}
		$posts   = Template_Query::query( array( 'template_type' => $template_type, 'limit' => 200 ) );
		$ranked  = Template_Query::rank_by_pattern( $posts, $keywords, max( 1, $limit ) );
		return array(
			'success'   => true,
			'templates' => $ranked,
			'count'     => count( $ranked ),
			/* translators: 1: count, 2: keywords */
			'message'   => sprintf( __( 'Ranked %1$d templates for keywords "%2$s".', 'acrossai-abilities-manager' ), count( $ranked ), $keywords ),
		);
	}
}
