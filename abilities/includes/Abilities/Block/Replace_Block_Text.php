<?php
/**
 * Feature 072 — search-and-replace across text-bearing leaf blocks.
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
 * Search-and-replace inside text-bearing leaf blocks (core/paragraph,
 * core/heading, core/list-item, core/quote, core/button).
 */
class Replace_Block_Text extends Ability_Definition {

	private const TEXT_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list-item',
		'core/quote',
		'core/button',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/replace-block-text',
			'args' => array(
				'label'               => __( 'Replace Block Text', 'acrossai-abilities-manager' ),
				'description'         => __( 'Search-and-replace text inside every text-bearing leaf block. Supports plain-string or regex mode. Optional block_names filter narrows scope.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'search'        => array( 'type' => 'string' ),
						'replace'       => array( 'type' => 'string' ),
						'mode'          => array(
							'type'    => 'string',
							'enum'    => array( 'plain', 'regex' ),
							'default' => 'plain',
						),
						'block_names'   => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
						'case_sensitive' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'post_id', 'search', 'replace' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'post_id'      => array( 'type' => 'integer' ),
						'replacements' => array( 'type' => 'integer' ),
						'affected'     => array( 'type' => 'integer' ),
						'message'      => array( 'type' => 'string' ),
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
						'idempotent'  => false,
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
		$post_id        = absint( $input['post_id'] ?? 0 );
		$search         = (string) ( $input['search'] ?? '' );
		$replace        = (string) ( $input['replace'] ?? '' );
		$mode           = in_array( (string) ( $input['mode'] ?? '' ), array( 'plain', 'regex' ), true ) ? (string) $input['mode'] : 'plain';
		$block_names    = is_array( $input['block_names'] ?? null ) ? array_map( 'strval', $input['block_names'] ) : array();
		$case_sensitive = (bool) ( $input['case_sensitive'] ?? true );
		$scope          = array() === $block_names ? self::TEXT_BLOCKS : $block_names;

		if ( '' === $search ) {
			return $this->failure( $post_id, __( 'search must be non-empty.', 'acrossai-abilities-manager' ) );
		}

		$blocks = Block_Tree::parse_post_blocks( $post_id, 'edit' );
		if ( is_wp_error( $blocks ) ) {
			return $this->failure( $post_id, (string) $blocks->get_error_message() );
		}

		$replacements = 0;
		$affected     = 0;

		self::walk( $blocks, $scope, $mode, $search, $replace, $case_sensitive, $replacements, $affected );

		if ( 0 === $affected ) {
			return array(
				'success'      => true,
				'post_id'      => $post_id,
				'replacements' => 0,
				'affected'     => 0,
				'message'      => __( 'No matches; post not modified.', 'acrossai-abilities-manager' ),
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
			'success'      => true,
			'post_id'      => $post_id,
			'replacements' => $replacements,
			'affected'     => $affected,
			/* translators: 1: replacement count, 2: block count */
			'message'      => sprintf( __( 'Made %1$d replacement(s) across %2$d block(s).', 'acrossai-abilities-manager' ), $replacements, $affected ),
		);
	}

	/**
	 * Recursively walk the tree, replacing text in matching leaves.
	 *
	 * @param array<int,array<string,mixed>> $blocks         Tree (by ref).
	 * @param string[]                       $scope          Allowed block names.
	 * @param string                         $mode           plain|regex.
	 * @param string                         $search         Pattern.
	 * @param string                         $replace        Replacement.
	 * @param bool                           $case_sensitive Case sensitivity.
	 * @param int                            $replacements   Total replacement counter (by ref).
	 * @param int                            $affected       Affected block counter (by ref).
	 * @return void
	 */
	private static function walk( array &$blocks, array $scope, string $mode, string $search, string $replace, bool $case_sensitive, int &$replacements, int &$affected ): void {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( in_array( (string) ( $block['blockName'] ?? '' ), $scope, true ) ) {
				$html    = (string) ( $block['innerHTML'] ?? '' );
				$updated = self::replace_in( $html, $mode, $search, $replace, $case_sensitive, $count );
				if ( $count > 0 && $updated !== $html ) {
					$block['innerHTML']    = $updated;
					$block['innerContent'] = array( $updated );
					$replacements         += $count;
					++$affected;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $scope, $mode, $search, $replace, $case_sensitive, $replacements, $affected );
			}
		}
	}

	/**
	 * Run one replacement pass and return the new string + match count.
	 *
	 * @param string $subject        Subject string.
	 * @param string $mode           plain|regex.
	 * @param string $search         Pattern.
	 * @param string $replace        Replacement.
	 * @param bool   $case_sensitive Case sensitivity.
	 * @param int    $count          Match count (by ref).
	 * @return string
	 */
	private static function replace_in( string $subject, string $mode, string $search, string $replace, bool $case_sensitive, ?int &$count ): string {
		$count = 0;
		if ( 'regex' === $mode ) {
			$flags   = $case_sensitive ? '' : 'i';
			$pattern = '/' . str_replace( '/', '\\/', $search ) . '/' . $flags;
			$out     = @preg_replace( $pattern, $replace, $subject, -1, $count );
			return null === $out ? $subject : (string) $out;
		}
		if ( $case_sensitive ) {
			return (string) str_replace( $search, $replace, $subject, $count );
		}
		return (string) str_ireplace( $search, $replace, $subject, $count );
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
			'success'      => false,
			'post_id'      => $post_id,
			'replacements' => 0,
			'affected'     => 0,
			'message'      => $message,
		);
	}
}
