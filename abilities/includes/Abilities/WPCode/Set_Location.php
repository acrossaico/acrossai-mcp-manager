<?php
/**
 * Feature 112 - places a WPCode snippet at one of the auto-insert locations.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Snippet_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * places a WPCode snippet at one of the auto-insert locations.
 *
 * @since 0.0.34
 */
final class Set_Location extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/set-location';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Set Snippet Location', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Set where a WPCode snippet is inserted: the site header, body or footer, everywhere, admin only, or around post content. Call list-locations first for the accepted values and what each one means. An unrecognised location is refused by name rather than stored, because a snippet with a location WPCode does not know simply never appears and nothing reports why.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'placement';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id'          => array(
				'type'        => 'integer',
				'description' => __( 'Snippet id.', 'acrossai-abilities-manager' ),
			),
			'location'    => array(
				'type'        => 'string',
				'enum'        => Snippet_Repository::LOCATIONS,
				'description' => __( 'Where the snippet should be inserted.', 'acrossai-abilities-manager' ),
			),
			'auto_insert' => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Whether WPCode inserts the snippet automatically at that location. Set false to keep the location but render the snippet only via its shortcode.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'id', 'location' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'snippet'   => array( 'type' => 'object', 'additionalProperties' => true ),
			'in_cache'  => array( 'type' => 'boolean' ),
			'safe_mode' => array( 'type' => 'boolean' ),
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
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$snippet = Snippet_Repository::find( (int) ( $input['id'] ?? 0 ) );

		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$placed = Snippet_Repository::place(
			$snippet,
			(string) ( $input['location'] ?? '' ),
			! array_key_exists( 'auto_insert', $input ) || (bool) $input['auto_insert']
		);

		if ( is_wp_error( $placed ) ) {
			return $placed;
		}

		return array(
			'snippet'   => $placed,
			'in_cache'  => $placed['in_cache'],
			'safe_mode' => WPCode_Guard::safe_mode(),
		);
	}
}
