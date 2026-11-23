<?php
/**
 * Feature 074 — structural metrics for a block tree.
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
 * Compute outline, link/media counts, block-type distribution, word count,
 * and estimated read time in a single tree walk.
 */
class Analyze_Content extends Ability_Definition {

	private const WORDS_PER_MINUTE = 200;

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/analyze-content',
			'args' => array(
				'label'               => __( 'Analyze Content', 'acrossai-abilities-manager' ),
				'description'         => __( 'Compute structural metrics for a block tree: outline (heading hierarchy), internal + external link counts, media counts, block-type distribution, word count, and estimated read time.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'blocks'  => array( 'type' => 'array' ),
						'content' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'           => array( 'type' => 'boolean' ),
						'outline'           => array( 'type' => 'array' ),
						'links'             => array( 'type' => 'object' ),
						'media'             => array( 'type' => 'object' ),
						'block_types'       => array( 'type' => 'object' ),
						'word_count'        => array( 'type' => 'integer' ),
						'read_time_minutes' => array( 'type' => 'integer' ),
						'message'           => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'analysis',
						'sub_group_label' => __( 'Analysis', 'acrossai-abilities-manager' ),
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
		$blocks = self::resolve_blocks( $input );
		if ( null === $blocks ) {
			return $this->failure( __( 'Provide post_id, blocks[], or content.', 'acrossai-abilities-manager' ) );
		}

		$site_host    = self::host_of( home_url() );
		$outline      = array();
		$links        = array( 'internal' => 0, 'external' => 0, 'total' => 0 );
		$media        = array( 'images' => 0, 'videos' => 0, 'embeds' => 0 );
		$block_types  = array();
		$word_count   = 0;

		self::walk(
			$blocks,
			array(),
			static function ( array $block, array $path ) use ( &$outline, &$links, &$media, &$block_types, &$word_count, $site_host ): void {
				$name = (string) ( $block['blockName'] ?? '' );
				if ( '' !== $name ) {
					$block_types[ $name ] = ( $block_types[ $name ] ?? 0 ) + 1;
				}

				if ( 'core/heading' === $name ) {
					$outline[] = array(
						'level' => (int) ( $block['attrs']['level'] ?? 2 ),
						'text'  => trim( (string) wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) ),
						'path'  => $path,
					);
				}

				if ( 'core/image' === $name ) {
					++$media['images'];
				} elseif ( 'core/video' === $name ) {
					++$media['videos'];
				} elseif ( 0 === strpos( $name, 'core/embed' ) || 'core/embed' === $name ) {
					++$media['embeds'];
				}

				$html = (string) ( $block['innerHTML'] ?? '' );
				if ( '' !== $html ) {
					$text = trim( (string) wp_strip_all_tags( $html ) );
					if ( '' !== $text ) {
						$word_count += str_word_count( $text );
					}
					if ( preg_match_all( '/href=["\']([^"\']+)["\']/i', $html, $m ) ) {
						foreach ( $m[1] as $url ) {
							++$links['total'];
							$host = self::host_of( $url );
							if ( '' === $host || $host === $site_host ) {
								++$links['internal'];
							} else {
								++$links['external'];
							}
						}
					}
				}
			}
		);

		$read_time = (int) max( 1, (int) ceil( $word_count / self::WORDS_PER_MINUTE ) );

		return array(
			'success'           => true,
			'outline'           => $outline,
			'links'             => (object) $links,
			'media'             => (object) $media,
			'block_types'       => (object) $block_types,
			'word_count'        => $word_count,
			'read_time_minutes' => $read_time,
			'message'           => __( 'Analysis complete.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Extract hostname from URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_of( string $url ): string {
		$parts = wp_parse_url( $url );
		return isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
	}

	/**
	 * Depth-first walk with path tracking.
	 *
	 * @param array<int,array<string,mixed>> $blocks  Blocks.
	 * @param int[]                          $prefix  Path prefix.
	 * @param callable                       $visitor Visitor.
	 * @return void
	 */
	private static function walk( array $blocks, array $prefix, callable $visitor ): void {
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$path = array_merge( $prefix, array( (int) $i ) );
			$visitor( $block, $path );
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $path, $visitor );
			}
		}
	}

	/**
	 * Resolve input into a parsed block tree.
	 *
	 * @param array<string,mixed> $input Input payload.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function resolve_blocks( array $input ): ?array {
		if ( isset( $input['post_id'] ) ) {
			$id   = absint( $input['post_id'] );
			$post = $id > 0 ? get_post( $id ) : null;
			if ( ! $post instanceof \WP_Post ) {
				return null;
			}
			return parse_blocks( (string) $post->post_content );
		}
		if ( isset( $input['blocks'] ) && is_array( $input['blocks'] ) ) {
			return $input['blocks'];
		}
		if ( isset( $input['content'] ) && is_string( $input['content'] ) ) {
			return parse_blocks( $input['content'] );
		}
		return null;
	}

	/**
	 * Failure envelope.
	 *
	 * @param string $message Failure message.
	 * @return array<string,mixed>
	 */
	private function failure( string $message ): array {
		return array(
			'success'           => false,
			'outline'           => array(),
			'links'             => (object) array( 'internal' => 0, 'external' => 0, 'total' => 0 ),
			'media'             => (object) array( 'images' => 0, 'videos' => 0, 'embeds' => 0 ),
			'block_types'       => (object) array(),
			'word_count'        => 0,
			'read_time_minutes' => 0,
			'message'           => $message,
		);
	}
}
