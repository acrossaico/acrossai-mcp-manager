<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Plugins
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Plugins;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Plugin_Helpers;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Asset_Installer;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Lookup;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Versions;

defined( 'ABSPATH' ) || exit;

/**
 * Install_Plugin ability class (absorbed).
 */
class Install_Plugin extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'plugins/install-plugin',
			'args' => array(
				'label'               => __( 'Install Plugin', 'acrossai-abilities-manager' ),
				'description'         => __( 'Install a plugin from the WordPress.org plugin directory by name or slug. Installs the latest release unless "version" names a specific published one. For a plugin that is already installed, use versions/rollback-plugin to change its version.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-plugins',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin'   => array(
							'type'        => 'string',
							'description' => __( 'The plugin name or slug to install from WordPress.org.', 'acrossai-abilities-manager' ),
						),
						'slug'     => array(
							'type'        => 'string',
							'description' => __( 'Alias for "plugin". If both are provided, "plugin" wins.', 'acrossai-abilities-manager' ),
						),
						'version'  => array(
							'type'        => 'string',
							'description' => __( 'Install this exact published version instead of the latest. Matched exactly — 4.3 will not install 4.3.1. versions/list-asset-versions returns the valid values.', 'acrossai-abilities-manager' ),
						),
						'activate' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to activate the plugin after installing.', 'acrossai-abilities-manager' ),
							'default'     => false,
						),
					),
					'anyOf'                => array(
						array( 'required' => array( 'plugin' ) ),
						array( 'required' => array( 'slug' ) ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'           => array( 'type' => 'boolean' ),
						'message'           => array( 'type' => 'string' ),
						'plugin_name'       => array( 'type' => 'string' ),
						'plugin_slug'       => array( 'type' => 'string' ),
						'activated'         => array( 'type' => 'boolean' ),
						'version'           => array( 'type' => 'string' ),
						'already_installed' => array( 'type' => 'boolean' ),
						'active'            => array( 'type' => 'boolean' ),
					),
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
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * WP Rollback covers what this ability cannot: a chosen version.
	 *
	 * Feature 136. This ability always installs the latest release — it takes no version parameter.
	 *
	 * `plugin_provides_abilities` is false and that is worth stating rather than omitting: WP
	 * Rollback registers no abilities of its own — verified by activating it and watching the
	 * registry stay at 388 — so installing it does nothing for an agent until this plugin's own
	 * WP Rollback suite is present. A caller told only "install WP Rollback" would otherwise expect
	 * new tools to appear.
	 *
	 * @since  0.0.45
	 * @return array<int,array<string,scalar>>
	 */
	protected function suggested_plugins(): array {
		return array(
			array(
				'slug'                          => 'wp-rollback',
				'name'                          => 'WP Rollback',
				'reason'                        => __( 'This installs the latest release only. To install or return a plugin to a specific earlier version, WP Rollback is the plugin that does it.', 'acrossai-abilities-manager' ),
				'covers'                        => __( 'Re-versioning an installed plugin or theme to any release WordPress.org publishes. Not core, and not assets hosted elsewhere.', 'acrossai-abilities-manager' ),
				'plugin_provides_abilities'     => false,
				'acrossai_provides_integration' => false,
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
		$blocked = File_Mods_Guard::blocked_response( 'install' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$raw_plugin = ! empty( $input['plugin'] ) ? $input['plugin'] : ( $input['slug'] ?? '' );

		if ( empty( $raw_plugin ) ) {
			return array(
				'success' => false,
				'message' => __( 'No plugin specified. Pass "plugin" (or its alias "slug").', 'acrossai-abilities-manager' ),
			);
		}

		$plugin_slug = sanitize_text_field( $raw_plugin );
		$activate    = ! empty( $input['activate'] );
		$version     = isset( $input['version'] ) && is_scalar( $input['version'] ) ? trim( (string) $input['version'] ) : '';

		$plugin_slug = sanitize_title( $plugin_slug );

		if ( '' === $plugin_slug ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid plugin slug.', 'acrossai-abilities-manager' ),
			);
		}

		// Check if plugin is already installed.
		$resolved = Plugin_Helpers::resolve_plugin( $plugin_slug );
		if ( null !== $resolved['plugin_file'] && $resolved['certainty'] >= 8.0 ) {
			$plugin_data = Plugin_Helpers::get_plugin_by_slug( $resolved['plugin_file'] );
			$status      = $plugin_data && $plugin_data['active'] ? __( 'active', 'acrossai-abilities-manager' ) : __( 'inactive', 'acrossai-abilities-manager' );

			// A version was asked for and the plugin is already here. Installing does not overwrite,
			// so saying only "already installed" would leave a caller who asked for 4.3.1 believing
			// they got it. Name the ability that does change a version.
			$already = '' === $version
				/* translators: 1: plugin name, 2: plugin status */
				? sprintf( __( 'Plugin "%1$s" is already installed (%2$s).', 'acrossai-abilities-manager' ), $resolved['plugin_name'], $status )
				: sprintf(
					/* translators: 1: plugin name, 2: installed version, 3: requested version */
					__( 'Plugin "%1$s" is already installed at version %2$s and was not changed. To put it on version %3$s, use versions/rollback-plugin, which replaces the files and keeps a restore point.', 'acrossai-abilities-manager' ),
					$resolved['plugin_name'],
					( $plugin_data && isset( $plugin_data['version'] ) ) ? (string) $plugin_data['version'] : __( 'unknown', 'acrossai-abilities-manager' ),
					$version
				);

			return array(
				'success'           => true,
				'message'           => $already,
				'already_installed' => true,
				'plugin_name'       => $resolved['plugin_name'],
				'plugin_slug'       => $resolved['plugin_file'],
				'active'            => $plugin_data && $plugin_data['active'],
			);
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $plugin_slug,
				'fields' => array(
					'short_description' => true,
					'sections'          => false,
					'requires'          => true,
					'tested'            => true,
					'rating'            => false,
					'downloaded'        => false,
					'download_link'     => true,
					'last_updated'      => false,
					'homepage'          => false,
					'tags'              => false,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return array(
				'success' => false,
				/* translators: 1: plugin slug, 2: error message */
				'message' => sprintf( __( 'Could not find plugin "%1$s" on WordPress.org: %2$s', 'acrossai-abilities-manager' ), $plugin_slug, $api->get_error_message() ),
			);
		}

		if ( empty( $api->download_link ) ) {
			return array(
				'success' => false,
				/* translators: %s: plugin name */
				'message' => sprintf( __( 'No download link available for "%s".', 'acrossai-abilities-manager' ), $api->name ?? $plugin_slug ),
			);
		}

		$package = (string) $api->download_link;

		if ( '' !== $version ) {
			// download_link always points at the latest release, so a named version has to come
			// from the published version map instead. Exact match only — a caller asking for 4.3
			// must not silently receive 4.3.1.
			$versions = Asset_Lookup::published_versions( 'plugin', $plugin_slug );

			if ( is_wp_error( $versions ) ) {
				return array(
					'success' => false,
					'message' => $versions->get_error_message(),
				);
			}

			$package = Asset_Installer::pick_version( (array) $versions, $version );

			if ( '' === $package ) {
				$releases = array_slice( Asset_Versions::releases( (array) $versions ), 0, 5 );

				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: 1: version, 2: plugin name, 3: newest published versions */
						__( 'WordPress.org publishes no version %1$s of "%2$s". Recent releases: %3$s. Use versions/list-asset-versions for the full list.', 'acrossai-abilities-manager' ),
						$version,
						$api->name ?? $plugin_slug,
						implode( ', ', $releases )
					),
				);
			}
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				/* translators: 1: plugin name, 2: error message */
				'message' => sprintf( __( 'Failed to install "%1$s": %2$s', 'acrossai-abilities-manager' ), $api->name, $result->get_error_message() ),
			);
		}

		if ( true !== $result ) {
			$errors    = $skin->get_errors();
			$feedback  = $skin->get_upgrade_messages();
			$error_msg = '';

			if ( is_wp_error( $errors ) && $errors->has_errors() ) {
				$error_msg = $errors->get_error_message();
			} elseif ( ! empty( $feedback ) ) {
				$error_msg = implode( ' ', $feedback );
			} else {
				$error_msg = __( 'Unknown error during installation.', 'acrossai-abilities-manager' );
			}

			return array(
				'success' => false,
				/* translators: 1: plugin name, 2: error message */
				'message' => sprintf( __( 'Failed to install "%1$s": %2$s', 'acrossai-abilities-manager' ), $api->name, $error_msg ),
			);
		}

		$activated = false;

		if ( $activate ) {
			wp_clean_plugins_cache();
			$installed = Plugin_Helpers::resolve_plugin( $plugin_slug );
			if ( null !== $installed['plugin_file'] ) {
				$activate_result = activate_plugin( $installed['plugin_file'] );
				$activated       = ! is_wp_error( $activate_result );
			}
		}

		if ( $activate && $activated ) {
			/* translators: %s: plugin name */
			$message = sprintf( __( 'Plugin "%s" has been installed and activated successfully.', 'acrossai-abilities-manager' ), $api->name );
		} elseif ( $activate && ! $activated ) {
			/* translators: %s: plugin name */
			$message = sprintf( __( 'Plugin "%s" was installed but could not be activated.', 'acrossai-abilities-manager' ), $api->name );
		} else {
			/* translators: %s: plugin name */
			$message = sprintf( __( 'Plugin "%s" has been installed successfully.', 'acrossai-abilities-manager' ), $api->name );
		}

		$result_payload = array(
			'success'     => true,
			'message'     => $message,
			'plugin_name' => $api->name,
			'plugin_slug' => $plugin_slug,
			'activated'   => $activated,
		);

		// Report which version landed, read back from the installed header rather than echoed from
		// the request, so the answer is what is on disk and not what was asked for.
		wp_clean_plugins_cache();
		$installed_now = Plugin_Helpers::resolve_plugin( $plugin_slug );

		if ( null !== $installed_now['plugin_file'] ) {
			$data = Plugin_Helpers::get_plugin_by_slug( (string) $installed_now['plugin_file'] );

			if ( null !== $data && isset( $data['version'] ) ) {
				$result_payload['version'] = (string) $data['version'];
			}
		}

		return $result_payload;
	}
}
