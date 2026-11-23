<?php
/**
 * Feature 055 — invalidate cached Site Editor context.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Flush any block-template and theme.json related caches so the next
 * call to site-editor-get-context returns fresh state.
 */
class Refresh_Site_Editor_Context extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/refresh-site-editor-context',
			'args' => array(
				'label'               => __( 'Refresh Site Editor Context', 'acrossai-abilities-manager' ),
				'description'         => __( 'Flush block-template + theme.json related caches so the next call to site-editor-get-context returns fresh state. Invalidates: `wp_theme_features`, `theme_json` cache group entries, and post cache for `wp_template` + `wp_template_part` post types.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'refreshed_at' => array( 'type' => 'integer' ),
						'invalidated'  => array( 'type' => 'array' ),
						'message'      => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'site-editor',
						'sub_group_label' => __( 'Site Editor', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		unset( $input );

		$invalidated = array();

		if ( function_exists( 'wp_cache_delete_group' ) ) {
			wp_cache_delete_group( 'theme_json' );
			$invalidated[] = 'theme_json';
		}
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache();
			$invalidated[] = 'themes';
		}
		if ( function_exists( 'clean_post_cache' ) ) {
			foreach ( array( 'wp_template', 'wp_template_part', 'wp_block' ) as $ptype ) {
				$ids = get_posts(
					array(
						'post_type'      => $ptype,
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
					)
				);
				if ( is_array( $ids ) ) {
					foreach ( $ids as $id ) {
						clean_post_cache( (int) $id );
					}
				}
				$invalidated[] = $ptype;
			}
		}
		if ( class_exists( '\WP_Theme_JSON_Resolver' ) && method_exists( '\WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
			$invalidated[] = 'wp_theme_json_resolver';
		}

		return array(
			'success'      => true,
			'refreshed_at' => time(),
			'invalidated'  => $invalidated,
			/* translators: %d: invalidated cache-source count */
			'message'      => sprintf( __( 'Site Editor context invalidated across %d cache source(s).', 'acrossai-abilities-manager' ), count( $invalidated ) ),
		);
	}
}
