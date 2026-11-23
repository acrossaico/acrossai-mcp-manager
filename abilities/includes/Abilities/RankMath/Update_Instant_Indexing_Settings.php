<?php
/**
 * Feature 069 — write the Rank Math Instant Indexing settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Instant_Indexing_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Settings_Writer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #5 — rank-math/update-instant-indexing-settings.
 *
 * Separate from the three panel writers because Instant Indexing stores its
 * settings in a DIFFERENT option (rank-math-options-instant-indexing) that is not
 * part of Option_Center::save_settings()'s internal $map — Rank Math special-cases
 * it too, at includes/rest/class-admin.php:287-302.
 *
 * Only bing_post_types and indexnow_api_key are writable.
 * indexnow_api_key_location is computed from home_url() by
 * Api::get_key_location() and never stored, so it is returned read-only.
 */
class Update_Instant_Indexing_Settings extends Base_Rank_Math_Ability {

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'update-instant-indexing-settings';
	}

	/**
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Rank Math Instant Indexing Settings', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Write the Rank Math Instant Indexing (IndexNow) settings: which post types are auto-submitted on publish, and the IndexNow API key. The key location URL is derived from the site address and cannot be set. To submit URLs immediately use rank-math/submit-urls; to rotate the key use rank-math/reset-indexing-key.', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function sub_group(): string {
		return 'rank-math-settings';
	}

	/**
	 * @return string
	 */
	protected function rank_math_cap(): string {
		return 'general';
	}

	/**
	 * @return string
	 */
	protected function required_module(): string {
		return 'instant-indexing';
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		return array(
			'settings' => array(
				'type'                 => 'object',
				'description'          => __( 'Field id => value. Writable fields are bing_post_types (a list of post type names) and indexnow_api_key (a string). Read the current values with rank-math/get-settings-panel panel=general-instant-indexing.', 'acrossai-abilities-manager' ),
				'additionalProperties' => true,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array(
			'updated'       => array( 'type' => 'object' ),
			'key_location'  => array( 'type' => 'string' ),
			'notifications' => array( 'type' => 'array' ),
		);
	}

	/**
	 * @return string[]
	 */
	protected function required_input(): array {
		return array( 'settings' );
	}

	/**
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
		if ( array() === $settings ) {
			return new WP_Error( 'invalid_input', __( 'The settings object is empty. Nothing to write.', 'acrossai-abilities-manager' ) );
		}

		$result = Settings_Writer::save( 'general-instant-indexing', '', $settings );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$count = count( $result['updated'] );

		return array(
			'updated'       => $result['updated'],
			'key_location'  => Instant_Indexing_Repository::key_location(),
			'notifications' => $result['notifications'],
			'message'       => sprintf(
				/* translators: %d: number of settings written */
				_n( 'Wrote %d Instant Indexing setting.', 'Wrote %d Instant Indexing settings.', $count, 'acrossai-abilities-manager' ),
				$count
			),
		);
	}

}
