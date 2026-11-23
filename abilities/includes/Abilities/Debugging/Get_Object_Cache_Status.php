<?php
/**
 * Get_Object_Cache_Status ability (Feature 129).
 *
 * Slug: debugging/get-object-cache-status
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\QueryMonitor\Query_Monitor_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Report the object-cache situation Query Monitor sees: whether a persistent
 * object cache is in use, which backend extension provides it, whether an opcode
 * cache is present, and the in-request hit ratio. Read-only.
 */
class Get_Object_Cache_Status extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'debugging/get-object-cache-status',
			'args' => array(
				'label'               => __( 'Get Object Cache Status (Query Monitor)', 'acrossai-abilities-manager' ),
				'description'         => __( 'Reports whether a persistent object cache is active and which backend extension provides it (Redis, Memcached, APCu and so on), whether an opcode cache is present, and the object-cache hit percentage for the request. Read-only. Requires the Query Monitor plugin to be active.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-debugging',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'active'                   => array( 'type' => 'boolean' ),
						'message'                  => array( 'type' => 'string' ),
						'has_object_cache'         => array( 'type' => 'boolean' ),
						'object_cache_extensions'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'has_opcode_cache'         => array( 'type' => 'boolean' ),
						'opcode_cache_extensions'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'cache_hit_percentage'     => array( 'type' => array( 'number', 'null' ) ),
					),
					'required'             => array( 'active' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'query-monitor',
						'sub_group'       => 'query-monitor',
						'sub_group_label' => __( 'Query Monitor', 'acrossai-abilities-manager' ),
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
	 * Execute — read Query Monitor's cache collector.
	 *
	 * @param array $input Ignored — this ability accepts no input.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		if ( ! Query_Monitor_Guard::is_active() ) {
			return Query_Monitor_Guard::not_active();
		}

		$data = Query_Monitor_Guard::collector_data( 'cache' );
		if ( null === $data ) {
			return array(
				'active'  => true,
				'message' => __( 'Query Monitor is active but its cache data was unavailable this request.', 'acrossai-abilities-manager' ),
			);
		}

		$object_exts = isset( $data->object_cache_extensions ) && is_array( $data->object_cache_extensions )
			? array_values( array_map( 'strval', array_keys( $data->object_cache_extensions ) ) )
			: array();
		$opcode_exts = isset( $data->opcode_cache_extensions ) && is_array( $data->opcode_cache_extensions )
			? array_values( array_map( 'strval', array_keys( $data->opcode_cache_extensions ) ) )
			: array();

		return array(
			'active'                  => true,
			'has_object_cache'        => ! empty( $data->has_object_cache ),
			'object_cache_extensions' => $object_exts,
			'has_opcode_cache'        => ! empty( $data->has_opcode_cache ),
			'opcode_cache_extensions' => $opcode_exts,
			'cache_hit_percentage'    => isset( $data->cache_hit_percentage ) ? (float) $data->cache_hit_percentage : null,
		);
	}
}
