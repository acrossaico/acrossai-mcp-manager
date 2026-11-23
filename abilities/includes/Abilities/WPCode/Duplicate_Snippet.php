<?php
/**
 * Feature 112 - copies a WPCode snippet, inactive.
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
 * copies a WPCode snippet, inactive.
 *
 * @since 0.0.34
 */
final class Duplicate_Snippet extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/duplicate-snippet';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Duplicate Code Snippet', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Copy an existing WPCode snippet. The copy is created inactive with Copy appended to its title, so it is safe to edit before switching on. Uses WPCode own duplicate routine, which preserves the code exactly including backslashes.', 'acrossai-abilities-manager' );
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
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
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

		/*
		 * WPCode's own duplicate() applies wp_slash() to the code before saving and forces the copy
		 * to draft, so nothing here needs to repeat either.
		 */
		$original_id = (int) $snippet->get_id();

		/*
		 * duplicate() unsets the object's own id and then saves, so after the call the SAME object
		 * carries the copy's id (class-wpcode-snippet.php:1203-1205). It also applies wp_slash() to
		 * the code first and forces the copy to draft, so nothing here repeats either. Reading the
		 * id back off the object is exact; searching for the newest wpcode post would be a guess
		 * that another request could win.
		 */
		$snippet->duplicate();

		Snippet_Repository::rebuild_cache();

		$copy_id = (int) $snippet->get_id();

		if ( $copy_id <= 0 || $copy_id === $original_id ) {
			return new WP_Error(
				'duplicate_failed',
				__( 'WPCode reported no error but did not create a copy.', 'acrossai-abilities-manager' )
			);
		}

		$copy = Snippet_Repository::find( $copy_id );

		if ( is_wp_error( $copy ) ) {
			return $copy;
		}

		$shaped = Snippet_Repository::shape( $copy );

		return array(
			'snippet'   => $shaped,
			'in_cache'  => $shaped['in_cache'],
			'safe_mode' => WPCode_Guard::safe_mode(),
			'message'   => __( 'Snippet duplicated. The copy is inactive.', 'acrossai-abilities-manager' ),
		);
	}
}
