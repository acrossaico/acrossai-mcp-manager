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
 * Flush_Transients ability class (absorbed).
 */
class Flush_Transients extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cache/flush-transients',
			'args' => array(
				'label'               => __( 'Flush Transients', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deletes WordPress transients. Use scope "expired" (default) to remove only expired transients, or "all" to remove every transient regardless of expiry.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cache',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array( 'scope' => 'expired' ),
					'properties'           => array(
						'scope' => array(
							'type'        => 'string',
							'enum'        => array( 'expired', 'all' ),
							'default'     => 'expired',
							'description' => __( '"expired" deletes only expired transients; "all" deletes every transient.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'scope'   => array( 'type' => 'string' ),
						'deleted' => array(
							'type'        => 'integer',
							'description' => __( 'Number of rows deleted (available for scope "all" only; -1 when not applicable).', 'acrossai-abilities-manager' ),
						),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success', 'scope', 'deleted', 'message' ),
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
						'destructive' => true,
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
	 * Clearing transients is not what a visitor sees. On a site running LiteSpeed the page cache is a
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
		global $wpdb;

		$scope = isset( $input['scope'] ) && 'all' === $input['scope'] ? 'all' : 'expired';

		if ( 'all' === $scope ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = (int) $wpdb->query(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE '\_transient\_%'
				    OR option_name LIKE '\_site\_transient\_%'"
			);
			// phpcs:enable

			return array(
				'success' => true,
				'scope'   => 'all',
				'deleted' => $deleted,
				'message' => sprintf(
					/* translators: %d: number of rows deleted */
					_n( '%d transient deleted.', '%d transients deleted.', $deleted, 'acrossai-abilities-manager' ),
					$deleted
				),
			);
		}

		delete_expired_transients( true );

		return array(
			'success' => true,
			'scope'   => 'expired',
			'deleted' => -1,
			'message' => __( 'Expired transients deleted.', 'acrossai-abilities-manager' ),
		);
	}
}
