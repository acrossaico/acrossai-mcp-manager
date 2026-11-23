<?php
/**
 * Update_Theme ability (Feature 041).
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

defined( 'ABSPATH' ) || exit;

/**
 * Apply the pending update for one or more installed themes.
 *
 * Wraps Theme_Upgrader::bulk_upgrade() the same way Install_Theme wraps
 * Theme_Upgrader::install(). Accepts stylesheets (e.g. "twentytwentyfour") or
 * theme names / partials — resolved via Theme_Helpers::resolve_theme().
 */
class Update_Theme extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'themes/update-theme',
			'args' => array(
				'label'               => __( 'Update Theme', 'acrossai-abilities-manager' ),
				'description'         => __( 'Apply the pending update for one or more installed themes. Accepts stylesheet directory names (e.g. "twentytwentyfour") or theme names (resolved via Theme_Helpers). Re-running when no update is available is a no-op.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-themes',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'update_themes' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'stylesheets'        => array(
							'type'        => 'array',
							'minItems'    => 1,
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'One or more theme stylesheets or theme names to update.', 'acrossai-abilities-manager' ),
						),
						'clear_update_cache' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'stylesheets' ),
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
									'stylesheet'   => array( 'type' => 'string' ),
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
	 * Feature 136. This ability only moves a theme forward to a pending update.
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
				'reason'                        => __( 'This applies a pending update and can only move forward. To put a theme back on an earlier version, WP Rollback is the plugin that does it.', 'acrossai-abilities-manager' ),
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

		$stylesheets = isset( $input['stylesheets'] ) && is_array( $input['stylesheets'] ) ? $input['stylesheets'] : array();
		if ( empty( $stylesheets ) ) {
			return array(
				'success' => false,
				'message' => __( 'Provide at least one theme stylesheet in "stylesheets".', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! function_exists( 'get_theme_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! class_exists( '\Theme_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$all_themes      = wp_get_themes();
		$theme_updates   = get_theme_updates();
		$per_slug_result = array();
		$to_upgrade      = array();

		foreach ( $stylesheets as $raw_slug ) {
			$identifier = sanitize_text_field( (string) $raw_slug );
			if ( '' === $identifier ) {
				continue;
			}

			$stylesheet = null;
			if ( isset( $all_themes[ $identifier ] ) ) {
				$stylesheet = $identifier;
			} else {
				$resolved = Theme_Helpers::resolve_theme( $identifier );
				if ( ! empty( $resolved['stylesheet'] ) && $resolved['certainty'] >= 8.0 ) {
					$stylesheet = (string) $resolved['stylesheet'];
				}
			}

			if ( null === $stylesheet ) {
				$per_slug_result[ $identifier ] = array(
					'stylesheet'   => $identifier,
					'from_version' => '',
					'to_version'   => '',
					'updated'      => false,
					'message'      => __( 'Could not resolve theme.', 'acrossai-abilities-manager' ),
				);
				continue;
			}

			$from_version = isset( $all_themes[ $stylesheet ] ) ? (string) $all_themes[ $stylesheet ]->get( 'Version' ) : '';
			$to_version   = '';
			if ( isset( $theme_updates[ $stylesheet ]->update['new_version'] ) ) {
				$to_version = (string) $theme_updates[ $stylesheet ]->update['new_version'];
			}

			if ( ! isset( $theme_updates[ $stylesheet ] ) ) {
				$per_slug_result[ $stylesheet ] = array(
					'stylesheet'   => $stylesheet,
					'from_version' => $from_version,
					'to_version'   => $from_version,
					'updated'      => false,
					'message'      => __( 'No update available.', 'acrossai-abilities-manager' ),
				);
				continue;
			}

			$to_upgrade[]                   = $stylesheet;
			$per_slug_result[ $stylesheet ] = array(
				'stylesheet'   => $stylesheet,
				'from_version' => $from_version,
				'to_version'   => $to_version,
				'updated'      => false,
				'message'      => __( 'Pending upgrade.', 'acrossai-abilities-manager' ),
			);
		}

		if ( empty( $to_upgrade ) ) {
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
				'message'       => __( 'No pending theme updates for the given stylesheets.', 'acrossai-abilities-manager' ),
			);
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$results  = $upgrader->bulk_upgrade(
			$to_upgrade,
			array(
				'clear_update_cache' => ! isset( $input['clear_update_cache'] ) || (bool) $input['clear_update_cache'],
			)
		);

		wp_clean_themes_cache();
		$themes_after = wp_get_themes();

		$updated_count = 0;
		$failed_count  = 0;

		foreach ( $to_upgrade as $stylesheet ) {
			$result      = $results[ $stylesheet ] ?? null;
			$now_version = isset( $themes_after[ $stylesheet ] ) ? (string) $themes_after[ $stylesheet ]->get( 'Version' ) : '';

			if ( is_wp_error( $result ) ) {
				$per_slug_result[ $stylesheet ]['updated']    = false;
				$per_slug_result[ $stylesheet ]['message']    = $result->get_error_message();
				$per_slug_result[ $stylesheet ]['to_version'] = $now_version;
				++$failed_count;
				continue;
			}
			if ( null === $result || false === $result ) {
				$skin_errors = $skin->get_errors();
				$msg         = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
					? $skin_errors->get_error_message()
					: __( 'Upgrade did not report success.', 'acrossai-abilities-manager' );
				$per_slug_result[ $stylesheet ]['updated']    = false;
				$per_slug_result[ $stylesheet ]['message']    = $msg;
				$per_slug_result[ $stylesheet ]['to_version'] = $now_version;
				++$failed_count;
				continue;
			}

			$per_slug_result[ $stylesheet ]['updated']    = true;
			$per_slug_result[ $stylesheet ]['to_version'] = $now_version;
			$per_slug_result[ $stylesheet ]['message']    = __( 'Updated.', 'acrossai-abilities-manager' );
			++$updated_count;
		}

		return array(
			'success'       => true,
			'results'       => array_values( $per_slug_result ),
			'updated_count' => $updated_count,
			'failed_count'  => $failed_count,
			'message'       => sprintf(
				/* translators: 1: updated count, 2: failed count */
				__( 'Theme update completed: %1$d updated, %2$d failed.', 'acrossai-abilities-manager' ),
				$updated_count,
				$failed_count
			),
		);
	}
}
