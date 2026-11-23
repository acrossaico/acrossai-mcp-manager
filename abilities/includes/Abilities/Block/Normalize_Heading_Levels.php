<?php
/**
 * Feature 072 — normalize heading hierarchy in a post.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Tree;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrite heading levels so no heading skips more than one level down. Demotes
 * headings above top_level down to top_level.
 */
class Normalize_Heading_Levels extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/normalize-heading-levels',
			'args' => array(
				'label'               => __( 'Normalize Heading Levels', 'acrossai-abilities-manager' ),
				'description'         => __( 'Rewrite heading levels so no heading skips more than one level. Demotes headings above top_level (default 2) down to top_level. preserve_h1 leaves H1 untouched (default true).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'      => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'top_level'    => array(
							'type'    => 'integer',
							'minimum' => 2,
							'maximum' => 4,
							'default' => 2,
						),
						'preserve_h1'  => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
						'changes' => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'mutation',
						'sub_group_label' => __( 'Mutation', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
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
	/**
	 * Where a caller should look before running this.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'content/inspect-post-builder',
				'reason' => __( 'Run first on an existing post. A page built with Elementor or another page builder keeps its layout outside post_content, so this write can report success, change nothing a visitor sees, and be reverted the next time the page is saved in the builder.', 'acrossai-abilities-manager' ),
			),
		);
	}

	public function execute( array $input = array() ): array {
		$post_id     = absint( $input['post_id'] ?? 0 );
		$top_level   = max( 2, min( 4, (int) ( $input['top_level'] ?? 2 ) ) );
		$preserve_h1 = (bool) ( $input['preserve_h1'] ?? true );

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return $this->failure( $post_id, (string) $blocks->get_error_message() );
		}

		$changes  = array();
		$last_lvl = $top_level - 1;
		self::walk( $blocks, $top_level, $preserve_h1, $last_lvl, $changes, array() );

		if ( array() === $changes ) {
			return array(
				'success' => true,
				'post_id' => $post_id,
				'changes' => array(),
				'message' => __( 'Heading hierarchy already normalized; no changes.', 'acrossai-abilities-manager' ),
			);
		}

		$saved = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => serialize_blocks( $blocks ),
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $this->failure( $post_id, (string) $saved->get_error_message() );
		}

		return array(
			'success' => true,
			'post_id' => $post_id,
			'changes' => $changes,
			/* translators: %d: heading count changed */
			'message' => sprintf( __( 'Normalized %d heading(s).', 'acrossai-abilities-manager' ), count( $changes ) ),
		);
	}

	/**
	 * Recursively walk the tree and normalize heading levels.
	 *
	 * @param array<int,array<string,mixed>> $blocks       Tree (by ref).
	 * @param int                            $top_level    Top allowed level.
	 * @param bool                           $preserve_h1  Whether to preserve H1.
	 * @param int                            $last_seen    Last seen valid heading level (by ref).
	 * @param array<int,array<string,mixed>> $changes      Change log (by ref).
	 * @param int[]                          $prefix       Path prefix.
	 * @return void
	 */
	private static function walk( array &$blocks, int $top_level, bool $preserve_h1, int &$last_seen, array &$changes, array $prefix ): void {
		foreach ( $blocks as $i => &$block ) {
			$path = array_merge( $prefix, array( $i ) );
			if ( is_array( $block ) && 'core/heading' === ( $block['blockName'] ?? '' ) ) {
				$current = (int) ( $block['attrs']['level'] ?? 2 );
				if ( 1 === $current && $preserve_h1 ) {
					// Skip H1 — do not mutate.
				} else {
					$target = $current;
					if ( $current < $top_level ) {
						$target = $top_level;
					} elseif ( $current > $last_seen + 1 ) {
						$target = $last_seen + 1;
					}
					if ( $target !== $current ) {
						$block['attrs']['level'] = $target;
						$changes[]              = array(
							'path'   => $path,
							'before' => $current,
							'after'  => $target,
						);
					}
					$last_seen = $target;
				}
			}
			if ( is_array( $block ) && ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $top_level, $preserve_h1, $last_seen, $changes, $path );
			}
		}
	}

	/**
	 * Failure envelope.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Failure message.
	 * @return array<string,mixed>
	 */
	private function failure( int $post_id, string $message ): array {
		return array(
			'success' => false,
			'post_id' => $post_id,
			'changes' => array(),
			'message' => $message,
		);
	}
}
