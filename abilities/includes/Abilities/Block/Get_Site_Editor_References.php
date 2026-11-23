<?php
/**
 * Feature 070 — cross-reference index for site-editor objects.
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
 * Given a target site-editor object (template-part slug, navigation ID, or
 * reusable-block ID), return every template + template-part that references it.
 */
class Get_Site_Editor_References extends Ability_Definition {

	private const OBJECT_TYPES = array( 'template_part', 'navigation', 'reusable_block' );

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/get-site-editor-references',
			'args' => array(
				'label'               => __( 'Get Site Editor References', 'acrossai-abilities-manager' ),
				'description'         => __( 'Given a target site-editor object (template-part slug, navigation ID, or reusable-block ID), return every template + template-part that references it. Answers "what breaks if I remove X?".', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'object_type' => array(
							'type'        => 'string',
							'enum'        => self::OBJECT_TYPES,
							'description' => __( 'Target object type: template_part, navigation, or reusable_block.', 'acrossai-abilities-manager' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Template-part slug (required when object_type is template_part).', 'acrossai-abilities-manager' ),
						),
						'theme'       => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Optional theme identifier for template_part matching.', 'acrossai-abilities-manager' ),
						),
						'id'          => array(
							'type'        => 'integer',
							'default'     => 0,
							'description' => __( 'Post ID (required when object_type is navigation or reusable_block).', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'object_type' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'references' => array( 'type' => 'array' ),
						'total'      => array( 'type' => 'integer' ),
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
		$object_type = isset( $input['object_type'] ) ? sanitize_key( (string) $input['object_type'] ) : '';
		if ( ! in_array( $object_type, self::OBJECT_TYPES, true ) ) {
			return $this->failure( __( 'Invalid or missing object_type.', 'acrossai-abilities-manager' ) );
		}

		$target_slug  = sanitize_text_field( (string) ( $input['slug'] ?? '' ) );
		$target_theme = sanitize_text_field( (string) ( $input['theme'] ?? '' ) );
		$target_id    = (int) ( $input['id'] ?? 0 );

		if ( 'template_part' === $object_type && '' === $target_slug ) {
			return $this->failure( __( 'slug is required when object_type is template_part.', 'acrossai-abilities-manager' ) );
		}
		if ( in_array( $object_type, array( 'navigation', 'reusable_block' ), true ) && $target_id <= 0 ) {
			return $this->failure( __( 'id is required when object_type is navigation or reusable_block.', 'acrossai-abilities-manager' ) );
		}

		$matcher = $this->build_matcher( $object_type, $target_slug, $target_theme, $target_id );

		$templates      = function_exists( 'get_block_templates' ) ? (array) get_block_templates( array(), 'wp_template' ) : array();
		$template_parts = function_exists( 'get_block_templates' ) ? (array) get_block_templates( array(), 'wp_template_part' ) : array();

		$references = array();

		foreach ( $templates as $template ) {
			$count = $this->count_matches( parse_blocks( (string) ( $template->content ?? '' ) ), $matcher );
			if ( $count > 0 ) {
				$references[] = array(
					'referencing_object_type'  => 'wp_template',
					'referencing_object_id'    => sanitize_text_field( (string) ( $template->id ?? '' ) ),
					'referencing_object_title' => sanitize_text_field( (string) ( is_object( $template->title ?? null ) ? ( $template->title->rendered ?? '' ) : ( $template->title ?? '' ) ) ),
					'occurrences'              => $count,
				);
			}
		}

		foreach ( $template_parts as $part ) {
			$count = $this->count_matches( parse_blocks( (string) ( $part->content ?? '' ) ), $matcher );
			if ( $count > 0 ) {
				$references[] = array(
					'referencing_object_type'  => 'wp_template_part',
					'referencing_object_id'    => sanitize_text_field( (string) ( $part->id ?? '' ) ),
					'referencing_object_title' => sanitize_text_field( (string) ( is_object( $part->title ?? null ) ? ( $part->title->rendered ?? '' ) : ( $part->title ?? '' ) ) ),
					'occurrences'              => $count,
				);
			}
		}

		return array(
			'success'    => true,
			'references' => $references,
			'total'      => count( $references ),
			/* translators: %d: reference count */
			'message'    => sprintf( __( 'Found %d referencing object(s).', 'acrossai-abilities-manager' ), count( $references ) ),
		);
	}

	/**
	 * Build a match closure for the requested target.
	 *
	 * @param string $object_type Target object type.
	 * @param string $slug        Template-part slug.
	 * @param string $theme       Template-part theme.
	 * @param int    $id          Navigation or reusable-block ID.
	 * @return callable
	 */
	private function build_matcher( string $object_type, string $slug, string $theme, int $id ): callable {
		switch ( $object_type ) {
			case 'template_part':
				return static function ( array $block ) use ( $slug, $theme ): bool {
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
			case 'navigation':
				return static function ( array $block ) use ( $id ): bool {
					return 'core/navigation' === ( $block['blockName'] ?? '' )
						&& (int) ( $block['attrs']['ref'] ?? 0 ) === $id;
				};
			case 'reusable_block':
			default:
				return static function ( array $block ) use ( $id ): bool {
					return 'core/block' === ( $block['blockName'] ?? '' )
						&& (int) ( $block['attrs']['ref'] ?? 0 ) === $id;
				};
		}
	}

	/**
	 * Recursively count matches in a parsed block tree.
	 *
	 * @param array<int,array<string,mixed>> $blocks  Parsed blocks.
	 * @param callable                       $matcher Match closure.
	 * @return int
	 */
	private function count_matches( array $blocks, callable $matcher ): int {
		$count = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( $matcher( $block ) ) {
				++$count;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$count += $this->count_matches( $block['innerBlocks'], $matcher );
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
			'message'    => $message,
		);
	}
}
