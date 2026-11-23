<?php
/**
 * Feature 112 - reads one WPCode snippet in full.
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
 * reads one WPCode snippet in full.
 *
 * @since 0.0.34
 */
final class Get_Snippet extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/get-snippet';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Code Snippet', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read one WPCode snippet by id: its code, code type, active state, auto-insert location, priority, tags, conditional logic and the last error WPCode recorded for it. Also reports whether the loader cache holds it, which is what decides whether it actually runs.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'snippets';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id' => array(
				'type'        => 'integer',
				'description' => __( 'Snippet id.', 'acrossai-abilities-manager' ),
			),
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
			'snippet'   => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => __( 'The snippet as it now stands, read back after the write.', 'acrossai-abilities-manager' ),
			),
			'in_cache'  => array(
				'type'        => 'boolean',
				'description' => __( 'Whether WPCode\'s loader cache now holds this snippet. This, not the database row, is what decides whether it actually runs.', 'acrossai-abilities-manager' ),
			),
			'safe_mode' => array(
				'type'        => 'boolean',
				'description' => __( 'True when safe mode is suppressing every snippet on this site, in which case nothing runs regardless of this change.', 'acrossai-abilities-manager' ),
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
		$snippet = Snippet_Repository::find( (int) ( $input['id'] ?? 0 ) );

		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$shaped = Snippet_Repository::shape( $snippet );

		return array(
			'snippet'   => $shaped,
			'in_cache'  => $shaped['in_cache'],
			'safe_mode' => WPCode_Guard::safe_mode(),
		);
	}
}
