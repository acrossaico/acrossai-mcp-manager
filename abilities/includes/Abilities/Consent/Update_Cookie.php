<?php
/**
 * Feature 118 - changes a declared cookie.
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
 * changes a declared cookie.
 *
 * @since 0.0.34
 */
final class Update_Cookie extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/update-cookie';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Declared Cookie', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Change a declared cookie. Only the fields supplied are touched, and a description or duration given for one language leaves the other languages as they were. The banner is refreshed afterwards, and the change is confirmed against what a visitor would see.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'cookies';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id' => array( 'type' => 'integer', 'description' => __( 'Cookie id, from consent/list-cookies.', 'acrossai-abilities-manager' ) ),
			'name' => array( 'type' => 'string', 'description' => __( 'New cookie name.', 'acrossai-abilities-manager' ) ),
			'category_id' => array( 'type' => 'integer', 'description' => __( 'Move it to this consent category.', 'acrossai-abilities-manager' ) ),
			'description' => array( 'description' => __( 'A string, or a map keyed by language code. Languages not supplied keep their current text.', 'acrossai-abilities-manager' ) ),
			'duration' => array( 'description' => __( 'A string, or a map keyed by language code.', 'acrossai-abilities-manager' ) ),
			'domain' => array( 'type' => 'string', 'description' => __( 'Domain that sets it.', 'acrossai-abilities-manager' ) ),
			'type' => array( 'type' => 'integer', 'description' => __( 'The consent plugin own numeric cookie type.', 'acrossai-abilities-manager' ) ),
			'url_pattern' => array( 'type' => 'string', 'description' => __( 'Restrict to pages matching this pattern.', 'acrossai-abilities-manager' ) ),
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
			'cookie' => array( 'type' => 'object', 'additionalProperties' => true ),
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
		if ( isset( $input['category_id'] ) ) {
			$category = Consent_Repository::get_category( (int) $input['category_id'] );

			if ( is_wp_error( $category ) ) {
				return $category;
			}
		}

		$fields = $input;
		unset( $fields['id'] );

		if ( array() === $fields ) {
			return new WP_Error(
				'invalid_input',
				__( 'Nothing to change. Supply at least one field besides the id.', 'acrossai-abilities-manager' )
			);
		}

		return Consent_Repository::update_cookie( (int) $input['id'], $fields );
	}
}
