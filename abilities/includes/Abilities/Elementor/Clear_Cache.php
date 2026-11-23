<?php
/**
 * Feature 067 — clear Elementor cache at post or site scope.
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
 * Clear Elementor's cache — post-scope, site-scope, or both. Optionally
 * regenerate the per-post CSS meta.
 */
class Clear_Cache extends Ability_Definition {

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'elementor/clear-cache',
			'args' => array(
				'label'               => __( 'Clear Elementor Cache', 'acrossai-abilities-manager' ),
				'description'         => __( 'Clear Elementor cache at post scope, site scope, or both. Optional regenerate_css=true to also invalidate the per-post CSS meta for one post.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scope'          => array( 'type' => 'string', 'enum' => array( 'post', 'site', 'all' ), 'default' => 'post' ),
						'post_id'        => array( 'type' => 'integer', 'minimum' => 1 ),
						'regenerate_css' => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'             => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'         => array( 'type' => 'boolean' ),
						'scope'           => array( 'type' => 'string' ),
						'cleared'         => array( 'type' => 'object' ),
						'css_regenerated' => array( 'type' => 'boolean' ),
						'message'         => array( 'type' => 'string' ),
						'error_code'      => array( 'type' => 'string' ),
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
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
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
		$scope           = isset( $input['scope'] ) ? sanitize_key( (string) $input['scope'] ) : 'post';
		$post_id         = absint( $input['post_id'] ?? 0 );
		$regenerate_css  = ! empty( $input['regenerate_css'] );
		$css_regenerated = false;

		$cleared = array();
		if ( 'post' === $scope || 'all' === $scope ) {
			if ( $post_id > 0 ) {
				$cleared['post'] = Document_Repository::invalidate_cache( $post_id, 'post' );
				if ( $regenerate_css ) {
					// The invalidate_cache call above already deletes _elementor_css meta;
					// Elementor regenerates the CSS file on the next frontend request.
					$css_regenerated = true;
				}
			}
		}
		if ( 'site' === $scope || 'all' === $scope ) {
			$instance = \Elementor\Plugin::$instance;
			if ( isset( $instance->files_manager ) && method_exists( $instance->files_manager, 'clear_cache' ) ) {
				$instance->files_manager->clear_cache();
				$cleared['site'] = array( 'files_manager' => true );
			}
		}

		return array(
			'success'         => true,
			'scope'           => $scope,
			'cleared'         => $cleared,
			'css_regenerated' => $css_regenerated,
			'message'         => sprintf( /* translators: %s: scope */ __( 'Cleared Elementor cache (scope: %s).', 'acrossai-abilities-manager' ), $scope ),
		);
	}
}
