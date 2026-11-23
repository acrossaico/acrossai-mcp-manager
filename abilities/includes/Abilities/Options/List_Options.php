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
 * List rows from wp_options. Returns option_name + autoload by default; pass
 * include_values=true to include option_value (may be very large for serialized
 * blobs — capped at 1 KiB per value).
 */
class List_Options extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'options/list-options',
			'args' => array(
				'label'               => __( 'List Options', 'acrossai-abilities-manager' ),
				'description'         => __( 'List wp_options rows. Defaults to names + autoload only; pass include_values=true to embed truncated option values.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-options',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'autoload_only'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'page'           => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 500,
							'default' => 100,
						),
						'include_values' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'options' => array( 'type' => 'array' ),
						'total'   => array( 'type' => 'integer' ),
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
	 * Feature 095 follow-up — hint that known-name reads should skip the
	 * enumeration entirely.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'options/get-option',
				'reason' => __( 'If you already know the option name, a targeted get-option read is far cheaper than enumerating every option in wp_options. List is for discovery; get is for retrieval.', 'acrossai-abilities-manager' ),
				'saves'  => __( '~15-30x fewer tokens for known-name reads', 'acrossai-abilities-manager' ),
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

		$per_page = min( 500, max( 1, (int) ( $input['per_page'] ?? 100 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$columns = ! empty( $input['include_values'] )
			? 'option_id, option_name, autoload, LEFT(option_value, 1024) AS option_value, LENGTH(option_value) AS value_bytes'
			: 'option_id, option_name, autoload, LENGTH(option_value) AS value_bytes';

		if ( ! empty( $input['autoload_only'] ) ) {
			$total = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on','auto-off')"
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $columns is a hard-coded SELECT list chosen above.
					"SELECT {$columns} FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on','auto-off') ORDER BY option_id ASC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $columns is a hard-coded SELECT list chosen above.
					"SELECT {$columns} FROM {$wpdb->options} ORDER BY option_id ASC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return array(
			'success' => true,
			'options' => is_array( $rows ) ? $rows : array(),
			'total'   => $total,
		);
	}
}
