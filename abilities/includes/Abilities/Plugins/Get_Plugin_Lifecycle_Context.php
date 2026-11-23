<?php
/**
 * Feature 055 — per-plugin lifecycle-context envelope.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Plugins
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Plugins;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Lifecycle_Event_Log;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Plugin_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Return the lifecycle-context object for a single plugin: header, active
 * state, autoupdate enrolment, update availability, and last activated /
 * deactivated / updated timestamps from the option-backed event log.
 */
class Get_Plugin_Lifecycle_Context extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'plugins/get-plugin-lifecycle-context',
			'args' => array(
				'label'               => __( 'Get Plugin Lifecycle Context', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the lifecycle-context envelope for a single plugin: header (name, version, author, description), active state, network-active state, autoupdate enrolment, update availability, and the last activated / deactivated / updated timestamps recorded since 0.0.13. Accepts a plugin file, display name, slug, or partial match, like the other plugin abilities; an ambiguous identifier returns candidate choices instead of a guess.', 'acrossai-abilities-manager' ),
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
							'minLength'   => 1,
							'description' => __( 'Plugin file path (e.g. akismet/akismet.php), display name, slug, or partial match.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'plugin' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'             => array( 'type' => 'boolean' ),
						'basename'            => array( 'type' => 'string' ),
						'header'              => array( 'type' => 'object' ),
						'is_active'           => array( 'type' => 'boolean' ),
						'is_network_active'   => array( 'type' => 'boolean' ),
						'autoupdate_enabled'  => array( 'type' => 'boolean' ),
						'update_available'    => array( 'type' => 'boolean' ),
						'last_activated_at'   => array( 'type' => 'integer' ),
						'last_deactivated_at' => array( 'type' => 'integer' ),
						'last_updated_at'     => array( 'type' => 'integer' ),
						'message'             => array( 'type' => 'string' ),
						// Resolution context. additionalProperties is false on this ability, so a
						// key returned but not declared here is stripped by output validation and
						// the caller silently loses it.
						'matched_plugin'      => array( 'type' => 'string' ),
						'certainty'           => array( 'type' => 'number' ),
						'actions'             => array( 'type' => 'array' ),
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
		// Feature 055 hardening — reject traversal and absolute paths before the identifier
		// reaches anything else. A space is permitted because a display name contains one
		// ("AcrossAI Abilities Manager") and a space cannot express a traversal; the identifier
		// is only ever compared against get_plugins() keys and header names, and is never
		// handed to the filesystem.
		$identifier = ltrim( (string) ( $input['plugin'] ?? '' ), '/' );
		if ( '' === $identifier
			|| str_contains( $identifier, '..' )
			|| str_contains( $identifier, '\\' )
			|| ! preg_match( '/^[A-Za-z0-9_\-\/\. ]+$/', $identifier ) ) {
			return array(
				'success' => false,
				'message' => __( 'A valid plugin identifier is required (e.g. "hello-dolly/hello.php", "Hello Dolly", or "hello-dolly").', 'acrossai-abilities-manager' ),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		// Issue #296: this used to be an exact `isset( $all_plugins[ $identifier ] )`, the only
		// raw lookup among the ten plugin abilities. Every sibling that takes an identifier goes
		// through the same six-tier matcher, so a slug accepted by deactivate-plugin was refused
		// here -- and refused with "not installed", which asserts something false about the site
		// rather than admitting the name could not be resolved.
		$resolved = Plugin_Helpers::resolve_plugin( $identifier );

		if ( null === $resolved['plugin_file'] ) {
			return array(
				'success'   => false,
				/* translators: %s: plugin identifier */
				'message'   => sprintf( __( 'No plugin found matching "%s".', 'acrossai-abilities-manager' ), $identifier ),
				'certainty' => 0.0,
			);
		}

		// Below the same 8.0 threshold the siblings use: offer the candidates rather than
		// reporting a confident envelope for a plugin the caller may not have meant.
		if ( $resolved['certainty'] < 8.0 ) {
			return Plugin_Helpers::build_candidate_response(
				$resolved,
				$identifier,
				'plugins/get-plugin-lifecycle-context',
				__( 'Inspect', 'acrossai-abilities-manager' )
			);
		}

		$basename    = (string) $resolved['plugin_file'];
		$all_plugins = get_plugins();

		// Defensive: resolve_plugin() derives plugin_file from get_plugins(), so this cannot miss
		// today. Kept so a future change to the matcher surfaces as a refusal rather than an
		// undefined-index warning, and worded so it never claims the plugin is absent.
		if ( ! isset( $all_plugins[ $basename ] ) ) {
			return array(
				'success'   => false,
				/* translators: %s: plugin identifier */
				'message'   => sprintf( __( 'Could not resolve "%s" to an installed plugin.', 'acrossai-abilities-manager' ), $identifier ),
				'certainty' => 0.0,
			);
		}

		$header               = (array) $all_plugins[ $basename ];
		$is_active            = is_plugin_active( $basename );
		$is_network_active    = is_multisite() && is_plugin_active_for_network( $basename );
		$autoupdates          = (array) get_site_option( 'auto_update_plugins', array() );
		$autoupdate_enabled   = in_array( $basename, $autoupdates, true );

		$updates          = (array) get_plugin_updates();
		$update_available = isset( $updates[ $basename ] );

		$summary = Lifecycle_Event_Log::get_summary( 'plugin', $basename );

		// Feature 055 hardening — cap free-form header fields so a plugin
		// with a malicious multi-KB Description cannot bloat responses.
		$name_max = 200;
		$auth_max = 200;
		$desc_max = 500;
		$name     = wp_strip_all_tags( (string) ( $header['Name'] ?? '' ) );
		$author   = wp_strip_all_tags( (string) ( $header['Author'] ?? '' ) );
		$descr    = wp_strip_all_tags( (string) ( $header['Description'] ?? '' ) );
		if ( strlen( $name ) > $name_max ) {
			$name = rtrim( substr( $name, 0, $name_max ) ) . '...';
		}
		if ( strlen( $author ) > $auth_max ) {
			$author = rtrim( substr( $author, 0, $auth_max ) ) . '...';
		}
		if ( strlen( $descr ) > $desc_max ) {
			$descr = rtrim( substr( $descr, 0, $desc_max ) ) . '...';
		}

		return array(
			'success'             => true,
			'basename'            => $basename,
			'header'              => (object) array(
				'name'         => $name,
				'version'      => sanitize_text_field( (string) ( $header['Version'] ?? '' ) ),
				'author'       => $author,
				'description'  => $descr,
				'requires_wp'  => sanitize_text_field( (string) ( $header['RequiresWP'] ?? '' ) ),
				'requires_php' => sanitize_text_field( (string) ( $header['RequiresPHP'] ?? '' ) ),
			),
			'is_active'           => (bool) $is_active,
			'is_network_active'   => (bool) $is_network_active,
			'autoupdate_enabled'  => (bool) $autoupdate_enabled,
			'update_available'    => (bool) $update_available,
			'last_activated_at'   => (int) $summary['last_activated_at'],
			'last_deactivated_at' => (int) $summary['last_deactivated_at'],
			'last_updated_at'     => (int) $summary['last_updated_at'],
			// Issue #296: report what the identifier resolved to. Without this, a caller that
			// passed a slug or a partial name cannot tell which plugin the envelope describes
			// except by reading basename and inferring it.
			'matched_plugin'      => (string) $resolved['plugin_name'],
			'certainty'           => (float) $resolved['certainty'],
			/* translators: %s: plugin basename */
			'message'             => sprintf( __( 'Lifecycle for %s.', 'acrossai-abilities-manager' ), $basename ),
		);
	}
}
