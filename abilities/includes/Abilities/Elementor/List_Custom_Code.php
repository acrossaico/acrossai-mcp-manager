<?php
/**
 * Feature 067 — list Elementor Pro custom code snippets.
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
 * List Elementor Pro custom code snippets (elementor_snippet CPT).
 * Pro-gated: requires class_exists('\ElementorPro\Plugin') or ELEMENTOR_PRO_VERSION.
 */
class List_Custom_Code extends Ability_Definition {

	/** The Elementor Pro custom-code CPT slug. */
	public const CPT = 'elementor_snippet';

	protected function ability(): array {
		return array(
			'name' => 'elementor/list-custom-code',
			'args' => array(
				'label'               => __( 'List Elementor Pro Custom Code', 'acrossai-abilities-manager' ),
				'description'         => __( 'List Elementor Pro Custom Code snippets. Filter by location and status. Requires Elementor Pro.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ); },
				'input_schema'        => array(
					'type' => 'object',
					'properties' => array(
						'location' => array( 'type' => 'string', 'enum' => array( 'head', 'body_start', 'body_end', 'footer' ) ),
						'status'   => array( 'type' => 'string', 'default' => 'any' ),
					),
					'required' => array(),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'  => array( 'type' => 'boolean' ),
						'snippets' => array( 'type' => 'array' ),
						'count'    => array( 'type' => 'integer' ),
						'message'  => array( 'type' => 'string' ),
						'error_code' => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => 'elementor-custom-code', 'sub_group_label' => __( 'Custom Code', 'acrossai-abilities-manager' ) ),
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
		$pro = Document_Repository::assert_elementor_pro_available();
		if ( is_wp_error( $pro ) ) {
			return array( 'success' => false, 'message' => (string) $pro->get_error_message(), 'error_code' => (string) $pro->get_error_code() );
		}
		$location = isset( $input['location'] ) ? sanitize_key( (string) $input['location'] ) : '';
		$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : 'any';

		$query_args = array(
			'post_type'      => self::CPT,
			'post_status'    => $status,
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);
		if ( '' !== $location ) {
			$query_args['meta_query'] = array(
				array( 'key' => '_elementor_snippet_location', 'value' => $location ),
			);
		}
		$query    = new WP_Query( $query_args );
		$snippets = array();
		foreach ( $query->posts as $post ) {
			$snippets[] = self::to_summary( $post );
		}
		return array(
			'success'  => true,
			'snippets' => $snippets,
			'count'    => count( $snippets ),
			/* translators: %d: count */
			'message'  => sprintf( __( 'Returned %d Pro custom code snippets.', 'acrossai-abilities-manager' ), count( $snippets ) ),
		);
	}

	/**
	 * @param \WP_Post $post
	 * @return array<string, mixed>
	 */
	public static function to_summary( \WP_Post $post ): array {
		return array(
			'id'       => (int) $post->ID,
			'title'    => (string) $post->post_title,
			'status'   => (string) $post->post_status,
			'location' => (string) get_post_meta( $post->ID, '_elementor_snippet_location', true ),
			'priority' => (int) get_post_meta( $post->ID, '_elementor_snippet_priority', true ),
		);
	}
}
