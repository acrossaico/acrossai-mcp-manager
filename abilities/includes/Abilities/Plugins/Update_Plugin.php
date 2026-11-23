<?php
/**
 * Update_Plugin ability (Feature 041).
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

defined( 'ABSPATH' ) || exit;

/**
 * Apply the pending update for one or more installed plugins.
 *
 * Wraps Plugin_Upgrader::bulk_upgrade() the same way Install_Plugin wraps
 * Plugin_Upgrader::install(). Bare slugs are resolved through Plugin_Helpers;
 * fully-qualified plugin files (e.g. "hello-dolly/hello.php") are used as-is.
 */
class Update_Plugin extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'plugins/update-plugin',
			'args' => array(
				'label'               => __( 'Update Plugin', 'acrossai-abilities-manager' ),
				'description'         => __( 'Apply the pending update for one or more installed plugins. Accepts plugin files (e.g. "hello-dolly/hello.php") or bare slugs (resolved via Plugin_Helpers). Re-running when no update is available is a no-op.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-plugins',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'update_plugins' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slugs'              => array(
							'type'        => 'array',
							'minItems'    => 1,
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'One or more plugin files or slugs to update.', 'acrossai-abilities-manager' ),
						),
						'clear_update_cache' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'slugs' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'       => array( 'type' => 'boolean' ),
						'results'       => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'slug'         => array( 'type' => 'string' ),
									'from_version' => array( 'type' => 'string' ),
									'to_version'   => array( 'type' => 'string' ),
									'updated'      => array( 'type' => 'boolean' ),
									'message'      => array( 'type' => 'string' ),
								),
								'additionalProperties' => false,
							),
						),
						'updated_count' => array( 'type' => 'integer' ),
						'failed_count'  => array( 'type' => 'integer' ),
						'message'       => array( 'type' => 'string' ),
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
	 * Feature 136. This ability only applies an update WordPress.org is already offering, so it cannot move backwards.
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
				'reason'                        => __( 'This applies a pending update and can only move forward. To put a plugin back on an earlier version after a bad update, WP Rollback is the plugin that does it.', 'acrossai-abilities-manager' ),
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

		$slugs = isset( $input['slugs'] ) && is_array( $input['slugs'] ) ? $input['slugs'] : array();
		if ( empty( $slugs ) ) {
			return array(
				'success' => false,
				'message' => __( 'Provide at least one plugin slug or file in "slugs".', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! class_exists( '\Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$installed       = get_plugins();
		$plugin_updates  = get_plugin_updates();
		$plugin_files    = array();
		$per_slug_result = array();

		foreach ( $slugs as $raw_slug ) {
			$identifier = sanitize_text_field( (string) $raw_slug );
			if ( '' === $identifier ) {
				continue;
			}

			$plugin_file = null;
			if ( isset( $installed[ $identifier ] ) ) {
				$plugin_file = $identifier;
			} else {
				$resolved = Plugin_Helpers::resolve_plugin( $identifier );
				if ( ! empty( $resolved['plugin_file'] ) && $resolved['certainty'] >= 8.0 ) {
					$plugin_file = (string) $resolved['plugin_file'];
				}
			}

			if ( null === $plugin_file ) {
				$per_slug_result[ $identifier ] = array(
					'slug'         => $identifier,
					'from_version' => '',
					'to_version'   => '',
					'updated'      => false,
					'message'      => __( 'Could not resolve plugin.', 'acrossai-abilities-manager' ),
				);
				continue;
			}

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WordPress plugin header keys.
			$from_version = isset( $installed[ $plugin_file ]['Version'] ) ? (string) $installed[ $plugin_file ]['Version'] : '';
			$to_version   = '';
			if ( isset( $plugin_updates[ $plugin_file ]->update->new_version ) ) {
				$to_version = (string) $plugin_updates[ $plugin_file ]->update->new_version;
			}

			if ( ! isset( $plugin_updates[ $plugin_file ] ) ) {
				$per_slug_result[ $plugin_file ] = array(
					'slug'         => $plugin_file,
					'from_version' => $from_version,
					'to_version'   => $from_version,
					'updated'      => false,
					'message'      => __( 'No update available.', 'acrossai-abilities-manager' ),
				);
				continue;
			}

			$plugin_files[]                  = $plugin_file;
			$per_slug_result[ $plugin_file ] = array(
				'slug'         => $plugin_file,
				'from_version' => $from_version,
				'to_version'   => $to_version,
				'updated'      => false,
				'message'      => __( 'Pending upgrade.', 'acrossai-abilities-manager' ),
			);
		}

		if ( empty( $plugin_files ) ) {
			$updated_count = 0;
			$failed_count  = 0;
			foreach ( $per_slug_result as $entry ) {
				if ( ! $entry['updated'] && __( 'No update available.', 'acrossai-abilities-manager' ) !== $entry['message'] ) {
					++$failed_count;
				}
			}
			return array(
				'success'       => true,
				'results'       => array_values( $per_slug_result ),
				'updated_count' => $updated_count,
				'failed_count'  => $failed_count,
				'message'       => __( 'No pending plugin updates for the given slugs.', 'acrossai-abilities-manager' ),
			);
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$results  = $upgrader->bulk_upgrade(
			$plugin_files,
			array(
				'clear_update_cache' => ! isset( $input['clear_update_cache'] ) || (bool) $input['clear_update_cache'],
			)
		);

		wp_clean_plugins_cache();
		$installed_after = get_plugins();

		$updated_count = 0;
		$failed_count  = 0;

		foreach ( $plugin_files as $plugin_file ) {
			$result = $results[ $plugin_file ] ?? null;

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- WP plugin header keys.
			$now_version = isset( $installed_after[ $plugin_file ]['Version'] ) ? (string) $installed_after[ $plugin_file ]['Version'] : '';

			if ( is_wp_error( $result ) ) {
				$per_slug_result[ $plugin_file ]['updated']    = false;
				$per_slug_result[ $plugin_file ]['message']    = $result->get_error_message();
				$per_slug_result[ $plugin_file ]['to_version'] = $now_version;
				++$failed_count;
				continue;
			}

			if ( null === $result || false === $result ) {
				$skin_errors = $skin->get_errors();
				$msg         = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
					? $skin_errors->get_error_message()
					: __( 'Upgrade did not report success.', 'acrossai-abilities-manager' );
				$per_slug_result[ $plugin_file ]['updated']    = false;
				$per_slug_result[ $plugin_file ]['message']    = $msg;
				$per_slug_result[ $plugin_file ]['to_version'] = $now_version;
				++$failed_count;
				continue;
			}

			$per_slug_result[ $plugin_file ]['updated']    = true;
			$per_slug_result[ $plugin_file ]['to_version'] = $now_version;
			$per_slug_result[ $plugin_file ]['message']    = __( 'Updated.', 'acrossai-abilities-manager' );
			++$updated_count;
		}

		return array(
			'success'       => true,
			'results'       => array_values( $per_slug_result ),
			'updated_count' => $updated_count,
			'failed_count'  => $failed_count,
			'message'       => sprintf(
				/* translators: 1: updated count, 2: failed count */
				__( 'Plugin update completed: %1$d updated, %2$d failed.', 'acrossai-abilities-manager' ),
				$updated_count,
				$failed_count
			),
		);
	}
}
