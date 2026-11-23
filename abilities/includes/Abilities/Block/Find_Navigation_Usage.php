<?php
/**
 * Feature 072 — find every object referencing a wp_navigation.
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
 * Given a wp_navigation ID, return every template, template-part, and
 * (optionally) post that references it via core/navigation { ref }.
 */
class Find_Navigation_Usage extends Ability_Definition {

	private const RESULT_CAP = 500;

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/find-navigation-usage',
			'args' => array(
				'label'               => __( 'Find Navigation Usage', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return every template + template-part (and optionally posts) whose block tree contains a core/navigation reference to the given wp_navigation ID.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'            => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'include_posts' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'post_types'    => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array( 'post', 'page' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'references' => array( 'type' => 'array' ),
						'total'      => array( 'type' => 'integer' ),
						'truncated'  => array( 'type' => 'boolean' ),
						'message'    => array( 'type' => 'string' ),
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
		$id            = absint( $input['id'] ?? 0 );
		$include_posts = (bool) ( $input['include_posts'] ?? false );
		$post_types    = is_array( $input['post_types'] ?? null ) ? array_map( 'sanitize_key', $input['post_types'] ) : array( 'post', 'page' );

		if ( $id <= 0 ) {
			return $this->failure( __( 'id is required.', 'acrossai-abilities-manager' ) );
		}

		$matcher = static function ( array $block ) use ( $id ): bool {
			return 'core/navigation' === ( $block['blockName'] ?? '' )
				&& (int) ( $block['attrs']['ref'] ?? 0 ) === $id;
		};

		$references = array();
		$truncated  = false;

		self::walk_templates( 'wp_template', $matcher, $references, $truncated );
		self::walk_templates( 'wp_template_part', $matcher, $references, $truncated );
		if ( $include_posts && ! $truncated ) {
			self::walk_posts( $post_types, $matcher, $references, $truncated );
		}

		return array(
			'success'    => true,
			'references' => $references,
			'total'      => count( $references ),
			'truncated'  => $truncated,
			/* translators: %d: reference count */
			'message'    => sprintf( __( 'Found %d referencing object(s).', 'acrossai-abilities-manager' ), count( $references ) ),
		);
	}

	/**
	 * Walk get_block_templates() output and collect matches.
	 *
	 * @param string                                     $type       Post type.
	 * @param callable                                   $matcher    Match closure.
	 * @param array<int,array<string,mixed>>             $references Accumulator.
	 * @param bool                                       $truncated  Truncation flag.
	 * @return void
	 */
	private static function walk_templates( string $type, callable $matcher, array &$references, bool &$truncated ): void {
		if ( $truncated || ! function_exists( 'get_block_templates' ) ) {
			return;
		}
		$templates = (array) get_block_templates( array(), $type );
		foreach ( $templates as $t ) {
			if ( count( $references ) >= self::RESULT_CAP ) {
				$truncated = true;
				return;
			}
			$count = self::count_matches( parse_blocks( (string) ( $t->content ?? '' ) ), $matcher );
			if ( $count > 0 ) {
				$references[] = array(
					'referencing_object_type'  => $type,
					'referencing_object_id'    => sanitize_text_field( (string) ( $t->id ?? '' ) ),
					'referencing_object_title' => sanitize_text_field( (string) ( is_object( $t->title ?? null ) ? ( $t->title->rendered ?? '' ) : ( $t->title ?? '' ) ) ),
					'occurrences'              => $count,
				);
			}
		}
	}

	/**
	 * Walk posts of the given types and collect matches.
	 *
	 * @param string[]                                   $post_types Post types.
	 * @param callable                                   $matcher    Match closure.
	 * @param array<int,array<string,mixed>>             $references Accumulator.
	 * @param bool                                       $truncated  Truncation flag.
	 * @return void
	 */
	private static function walk_posts( array $post_types, callable $matcher, array &$references, bool &$truncated ): void {
		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
			)
		);
		foreach ( (array) $posts as $p ) {
			if ( count( $references ) >= self::RESULT_CAP ) {
				$truncated = true;
				return;
			}
			$count = self::count_matches( parse_blocks( (string) $p->post_content ), $matcher );
			if ( $count > 0 ) {
				$references[] = array(
					'referencing_object_type'  => (string) $p->post_type,
					'referencing_object_id'    => (string) $p->ID,
					'referencing_object_title' => sanitize_text_field( (string) $p->post_title ),
					'occurrences'              => $count,
				);
			}
		}
	}

	/**
	 * Recursively count matches in a parsed block tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks  Parsed blocks.
	 * @param callable                       $matcher Match closure.
	 * @return int
	 */
	private static function count_matches( array $blocks, callable $matcher ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( $matcher( $block ) ) {
				++$count;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$count += self::count_matches( $block['innerBlocks'], $matcher );
			}
		}
		return $count;
	}

	/**
	 * Consistent failure envelope.
	 *
	 * @param string $message Failure message.
	 * @return array<string,mixed>
	 */
	private function failure( string $message ): array {
		return array(
			'success'    => false,
			'references' => array(),
			'total'      => 0,
			'truncated'  => false,
			'message'    => $message,
		);
	}
}
