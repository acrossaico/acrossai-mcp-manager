<?php
/**
 * Set_Override ability (Feature 061).
 *
 * Slug: acrossai/conflict-test-set-override
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.21
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Set the effective active state of one plugin without modifying wp_options.active_plugins.
 *
 * On `active=true`, performs a WP_SANDBOX_SCRAPING probe of the plugin file
 * before the override is recorded (mirrors WP core's plugin_sandbox_scrape).
 * If PHP fatals during the include, the override is never written and the
 * site's runtime behaviour is unchanged.
 *
 * Cascade default = true. When cascade is on:
 *  - `active=false` writes override entries for every transitive dependent
 *    (plugin B declaring plugin A as a required plugin gets a false override
 *    when A is deactivated).
 *  - `active=true` writes override entries for every transitive requirement.
 */
class Set_Override extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'acrossai/conflict-test-set-override',
			'args' => array(
				'label'               => __( 'Set Conflict-Test Override', 'acrossai-abilities-manager' ),
				'description'         => __( 'Set the effective active state of one plugin without modifying the WordPress active_plugins option row. Optionally cascade through the Requires Plugins header.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-debugging',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin_file' => array(
							'type'        => 'string',
							'description' => __( 'Plugin file identifier, e.g. hello-dolly/hello.php.', 'acrossai-abilities-manager' ),
						),
						'active'      => array(
							'type'        => 'boolean',
							'description' => __( 'Desired effective state — true to force effectively-active, false to force effectively-inactive.', 'acrossai-abilities-manager' ),
						),
						'cascade'     => array(
							'type'        => 'boolean',
							'description' => __( 'When true (default), walks the Requires Plugins graph and writes override entries for transitive dependents (on deactivate) or requirements (on activate). When false, only the named plugin is touched.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'plugin_file', 'active' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'         => array( 'type' => 'boolean' ),
						'plugin_file'     => array( 'type' => 'string' ),
						'recorded'        => array( 'type' => 'boolean' ),
						'reason'          => array( 'type' => 'string' ),
						'cascade_applied' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'plugin_file' => array( 'type' => 'string' ),
									'active'      => array( 'type' => 'boolean' ),
									'reason'      => array( 'type' => 'string' ),
								),
								'required'             => array( 'plugin_file', 'active', 'reason' ),
								'additionalProperties' => false,
							),
						),
						'message'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'conflict-testing',
						'sub_group_label' => __( 'Conflict Testing', 'acrossai-abilities-manager' ),
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
	 * @param array $input Input payload — plugin_file + active + optional cascade.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$plugin_file = sanitize_text_field( $input['plugin_file'] ?? '' );
		$active      = (bool) ( $input['active'] ?? false );
		$cascade     = isset( $input['cascade'] ) ? (bool) $input['cascade'] : true;

		if ( '' === $plugin_file ) {
			return array(
				'success' => false,
				'reason'  => 'plugin-file-missing',
				'message' => __( 'plugin_file input is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = get_plugins();
		if ( ! isset( $installed[ $plugin_file ] ) ) {
			return array(
				'success'     => false,
				'plugin_file' => $plugin_file,
				'reason'      => 'plugin-not-installed',
				'message'     => sprintf(
					/* translators: %s: plugin file identifier */
					__( 'The plugin file "%s" is not installed on this site.', 'acrossai-abilities-manager' ),
					$plugin_file
				),
			);
		}

		// FR-022 sandbox-scrape probe on active=true writes. Uncaught by design —
		// a fatal in the include kills the request before the override is written,
		// so the site's runtime behaviour is unchanged.
		if ( $active ) {
			$scrape_result = self::sandbox_scrape( $plugin_file );
			if ( false === $scrape_result ) {
				return array(
					'success'     => false,
					'plugin_file' => $plugin_file,
					'reason'      => 'plugin-fatal-on-load',
					'message'     => sprintf(
						/* translators: %s: plugin file identifier */
						__( 'Including "%s" triggered a PHP error; override refused.', 'acrossai-abilities-manager' ),
						$plugin_file
					),
				);
			}
		}

		$store    = Overrides_Store::instance();
		$write    = $store->write_one( $plugin_file, $active );

		$cascade_applied = array();
		if ( $cascade ) {
			$targets = $active
				? Dependency_Resolver::instance()->requirements_of( $plugin_file )
				: Dependency_Resolver::instance()->dependents_of( $plugin_file );

			foreach ( $targets as $target ) {
				if ( $active ) {
					$scrape = self::sandbox_scrape( $target );
					if ( false === $scrape ) {
						$cascade_applied[] = array(
							'plugin_file' => $target,
							'active'      => $active,
							'reason'      => 'plugin-fatal-on-load',
						);
						continue;
					}
				}

				$target_write = $store->write_one( $target, $active );
				$cascade_applied[] = array(
					'plugin_file' => $target,
					'active'      => $active,
					'reason'      => $target_write['reason'],
				);
			}
		}

		return array(
			'success'         => true,
			'plugin_file'     => $plugin_file,
			'recorded'        => (bool) $write['recorded'],
			'reason'          => (string) $write['reason'],
			'cascade_applied' => $cascade_applied,
		);
	}

	/**
	 * Include the plugin file behind the WP_SANDBOX_SCRAPING marker.
	 *
	 * Mirrors WP core's plugin_sandbox_scrape() (wp-admin/includes/plugin.php).
	 * A PHP fatal in the includee terminates the request before the caller
	 * can write anything — which is the whole point of the probe. Catchable
	 * Throwables (ParseError, Error in PHP 7+ from certain code paths) surface
	 * as a false return so the caller can classify the plugin under
	 * `plugin-fatal-on-load`.
	 *
	 * Shared between Set_Override and Bulk_Set_Overrides — public static so
	 * the bulk path can call it without instantiating Set_Override.
	 *
	 * @param string $plugin_file Plugin file identifier.
	 * @return bool True on success, false if a catchable Throwable was raised.
	 */
	public static function sandbox_scrape( string $plugin_file ): bool {
		if ( ! defined( 'WP_SANDBOX_SCRAPING' ) ) {
			define( 'WP_SANDBOX_SCRAPING', true );
		}

		if ( function_exists( 'wp_register_plugin_realpath' ) ) {
			wp_register_plugin_realpath( WP_PLUGIN_DIR . '/' . $plugin_file );
		}

		try {
			include_once WP_PLUGIN_DIR . '/' . $plugin_file;
			return true;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- classify as fatal-on-load; specific error surfaced to caller via the reason field
			return false;
		}
	}
}
