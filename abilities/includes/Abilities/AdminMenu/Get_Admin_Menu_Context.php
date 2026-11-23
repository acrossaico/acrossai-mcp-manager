<?php
/**
 * Feature 055 — snapshot of the current admin-menu context.
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
 * Return the current WP-admin screen context: base, id, post_type,
 * top-level menu slug (if resolvable), and the current caller's role set.
 */
class Get_Admin_Menu_Context extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'admin-menu/get-admin-menu-context',
			'args' => array(
				'label'               => __( 'Get Admin Menu Context', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the current WP-admin screen context: base, id, post_type, top-level menu slug (if resolvable via $_GET[page]), current caller\'s roles, and admin URL. Reads get_current_screen() when available; falls back to sanitized $_GET data otherwise.', 'acrossai-abilities-manager' ),
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
						'success'        => array( 'type' => 'boolean' ),
						'screen_id'      => array( 'type' => 'string' ),
						'screen_base'    => array( 'type' => 'string' ),
						'post_type'      => array( 'type' => 'string' ),
						'page_slug'      => array( 'type' => 'string' ),
						'admin_base_url' => array( 'type' => 'string' ),
						'user_roles'     => array( 'type' => 'array' ),
						'message'        => array( 'type' => 'string' ),
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		unset( $input );

		$screen_id   = '';
		$screen_base = '';
		$post_type   = '';
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen instanceof \WP_Screen ) {
				$screen_id   = (string) $screen->id;
				$screen_base = (string) $screen->base;
				$post_type   = (string) $screen->post_type;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page_slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		$user = wp_get_current_user();
		$roles = $user instanceof \WP_User ? array_values( array_map( 'strval', (array) $user->roles ) ) : array();

		return array(
			'success'        => true,
			'screen_id'      => $screen_id,
			'screen_base'    => $screen_base,
			'post_type'      => $post_type,
			'page_slug'      => $page_slug,
			'admin_base_url' => admin_url(),
			'user_roles'     => $roles,
			'message'        => __( 'Admin menu context snapshot.', 'acrossai-abilities-manager' ),
		);
	}
}
