<?php
/**
 * Feature 067 — return a post's raw Elementor document data.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return the parsed Elementor tree + page settings for a post.
 * Read-only, idempotent.
 */
class Get_Data extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/get-data',
			'args' => array(
				'label'               => __( 'Get Elementor Document Data', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the parsed Elementor document tree + page settings for a post.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'post_id'       => array( 'type' => 'integer' ),
						'data'          => array( 'type' => 'array' ),
						'page_settings' => array( 'type' => 'object' ),
						'element_count' => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
						'error_code'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-documents',
						'sub_group_label' => __( 'Documents', 'acrossai-abilities-manager' ),
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
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$elementor_check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $elementor_check ) ) {
			return $this->fail( 0, (string) $elementor_check->get_error_code(), (string) $elementor_check->get_error_message() );
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		$doc     = Document_Repository::load_document( $post_id, 'read' );
		if ( is_wp_error( $doc ) ) {
			return $this->fail( $post_id, (string) $doc->get_error_code(), (string) $doc->get_error_message() );
		}

		$element_count = 0;
		Document_Repository::walk_tree(
			$doc['data'],
			static function () use ( &$element_count ): void {
				++$element_count;
			}
		);

		return array(
			'success'       => true,
			'post_id'       => $post_id,
			'data'          => $doc['data'],
			'page_settings' => $doc['page_settings'],
			'element_count' => $element_count,
			/* translators: 1: element count, 2: post ID */
			'message'       => sprintf( __( 'Returned %1$d elements for post #%2$d.', 'acrossai-abilities-manager' ), $element_count, $post_id ),
		);
	}

	/**
	 * @param int    $post_id
	 * @param string $code
	 * @param string $message
	 * @return array<string,mixed>
	 */
	private function fail( int $post_id, string $code, string $message ): array {
		return array(
			'success'    => false,
			'post_id'    => $post_id,
			'message'    => $message,
			'error_code' => $code,
		);
	}
}
