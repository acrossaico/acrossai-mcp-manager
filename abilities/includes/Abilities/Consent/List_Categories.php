<?php
/**
 * Feature 118 - lists the consent categories.
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
 * lists the consent categories.
 *
 * @since 0.0.34
 */
final class List_Categories extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/list-categories';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Consent Categories', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List the consent categories the banner offers, with how many cookies each one declares and whether it is shown in the preference centre. Names and descriptions come back keyed by language.', 'acrossai-abilities-manager' );
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
			'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
			'count' => array( 'type' => 'integer' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$categories = Consent_Repository::list_categories();

		foreach ( $categories as $index => $category ) {
			$categories[ $index ]['cookie_count'] = count( Consent_Repository::list_cookies( (int) $category['id'] ) );
		}

		return array(
			'categories' => $categories,
			'count'      => count( $categories ),
		);
	}
}
