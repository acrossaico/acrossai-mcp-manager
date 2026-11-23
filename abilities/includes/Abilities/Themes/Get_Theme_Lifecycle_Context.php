<?php
/**
 * Feature 055 — per-theme lifecycle-context envelope.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Themes
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Themes;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Lifecycle_Event_Log;

defined( 'ABSPATH' ) || exit;

/**
 * Return the lifecycle-context envelope for a single theme: header, active
 * state, parent/child relationship, autoupdate enrolment, update availability,
 * and last activated / deactivated / updated timestamps.
 */
class Get_Theme_Lifecycle_Context extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'themes/get-theme-lifecycle-context',
			'args' => array(
				'label'               => __( 'Get Theme Lifecycle Context', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the lifecycle-context envelope for a single theme (by stylesheet slug): header (name, version, author), active state, parent (if child theme), autoupdate enrolment, update availability, and last activated / deactivated / updated timestamps.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-themes',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'stylesheet' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
					),
					'required'             => array( 'stylesheet' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'             => array( 'type' => 'boolean' ),
						'stylesheet'          => array( 'type' => 'string' ),
						'header'              => array( 'type' => 'object' ),
						'is_active'           => array( 'type' => 'boolean' ),
						'is_child'            => array( 'type' => 'boolean' ),
						'parent'              => array( 'type' => 'string' ),
						'is_block_theme'      => array( 'type' => 'boolean' ),
						'autoupdate_enabled'  => array( 'type' => 'boolean' ),
						'update_available'    => array( 'type' => 'boolean' ),
						'last_activated_at'   => array( 'type' => 'integer' ),
						'last_deactivated_at' => array( 'type' => 'integer' ),
						'last_updated_at'     => array( 'type' => 'integer' ),
						'message'             => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'updates',
						'sub_group'       => 'lifecycle',
						'sub_group_label' => __( 'Lifecycle', 'acrossai-abilities-manager' ),
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
		$stylesheet = sanitize_key( (string) ( $input['stylesheet'] ?? '' ) );
		if ( '' === $stylesheet ) {
			return array(
				'success' => false,
				'message' => __( 'A theme stylesheet slug is required (e.g. "twentytwentyfive").', 'acrossai-abilities-manager' ),
			);
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme instanceof \WP_Theme || ! $theme->exists() ) {
			return array(
				'success' => false,
				/* translators: %s: theme stylesheet slug */
				'message' => sprintf( __( 'Theme "%s" not installed.', 'acrossai-abilities-manager' ), $stylesheet ),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/update.php';
		$updates          = (array) get_theme_updates();
		$update_available = isset( $updates[ $stylesheet ] );

		$autoupdates        = (array) get_site_option( 'auto_update_themes', array() );
		$autoupdate_enabled = in_array( $stylesheet, $autoupdates, true );

		$parent      = $theme->parent();
		$parent_slug = $parent instanceof \WP_Theme ? (string) $parent->get_stylesheet() : '';

		$summary = Lifecycle_Event_Log::get_summary( 'theme', $stylesheet );

		// Feature 055 hardening — cap free-form header fields.
		$name_max = 200;
		$auth_max = 200;
		$name     = wp_strip_all_tags( (string) $theme->get( 'Name' ) );
		$author   = wp_strip_all_tags( (string) $theme->get( 'Author' ) );
		if ( strlen( $name ) > $name_max ) {
			$name = rtrim( substr( $name, 0, $name_max ) ) . '...';
		}
		if ( strlen( $author ) > $auth_max ) {
			$author = rtrim( substr( $author, 0, $auth_max ) ) . '...';
		}

		return array(
			'success'             => true,
			'stylesheet'          => $stylesheet,
			'header'              => (object) array(
				'name'     => $name,
				'version'  => sanitize_text_field( (string) $theme->get( 'Version' ) ),
				'author'   => $author,
				'template' => sanitize_text_field( (string) $theme->get_template() ),
			),
			'is_active'           => $stylesheet === (string) get_stylesheet(),
			'is_child'            => '' !== $parent_slug,
			'parent'              => $parent_slug,
			'is_block_theme'      => method_exists( $theme, 'is_block_theme' ) ? (bool) $theme->is_block_theme() : false,
			'autoupdate_enabled'  => (bool) $autoupdate_enabled,
			'update_available'    => (bool) $update_available,
			'last_activated_at'   => (int) $summary['last_activated_at'],
			'last_deactivated_at' => (int) $summary['last_deactivated_at'],
			'last_updated_at'     => (int) $summary['last_updated_at'],
			/* translators: %s: theme slug */
			'message'             => sprintf( __( 'Lifecycle for theme %s.', 'acrossai-abilities-manager' ), $stylesheet ),
		);
	}
}
