<?php
/**
 * Feature 116 - reports whether translations can be written and loaded on this site.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LocoTranslate;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Bundle_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Loco_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * reports whether translations can be written and loaded on this site.
 *
 * @since 0.0.34
 */
final class Get_Translation_Status extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/get-translation-status';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Translation Status', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report whether this site can actually load and save translations: the site locale, whether the languages directory exists and is writable, how many bundles Loco sees, and crucially whether this WordPress uses the .l10n.php translation cache. That last one decides how to read a save: WordPress 6.5 and later reads that cache in preference to the MO file, so a save that produced an MO but no cache leaves the old strings rendering. Run this first when a translation appears not to take effect.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'discovery';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'site_locale'          => array( 'type' => 'string' ),
			'languages_dir'        => array( 'type' => 'string' ),
			'languages_dir_exists' => array( 'type' => 'boolean' ),
			'languages_dir_writable' => array( 'type' => 'boolean' ),
			'uses_php_cache'       => array(
				'type'        => 'boolean',
				'description' => __( 'True on WordPress 6.5+, where the .l10n.php cache is read in preference to the MO file.', 'acrossai-abilities-manager' ),
			),
			'bundle_count'         => array( 'type' => 'integer' ),
			'loco_version'         => array( 'type' => 'string' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$dir     = defined( 'WP_LANG_DIR' ) ? (string) WP_LANG_DIR : '';
		$exists  = '' !== $dir && is_dir( $dir );
		$bundles = Bundle_Repository::all();

		return array(
			'site_locale'            => (string) get_locale(),
			'languages_dir'          => $dir,
			'languages_dir_exists'   => $exists,
			// A missing directory is not a failure: Loco creates it on first save. What matters is
			// whether the PARENT can be written, which is what decides if that save can succeed.
			'languages_dir_writable' => $exists ? is_writable( $dir ) : ( '' !== $dir && is_writable( dirname( $dir ) ) ),
			'uses_php_cache'         => Loco_Guard::uses_php_cache(),
			'bundle_count'           => is_wp_error( $bundles ) ? 0 : count( $bundles ),
			// A FUNCTION, not a constant, despite the lower-case name. `defined()` is always false for
			// it, so this field reported an empty version on every site until it was measured.
			'loco_version'           => function_exists( 'loco_plugin_version' ) ? (string) loco_plugin_version() : '',
		);
	}
}
