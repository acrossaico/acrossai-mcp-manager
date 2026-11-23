<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Info;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every block registered with WP_Block_Type_Registry.
 *
 * Scenarios:
 *  - 1: list all
 *  - 2: filter by category (text, media, design, widgets, theme, embed)
 *  - 3: filter by keyword (name + title + description + keywords)
 *  - 4: filter by source (core, plugin, theme, custom)
 *  - 5: combine any of the above
 *  - 15: WP_Block_Type_Registry not loaded → clear error
 *  - 16: registry is empty → clear empty-result message
 *  - 17: invalid filter values rejected with the allowed list
 */
class List_Blocks extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/list-blocks',
			'args' => array(
				'label'               => __( 'List Blocks', 'acrossai-abilities-manager' ),
				'description'         => __( 'Lists every block registered with WP_Block_Type_Registry. Filter by category, keyword, source, or any combination. Returns name, title, description, category, icon, keywords, and source for each block, sorted by name.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'category' => array(
							'type'        => 'string',
							'enum'        => array_merge( array( '' ), Block_Info::CATEGORIES ),
							'default'     => '',
							'description' => __( 'Restrict to one block category (text, media, design, widgets, theme, embed).', 'acrossai-abilities-manager' ),
						),
						'keyword'  => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Search across name, title, description, and keywords.', 'acrossai-abilities-manager' ),
						),
						'source'   => array(
							'type'        => 'string',
							'enum'        => array_merge( array( '' ), Block_Info::SOURCES ),
							'default'     => '',
							'description' => __( 'Restrict to one block source (core, plugin, theme, custom).', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'blocks'  => array( 'type' => 'array' ),
						'total'   => array( 'type' => 'integer' ),
						'filters' => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'block-info',
						'sub_group_label' => __( 'Block Info', 'acrossai-abilities-manager' ),
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
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		// Scenario 15 — registry not loaded yet.
		if ( ! Block_Info::registry_available() ) {
			return array(
				'success' => false,
				'message' => __( 'WP_Block_Type_Registry is not available yet. Call this ability after the WordPress init hook has fired.', 'acrossai-abilities-manager' ),
			);
		}

		$category = sanitize_text_field( $input['category'] ?? '' );
		$keyword  = sanitize_text_field( $input['keyword'] ?? '' );
		$source   = sanitize_text_field( $input['source'] ?? '' );

		// Scenario 17 — validate filter values.
		if ( '' !== $category && ! Block_Info::valid_category( $category ) ) {
			return array(
				'success' => false,
				/* translators: %s: list of valid categories */
				'message' => sprintf( __( 'Invalid category. Allowed: %s.', 'acrossai-abilities-manager' ), implode( ', ', Block_Info::CATEGORIES ) ),
			);
		}
		if ( '' !== $source && ! Block_Info::valid_source( $source ) ) {
			return array(
				'success' => false,
				/* translators: %s: list of valid sources */
				'message' => sprintf( __( 'Invalid source. Allowed: %s.', 'acrossai-abilities-manager' ), implode( ', ', Block_Info::SOURCES ) ),
			);
		}

		$registered = Block_Info::all_blocks();

		// Scenario 16 — registry has no blocks at all.
		if ( empty( $registered ) ) {
			return array(
				'success' => true,
				'blocks'  => array(),
				'total'   => 0,
				'filters' => array(
					'category' => $category,
					'keyword'  => $keyword,
					'source'   => $source,
				),
				'message' => __( 'No blocks are registered. Check that WordPress core blocks have loaded — this usually means the init hook has not fired yet.', 'acrossai-abilities-manager' ),
			);
		}

		$blocks = array();
		foreach ( $registered as $block ) {
			if ( ! $block instanceof \WP_Block_Type ) {
				continue;
			}
			if ( '' !== $category && (string) $block->category !== $category ) {
				continue;
			}
			if ( '' !== $source && Block_Info::classify_source( $block ) !== $source ) {
				continue;
			}
			if ( '' !== $keyword && ! Block_Info::matches_keyword( $block, $keyword ) ) {
				continue;
			}
			$blocks[] = Block_Info::summary( $block );
		}

		usort(
			$blocks,
			static function ( array $a, array $b ): int {
				return strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
			}
		);

		// Scenario 5 (combined filters with no matches) gets a friendly message.
		$message = '';
		if ( empty( $blocks ) ) {
			$message = __( 'No blocks match the requested filters.', 'acrossai-abilities-manager' );
		}

		return array(
			'success' => true,
			'blocks'  => $blocks,
			'total'   => count( $blocks ),
			'filters' => array(
				'category' => $category,
				'keyword'  => $keyword,
				'source'   => $source,
			),
			'message' => $message,
		);
	}
}
