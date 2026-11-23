<?php
/**
 * Feature 116 - recompiles the artefacts WordPress loads from an existing PO.
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
 * recompiles the artefacts WordPress loads from an existing PO.
 *
 * @since 0.0.34
 */
final class Compile_Translations extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/compile-translations';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Recompile Translations', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Recompile the MO file, the .l10n.php cache and the JSON fragments from a translation\'s PO, without changing a single string. This is the repair for a PO that was edited by hand or by the file abilities: those edits leave the compiled files stale, so the site keeps serving the old strings with no error anywhere. Run it whenever a translation looks correct in the PO but wrong on the site.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'maintenance';
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
				'description' => __( 'Locale to recompile.', 'acrossai-abilities-manager' ),
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
			'artefacts' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => __( 'Bytes written per artefact, read back from disk.', 'acrossai-abilities-manager' ),
			),
			'file'         => array( 'type' => 'string' ),
			'string_count' => array( 'type' => 'integer' ),
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
		$file = $this->resolve_file( $input );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$project = $this->resolve_project( $input );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$po = Translation_Repository::load( $file );

		if ( is_wp_error( $po ) ) {
			return $po;
		}

		// Written back unchanged. write() re-runs the whole compile chain and then proves each
		// artefact landed, which is the entire job here.
		$written = Translation_Repository::write( $file, $po, $project );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'artefacts'    => $written,
			'file'         => $file,
			'string_count' => count( Translation_Repository::messages( $po, '', PHP_INT_MAX ) ),
			'message'      => __( 'Recompiled. The MO and, where this WordPress uses it, the .l10n.php cache now match the PO.', 'acrossai-abilities-manager' ),
		);
	}
}
