<?php
/**
 * Feature 118 - declares a cookie in the consent banner.
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
 * declares a cookie in the consent banner.
 *
 * @since 0.0.34
 */
final class Add_Cookie extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/add-cookie';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Declare a Cookie', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Declare a cookie so it appears in the preference centre under its consent category. Supply the name a browser stores it under, the category, and a plain description a visitor can understand. Description and duration accept either one string, applied to every language, or a map keyed by language code. This does not make the cookie exist or stop it being set - it records what the site uses, which is what the banner reports.', 'acrossai-abilities-manager' );
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
			'name' => array( 'type' => 'string', 'description' => __( 'The cookie name as stored in the browser, for example _ga.', 'acrossai-abilities-manager' ) ),
			'category_id' => array( 'type' => 'integer', 'description' => __( 'Consent category id, from consent/list-categories.', 'acrossai-abilities-manager' ) ),
			'description' => array( 'description' => __( 'What the cookie does, in plain language. A string, or a map keyed by language code.', 'acrossai-abilities-manager' ) ),
			'duration' => array( 'description' => __( 'How long it lasts, for example "1 year". A string, or a map keyed by language code.', 'acrossai-abilities-manager' ) ),
			'domain' => array( 'type' => 'string', 'description' => __( 'Domain that sets it. Optional.', 'acrossai-abilities-manager' ) ),
			'type' => array( 'type' => 'integer', 'description' => __( 'The consent plugin own numeric cookie type. It publishes no meaning for the codes, so leave it unset unless you are copying an existing entry.', 'acrossai-abilities-manager' ) ),
			'url_pattern' => array( 'type' => 'string', 'description' => __( 'Restrict to pages matching this pattern. Optional.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'name', 'category_id' );
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
		$category = Consent_Repository::get_category( (int) $input['category_id'] );

		if ( is_wp_error( $category ) ) {
			return $category;
		}

		return Consent_Repository::create_cookie( $input );
	}
}
