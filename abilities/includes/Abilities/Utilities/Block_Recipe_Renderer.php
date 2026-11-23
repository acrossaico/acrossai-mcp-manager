<?php
/**
 * Feature 075 — recipe rendering (validate + substitute + assemble).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministically render a recipe into a block tree from a small input map.
 * Theme-neutral: no colors, typography, or width overrides in output.
 */
class Block_Recipe_Renderer {

	/**
	 * Render one section recipe into blocks.
	 *
	 * @param string              $recipe_id Recipe ID.
	 * @param array<string,mixed> $input     Input payload.
	 * @return array{blocks:array<int,array<string,mixed>>, warnings:string[]}
	 */
	public static function render( string $recipe_id, array $input ): array {
		$warnings = array();
		$recipe   = Block_Recipe_Registry::get( $recipe_id );
		if ( null === $recipe ) {
			return array( 'blocks' => array(), 'warnings' => array( sprintf( 'Unknown recipe id: %s', $recipe_id ) ) );
		}

		$shape = is_array( $recipe['input_shape'] ?? null ) ? $recipe['input_shape'] : array();
		foreach ( $shape as $key => $type ) {
			if ( ! array_key_exists( $key, $input ) ) {
				$warnings[] = sprintf( 'Missing required input: %s', (string) $key );
			}
		}

		$blocks = self::render_by_id( $recipe_id, $input );
		return array( 'blocks' => $blocks, 'warnings' => $warnings );
	}

	/**
	 * Render a full-page recipe by composing its section slugs.
	 *
	 * @param array<string,mixed>            $recipe Recipe entry.
	 * @param array<string,mixed>            $input  Input payload.
	 * @return array{blocks:array<int,array<string,mixed>>, warnings:string[], section_ids_used:string[]}
	 */
	public static function render_page( array $recipe, array $input ): array {
		$sections = is_array( $recipe['section_slugs'] ?? null ) ? $recipe['section_slugs'] : array();
		$blocks   = array();
		$warnings = array();
		foreach ( $sections as $section_id ) {
			$rendered = self::render_by_id( (string) $section_id, $input );
			$blocks   = array_merge( $blocks, $rendered );
		}
		return array( 'blocks' => $blocks, 'warnings' => $warnings, 'section_ids_used' => array_map( 'strval', $sections ) );
	}

	/**
	 * Render one section by ID (bundled recipes only — theme-neutral output).
	 *
	 * @param string              $id    Section ID.
	 * @param array<string,mixed> $input Input.
	 * @return array<int,array<string,mixed>>
	 */
	private static function render_by_id( string $id, array $input ): array {
		$biz  = sanitize_text_field( (string) ( $input['business_name'] ?? '' ) );
		$tone = sanitize_text_field( (string) ( $input['tone'] ?? '' ) );

		switch ( $id ) {
			case 'hero-simple':
				$headline = sanitize_text_field( (string) ( $input['headline'] ?? ( '' !== $biz ? $biz : 'Welcome' ) ) );
				$intro    = sanitize_text_field( (string) ( $input['intro'] ?? ( '' !== $tone ? "Written in a $tone tone." : 'A short intro.' ) ) );
				return array(
					self::heading( 1, $headline ),
					self::paragraph( $intro ),
				);
			case 'copy-block':
				$body = sanitize_text_field( (string) ( $input['body'] ?? 'Body copy goes here.' ) );
				return array( self::paragraph( $body ) );
			case 'cta-simple':
				$headline = sanitize_text_field( (string) ( $input['headline'] ?? 'Ready to talk?' ) );
				$label    = sanitize_text_field( (string) ( $input['button'] ?? 'Get in touch' ) );
				$url      = esc_url_raw( (string) ( $input['url'] ?? '' ) );
				return array(
					self::heading( 2, $headline ),
					self::button( $label, $url ),
				);
			case 'service-list':
				$headline = sanitize_text_field( (string) ( $input['headline'] ?? 'What we do' ) );
				$items    = is_array( $input['items'] ?? null ) ? array_map( 'strval', $input['items'] ) : array( 'Item A', 'Item B', 'Item C' );
				return array(
					self::heading( 2, $headline ),
					self::list_block( $items ),
				);
			case 'testimonial-quote':
				$quote  = sanitize_text_field( (string) ( $input['quote'] ?? 'Great work.' ) );
				$author = sanitize_text_field( (string) ( $input['author'] ?? 'A customer' ) );
				return array( self::quote( $quote, $author ) );
			case 'query-latest-posts':
				$headline = sanitize_text_field( (string) ( $input['headline'] ?? 'Latest' ) );
				return array(
					self::heading( 2, $headline ),
					self::query_loop(
						(string) ( $input['post_type'] ?? 'post' ),
						(int) ( $input['per_page'] ?? 5 )
					),
				);
			default:
				return array();
		}
	}

	/**
	 * Build a core/heading block.
	 *
	 * @param int    $level Heading level.
	 * @param string $text  Heading text.
	 * @return array<string,mixed>
	 */
	private static function heading( int $level, string $text ): array {
		$tag  = 'h' . max( 1, min( 6, $level ) );
		$html = sprintf( '<%1$s>%2$s</%1$s>', $tag, esc_html( $text ) );
		return array(
			'blockName'    => 'core/heading',
			'attrs'        => array( 'level' => $level ),
			'innerHTML'    => $html,
			'innerBlocks'  => array(),
			'innerContent' => array( $html ),
		);
	}

	/**
	 * Build a core/paragraph block.
	 *
	 * @param string $text Text.
	 * @return array<string,mixed>
	 */
	private static function paragraph( string $text ): array {
		$html = '<p>' . esc_html( $text ) . '</p>';
		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerHTML'    => $html,
			'innerBlocks'  => array(),
			'innerContent' => array( $html ),
		);
	}

	/**
	 * Build a core/list from string items.
	 *
	 * @param string[] $items Items.
	 * @return array<string,mixed>
	 */
	private static function list_block( array $items ): array {
		$inner_items = array();
		foreach ( $items as $item ) {
			$html          = '<li>' . esc_html( (string) $item ) . '</li>';
			$inner_items[] = array(
				'blockName'    => 'core/list-item',
				'attrs'        => array(),
				'innerHTML'    => $html,
				'innerBlocks'  => array(),
				'innerContent' => array( $html ),
			);
		}
		return array(
			'blockName'    => 'core/list',
			'attrs'        => array(),
			'innerHTML'    => '<ul></ul>',
			'innerBlocks'  => $inner_items,
			'innerContent' => array_merge( array( '<ul>' ), array_fill( 0, count( $inner_items ), null ), array( '</ul>' ) ),
		);
	}

	/**
	 * Build a core/buttons container with one core/button.
	 *
	 * @param string $label Button label.
	 * @param string $url   Destination.
	 * @return array<string,mixed>
	 */
	private static function button( string $label, string $url ): array {
		$anchor = sprintf( '<a class="wp-block-button__link wp-element-button" href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $label ) );
		$button = array(
			'blockName'    => 'core/button',
			'attrs'        => '' === $url ? array() : array( 'url' => esc_url_raw( $url ) ),
			'innerHTML'    => '<div class="wp-block-button">' . $anchor . '</div>',
			'innerBlocks'  => array(),
			'innerContent' => array( '<div class="wp-block-button">' . $anchor . '</div>' ),
		);
		return array(
			'blockName'    => 'core/buttons',
			'attrs'        => array(),
			'innerHTML'    => '',
			'innerBlocks'  => array( $button ),
			'innerContent' => array( null ),
		);
	}

	/**
	 * Build a core/quote.
	 *
	 * @param string $text   Quote text.
	 * @param string $author Attribution.
	 * @return array<string,mixed>
	 */
	private static function quote( string $text, string $author ): array {
		$html = sprintf( '<blockquote class="wp-block-quote"><p>%1$s</p><cite>%2$s</cite></blockquote>', esc_html( $text ), esc_html( $author ) );
		return array(
			'blockName'    => 'core/quote',
			'attrs'        => array(),
			'innerHTML'    => $html,
			'innerBlocks'  => array(),
			'innerContent' => array( $html ),
		);
	}

	/**
	 * Build a minimal core/query with a post-template.
	 *
	 * @param string $post_type Post type.
	 * @param int    $per_page  Per-page.
	 * @return array<string,mixed>
	 */
	private static function query_loop( string $post_type, int $per_page ): array {
		$per_page = max( 1, min( 50, $per_page ) );
		return array(
			'blockName'    => 'core/query',
			'attrs'        => array(
				'query' => array(
					'perPage'  => $per_page,
					'postType' => sanitize_key( $post_type ),
				),
			),
			'innerHTML'    => '',
			'innerBlocks'  => array(
				array(
					'blockName'    => 'core/post-template',
					'attrs'        => array(),
					'innerHTML'    => '',
					'innerBlocks'  => array(
						array(
							'blockName'    => 'core/post-title',
							'attrs'        => array( 'isLink' => true ),
							'innerHTML'    => '',
							'innerBlocks'  => array(),
							'innerContent' => array(),
						),
						array(
							'blockName'    => 'core/post-excerpt',
							'attrs'        => array(),
							'innerHTML'    => '',
							'innerBlocks'  => array(),
							'innerContent' => array(),
						),
					),
					'innerContent' => array( null, null ),
				),
			),
			'innerContent' => array( null ),
		);
	}
}
