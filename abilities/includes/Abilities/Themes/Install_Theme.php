<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Themes
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Themes;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Theme_Helpers;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Asset_Installer;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Lookup;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Versions;

defined( 'ABSPATH' ) || exit;

/**
 * Install_Theme ability class (absorbed).
 */
class Install_Theme extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'themes/install-theme',
			'args' => array(
				'label'               => __( 'Install Theme', 'acrossai-abilities-manager' ),
				'description'         => __( 'Install a theme from the WordPress.org theme directory by name or slug. Installs the latest release unless "version" names a specific published one. For a theme that is already installed, use versions/rollback-theme to change its version.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-themes',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'theme'    => array(
							'type'        => 'string',
							'description' => __( 'The theme name or slug to install from WordPress.org.', 'acrossai-abilities-manager' ),
						),
						'version'  => array(
							'type'        => 'string',
							'description' => __( 'Install this exact published version instead of the latest. Matched exactly — 3.3 will not install 3.3.1. versions/list-asset-versions returns the valid values.', 'acrossai-abilities-manager' ),
						),
						'activate' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether to activate the theme after installing.', 'acrossai-abilities-manager' ),
							'default'     => false,
						),
					),
					'required'             => array( 'theme' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'           => array( 'type' => 'boolean' ),
						'message'           => array( 'type' => 'string' ),
						'theme_name'        => array( 'type' => 'string' ),
						'theme_slug'        => array( 'type' => 'string' ),
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
				'reason'                        => __( 'This installs the latest release only. To install or return a theme to a specific earlier version, WP Rollback is the plugin that does it.', 'acrossai-abilities-manager' ),
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

		if ( empty( $input['theme'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'No theme specified.', 'acrossai-abilities-manager' ),
			);
		}

		$theme_slug = sanitize_text_field( $input['theme'] );
		$activate   = ! empty( $input['activate'] );
		$version    = isset( $input['version'] ) && is_scalar( $input['version'] ) ? trim( (string) $input['version'] ) : '';

		$theme_slug = sanitize_title( $theme_slug );

		if ( '' === $theme_slug ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid theme slug.', 'acrossai-abilities-manager' ),
			);
		}

		// Check if theme is already installed.
		$resolved = Theme_Helpers::resolve_theme( $theme_slug );
		if ( null !== $resolved['stylesheet'] && $resolved['certainty'] >= 8.0 ) {
			$theme_data = Theme_Helpers::get_theme_by_slug( $resolved['stylesheet'] );
			$status     = $theme_data && $theme_data['active'] ? __( 'active', 'acrossai-abilities-manager' ) : __( 'inactive', 'acrossai-abilities-manager' );

			// Installing does not overwrite, so a caller who named a version would otherwise read
			// "already installed" as "you now have the version you asked for".
			$already = '' === $version
				/* translators: 1: theme name, 2: theme status */
				? sprintf( __( 'Theme "%1$s" is already installed (%2$s).', 'acrossai-abilities-manager' ), $resolved['theme_name'], $status )
				: sprintf(
					/* translators: 1: theme name, 2: installed version, 3: requested version */
					__( 'Theme "%1$s" is already installed at version %2$s and was not changed. To put it on version %3$s, use versions/rollback-theme, which replaces the files and keeps a restore point.', 'acrossai-abilities-manager' ),
					$resolved['theme_name'],
					( $theme_data && isset( $theme_data['version'] ) ) ? (string) $theme_data['version'] : __( 'unknown', 'acrossai-abilities-manager' ),
					$version
				);

			return array(
				'success'           => true,
				'message'           => $already,
				'already_installed' => true,
				'theme_name'        => $resolved['theme_name'],
				'theme_slug'        => $resolved['stylesheet'],
				'active'            => $theme_data && $theme_data['active'],
			);
		}

		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		if ( ! class_exists( 'Theme_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$api = themes_api(
			'theme_information',
			array(
				'slug'   => $theme_slug,
				'fields' => array(
					'sections'      => false,
					'screenshot'    => false,
					'rating'        => false,
					'downloaded'    => false,
					'download_link' => true,
					'last_updated'  => false,
					'homepage'      => false,
					'tags'          => false,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return array(
				'success' => false,
				/* translators: 1: theme slug, 2: error message */
				'message' => sprintf( __( 'Could not find theme "%1$s" on WordPress.org: %2$s', 'acrossai-abilities-manager' ), $theme_slug, $api->get_error_message() ),
			);
		}

		if ( empty( $api->download_link ) ) {
			return array(
				'success' => false,
				/* translators: %s: theme name */
				'message' => sprintf( __( 'No download link available for "%s".', 'acrossai-abilities-manager' ), $api->name ?? $theme_slug ),
			);
		}

		$package = (string) $api->download_link;

		if ( '' !== $version ) {
			// download_link always points at the latest release.
			$versions = Asset_Lookup::published_versions( 'theme', $theme_slug );

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
						/* translators: 1: version, 2: theme name, 3: newest published versions */
						__( 'WordPress.org publishes no version %1$s of "%2$s". Recent releases: %3$s. Use versions/list-asset-versions for the full list.', 'acrossai-abilities-manager' ),
						$version,
						$api->name ?? $theme_slug,
						implode( ', ', $releases )
					),
				);
			}
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				/* translators: 1: theme name, 2: error message */
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
				/* translators: 1: theme name, 2: error message */
				'message' => sprintf( __( 'Failed to install "%1$s": %2$s', 'acrossai-abilities-manager' ), $api->name, $error_msg ),
			);
		}

		$activated = false;

		if ( $activate ) {
			wp_clean_themes_cache();
			$installed = Theme_Helpers::resolve_theme( $theme_slug );
			if ( null !== $installed['stylesheet'] ) {
				switch_theme( $installed['stylesheet'] );
				$current   = wp_get_theme();
				$activated = $current && $current->get_stylesheet() === $installed['stylesheet'];
			}
		}

		if ( $activate && $activated ) {
			/* translators: %s: theme name */
			$message = sprintf( __( 'Theme "%s" has been installed and activated successfully.', 'acrossai-abilities-manager' ), $api->name );
		} elseif ( $activate && ! $activated ) {
			/* translators: %s: theme name */
			$message = sprintf( __( 'Theme "%s" was installed but could not be activated.', 'acrossai-abilities-manager' ), $api->name );
		} else {
			/* translators: %s: theme name */
			$message = sprintf( __( 'Theme "%s" has been installed successfully.', 'acrossai-abilities-manager' ), $api->name );
		}

		$result_payload = array(
			'success'    => true,
			'message'    => $message,
			'theme_name' => $api->name,
			'theme_slug' => $theme_slug,
			'activated'  => $activated,
		);

		// Read the version back off disk rather than echoing the request.
		wp_clean_themes_cache();
		$installed_now = wp_get_theme( $theme_slug );

		if ( $installed_now->exists() ) {
			$result_payload['version'] = (string) $installed_now->get( 'Version' );
		}

		return $result_payload;
	}
}
