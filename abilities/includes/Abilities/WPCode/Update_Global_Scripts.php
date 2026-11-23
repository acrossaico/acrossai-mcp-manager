<?php
/**
 * Feature 112 - writes the global header, body and footer scripts.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Snippet_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * writes the global header, body and footer scripts.
 *
 * @since 0.0.34
 */
final class Update_Global_Scripts extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/update-global-scripts';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Global Header and Footer Scripts', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Replace the site-wide header, body or footer script that WPCode outputs on every page. Supply only the slots you want to change; the others are left alone. The value replaces the slot wholesale, so read get-global-scripts first if you mean to append. Requires confirm: true because this markup runs on every page of the site, including the checkout and login pages.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'global-scripts';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'header'         => array(
				'type'        => 'string',
				'description' => __( 'Markup for the site head. Replaces the current value entirely.', 'acrossai-abilities-manager' ),
			),
			'body'           => array(
				'type'        => 'string',
				'description' => __( 'Markup for just after the opening body tag. Only rendered by themes that call wp_body_open.', 'acrossai-abilities-manager' ),
			),
			'footer'         => array(
				'type'        => 'string',
				'description' => __( 'Markup for the site footer. Replaces the current value entirely.', 'acrossai-abilities-manager' ),
			),
		);
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
			'header'  => array( 'type' => 'string' ),
			'body'    => array( 'type' => 'string' ),
			'footer'  => array( 'type' => 'string' ),
			'updated' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Which slots were changed.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This markup is output on every page of the site. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$slots = array_intersect_key( $input, Snippet_Repository::GLOBAL_KEYS );

		if ( empty( $slots ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Supply at least one of header, body or footer.', 'acrossai-abilities-manager' )
			);
		}

		$result = Snippet_Repository::update_global_scripts( $slots );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge( $result, array( 'updated' => array_keys( $slots ) ) );
	}
}
