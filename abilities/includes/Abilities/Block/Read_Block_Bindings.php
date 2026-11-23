<?php
/**
 * Feature 073 — read metadata.bindings on a block.
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
 * Return metadata.bindings map for a block at a given path plus resolved
 * values via WP_Block_Bindings_Registry (WP 6.5+).
 */
class Read_Block_Bindings extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/read-block-bindings',
			'args' => array(
				'label'               => __( 'Read Block Bindings', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the metadata.bindings map for the block at the given path plus resolved values via WP_Block_Bindings_Registry (WP 6.5+).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'path'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer', 'minimum' => 0 ),
						),
					),
					'required'             => array( 'post_id', 'path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'post_id'  => array( 'type' => 'integer' ),
						'path'     => array( 'type' => 'array' ),
						'bindings' => array( 'type' => 'object' ),
						'resolved' => array( 'type' => 'object' ),
						'message'  => array( 'type' => 'string' ),
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
		if ( ! class_exists( '\WP_Block_Bindings_Registry' ) ) {
			return $this->failure( 0, __( 'Block bindings require WordPress 6.5 or later.', 'acrossai-abilities-manager' ) );
		}

		$post_id = absint( $input['post_id'] ?? 0 );
		$path    = self::sanitize_path( $input['path'] ?? array() );

		if ( array() === $path ) {
			return $this->failure( $post_id, __( 'path must be a non-empty integer array.', 'acrossai-abilities-manager' ) );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return $this->failure( $post_id, (string) $blocks->get_error_message() );
		}

		$target = Block_Tree::get_at_path( $blocks, $path );
		if ( ! is_array( $target ) ) {
			return $this->failure( $post_id, __( 'path does not resolve.', 'acrossai-abilities-manager' ) );
		}

		$bindings = is_array( $target['attrs']['metadata']['bindings'] ?? null ) ? $target['attrs']['metadata']['bindings'] : array();

		$resolved = array();
		if ( array() !== $bindings ) {
			$registry = \WP_Block_Bindings_Registry::get_instance();
			foreach ( $bindings as $attr => $spec ) {
				$source_name = (string) ( $spec['source'] ?? '' );
				$args        = is_array( $spec['args'] ?? null ) ? $spec['args'] : array();
				$source      = $registry->get_registered( $source_name );
				if ( null === $source ) {
					$resolved[ $attr ] = null;
					continue;
				}
				$resolved[ $attr ] = $source->get_value( $args, null, (string) $attr );
			}
		}

		return array(
			'success'  => true,
			'post_id'  => $post_id,
			'path'     => $path,
			'bindings' => (object) $bindings,
			'resolved' => (object) $resolved,
			'message'  => __( 'Bindings returned.', 'acrossai-abilities-manager' ),
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
			'success'  => false,
			'post_id'  => $post_id,
			'path'     => array(),
			'bindings' => (object) array(),
			'resolved' => (object) array(),
			'message'  => $message,
		);
	}
}
