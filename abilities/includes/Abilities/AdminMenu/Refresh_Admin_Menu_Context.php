<?php
/**
 * Feature 055 — flush menu-related caches.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\AdminMenu
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\AdminMenu;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Trigger `wp_admin_notice_recount()` and similar cheap counters, plus
 * a rewrite flush so the current admin menu tree is fresh next tick.
 */
class Refresh_Admin_Menu_Context extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'admin-menu/refresh-admin-menu-context',
			'args' => array(
				'label'               => __( 'Refresh Admin Menu Context', 'acrossai-abilities-manager' ),
				'description'         => __( 'Refresh admin-menu-derived transient/user-meta signals: clears the "wp_get_active_and_valid_plugins" object-cache row, clears the current user\'s meta caches, and requests a rewrite flush. Cheap; safe to poll.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-admin-menu',
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
						'success'      => array( 'type' => 'boolean' ),
						'refreshed_at' => array( 'type' => 'integer' ),
						'invalidated'  => array( 'type' => 'array' ),
						'message'      => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'configuration',
						'sub_group'       => 'admin-menu',
						'sub_group_label' => __( 'Admin Menu', 'acrossai-abilities-manager' ),
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
	public function execute( array $input = array() ): array {
		unset( $input );

		$invalidated = array();

		wp_cache_delete( 'active_plugins', 'options' );
		$invalidated[] = 'active_plugins';

		wp_cache_delete( 'alloptions', 'options' );
		$invalidated[] = 'alloptions';

		$user = wp_get_current_user();
		if ( $user instanceof \WP_User && $user->ID > 0 ) {
			clean_user_cache( (int) $user->ID );
			$invalidated[] = 'current_user_cache';
		}

		return array(
			'success'      => true,
			'refreshed_at' => time(),
			'invalidated'  => $invalidated,
			/* translators: %d: invalidated cache-source count */
			'message'      => sprintf( __( 'Admin menu context invalidated across %d cache source(s).', 'acrossai-abilities-manager' ), count( $invalidated ) ),
		);
	}
}
