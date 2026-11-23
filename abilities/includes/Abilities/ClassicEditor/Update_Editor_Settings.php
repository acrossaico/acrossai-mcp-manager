<?php
/**
 * Feature 107 — Update Editor Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ClassicEditor
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ClassicEditor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ClassicEditor\Editor_Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * editor/update-editor-settings — change the site default and the per-user switch.
 */
final class Update_Editor_Settings extends Base_Classic_Editor_Ability {

	protected function slug(): string {
		return 'editor/update-editor-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Editor Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Set the site-wide default editor and whether users may choose their own. Values go through Classic Editor\'s own validators and are read back afterwards, so an unaccepted value is refused by name rather than stored and silently treated as classic. Changing the default changes which editor every author gets, so it asks for confirmation; allowing or disallowing user choice does not.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'editor-settings';
	}

	protected function input_properties(): array {
		return array(
			'editor'      => array(
				'type'        => 'string',
				'enum'        => Editor_Settings_Repository::EDITORS,
				'description' => __( 'The site-wide default editor. Changing this requires confirm: true.', 'acrossai-abilities-manager' ),
			),
			'allow_users' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether authors may choose their own editor and switch an individual post between them. With this off there is no per-post logic at all.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'changed'  => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'settings' => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * Declares `confirm` in the input schema. The runtime gate is narrower — see below.
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * Only a real change to the site-wide default is gated.
	 *
	 * Gating the allow-users switch too would be friction without a matching risk: it widens or
	 * narrows who may choose and changes nothing about what any post currently opens in. Gating a
	 * no-op — setting the default to the value it already holds — would be worse still, since the
	 * caller would be asked to confirm something that is not going to happen.
	 *
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
		if ( ! array_key_exists( 'editor', $input ) ) {
			return false;
		}

		return (string) $input['editor'] !== (string) Editor_Settings_Repository::describe()['editor'];
	}

	protected function confirmation_message(): string {
		return __( 'Changing the site-wide default editor changes which editor every author gets. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'editor/get-editor-settings',
				'reason' => __( 'Read the effective state first — the stored value and the effective value differ, and on multisite the network may have locked per-site settings entirely.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function run( array $input ) {
		$patch = array();

		if ( array_key_exists( 'editor', $input ) ) {
			$patch['editor'] = $input['editor'];
		}

		if ( array_key_exists( 'allow_users', $input ) ) {
			$patch['allow_users'] = $input['allow_users'];
		}

		$result = Editor_Settings_Repository::update( $patch );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$settings = Editor_Settings_Repository::describe();

		return array(
			'changed'  => $result['changed'],
			'settings' => $settings,
			'message'  => array() === $result['changed']
				? __( 'No change — those settings already had those values.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: 1: comma-separated setting names, 2: classic or block */
					__( 'Updated: %1$s. The site default is now %2$s.', 'acrossai-abilities-manager' ),
					implode( ', ', $result['changed'] ),
					$settings['editor']
				),
		);
	}
}
