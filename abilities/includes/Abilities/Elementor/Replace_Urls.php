<?php
/**
 * Feature 067 — bulk-replace URLs inside Elementor documents.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Iterate all posts of the given types (default: any post with Elementor
 * content) and str_replace($from, $to) inside each `_elementor_data`.
 * Supports dry_run to preview counts without writing.
 */
class Replace_Urls extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/replace-urls',
			'args' => array(
				'label'               => __( 'Replace URLs in Elementor Documents', 'acrossai-abilities-manager' ),
				'description'         => __( 'Bulk-replace URLs (or any string) inside every Elementor document across the site. Useful for post-migration domain rewrites. Supports dry_run for a preview count with no writes.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'from'       => array( 'type' => 'string' ),
						'to'         => array( 'type' => 'string' ),
						'dry_run'    => array( 'type' => 'boolean', 'default' => true ),
						'post_types' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
					'required'             => array( 'from', 'to' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'replacements'   => array( 'type' => 'integer' ),
						'posts_affected' => array( 'type' => 'integer' ),
						'dry_run'        => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'error_code'     => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'elementor',
						'sub_group'       => 'elementor-system',
						'sub_group_label' => __( 'System & Maintenance', 'acrossai-abilities-manager' ),
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
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$from       = isset( $input['from'] ) ? (string) $input['from'] : '';
		$to         = isset( $input['to'] ) ? (string) $input['to'] : '';
		$dry_run    = ! isset( $input['dry_run'] ) || (bool) $input['dry_run'];
		$post_types = isset( $input['post_types'] ) && is_array( $input['post_types'] )
			? array_map( 'sanitize_key', $input['post_types'] )
			: array( 'post', 'page', 'elementor_library' );

		if ( '' === $from ) {
			return array( 'success' => false, 'message' => __( 'from is required.', 'acrossai-abilities-manager' ), 'error_code' => 'invalid_payload' );
		}

		$query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => '_elementor_data',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$total_replacements = 0;
		$posts_affected     = 0;
		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			$raw     = Document_Repository::get_raw_data( $post_id );
			if ( '' === $raw || false === strpos( $raw, $from ) ) {
				continue;
			}
			$count   = 0;
			$patched = str_replace( $from, $to, $raw, $count );
			if ( 0 === $count ) {
				continue;
			}
			$total_replacements += $count;
			++$posts_affected;
			if ( ! $dry_run ) {
				$parsed = Document_Repository::decode_data( $patched );
				Document_Repository::save_data( $post_id, $parsed, 'post' );
			}
		}

		return array(
			'success'        => true,
			'replacements'   => $total_replacements,
			'posts_affected' => $posts_affected,
			'dry_run'        => $dry_run,
			/* translators: 1: replacement count, 2: posts affected, 3: dry_run indicator */
			'message'        => sprintf( __( '%1$d replacements across %2$d posts (dry_run=%3$s).', 'acrossai-abilities-manager' ), $total_replacements, $posts_affected, $dry_run ? 'true' : 'false' ),
		);
	}
}
