<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteHealth
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteHealth;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Debug_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the Site Health Info report — the same data shown on Tools → Site Health → Info
 * (server, database, WordPress core, themes, plugins, media, filesystem, constants, paths & sizes).
 * Optionally narrows the output to one or more sections (e.g. `wp-server`, `wp-database`).
 */
class Get_Site_Health_Info extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'site-health/get-site-health-info',
			'args' => array(
				'label'               => __( 'Get Site Health Info', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the Site Health Info report (WP_Debug_Data::debug_data()) — server, database, WordPress, themes, plugins, media, filesystem, constants and paths/sizes. Optionally filter to specific sections.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-site-health',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'sections' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Optional list of section keys to return (e.g. wp-core, wp-server, wp-database, wp-active-theme, wp-plugins-active). Empty/omitted returns all sections.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'sections' => array(
							'type'        => 'object',
							'description' => __( 'Map of section key → { label, description, fields[] }.', 'acrossai-abilities-manager' ),
						),
						'error'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'read',
						'sub_group_label' => __( 'Read Site Health', 'acrossai-abilities-manager' ),
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
	 * Load the admin function libraries `WP_Debug_Data` calls internally.
	 *
	 * ## Why the class file alone is not enough
	 *
	 * Requiring `class-wp-debug-data.php` makes the CLASS exist. It does not
	 * make the functions that class calls exist, and `debug_data()` reaches
	 * for four admin libraries by name:
	 *
	 *   - `update.php`  — `get_core_updates()`, `get_plugin_updates()`,
	 *                     `get_theme_updates()`, the auto-update helpers
	 *   - `plugin.php`  — `get_plugins()`, `get_mu_plugins()`, `get_dropins()`
	 *   - `file.php`    — `get_home_path()`
	 *   - `misc.php`    — `got_url_rewrite()`
	 *
	 * In a real wp-admin request `wp-admin/includes/admin.php` has already
	 * loaded all of them, which is why this ability worked when it was
	 * written. Over REST — which is how an MCP client reaches it — none of
	 * them are loaded, and the ability returned
	 * `Call to undefined function get_core_updates()` for every request,
	 * whichever sections were asked for.
	 *
	 * ## Why all four, and not just the one that failed
	 *
	 * Which function is missing FIRST depends on what else the site happens
	 * to have loaded. Observed while diagnosing this: `get_plugins()` already
	 * existed, because an unrelated plugin had pulled in `plugin.php` — so
	 * the error named `get_core_updates()`. On a site without that plugin the
	 * same bug would have surfaced as a different undefined function, and a
	 * fix that only added `update.php` would have looked correct here and
	 * still failed there.
	 *
	 * Matches the pattern the upgrader abilities already use — every one of
	 * them loads its own dependencies rather than assuming an admin request.
	 *
	 * @since 0.0.45
	 * @return void
	 */
	private static function load_admin_dependencies(): void {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'got_url_rewrite' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		self::load_admin_dependencies();

		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}

		$requested = array();
		if ( isset( $input['sections'] ) && is_array( $input['sections'] ) ) {
			$requested = array_values(
				array_filter(
					array_map( 'sanitize_key', $input['sections'] ),
					static function ( $key ): bool {
						return '' !== $key;
					}
				)
			);
		}

		try {
			$info = WP_Debug_Data::debug_data();
		} catch ( \Throwable $e ) {
			return array(
				'success'  => false,
				'sections' => array(),
				'error'    => $e->getMessage(),
			);
		}

		if ( ! empty( $requested ) ) {
			$info = array_intersect_key( $info, array_flip( $requested ) );
		}

		$sections = array();
		foreach ( $info as $section_key => $section ) {
			$sections[ $section_key ] = array(
				'label'       => isset( $section['label'] ) ? (string) $section['label'] : (string) $section_key,
				'description' => isset( $section['description'] ) ? (string) $section['description'] : '',
				'fields'      => $this->flatten_fields( $section['fields'] ?? array() ),
			);
		}

		return array(
			'success'  => true,
			'sections' => $sections,
		);
	}

	/**
	 * Flatten the `fields` block produced by WP_Debug_Data into a predictable list.
	 * Each field becomes { key, label, value, debug } so consumers don't have to
	 * worry about whether `debug` was set or whether `value` was a nested array.
	 *
	 * @param array $fields Raw fields keyed by field id.
	 * @return array<int, array{key:string,label:string,value:mixed,debug:mixed}>
	 */
	private function flatten_fields( array $fields ): array {
		$out = array();
		foreach ( $fields as $field_key => $field ) {
			if ( ! is_array( $field ) ) {
				$out[] = array(
					'key'   => (string) $field_key,
					'label' => (string) $field_key,
					'value' => $field,
					'debug' => null,
				);
				continue;
			}
			$out[] = array(
				'key'   => (string) $field_key,
				'label' => isset( $field['label'] ) ? (string) $field['label'] : (string) $field_key,
				'value' => $field['value'] ?? null,
				'debug' => $field['debug'] ?? null,
			);
		}
		return $out;
	}
}
