<?php
/**
 * Feature 072 — find every object referencing a template-part slug.
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
 * Given a template-part slug (+ optional theme), return every template +
 * template-part whose block tree contains a matching core/template-part.
 */
class Find_Template_Part_Usage extends Ability_Definition {

	private const RESULT_CAP = 500;

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/find-template-part-usage',
			'args' => array(
				'label'               => __( 'Find Template Part Usage', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return every template + template-part whose block tree contains a core/template-part reference matching the given slug (and optional theme).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'  => array( 'type' => 'string' ),
						'theme' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
					'required'             => array( 'slug' ),
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
		$slug  = sanitize_text_field( (string) ( $input['slug'] ?? '' ) );
		$theme = sanitize_text_field( (string) ( $input['theme'] ?? '' ) );

		if ( '' === $slug ) {
			return $this->failure( __( 'slug is required.', 'acrossai-abilities-manager' ) );
		}

		$matcher = static function ( array $block ) use ( $slug, $theme ): bool {
			if ( 'core/template-part' !== ( $block['blockName'] ?? '' ) ) {
				return false;
			}
			if ( ( $block['attrs']['slug'] ?? '' ) !== $slug ) {
				return false;
			}
			if ( '' !== $theme && ( $block['attrs']['theme'] ?? '' ) !== $theme ) {
				return false;
			}
			return true;
		};

		$references = array();
		$truncated  = false;
		self::walk_templates( 'wp_template', $matcher, $references, $truncated );
		self::walk_templates( 'wp_template_part', $matcher, $references, $truncated );

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
