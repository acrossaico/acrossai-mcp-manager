<?php
/**
 * Feature 112 - pulls the newer library version of an installed snippet.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Library_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * pulls the newer library version of an installed snippet.
 *
 * @since 0.0.34
 */
final class Update_Snippet_From_Library extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/update-snippet-from-library';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Snippet From Library', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Replace an installed snippet with the newer version from the WPCode library. This overwrites the code completely, so any local edit to that snippet is lost, which is why it requires confirm: true. The snippet keeps whatever active state it had here: WPCode own updater takes that from the library payload and would otherwise switch a deliberately disabled snippet back on as a side effect of an update.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'library';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id' => array(
				'type'        => 'integer',
				'description' => __( 'Local snippet id, from list-snippet-updates.', 'acrossai-abilities-manager' ),
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
		return __( 'This replaces the snippet code with the library version. Any local edit to it will be lost. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return array<int, array<string, string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'snippets/list-snippet-updates',
				'reason' => __( 'Lists which installed snippets actually have a newer version, so you update only what is stale.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'   => 'snippets/deactivate-snippet',
				'reason' => __( 'If an update breaks something, switching the snippet off stops it running without deleting it.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$updated = Library_Repository::pull_update( (int) ( $input['id'] ?? 0 ) );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'snippet'   => $updated,
			'in_cache'  => $updated['in_cache'],
			'safe_mode' => WPCode_Guard::safe_mode(),
			'message'   => __( 'Snippet updated from the library. Its active state on this site was preserved.', 'acrossai-abilities-manager' ),
		);
	}
}
