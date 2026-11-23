<?php
/**
 * Feature 104 — Apply A Configuration Preset.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Toolbox_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/apply-preset — Apply A Configuration Preset.
 */
final class Apply_Preset extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'apply-preset';
	}

	protected function ability_label(): string {
		return __( 'Apply A Configuration Preset', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Replace the whole LiteSpeed configuration with one of five shipped presets: essentials, basic, advanced, aggressive or extreme, weakest to strongest. LiteSpeed takes an automatic backup first, which litespeed/restore-preset-backup can roll back to — that is why this confirms rather than being marked destructive. Anything above advanced changes front-end rendering and should be tested immediately.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-toolbox';
	}

	protected function suggested_abilities(): array {
		return array(
			'litespeed/list-preset-backups',
			'litespeed/restore-preset-backup',
		);
	}

	protected function input_properties(): array {
		return array(
			'preset' => array(
				'type'        => 'string',
				'enum'        => array( 'essentials', 'basic', 'advanced', 'aggressive', 'extreme' ),
				'description' => __( 'Which preset to apply.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'preset',
		);
	}

	protected function output_properties(): array {
		return array(
			'preset'  => array( 'type' => 'string' ),

			'backups' => array( 'type' => 'array' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function confirmation_message(): string {
		return __(
			'Applying a preset replaces the entire LiteSpeed configuration. A backup is taken first and can be restored with litespeed/restore-preset-backup. Pass confirm: true to proceed.',
			'acrossai-abilities-manager'
		);
	}

	protected function run( array $input ) {
		$preset  = isset( $input['preset'] ) ? (string) $input['preset'] : '';
		$applied = Toolbox_Repository::apply_preset( $preset );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		return array(
			'preset'  => $preset,
			'backups' => Toolbox_Repository::backups(),
			'message' => sprintf(
				/* translators: %s: preset name */
				__( 'Applied the "%s" preset. A backup was taken first — see litespeed/list-preset-backups. Test the front end now.', 'acrossai-abilities-manager' ),
				$preset
			),
		);
	}
}
