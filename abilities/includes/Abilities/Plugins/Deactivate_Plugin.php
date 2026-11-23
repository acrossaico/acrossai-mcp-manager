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
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Plugin_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivate_Plugin ability class (absorbed).
 */
class Deactivate_Plugin extends Ability_Definition {

	/**
	 * Hardcoded list of plugin file paths that must never be deactivated
	 * by this ability. Deactivating any of them would either cut the AI's
	 * connection to the site (mcp-manager) or disable the entire ability
	 * surface (abilities-manager, acrossai-pro).
	 *
	 * @var array<int,string>
	 */
	private const PROTECTED_PLUGINS = array(
		'acrossai-mcp-manager/acrossai-mcp-manager.php',
		'acrossai-abilities-manager/acrossai-abilities-manager.php',
		'acrossai-pro/acrossai-pro.php',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'plugins/deactivate-plugin',
			'args' => array(
				'label'               => __( 'Deactivate Plugin', 'acrossai-abilities-manager' ),
				'description'         => __( 'Deactivate an active WordPress plugin by name, slug, or partial match. Works in recovery mode; only updates the active-plugins option and does not load the plugin file.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-plugins',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => __( 'Plugin name, file path (e.g. akismet/akismet.php), or partial match.', 'acrossai-abilities-manager' ),
						),
						'slug'   => array(
							'type'        => 'string',
							'description' => __( 'Alias for "plugin". If both are provided, "plugin" wins.', 'acrossai-abilities-manager' ),
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
						'success'        => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'matched_plugin' => array( 'type' => 'string' ),
						'certainty'      => array( 'type' => 'number' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'plugin'         => array( 'type' => 'string' ),
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
	public function execute( array $input = array() ): array {
		$raw_plugin = ! empty( $input['plugin'] ) ? $input['plugin'] : ( $input['slug'] ?? '' );

		if ( empty( $raw_plugin ) ) {
			return array(
				'success' => false,
				'message' => __( 'No plugin specified. Pass "plugin" (or its alias "slug").', 'acrossai-abilities-manager' ),
			);
		}

		$identifier = sanitize_text_field( (string) $raw_plugin );
		$resolved   = Plugin_Helpers::resolve_plugin( $identifier );

		if ( null === $resolved['plugin_file'] ) {
			return array(
				'success'   => false,
				/* translators: %s: plugin identifier */
				'message'   => sprintf( __( 'No plugin found matching "%s".', 'acrossai-abilities-manager' ), $identifier ),
				'certainty' => 0.0,
			);
		}

		if ( $resolved['certainty'] < 8.0 ) {
			return Plugin_Helpers::build_candidate_response(
				$resolved,
				$identifier,
				'plugins/deactivate-plugin',
				__( 'Deactivate', 'acrossai-abilities-manager' )
			);
		}

		$plugin_file = $resolved['plugin_file'];
		$plugin_name = $resolved['plugin_name'];
		$certainty   = $resolved['certainty'];

		// Protected-plugin guard: refuse to deactivate any AcrossAI-family plugin.
		// Check runs against the resolved file path so it cannot be bypassed by
		// passing a partial/fuzzy identifier that resolves to a protected plugin.
		if ( in_array( $plugin_file, self::PROTECTED_PLUGINS, true ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'protected_plugin',
				/* translators: %s: plugin name */
				'message'        => sprintf( __( 'Plugin "%s" is protected and cannot be deactivated by this ability.', 'acrossai-abilities-manager' ), $plugin_name ),
				'matched_plugin' => $plugin_name,
				'plugin'         => $plugin_file,
				'certainty'      => $certainty,
			);
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			return array(
				'success'        => true,
				/* translators: %s: plugin name */
				'message'        => sprintf( __( 'Plugin "%s" is already inactive.', 'acrossai-abilities-manager' ), $plugin_name ),
				'matched_plugin' => $plugin_name,
				'certainty'      => $certainty,
			);
		}

		deactivate_plugins( $plugin_file );

		if ( is_plugin_active( $plugin_file ) ) {
			return array(
				'success'        => false,
				/* translators: %s: plugin name */
				'message'        => sprintf( __( 'Failed to deactivate plugin "%s".', 'acrossai-abilities-manager' ), $plugin_name ),
				'matched_plugin' => $plugin_name,
				'certainty'      => $certainty,
			);
		}

		return array(
			'success'        => true,
			/* translators: %s: plugin name */
			'message'        => sprintf( __( 'Plugin "%s" has been deactivated.', 'acrossai-abilities-manager' ), $plugin_name ),
			'matched_plugin' => $plugin_name,
			'certainty'      => $certainty,
		);
	}
}
