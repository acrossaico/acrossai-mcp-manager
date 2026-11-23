<?php
/**
 * Feature 067 — replace an Elementor document's full data payload.
 *
 * Distinct from patch-data (string find/replace) and clone-data
 * (post-to-post copy) — this ability overwrites `_elementor_data`
 * with a caller-supplied element tree. Guarded by force_replace when
 * replacing populated content.
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
 * Overwrite the full Elementor document tree for a post. Guarded by
 * force_replace when the new payload is materially smaller than the
 * existing document (prevents accidental wipes).
 */
class Update_Data extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/update-data',
			'args' => array(
				'label'               => __( 'Update Elementor Document Data', 'acrossai-abilities-manager' ),
				'description'         => __( 'Overwrite the full Elementor document tree for a post with a caller-supplied element array. Optional page_settings. Guarded by force_replace=true when replacing a populated document with a smaller payload.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
						'data'          => array( 'type' => 'array' ),
						'page_settings' => array( 'type' => 'object' ),
						'force_replace' => array( 'type' => 'boolean', 'default' => false ),
						'cache_scope'   => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
					),
					'required'             => array( 'post_id', 'data' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'post_id'       => array( 'type' => 'integer' ),
						'element_count' => array( 'type' => 'integer' ),
						'cache'         => array( 'type' => 'object' ),
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
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
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
			return $this->fail( 0, (string) $check->get_error_code(), (string) $check->get_error_message() );
		}

		$post_id       = absint( $input['post_id'] ?? 0 );
		$data          = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : null;
		$page_settings = isset( $input['page_settings'] ) && is_array( $input['page_settings'] ) ? $input['page_settings'] : null;
		$force_replace = ! empty( $input['force_replace'] );
		$cache_scope   = isset( $input['cache_scope'] ) ? (string) $input['cache_scope'] : 'post';

		if ( null === $data ) {
			return $this->fail( $post_id, 'invalid_payload', __( 'data array is required.', 'acrossai-abilities-manager' ) );
		}

		$doc = Document_Repository::load_document( $post_id, 'edit' );
		if ( is_wp_error( $doc ) ) {
			return $this->fail( $post_id, (string) $doc->get_error_code(), (string) $doc->get_error_message() );
		}

		// Force-guard: refuse when the new payload is materially smaller than existing.
		if ( ! $force_replace && Document_Repository::is_document_populated( $doc['data'] ) && count( $data ) < count( $doc['data'] ) ) {
			return $this->fail(
				$post_id,
				'force_replace_required',
				__( 'New data is smaller than existing document. Pass force_replace=true to overwrite.', 'acrossai-abilities-manager' )
			);
		}

		$saved = Document_Repository::save_data( $post_id, $data, $cache_scope );
		if ( is_wp_error( $saved ) ) {
			return $this->fail( $post_id, (string) $saved->get_error_code(), (string) $saved->get_error_message() );
		}
		if ( null !== $page_settings ) {
			update_post_meta( $post_id, '_elementor_page_settings', $page_settings );
		}

		$element_count = 0;
		Document_Repository::walk_tree(
			$data,
			static function () use ( &$element_count ): void {
				++$element_count;
			}
		);

		return array(
			'success'       => true,
			'post_id'       => $post_id,
			'element_count' => $element_count,
			'cache'         => array( 'scope' => $cache_scope, 'invalidated' => 'none' !== $cache_scope ),
			/* translators: 1: element count, 2: post id */
			'message'       => sprintf( __( 'Replaced Elementor document (%1$d elements) on post #%2$d.', 'acrossai-abilities-manager' ), $element_count, $post_id ),
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
