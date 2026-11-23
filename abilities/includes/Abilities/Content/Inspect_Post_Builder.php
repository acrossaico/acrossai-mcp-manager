<?php
/**
 * Feature 108 — report how a post's content is stored before anything writes to it.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Content;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Post_Builder_Detector;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Tell the caller which editor or page builder owns a post's content.
 *
 * Lives in the `content` group rather than beside the Elementor abilities on purpose. The Elementor
 * suite is gated on `class_exists( '\Elementor\Plugin' )`, so it disappears when Elementor is
 * deactivated — precisely when a post still flagged as Elementor-built is most likely to be
 * mishandled. This has to be reachable whatever is or is not installed, and it has to be in the
 * same toolset as the writers it is warning about.
 */
class Inspect_Post_Builder extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/inspect-post-builder',
			'args' => array(
				'label'               => __( 'Inspect Post Builder', 'acrossai-abilities-manager' ),
				'description'         => __( 'Report which editor or page builder owns a post\'s content — Elementor, the block editor, the classic editor, another page builder, or empty — and whether writing post_content will actually take effect. Run this BEFORE updating the content of any post, page or custom post type. A page builder keeps its layout outside post_content, so an update there can report success while changing nothing a visitor sees and being silently reverted on the next save in the builder; two builders store shortcodes in post_content instead, where a rewrite destroys the layout. Returns the storage location, a three-way verdict on post_content writes, the evidence behind the answer, and what to use instead.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'  => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The post, page or custom post type record to inspect.', 'acrossai-abilities-manager' ),
						),
						'post_ids' => array(
							'type'        => 'array',
							'items'       => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'maxItems'    => 100,
							'description' => __( 'Inspect several at once, for scoping a bulk edit. Use instead of post_id, not with it. Maximum 100.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'posts'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'count'   => array( 'type' => 'integer' ),
						'missing' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'posts',
						'sub_group_label' => __( 'Posts', 'acrossai-abilities-manager' ),
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
	 * Where a caller usually goes next.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'content/list-posts',
				'reason' => __( 'To find every post owned by one builder before a bulk edit, pass its ownership meta key — for example meta_key=_elementor_edit_mode for Elementor.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'blocks/outline-post-blocks',
				'reason' => __( 'When this reports the block editor, outline first and edit one block rather than rewriting the whole body.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Run.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$ids = array();

		if ( ! empty( $input['post_ids'] ) && is_array( $input['post_ids'] ) ) {
			$ids = array_map( 'absint', $input['post_ids'] );
		} elseif ( ! empty( $input['post_id'] ) ) {
			$ids = array( absint( $input['post_id'] ) );
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( array() === $ids ) {
			return array(
				'success' => false,
				'message' => __( 'Supply post_id, or post_ids for several at once.', 'acrossai-abilities-manager' ),
			);
		}

		$rows    = array();
		$missing = array();

		foreach ( $ids as $id ) {
			$row = Post_Builder_Detector::detect( (int) $id );

			if ( null === $row ) {
				$missing[] = (int) $id;
				continue;
			}

			$rows[] = $row;
		}

		return array(
			'success' => true,
			'posts'   => $rows,
			'count'   => count( $rows ),
			'missing' => $missing,
			'message' => $this->summarise( $rows, $missing ),
		);
	}

	/**
	 * A one-line summary that leads with the thing the caller must not miss.
	 *
	 * @since  0.0.34
	 * @param  array<int, array<string, mixed>> $rows    Detected rows.
	 * @param  array<int, int>                  $missing IDs that do not exist.
	 * @return string
	 */
	private function summarise( array $rows, array $missing ): string {
		$unsafe = array();

		foreach ( $rows as $row ) {
			if ( Post_Builder_Detector::WRITES_APPLY !== $row['post_content_writes'] ) {
				$unsafe[] = $row['post_id'] . ' (' . $row['builder_label'] . ')';
			}
		}

		if ( array() !== $unsafe ) {
			return sprintf(
				/* translators: %s: comma-separated post IDs with their builder */
				__( 'Do not write post_content on: %s. Read each row\'s guidance before editing.', 'acrossai-abilities-manager' ),
				implode( ', ', $unsafe )
			);
		}

		$message = sprintf(
			/* translators: %d: number of posts */
			_n( '%d post inspected; post_content writes apply normally.', '%d posts inspected; post_content writes apply normally.', count( $rows ), 'acrossai-abilities-manager' ),
			count( $rows )
		);

		if ( array() !== $missing ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated post IDs */
				__( 'Not found: %s.', 'acrossai-abilities-manager' ),
				implode( ', ', $missing )
			);
		}

		return $message;
	}
}
