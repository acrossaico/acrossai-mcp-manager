<?php
/**
 * Reinstall_Plugin ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Plugins
 * @since      0.0.45
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Plugins;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Asset_Installer;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Lookup;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Re-apply the package for the version a plugin is already on.
 *
 * The counterpart to `core/reinstall-wp-core`, and the repair step this suite was missing.
 * `plugins/verify-plugin-checksums` can now detect a plugin whose files no longer match what
 * WordPress.org published for its version — a partial update, a truncated FTP transfer, an edit
 * someone made directly on the server — but nothing could put those files back. Reinstalling is
 * the fix, and it does not change the version, so it is safe in a way a rollback is not.
 *
 * Deliberately shaped like `core/reinstall-wp-core`: destructive, idempotent, and no `confirm`
 * flag, because the operation returns the plugin to its own current version rather than moving it.
 *
 * @since 0.0.45
 */
class Reinstall_Plugin extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'plugins/reinstall-plugin',
			'args' => array(
				'label'               => __( 'Reinstall Plugin', 'acrossai-abilities-manager' ),
				'description'         => __( 'Re-download and re-apply the WordPress.org package for the version a plugin is already on, repairing modified or missing files without changing the version. The counterpart to core/reinstall-wp-core, and the repair for what plugins/verify-plugin-checksums reports. The previous copy is backed up first and restored automatically if the package fails to apply. Settings and database content are untouched. Honours DISALLOW_FILE_MODS. Idempotent.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-plugins',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'update_plugins' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => __( 'The plugin to reinstall — folder name, plugin file, or display name.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'plugin' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'reinstalled'  => array( 'type' => 'boolean' ),
						'plugin'       => array( 'type' => 'string' ),
						'plugin_name'  => array( 'type' => 'string' ),
						'version'      => array( 'type' => 'string' ),
						'was_active'   => array( 'type' => 'boolean' ),
						'error_code'   => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'message'      => array( 'type' => 'string' ),
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
			return $blocked;
		}

		$identifier = isset( $input['plugin'] ) && is_scalar( $input['plugin'] ) ? trim( (string) $input['plugin'] ) : '';

		if ( '' === $identifier ) {
			return array(
				'success'    => false,
				'error_code' => 'missing_identifier',
				'message'    => __( 'No plugin specified. Pass "plugin".', 'acrossai-abilities-manager' ),
			);
		}

		$asset = Asset_Lookup::resolve( 'plugin', $identifier );

		if ( is_wp_error( $asset ) ) {
			return array(
				'success'    => false,
				'error_code' => $asset->get_error_code(),
				'message'    => $asset->get_error_message(),
			);
		}

		$version = (string) $asset['installed_version'];

		if ( '' === $version ) {
			return array(
				'success'    => false,
				'error_code' => 'unknown_installed_version',
				'message'    => sprintf(
					/* translators: %s: plugin name */
					__( '"%s" does not declare a version in its header, so there is no specific package to re-apply.', 'acrossai-abilities-manager' ),
					(string) $asset['name']
				),
			);
		}

		$versions = Asset_Lookup::published_versions( 'plugin', (string) $asset['slug'] );

		if ( is_wp_error( $versions ) ) {
			return array(
				'success'    => false,
				'error_code' => $versions->get_error_code(),
				'message'    => $versions->get_error_message(),
			);
		}

		// The installed version must be one WordPress.org actually published. A site running a
		// nightly, a patched build, or a version newer than the directory knows has no package to
		// re-apply, and silently installing the nearest release would be a downgrade disguised as
		// a repair.
		if ( ! isset( $versions[ $version ] ) || '' === (string) $versions[ $version ] ) {
			return array(
				'success'    => false,
				'error_code' => 'version_not_published',
				'plugin'     => (string) $asset['plugin_file'],
				'version'    => $version,
				'message'    => sprintf(
					/* translators: 1: plugin name, 2: installed version */
					__( 'WordPress.org publishes no package for "%1$s" version %2$s, so that exact version cannot be re-applied. Use plugins/update-plugin to move to a published release instead.', 'acrossai-abilities-manager' ),
					(string) $asset['name'],
					$version
				),
			);
		}

		$plugin_file = (string) $asset['plugin_file'];
		$was_active  = is_plugin_active( $plugin_file );

		$outcome = Asset_Installer::install( 'plugin', (string) $asset['slug'], (string) $versions[ $version ] );

		if ( is_wp_error( $outcome ) ) {
			return array(
				'success'     => false,
				'reinstalled' => false,
				'error_code'  => $outcome->get_error_code(),
				'plugin'      => $plugin_file,
				'version'     => $version,
				'message'     => $outcome->get_error_message(),
			);
		}

		if ( ! $outcome['ok'] ) {
			return array(
				'success'     => false,
				'reinstalled' => false,
				'error_code'  => 'reinstall_failed',
				'plugin'      => $plugin_file,
				'plugin_name' => (string) $asset['name'],
				'version'     => $version,
				'was_active'  => $was_active,
				'message'     => sprintf(
					/* translators: 1: plugin name, 2: failure reason */
					__( 'Could not reinstall "%1$s": %2$s The previous copy is restored automatically.', 'acrossai-abilities-manager' ),
					(string) $asset['name'],
					$outcome['message']
				),
			);
		}

		// An overwrite clears the destination, which drops the plugin out of the active list even
		// though the caller never asked to deactivate it. Put it back exactly as it was found.
		$reactivated = true;

		if ( $was_active && ! is_plugin_active( $plugin_file ) ) {
			$activated   = activate_plugin( $plugin_file );
			$reactivated = ! is_wp_error( $activated );
		}

		$message = sprintf(
			/* translators: 1: plugin name, 2: version */
			__( '"%1$s" reinstalled at version %2$s. Files now match what WordPress.org publishes for that release.', 'acrossai-abilities-manager' ),
			(string) $asset['name'],
			$version
		);

		if ( ! $reactivated ) {
			$message .= ' ' . __( 'It was active before the reinstall but could not be reactivated — activate it manually.', 'acrossai-abilities-manager' );
		}

		return array(
			'success'     => true,
			'reinstalled' => true,
			'plugin'      => $plugin_file,
			'plugin_name' => (string) $asset['name'],
			'version'     => $version,
			'was_active'  => $was_active,
			'message'     => $message,
		);
	}
}
