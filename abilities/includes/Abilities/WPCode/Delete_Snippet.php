<?php
/**
 * Feature 112 - permanently deletes a WPCode snippet and clears it from the loader cache.
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
 * permanently deletes a WPCode snippet and clears it from the loader cache.
 *
 * @since 0.0.34
 */
final class Delete_Snippet extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/delete-snippet';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Delete Code Snippet', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Permanently delete a WPCode snippet. This cannot be undone, so it requires confirm: true. Prefer deactivate-snippet, which stops the code running while keeping it. WordPress delete alone would leave the snippet in WPCode loader cache and it could keep running, so this rebuilds the cache and verifies the snippet is gone from it before reporting success.', 'acrossai-abilities-manager' );
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
			'deleted_id' => array( 'type' => 'integer' ),
			'title'      => array( 'type' => 'string' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => false );
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
		return __( 'Deleting a snippet cannot be undone. Pass confirm: true to proceed, or use deactivate-snippet to stop it running while keeping the code.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$id      = (int) ( $input['id'] ?? 0 );
		$snippet = Snippet_Repository::find( $id );

		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$title   = (string) $snippet->get_title();
		$deleted = Snippet_Repository::delete( $id );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return array(
			'deleted_id' => $id,
			'title'      => $title,
		);
	}
}
