<?php
/**
 * Feature 075 — recipe registry for block-editor generators.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Load bundled recipes and expose them through a filter-extensible seam.
 * Recipe shape: [ id, title, description, input_shape, blocks (template tree) ].
 */
class Block_Recipe_Registry {

	public const KIND_PAGE          = 'page';
	public const KIND_SECTION       = 'section';
	public const KIND_QUERY_SECTION = 'query_section';

	/**
	 * Return every recipe of a given kind after filter extension.
	 *
	 * @param string $kind One of the KIND_* constants.
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( string $kind ): array {
		$builtin  = self::builtins( $kind );
		$filtered = apply_filters( 'acrossai_block_recipes', $builtin, $kind );
		if ( ! is_array( $filtered ) ) {
			$filtered = $builtin;
		}
		$out = array();
		foreach ( $filtered as $recipe ) {
			if ( is_array( $recipe ) && isset( $recipe['id'] ) ) {
				$out[] = $recipe;
			}
		}
		return $out;
	}

	/**
	 * Look up one recipe by ID (across all kinds).
	 *
	 * @param string $id Recipe ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		foreach ( array( self::KIND_PAGE, self::KIND_SECTION, self::KIND_QUERY_SECTION ) as $kind ) {
			foreach ( self::all( $kind ) as $recipe ) {
				if ( (string) $recipe['id'] === $id ) {
					return $recipe;
				}
			}
		}
		return null;
	}

	/**
	 * Built-in recipes shipped with the plugin.
	 *
	 * @param string $kind Kind.
	 * @return array<int,array<string,mixed>>
	 */
	private static function builtins( string $kind ): array {
		switch ( $kind ) {
			case self::KIND_PAGE:
				return array(
					array(
						'id'          => 'landing-simple',
						'title'       => 'Simple Landing Page',
						'description' => 'Hero + about + call-to-action.',
						'section_slugs' => array( 'hero-simple', 'copy-block', 'cta-simple' ),
						'input_shape' => array(
							'business_name' => 'string',
							'tone'          => 'string',
						),
					),
					array(
						'id'          => 'landing-service',
						'title'       => 'Service Landing Page',
						'description' => 'Hero + service list + testimonials + CTA.',
						'section_slugs' => array( 'hero-simple', 'service-list', 'testimonial-quote', 'cta-simple' ),
						'input_shape' => array(
							'business_name' => 'string',
							'tone'          => 'string',
						),
					),
				);
			case self::KIND_SECTION:
				return array(
					array(
						'id'          => 'hero-simple',
						'title'       => 'Simple Hero',
						'description' => 'Heading + intro paragraph.',
						'input_shape' => array(
							'headline' => 'string',
							'intro'    => 'string',
						),
						'preview_blocks' => array(),
					),
					array(
						'id'          => 'cta-simple',
						'title'       => 'Simple CTA',
						'description' => 'Heading + call-to-action button.',
						'input_shape' => array(
							'headline' => 'string',
							'button'   => 'string',
							'url'      => 'string',
						),
						'preview_blocks' => array(),
					),
					array(
						'id'          => 'copy-block',
						'title'       => 'Copy Block',
						'description' => 'One paragraph of body copy.',
						'input_shape' => array( 'body' => 'string' ),
						'preview_blocks' => array(),
					),
					array(
						'id'          => 'service-list',
						'title'       => 'Service List',
						'description' => 'Bulleted list of services.',
						'input_shape' => array(
							'headline' => 'string',
							'items'    => 'array',
						),
						'preview_blocks' => array(),
					),
					array(
						'id'          => 'testimonial-quote',
						'title'       => 'Testimonial Quote',
						'description' => 'Blockquote with attribution.',
						'input_shape' => array(
							'quote'  => 'string',
							'author' => 'string',
						),
						'preview_blocks' => array(),
					),
				);
			case self::KIND_QUERY_SECTION:
				return array(
					array(
						'id'          => 'query-latest-posts',
						'title'       => 'Latest Posts Query',
						'description' => 'Query loop of latest posts.',
						'input_shape' => array(
							'headline'    => 'string',
							'post_type'   => 'string',
							'per_page'    => 'integer',
						),
						'post_type_defaults' => array( 'post_type' => 'post', 'per_page' => 5 ),
						'preview_blocks'     => array(),
					),
				);
			default:
				return array();
		}
	}
}
