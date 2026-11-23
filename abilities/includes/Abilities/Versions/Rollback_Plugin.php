<?php
/**
 * Feature 136 — move an installed plugin onto a chosen version.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Versions
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Versions;

defined( 'ABSPATH' ) || exit;

/**
 * Put a plugin on a specific version, in either direction.
 *
 * This is the recovery step the suite had no answer for: once a plugin was on 4.3.5 there was no
 * route back to 4.3.1, which is the most common need after a bad update. `plugins/update-plugin`
 * only applies an update WordPress.org is already offering, and `plugins/install-plugin` takes no
 * version at all.
 *
 * Also covers installing a version *forward* of the installed one without waiting for an update
 * offer, since the operation is the same either way.
 *
 * @since 0.0.45
 */
class Rollback_Plugin extends Base_Asset_Rollback {

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function slug(): string {
		return 'rollback-plugin';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function asset_type(): string {
		return 'plugin';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function identifier_key(): string {
		return 'plugin';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Roll Back Plugin', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Move an installed plugin onto a specific version — older or newer — by replacing its files with that version\'s package from WordPress.org, or from a local archive kept by WP Rollback for a privately-hosted plugin. The recovery step for a bad update, and the only way to install a version other than the latest. Settings and database content are untouched; an active plugin stays active. The previous copy is backed up first and restored automatically if the package fails to apply. Requires confirm: true. Run versions/check-asset-rollback first to see what it will affect.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This replaces the plugin\'s files with a different version. Settings and database content are kept, and the previous copy is restored automatically if the package fails to apply — but a version change can break a site whose other plugins expect the current one. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array_merge(
			parent::output_properties(),
			array( 'plugin' => array( 'type' => 'string' ) )
		);
	}

	/**
	 * Whether the plugin was active before its files were replaced.
	 *
	 * @since  0.0.45
	 * @param  array<string,string> $asset Resolved asset.
	 * @return array<string,mixed>
	 */
	protected function before_install( array $asset ): array {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array( 'was_active' => is_plugin_active( (string) $asset['plugin_file'] ) );
	}

	/**
	 * Put the plugin back in the active list if that is where it was found.
	 *
	 * An overwrite clears the destination, which deactivates the plugin as a side effect the caller
	 * never asked for. Rolling back and silently deactivating would look like the new version
	 * crashing the site.
	 *
	 * @since  0.0.45
	 * @param  array<string,string> $asset   Resolved asset.
	 * @param  array<string,mixed>  $state   State from before_install().
	 * @param  string               $version Version now installed.
	 * @return array<string,mixed>
	 */
	protected function after_install( array $asset, array $state, string $version ): array {
		$plugin_file = (string) $asset['plugin_file'];
		$was_active  = ! empty( $state['was_active'] );
		$reactivated = true;

		if ( $was_active && ! is_plugin_active( $plugin_file ) ) {
			$activated   = activate_plugin( $plugin_file );
			$reactivated = ! is_wp_error( $activated );
		}

		$fields = array(
			'plugin'      => $plugin_file,
			'was_active'  => $was_active,
			'reactivated' => $reactivated,
			'message'     => sprintf(
				/* translators: 1: plugin name, 2: version */
				__( '"%1$s" is now on version %2$s.', 'acrossai-abilities-manager' ),
				(string) $asset['name'],
				$version
			),
		);

		if ( $was_active && ! $reactivated ) {
			$fields['message'] .= ' ' . __( 'It was active before the change but could not be reactivated — the version you moved to may be incompatible with this site. Check it before activating manually.', 'acrossai-abilities-manager' );
		}

		return $fields;
	}
}
