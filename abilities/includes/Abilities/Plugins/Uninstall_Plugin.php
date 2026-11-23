<?php
/**
 * Feature 064 — Uninstall a plugin (fires uninstall hook + deletes files).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Plugins
 * @since      0.0.23
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Plugins;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Plugin_Helpers;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Uninstall a plugin by fuzzy identifier. Wraps WP core's `delete_plugins()`,
 * which fires the plugin's registered uninstall hook (by calling
 * `uninstall_plugin()` itself) and then removes its files.
 *
 * The distinction is load-bearing and was wrong here for a while:
 * `uninstall_plugin()` ALONE fires the hook and deletes nothing, so a plugin
 * with no uninstall hook — most of them — reported a successful uninstall
 * while staying on disk and in the plugin list.
 *
 * Guardrails, in order:
 *   1. File_Mods_Guard::blocked_response('install') -> file_mods_disallowed.
 *   2. Plugin_Helpers::resolve_plugin() returns null (or low certainty) ->
 *      plugin_not_found.
 *   3. is_plugin_active() -> plugin_active (caller must deactivate first).
 */
class Uninstall_Plugin extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'plugins/uninstall-plugin',
			'args' => array(
				'label'               => __( 'Uninstall Plugin', 'acrossai-abilities-manager' ),
				'description'         => __( 'Uninstall a plugin: fires its uninstall hook and deletes its files, via WordPress core delete_plugins(). Distinct from deactivate-plugin (which only flips active_plugins). Refuses on active plugins, on symlinked plugins, when the filesystem needs FTP credentials, and when DISALLOW_FILE_MODS is set; verifies the files are gone before reporting success.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-plugins',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin'      => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'delete_data' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'plugin' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'uninstalled'    => array( 'type' => 'boolean' ),
						'plugin_file'    => array( 'type' => array( 'string', 'null' ) ),
						'plugin_name'    => array( 'type' => array( 'string', 'null' ) ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
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
		$blocked = File_Mods_Guard::blocked_response( 'install' );
		if ( null !== $blocked ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'file_mods_disallowed',
				'message'        => $blocked['message'],
			);
		}

		$identifier = sanitize_text_field( (string) ( $input['plugin'] ?? '' ) );
		if ( '' === $identifier ) {
			return array(
				'success' => false,
				'message' => __( 'plugin is required.', 'acrossai-abilities-manager' ),
			);
		}

		$resolved = Plugin_Helpers::resolve_plugin( $identifier );
		if ( null === $resolved['plugin_file'] || $resolved['certainty'] < 8.0 ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'plugin_not_found',
				'plugin_file'    => null,
				'plugin_name'    => null,
				'message'        => sprintf(
					/* translators: %s: input identifier */
					__( 'No plugin found matching "%s". Pass a more specific slug or the full plugin_file value.', 'acrossai-abilities-manager' ),
					$identifier
				),
			);
		}

		$plugin_file = (string) $resolved['plugin_file'];
		$plugin_name = (string) $resolved['plugin_name'];

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( is_plugin_active( $plugin_file ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'plugin_active',
				'plugin_file'    => $plugin_file,
				'plugin_name'    => $plugin_name,
				'message'        => sprintf(
					/* translators: %s: plugin name */
					__( 'Plugin "%s" is currently active. Deactivate it via plugins/deactivate-plugin before uninstalling.', 'acrossai-abilities-manager' ),
					$plugin_name
				),
			);
		}

		// A SYMLINKED plugin is refused outright.
		//
		// `WP_Filesystem_Direct::delete()` in recursive mode calls
		// `dirlist()`, and PHP's directory functions follow symlinks — so
		// deleting a linked plugin walks into the TARGET and removes its
		// contents, leaving an empty link behind. On the development layout
		// this project uses, where several sites symlink one checkout, that
		// destroys the plugin for every site at once and takes its `.git`
		// with it.
		//
		// Not hypothetical: one fleet-wide `uninstall-plugin` across four
		// symlinked sites emptied the shared acrossai-abilities-manager
		// working tree, and only the committed work was recoverable. Deleting
		// through a link is never what the operator means — they asked to
		// remove a plugin from THIS site, not to destroy what it points at.
		$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );

		if ( is_link( $plugin_dir ) ) {
			return array(
				'success'        => false,
				'uninstalled'    => false,
				'blocked_reason' => 'plugin_is_symlinked',
				'plugin_file'    => $plugin_file,
				'plugin_name'    => $plugin_name,
				'message'        => sprintf(
					/* translators: 1: plugin name, 2: the link target. */
					__( '"%1$s" is a symlink to %2$s. Deleting it would erase that directory for every site linking to it, so this refuses. Remove the symlink by hand if that is what you want.', 'acrossai-abilities-manager' ),
					$plugin_name,
					(string) readlink( $plugin_dir )
				),
			);
		}

		// `delete_plugins()`, not `uninstall_plugin()`.
		//
		// Core's `uninstall_plugin()` fires the plugin's uninstall hook or
		// includes its `uninstall.php` — and that is ALL it does. It never
		// removes a file. `delete_plugins()` calls it internally and then
		// deletes the directory, which is what this ability has always said
		// it does. The old call left every file in place and reported
		// success, so a plugin with no uninstall hook — most of them —
		// "uninstalled" with nothing happening and stayed listed in wp-admin.
		//
		// The filesystem method is checked first rather than left to
		// `delete_plugins()`, which calls `request_filesystem_credentials()`
		// and ECHOES an HTML form when it cannot proceed. This ability answers
		// in JSON, so an FTP-credentialled host would have produced markup in
		// the middle of a response.
		if ( 'direct' !== get_filesystem_method() ) {
			return array(
				'success'        => false,
				'uninstalled'    => false,
				'blocked_reason' => 'filesystem_not_direct',
				'plugin_file'    => $plugin_file,
				'plugin_name'    => $plugin_name,
				'message'        => __( 'This site cannot write to its own plugin directory without FTP credentials, so a plugin cannot be removed programmatically. Remove it from wp-admin instead.', 'acrossai-abilities-manager' ),
			);
		}

		$result = delete_plugins( array( $plugin_file ) );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'     => false,
				'uninstalled' => false,
				'plugin_file' => $plugin_file,
				'plugin_name' => $plugin_name,
				'message'     => sprintf(
					/* translators: 1: plugin name, 2: error message */
					__( 'Failed to uninstall "%1$s": %2$s', 'acrossai-abilities-manager' ),
					$plugin_name,
					$result->get_error_message()
				),
			);
		}

		// VERIFIED, not reported. The defect being fixed here was a success
		// message nobody checked against the filesystem, so taking
		// `delete_plugins()` at its word would repeat the mistake one level up.
		//
		// `clearstatcache()` first: PHP can answer `file_exists()` from its
		// stat cache, and a check that might be reading a stale answer is not
		// a check.
		clearstatcache( true, WP_PLUGIN_DIR . '/' . $plugin_file );

		if ( true !== $result || file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			return array(
				'success'        => false,
				'uninstalled'    => false,
				'blocked_reason' => 'files_remain',
				'plugin_file'    => $plugin_file,
				'plugin_name'    => $plugin_name,
				'message'        => sprintf(
					/* translators: %s: plugin name */
					__( 'WordPress reported no error removing "%s", but its files are still on disk. Check the plugin directory\'s permissions.', 'acrossai-abilities-manager' ),
					$plugin_name
				),
			);
		}

		return array(
			'success'     => true,
			'uninstalled' => true,
			'plugin_file' => $plugin_file,
			'plugin_name' => $plugin_name,
			'message'     => sprintf(
				/* translators: %s: plugin name */
				__( 'Uninstalled plugin "%s" and removed its files.', 'acrossai-abilities-manager' ),
				$plugin_name
			),
		);
	}
}
