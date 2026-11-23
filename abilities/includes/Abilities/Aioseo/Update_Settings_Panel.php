<?php
/**
 * Feature 132 — write one AIOSEO settings panel.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Settings_Registry;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Settings_Writer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability — aioseo/update-settings-panel.
 *
 * The write AIOSEO ships no equivalent of: its own `aioseo-settings/get` is documented "Read-only:
 * settings cannot be modified via abilities — use the AIOSEO admin UI."
 *
 * All the care lives in {@see Settings_Writer}, which exists because AIOSEO's `sanitizeAndSave()`
 * deletes any key absent from the array it is given. This ability's job is to name the panel, hand
 * over the fields, and report what AIOSEO actually stored.
 *
 * Not confirm-gated. A settings write is reversible — read the panel, write the old value back — and
 * `aioseo/create-backup` exists for the bulk case. Gating every field change behind a confirmation
 * would make the ordinary path two round trips for no safety gained.
 *
 * @since 0.0.44
 */
class Update_Settings_Panel extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'update-settings-panel';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update All in One SEO Settings Panel', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Write fields in one All in One SEO settings panel — breadcrumbs, webmaster verification codes, RSS content, search appearance, sitemaps, social profiles and cards, SEO alerts and the advanced toggles. AIOSEO offers no settings writer of its own, so this is the only way to change them without the admin screens. Read the panel with aioseo/get-settings-panel first to discover its field ids: a field that does not belong to the panel rejects the whole write rather than applying part of it. Values go through AIOSEO\'s own sanitizer, which also regenerates the sitemap and flushes rewrite rules when the settings you changed require it, and the response reports each field read back after the save — so a value AIOSEO reduced or rejected is visible rather than assumed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function sub_group(): string {
		return 'aioseo-settings';
	}

	/**
	 * Resolved per panel inside Settings_Writer — the panel decides the capability and
	 * permission_callback receives no input.
	 *
	 * @since  0.0.44
	 * @return string
	 */
	protected function aioseo_cap(): string {
		return '';
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'panel'    => array(
				'type'        => 'string',
				'enum'        => Settings_Registry::writable_panel_slugs(),
				'description' => __( 'Which settings panel to write.', 'acrossai-abilities-manager' ),
			),
			'settings' => array(
				'type'        => 'object',
				'description' => __( 'Field id => value. Ids are the ones aioseo/get-settings-panel reports for this panel, dotted for nested fields such as postTypes.all. Any id not belonging to the panel rejects the whole write.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'scope'     => array( 'type' => 'string' ),
			'updated'   => array( 'type' => 'object' ),
			'unchanged' => array( 'type' => 'object' ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'panel', 'settings' );
	}

	/**
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$settings = $input['settings'] ?? array();

		if ( ! is_array( $settings ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'settings must be an object of field id => value.', 'acrossai-abilities-manager' )
			);
		}

		return Settings_Writer::write( self::scalar( $input['panel'] ?? '' ), $settings );
	}
}
