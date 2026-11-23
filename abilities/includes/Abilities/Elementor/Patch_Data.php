<?php
/**
 * Feature 067 — find/replace text within an Elementor document.
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
 * Search-and-replace text within an Elementor document's serialised JSON.
 * Case-sensitive; returns the replacement count.
 */
class Patch_Data extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/patch-data',
			'args' => array(
				'label'               => __( 'Patch Elementor Document Data', 'acrossai-abilities-manager' ),
				'description'         => __( 'Find and replace text within an Elementor document\'s serialised JSON. Operates on the raw string so it can update text in any control (headings, text-editors, buttons, image alt-text, etc.) in one pass. Case-sensitive.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'find'        => array( 'type' => 'string' ),
						'replace'     => array( 'type' => 'string' ),
						'cache_scope' => array( 'type' => 'string', 'enum' => array( 'none', 'post', 'site' ), 'default' => 'post' ),
					),
					'required'             => array( 'post_id', 'find', 'replace' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'post_id'      => array( 'type' => 'integer' ),
						'replacements' => array( 'type' => 'integer' ),
						'message'      => array( 'type' => 'string' ),
						'error_code'   => array( 'type' => 'string' ),
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
			return array( 'success' => false, 'post_id' => 0, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$post_id     = absint( $input['post_id'] ?? 0 );
		$find        = isset( $input['find'] ) ? (string) $input['find'] : '';
		$replace     = isset( $input['replace'] ) ? (string) $input['replace'] : '';
		$cache_scope = isset( $input['cache_scope'] ) ? (string) $input['cache_scope'] : 'post';

		if ( '' === $find ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => __( 'find is required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_payload' );
		}

		$doc = Document_Repository::load_document( $post_id, 'edit' );
		if ( is_wp_error( $doc ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => (string) $doc->get_error_message(), 'error_code' => (string) $doc->get_error_code() );
		}

		$raw    = (string) $doc['raw_data'];
		$count  = 0;
		$patched = str_replace( $find, $replace, $raw, $count );
		if ( 0 === $count ) {
			return array(
				'success'      => true,
				'post_id'      => $post_id,
				'replacements' => 0,
				'message'      => __( 'No matches found — nothing changed.', 'acrossai-abilities-manager' ),
			);
		}

		$parsed = Document_Repository::decode_data( $patched );
		$saved  = Document_Repository::save_data( $post_id, $parsed, $cache_scope );
		if ( is_wp_error( $saved ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => (string) $saved->get_error_message(), 'error_code' => (string) $saved->get_error_code() );
		}

		return array(
			'success'      => true,
			'post_id'      => $post_id,
			'replacements' => (int) $count,
			/* translators: 1: count, 2: post id */
			'message'      => sprintf( __( 'Replaced %1$d occurrences in post #%2$d.', 'acrossai-abilities-manager' ), $count, $post_id ),
		);
	}
}
