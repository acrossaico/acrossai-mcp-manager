<?php
/**
 * Feature 070 — categorized site-editor inventory summary.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return templates by area, template parts by area, style variations by title,
 * and navigations by title + slug. Complements get-site-editor-context (counts only).
 */
class Get_Site_Editor_Summary extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/get-site-editor-summary',
			'args' => array(
				'label'               => __( 'Get Site Editor Summary', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return a categorized inventory of Site Editor objects: templates grouped by area, template parts grouped by area, style variations by title, and navigation entities by title + slug. Complements get-site-editor-context (scalar counts).', 'acrossai-abilities-manager' ),
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
						'success'              => array( 'type' => 'boolean' ),
						'templates_by_area'    => array( 'type' => 'object' ),
						'template_parts_by_area' => array( 'type' => 'object' ),
						'style_variations'     => array( 'type' => 'array' ),
						'navigations'          => array( 'type' => 'array' ),
						'message'              => array( 'type' => 'string' ),
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
						'readonly'    => true,
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

		$templates      = function_exists( 'get_block_templates' ) ? (array) get_block_templates( array(), 'wp_template' ) : array();
		$template_parts = function_exists( 'get_block_templates' ) ? (array) get_block_templates( array(), 'wp_template_part' ) : array();

		$templates_by_area      = array();
		$template_parts_by_area = array();

		foreach ( $templates as $template ) {
			$area  = isset( $template->area ) && '' !== $template->area ? sanitize_key( (string) $template->area ) : 'uncategorized';
			$slug  = sanitize_text_field( (string) ( $template->slug ?? '' ) );
			$title = sanitize_text_field( (string) ( is_object( $template->title ?? null ) ? ( $template->title->rendered ?? '' ) : ( $template->title ?? '' ) ) );

			if ( ! isset( $templates_by_area[ $area ] ) ) {
				$templates_by_area[ $area ] = array();
			}
			$templates_by_area[ $area ][] = array(
				'slug'   => $slug,
				'title'  => $title,
				'source' => sanitize_text_field( (string) ( $template->source ?? '' ) ),
			);
		}

		foreach ( $template_parts as $part ) {
			$area  = isset( $part->area ) && '' !== $part->area ? sanitize_key( (string) $part->area ) : 'uncategorized';
			$slug  = sanitize_text_field( (string) ( $part->slug ?? '' ) );
			$title = sanitize_text_field( (string) ( is_object( $part->title ?? null ) ? ( $part->title->rendered ?? '' ) : ( $part->title ?? '' ) ) );

			if ( ! isset( $template_parts_by_area[ $area ] ) ) {
				$template_parts_by_area[ $area ] = array();
			}
			$template_parts_by_area[ $area ][] = array(
				'slug'   => $slug,
				'title'  => $title,
				'source' => sanitize_text_field( (string) ( $part->source ?? '' ) ),
			);
		}

		$style_variations = array();
		if ( class_exists( '\WP_Theme_JSON_Resolver' ) && method_exists( '\WP_Theme_JSON_Resolver', 'get_style_variations' ) ) {
			$raw = (array) \WP_Theme_JSON_Resolver::get_style_variations();
			foreach ( $raw as $variation ) {
				$style_variations[] = array(
					'title' => sanitize_text_field( (string) ( $variation['title'] ?? '' ) ),
					'slug'  => sanitize_key( (string) ( $variation['slug'] ?? '' ) ),
				);
			}
		}

		$navigations = array();
		$nav_posts   = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( (array) $nav_posts as $nav ) {
			$navigations[] = array(
				'id'    => (int) $nav->ID,
				'title' => sanitize_text_field( (string) $nav->post_title ),
				'slug'  => sanitize_title( (string) $nav->post_name ),
			);
		}

		return array(
			'success'                => true,
			'templates_by_area'      => (object) $templates_by_area,
			'template_parts_by_area' => (object) $template_parts_by_area,
			'style_variations'       => $style_variations,
			'navigations'            => $navigations,
			'message'                => __( 'Site editor inventory returned.', 'acrossai-abilities-manager' ),
		);
	}
}
