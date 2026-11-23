<?php
/**
 * Feature 106 — shared shape for the twelve settings writers.
 *
 * Each writer owns exactly one area of Settings_Repository::areas(), and that area IS its whitelist:
 * a key outside it is refused with `setting_not_writable` naming what the area does accept, rather
 * than passed through to Yoast. Per-area typing is what makes 214 writable keys safe without
 * exposing them all behind one call.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Writes one settings area.
 */
abstract class Base_Settings_Write_Ability extends Base_Yoast_Ability {

	/**
	 * The settings area this ability writes.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function area_written(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'yoast-settings';
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function is_writer(): bool {
		return true;
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'settings' => array(
				'type'        => 'object',
				'description' => sprintf(
					/* translators: %s: comma-separated writable keys */
					__( 'Setting key => new value. Accepted keys: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', array_keys( Settings_Repository::keys_for( $this->area_written() ) ) )
				),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'settings' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'updated'  => array( 'type' => 'array' ),
			'settings' => array( 'type' => 'array' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function run( array $input ) {
		$patch = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		$area  = $this->area_written();

		$updated = Settings_Repository::write( $area, (array) Slash_Input::slash( $patch, $input ) );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'updated'  => $updated,
			'settings' => Settings_Repository::describe_area( $area ),
			'message'  => array() === $updated
				? __( 'No change — those settings already had those values.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %s: comma-separated setting keys */
					__( 'Updated: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', $updated )
				),
		);
	}
}
