<?php
/**
 * Feature 118 - changes a consent category.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Consent
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Consent;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Consent\Consent_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * changes a consent category.
 *
 * @since 0.0.34
 */
final class Update_Category extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/update-category';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Consent Category', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Change how a consent category is presented: its name, its description, whether it appears in the preference centre, and its order. Whether a category is strictly necessary is deliberately not changeable here - that is what decides if it loads before any consent is given, and turning a real choice into a non-choice belongs to a person on the settings screen.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'categories';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id' => array( 'type' => 'integer', 'description' => __( 'Category id, from consent/list-categories.', 'acrossai-abilities-manager' ) ),
			'name' => array( 'description' => __( 'A string, or a map keyed by language code. Languages not supplied keep their current name.', 'acrossai-abilities-manager' ) ),
			'description' => array( 'description' => __( 'A string, or a map keyed by language code.', 'acrossai-abilities-manager' ) ),
			'visible' => array( 'type' => 'boolean', 'description' => __( 'Whether the category is shown in the preference centre.', 'acrossai-abilities-manager' ) ),
			'priority' => array( 'type' => 'integer', 'description' => __( 'Ordering within the preference centre.', 'acrossai-abilities-manager' ) ),
			'sell_personal_data' => array( 'type' => 'boolean', 'description' => __( 'Whether this category counts as selling or sharing personal information, which matters under US state laws.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'id' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'category' => array( 'type' => 'object', 'additionalProperties' => true ),
			'banner_refreshed' => array( 'type' => 'boolean' ),
			'languages' => array( 'type' => 'object', 'additionalProperties' => true ),
			'rendered' => array( 'type' => 'boolean' ),
			'pending_rebuild' => array( 'type' => 'boolean' ),
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
			'idempotent'  => false,
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$fields = $input;
		unset( $fields['id'] );

		if ( array() === $fields ) {
			return new WP_Error(
				'invalid_input',
				__( 'Nothing to change. Supply at least one field besides the id.', 'acrossai-abilities-manager' )
			);
		}

		return Consent_Repository::update_category( (int) $input['id'], $fields );
	}
}
