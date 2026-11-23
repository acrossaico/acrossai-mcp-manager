<?php
/**
 * Feature 073 — set or clear metadata.bindings on a block.
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
 * Merge or replace metadata.bindings on the target block. Validates every
 * source against WP_Block_Bindings_Registry.
 */
class Set_Block_Bindings extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/set-block-bindings',
			'args' => array(
				'label'               => __( 'Set Block Bindings', 'acrossai-abilities-manager' ),
				'description'         => __( 'Merge or replace metadata.bindings on the target block. Validates sources against WP_Block_Bindings_Registry (WP 6.5+). `clear` is a list of attribute names to delete.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'path'     => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer', 'minimum' => 0 ),
						),
						'bindings' => array( 'type' => 'object' ),
						'mode'     => array(
							'type'    => 'string',
							'enum'    => array( 'merge', 'replace' ),
							'default' => 'merge',
						),
						'clear'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'required'             => array( 'post_id', 'path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'post_id' => array( 'type' => 'integer' ),
						'path'    => array( 'type' => 'array' ),
						'before'  => array( 'type' => 'object' ),
						'after'   => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'bindings',
						'sub_group_label' => __( 'Bindings', 'acrossai-abilities-manager' ),
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
		if ( ! class_exists( '\WP_Block_Bindings_Registry' ) ) {
			return $this->failure( 0, __( 'Block bindings require WordPress 6.5 or later.', 'acrossai-abilities-manager' ) );
		}

		$post_id  = absint( $input['post_id'] ?? 0 );
		$path     = self::sanitize_path( $input['path'] ?? array() );
		$mode     = in_array( (string) ( $input['mode'] ?? '' ), array( 'merge', 'replace' ), true ) ? (string) $input['mode'] : 'merge';
		$bindings = is_array( $input['bindings'] ?? null ) ? $input['bindings'] : array();
		$clear    = is_array( $input['clear'] ?? null ) ? array_map( 'strval', $input['clear'] ) : array();

		if ( array() === $path ) {
			return $this->failure( $post_id, __( 'path must be a non-empty integer array.', 'acrossai-abilities-manager' ) );
		}
		if ( array() === $bindings && array() === $clear ) {
			return $this->failure( $post_id, __( 'Provide `bindings` and/or `clear`.', 'acrossai-abilities-manager' ) );
		}

		$registry = \WP_Block_Bindings_Registry::get_instance();
		foreach ( $bindings as $attr => $spec ) {
			$source = (string) ( is_array( $spec ) ? ( $spec['source'] ?? '' ) : '' );
			if ( '' === $source || null === $registry->get_registered( $source ) ) {
				return $this->failure( $post_id, sprintf( 'Unknown binding source "%s".', $source ) );
			}
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return $this->failure( $post_id, (string) $blocks->get_error_message() );
		}

		$target = Block_Tree::get_at_path( $blocks, $path );
		if ( ! is_array( $target ) ) {
			return $this->failure( $post_id, __( 'path does not resolve.', 'acrossai-abilities-manager' ) );
		}

		$current = is_array( $target['attrs']['metadata']['bindings'] ?? null ) ? $target['attrs']['metadata']['bindings'] : array();
		$before  = $current;

		$next = 'replace' === $mode ? $bindings : array_merge( $current, $bindings );
		foreach ( $clear as $attr ) {
			unset( $next[ $attr ] );
		}

		if ( ! isset( $target['attrs']['metadata'] ) || ! is_array( $target['attrs']['metadata'] ) ) {
			$target['attrs']['metadata'] = array();
		}
		if ( array() === $next ) {
			unset( $target['attrs']['metadata']['bindings'] );
			if ( array() === $target['attrs']['metadata'] ) {
				unset( $target['attrs']['metadata'] );
			}
		} else {
			$target['attrs']['metadata']['bindings'] = $next;
		}

		Block_Tree::replace_at_path( $blocks, $path, $target );

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
			'path'    => $path,
			'before'  => (object) $before,
			'after'   => (object) $next,
			'message' => __( 'Bindings updated.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Coerce raw path to int[].
	 *
	 * @param mixed $raw Raw path input.
	 * @return int[]
	 */
	private static function sanitize_path( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( is_int( $item ) && $item >= 0 ) {
				$out[] = $item;
			} elseif ( is_string( $item ) && ctype_digit( $item ) ) {
				$out[] = (int) $item;
			} else {
				return array();
			}
		}
		return $out;
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
			'path'    => array(),
			'before'  => (object) array(),
			'after'   => (object) array(),
			'message' => $message,
		);
	}
}
