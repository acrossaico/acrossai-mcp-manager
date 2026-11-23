<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Options
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Options;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Search wp_options.option_name with a LIKE pattern. The query is bound and
 * the user-supplied term goes through esc_like() before being wrapped, so the
 * caller can pass plain text without worrying about wildcards.
 */
class Search_Options extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'options/search-options',
			'args' => array(
				'label'               => __( 'Search Options', 'acrossai-abilities-manager' ),
				'description'         => __( 'Search wp_options.option_name with a LIKE pattern. Pass exact_match=true to require an exact match.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-options',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'term'           => array( 'type' => 'string' ),
						'exact_match'    => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'per_page'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 200,
							'default' => 50,
						),
						'include_values' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'term' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'options' => array( 'type' => 'array' ),
						'total'   => array( 'type' => 'integer' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'configuration',
						'sub_group'       => 'search',
						'sub_group_label' => __( 'Search', 'acrossai-abilities-manager' ),
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
		global $wpdb;

		$term = sanitize_text_field( (string) ( $input['term'] ?? '' ) );
		if ( '' === $term ) {
			return array(
				'success' => false,
				'message' => __( 'term is required.', 'acrossai-abilities-manager' ),
			);
		}

		$per_page = min( 200, max( 1, (int) ( $input['per_page'] ?? 50 ) ) );
		$pattern  = ! empty( $input['exact_match'] )
			? $wpdb->esc_like( $term )
			: '%' . $wpdb->esc_like( $term ) . '%';

		$columns = ! empty( $input['include_values'] )
			? 'option_id, option_name, autoload, LEFT(option_value, 1024) AS option_value, LENGTH(option_value) AS value_bytes'
			: 'option_id, option_name, autoload, LENGTH(option_value) AS value_bytes';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $columns is a hard-coded SELECT list chosen above.
				"SELECT {$columns} FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
				$pattern,
				$per_page
			),
			ARRAY_A
		);

		return array(
			'success' => true,
			'options' => is_array( $rows ) ? $rows : array(),
			'total'   => is_array( $rows ) ? count( $rows ) : 0,
		);
	}
}
