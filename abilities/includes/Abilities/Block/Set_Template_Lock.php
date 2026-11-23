<?php
/**
 * Feature 073 — set templateLock on a container block.
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
 * Set `templateLock` on a container block. Enum: all|insert|contentOnly|false.
 */
class Set_Template_Lock extends Ability_Definition {

	private const MODES     = array( 'all', 'insert', 'contentOnly', 'false' );
	private const CONTAINERS = array(
		'core/group',
		'core/columns',
		'core/column',
		'core/cover',
		'core/query',
		'core/post-template',
		'core/buttons',
		'core/social-links',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/set-template-lock',
			'args' => array(
				'label'               => __( 'Set Template Lock', 'acrossai-abilities-manager' ),
				'description'         => __( 'Set the `templateLock` attribute on a container block. Modes: `all` (freeze), `insert` (allow content edits, block structural), `contentOnly` (WP 6.5+), `false` (no lock). `clear: true` removes the attribute.', 'acrossai-abilities-manager' ),
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
						'mode'    => array(
							'type' => 'string',
							'enum' => self::MODES,
						),
						'clear'   => array(
							'type'    => 'boolean',
							'default' => false,
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
						'before'  => array( 'type' => 'string' ),
						'after'   => array( 'type' => 'string' ),
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
		$post_id = absint( $input['post_id'] ?? 0 );
		$path    = self::sanitize_path( $input['path'] ?? array() );
		$clear   = (bool) ( $input['clear'] ?? false );
		$mode    = isset( $input['mode'] ) ? (string) $input['mode'] : '';

		if ( array() === $path ) {
			return $this->failure( $post_id, __( 'path must be a non-empty integer array.', 'acrossai-abilities-manager' ) );
		}
		if ( ! $clear && ! in_array( $mode, self::MODES, true ) ) {
			return $this->failure( $post_id, __( 'mode is required unless clear: true.', 'acrossai-abilities-manager' ) );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return $this->failure( $post_id, (string) $blocks->get_error_message() );
		}

		$target = Block_Tree::get_at_path( $blocks, $path );
		if ( ! is_array( $target ) ) {
			return $this->failure( $post_id, __( 'path does not resolve.', 'acrossai-abilities-manager' ) );
		}

		$name = (string) ( $target['blockName'] ?? '' );
		if ( ! in_array( $name, self::CONTAINERS, true ) && ! apply_filters( 'acrossai_block_is_container', false, $name ) ) {
			return $this->failure( $post_id, __( 'Target block is not a container.', 'acrossai-abilities-manager' ) );
		}

		$before = (string) ( $target['attrs']['templateLock'] ?? '' );

		if ( $clear ) {
			unset( $target['attrs']['templateLock'] );
			$after = '';
		} else {
			$target['attrs']['templateLock'] = ( 'false' === $mode ) ? false : $mode;
			$after                            = $mode;
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
			'before'  => $before,
			'after'   => $after,
			'message' => __( 'templateLock updated.', 'acrossai-abilities-manager' ),
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
			'before'  => '',
			'after'   => '',
			'message' => $message,
		);
	}
}
