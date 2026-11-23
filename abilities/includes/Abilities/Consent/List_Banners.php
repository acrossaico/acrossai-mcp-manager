<?php
/**
 * Feature 118 - lists the configured consent banners.
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
 * lists the configured consent banners.
 *
 * @since 0.0.34
 */
final class List_Banners extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/list-banners';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Consent Banners', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List the consent banners configured on this site and which one is active. A site normally has one per privacy law it answers to, and the active one decides what visitors are shown.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'banner';
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
			'banners' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
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
		$banners = Consent_Repository::list_banners();

		return array(
			'banners' => $banners,
			'count'   => count( $banners ),
		);
	}
}
