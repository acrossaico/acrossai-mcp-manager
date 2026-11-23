<?php
/**
 * Feature 116 - deletes a translation and every artefact compiled from it.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LocoTranslate;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Bundle_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Translation_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * deletes a translation and every artefact compiled from it.
 *
 * @since 0.0.34
 */
final class Delete_Translation_File extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/delete-translation-file';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Delete Translation File', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Permanently delete a locale\'s translation and every artefact compiled from it - the PO, the MO, the .l10n.php cache and any JSON fragments. This cannot be undone and the translations are not recoverable, so it requires confirm: true. Deleting only the PO through the file abilities would leave the compiled files behind and the site would keep serving the deleted translations.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'files';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'bundle' => array(
				'type'        => 'string',
				'description' => __( 'Bundle id, handle or slug.', 'acrossai-abilities-manager' ),
			),
			'domain' => array(
				'type'        => 'string',
				'description' => __( 'Text domain. Omit for the bundle default.', 'acrossai-abilities-manager' ),
			),
			'locale' => array(
				'type'        => 'string',
				'description' => __( 'Locale to delete.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'bundle', 'locale' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'deleted'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'deleted_count' => array( 'type' => 'integer' ),
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
		return __( 'Deleting a translation cannot be undone - the translated strings are lost along with the compiled files. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$file = $this->resolve_file( $input );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$deleted = Translation_Repository::delete( $file );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return array(
			'deleted'       => $deleted,
			'deleted_count' => count( $deleted ),
			'message'       => sprintf(
				/* translators: %d: number of files removed. */
				_n( '%d file removed.', '%d files removed, including every compiled artefact.', count( $deleted ), 'acrossai-abilities-manager' ),
				count( $deleted )
			),
		);
	}
}
