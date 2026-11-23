<?php
/**
 * Reinstall_Theme ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Themes
 * @since      0.0.45
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Themes;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Asset_Installer;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Lookup;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Re-apply the package for the version a theme is already on.
 *
 * The theme half of the repair step, alongside `plugins/reinstall-plugin`. Replaces the theme's
 * files with the package WordPress.org publishes for its current version, leaving the version,
 * the active theme, and every stored customisation alone.
 *
 * Reinstalling the **active** theme is permitted and needs no `force` flag, unlike a rollback: the
 * version does not change, so the files that arrive are the ones the site is already running. The
 * swap happens behind maintenance mode, and the previous copy is restored automatically if the
 * package fails to apply.
 *
 * Customisations are safe only because WordPress stores them outside the theme directory —
 * Customizer settings in options, template edits in the database. A **child** theme is a separate
 * directory and is not touched. Files edited directly inside this theme's folder are exactly what
 * this ability is for, and will be replaced; that is the point.
 *
 * @since 0.0.45
 */
class Reinstall_Theme extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'themes/reinstall-theme',
			'args' => array(
				'label'               => __( 'Reinstall Theme', 'acrossai-abilities-manager' ),
				'description'         => __( 'Re-download and re-apply the WordPress.org package for the version a theme is already on, repairing modified or missing files without changing the version. Works on the active theme. Customizer settings and content are stored outside the theme folder and are untouched, as are child themes; files edited inside the theme folder are replaced. The previous copy is backed up first and restored automatically if the package fails to apply. Honours DISALLOW_FILE_MODS. Idempotent.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-themes',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'update_themes' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'stylesheet' => array(
							'type'        => 'string',
							'description' => __( 'The theme directory name (stylesheet) to reinstall, e.g. "twentytwentyfive".', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'stylesheet' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'reinstalled'    => array( 'type' => 'boolean' ),
						'stylesheet'     => array( 'type' => 'string' ),
						'theme_name'     => array( 'type' => 'string' ),
						'version'        => array( 'type' => 'string' ),
						'was_active'     => array( 'type' => 'boolean' ),
						'error_code'     => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'message'        => array( 'type' => 'string' ),
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

		$identifier = isset( $input['stylesheet'] ) && is_scalar( $input['stylesheet'] ) ? trim( (string) $input['stylesheet'] ) : '';

		if ( '' === $identifier ) {
			return array(
				'success'    => false,
				'error_code' => 'missing_identifier',
				'message'    => __( 'No theme specified. Pass "stylesheet".', 'acrossai-abilities-manager' ),
			);
		}

		$asset = Asset_Lookup::resolve( 'theme', $identifier );

		if ( is_wp_error( $asset ) ) {
			return array(
				'success'    => false,
				'error_code' => $asset->get_error_code(),
				'message'    => $asset->get_error_message(),
			);
		}

		$stylesheet = (string) $asset['slug'];
		$version    = (string) $asset['installed_version'];
		$was_active = get_stylesheet() === $stylesheet;

		if ( '' === $version ) {
			return array(
				'success'    => false,
				'error_code' => 'unknown_installed_version',
				'stylesheet' => $stylesheet,
				'message'    => sprintf(
					/* translators: %s: theme name */
					__( '"%s" does not declare a version in its stylesheet header, so there is no specific package to re-apply.', 'acrossai-abilities-manager' ),
					(string) $asset['name']
				),
			);
		}

		$versions = Asset_Lookup::published_versions( 'theme', $stylesheet );

		if ( is_wp_error( $versions ) ) {
			return array(
				'success'    => false,
				'error_code' => $versions->get_error_code(),
				'stylesheet' => $stylesheet,
				'message'    => $versions->get_error_message(),
			);
		}

		if ( ! isset( $versions[ $version ] ) || '' === (string) $versions[ $version ] ) {
			return array(
				'success'    => false,
				'error_code' => 'version_not_published',
				'stylesheet' => $stylesheet,
				'version'    => $version,
				'message'    => sprintf(
					/* translators: 1: theme name, 2: installed version */
					__( 'WordPress.org publishes no package for "%1$s" version %2$s, so that exact version cannot be re-applied. Use themes/update-theme to move to a published release instead.', 'acrossai-abilities-manager' ),
					(string) $asset['name'],
					$version
				),
			);
		}

		$outcome = Asset_Installer::install( 'theme', $stylesheet, (string) $versions[ $version ] );

		if ( is_wp_error( $outcome ) ) {
			return array(
				'success'     => false,
				'reinstalled' => false,
				'error_code'  => $outcome->get_error_code(),
				'stylesheet'  => $stylesheet,
				'version'     => $version,
				'message'     => $outcome->get_error_message(),
			);
		}

		if ( ! $outcome['ok'] ) {
			return array(
				'success'     => false,
				'reinstalled' => false,
				'error_code'  => 'reinstall_failed',
				'stylesheet'  => $stylesheet,
				'theme_name'  => (string) $asset['name'],
				'version'     => $version,
				'was_active'  => $was_active,
				'message'     => sprintf(
					/* translators: 1: theme name, 2: failure reason */
					__( 'Could not reinstall "%1$s": %2$s The previous copy is restored automatically.', 'acrossai-abilities-manager' ),
					(string) $asset['name'],
					$outcome['message']
				),
			);
		}

		return array(
			'success'     => true,
			'reinstalled' => true,
			'stylesheet'  => $stylesheet,
			'theme_name'  => (string) $asset['name'],
			'version'     => $version,
			'was_active'  => $was_active,
			'message'     => sprintf(
				/* translators: 1: theme name, 2: version */
				__( '"%1$s" reinstalled at version %2$s. Files now match what WordPress.org publishes for that release.', 'acrossai-abilities-manager' ),
				(string) $asset['name'],
				$version
			),
		);
	}
}
