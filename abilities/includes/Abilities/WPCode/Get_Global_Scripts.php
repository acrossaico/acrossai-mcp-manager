<?php
/**
 * Feature 112 - reads the global header, body and footer scripts.
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
 * reads the global header, body and footer scripts.
 *
 * @since 0.0.34
 */
final class Get_Global_Scripts extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/get-global-scripts';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Global Header and Footer Scripts', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read the site-wide header, body and footer scripts that WPCode outputs on every page. These are the original Insert Headers and Footers fields and are stored separately from snippets. Note the body script only appears on themes that call wp_body_open.', 'acrossai-abilities-manager' );
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
			'header'         => array( 'type' => 'string' ),
			'body'           => array( 'type' => 'string' ),
			'footer'         => array( 'type' => 'string' ),
			'option_keys'    => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => __( 'The option name backing each slot, for use with the Configuration tools.', 'acrossai-abilities-manager' ),
			),
			'body_supported' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the active theme calls wp_body_open. When false the body script is stored but never rendered.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$scripts = Snippet_Repository::global_scripts();

		return array_merge(
			$scripts,
			array(
				'option_keys'    => Snippet_Repository::GLOBAL_KEYS,
				'body_supported' => (bool) current_theme_supports( 'wp-body-open' ) || did_action( 'wp_body_open' ) > 0,
			)
		);
	}
}
