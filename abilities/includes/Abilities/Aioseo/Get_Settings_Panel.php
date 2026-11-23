<?php
/**
 * Feature 132 — read one AIOSEO settings panel with its field specification.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Aioseo_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Settings_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability — aioseo/get-settings-panel.
 *
 * The entry point for every settings write in this suite, and the thing AIOSEO has no equivalent of.
 * Its own `aioseo-settings/get` returns the whole tree and documents the shape as "internal AIOSEO;
 * treat as opaque structured data" with no write counterpart — so a caller holding that response
 * still cannot tell which keys are writable, what each accepts, or which AIOSEO capability governs
 * it. This returns one named panel, every field's type, default, current value and modifiers, and
 * whether the current user may write it.
 *
 * CAPABILITY NOTE — `aioseo_cap()` is empty and the check happens inside `run()`. The panel's
 * required capability depends on the `panel` input, and `permission_callback` receives no input, so
 * gating at the callback would mean either the loosest capability for every panel or the tightest for
 * all of them. A reviewer seeing only the callback would otherwise read this as a hole.
 *
 * Read-only, idempotent.
 *
 * @since 0.0.44
 */
class Get_Settings_Panel extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'get-settings-panel';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get All in One SEO Settings Panel', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read one All in One SEO settings panel: every field with its id, type, default, current value, and whether its text is localized or keeps HTML. Call this before aioseo/update-settings-panel to discover exactly which field ids that panel accepts — a field that does not belong to the panel rejects the whole write. Omit panel to list the panels with their AIOSEO capability and whether you may write each. Distinct from AIOSEO\'s own aioseo-settings/get, which returns the entire option tree as opaque data with no field specification and no writer.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function sub_group(): string {
		return 'aioseo-settings';
	}

	/**
	 * Resolved per panel inside run() — see the class docblock.
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
			'panel' => array(
				'type'        => 'string',
				'enum'        => Settings_Registry::panel_slugs(),
				'description' => __( 'Which settings panel to read. Omit to list every panel instead.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'panel'      => array( 'type' => array( 'string', 'null' ) ),
			'label'      => array( 'type' => array( 'string', 'null' ) ),
			'capability' => array( 'type' => array( 'string', 'null' ) ),
			'writable'   => array( 'type' => array( 'boolean', 'null' ) ),
			'permitted'  => array( 'type' => array( 'boolean', 'null' ) ),
			'fields'     => array( 'type' => array( 'object', 'null' ) ),
			'panels'     => array( 'type' => array( 'array', 'null' ) ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
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
		$panel = self::scalar( $input['panel'] ?? '' );

		if ( '' === $panel ) {
			return array(
				'panel'      => null,
				'label'      => null,
				'capability' => null,
				'writable'   => null,
				'permitted'  => null,
				'fields'     => null,
				'panels'     => $this->panel_directory(),
				'message'    => __( 'Every All in One SEO settings panel, with the AIOSEO capability each needs. Call again with one panel to read its fields.', 'acrossai-abilities-manager' ),
			);
		}

		$resolved = Settings_Registry::resolve( $panel );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$allowed = Aioseo_Guard::assert_capability( (string) $resolved['capability'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$fields = Settings_Registry::fields( $panel );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		return array(
			'panel'      => $panel,
			'label'      => (string) $resolved['label'],
			'capability' => (string) $resolved['capability'],
			'writable'   => Settings_Registry::is_writable( $panel ),
			'permitted'  => true,
			'fields'     => $fields,
			'panels'     => null,
			'message'    => Settings_Registry::is_writable( $panel )
				? sprintf(
					/* translators: 1: number of fields, 2: panel slug */
					_n( '%1$d field in the "%2$s" panel.', '%1$d fields in the "%2$s" panel.', count( $fields ), 'acrossai-abilities-manager' ),
					count( $fields ),
					$panel
				)
				: sprintf(
					/* translators: %s: panel slug */
					__( 'The "%s" panel is readable but not writable here — AIOSEO\'s own aioseo-robots/rules-add, rules-update and rules-delete manage these rules by id.', 'acrossai-abilities-manager' ),
					$panel
				),
		);
	}

	/**
	 * Every panel, with whether this caller may write it.
	 *
	 * @since  0.0.44
	 * @return array<int, array<string, mixed>>
	 */
	private function panel_directory(): array {
		$rows = array();

		foreach ( Settings_Registry::panels() as $slug => $panel ) {
			$rows[] = array(
				'panel'      => $slug,
				'label'      => (string) $panel['label'],
				'capability' => (string) $panel['capability'],
				'writable'   => Settings_Registry::is_writable( $slug ),
				'permitted'  => Aioseo_Guard::has_capability( (string) $panel['capability'] ),
			);
		}

		return $rows;
	}
}
