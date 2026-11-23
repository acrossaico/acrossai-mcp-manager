<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Cache
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Cache;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Flush_Object_Cache ability class (absorbed).
 */
class Flush_Object_Cache extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cache/flush-object-cache',
			'args' => array(
				'label'               => __( 'Flush Object Cache', 'acrossai-abilities-manager' ),
				'description'         => __( 'Flushes the entire WordPress object cache via wp_cache_flush(). Useful after data changes when stale cached values may be served.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cache',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'message' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'cache',
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
	 * @param array $input Ability input payload.
	 * @return array
	 */
	/**
	 * Feature 128 — send page-cache questions to the plugin that owns the page cache.
	 *
	 * Clearing the object cache is not what a visitor sees. On a site running LiteSpeed the page cache is a
	 * separate layer, and an operator who asked to "clear the cache" and got this almost certainly
	 * meant that one. `only_if_registered` keeps the hint out of the payload on sites without
	 * LiteSpeed, where the suggested ability does not exist.
	 *
	 * @since  0.0.36
	 * @return array<int,array<string,string|bool>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'                => 'litespeed/purge-cache',
				'reason'              => __( 'For page cache purges (what visitors see), use LiteSpeed; this ability only clears transients / object cache.', 'acrossai-abilities-manager' ),
				'only_if_registered'  => true,
			),
		);
	}

	public function execute( array $input = array() ): array {
		$ok = wp_cache_flush();
		return array(
			'success' => (bool) $ok,
			'message' => $ok
				? __( 'Object cache flushed.', 'acrossai-abilities-manager' )
				: __( 'wp_cache_flush() returned false; the active object cache may not support flushing.', 'acrossai-abilities-manager' ),
		);
	}
}
