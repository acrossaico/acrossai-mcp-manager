<?php
/**
 * Feature 116 - rebuilds a POT template by scanning the bundle source.
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
 * rebuilds a POT template by scanning the bundle source.
 *
 * @since 0.0.34
 */
final class Extract_Strings extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/extract-strings';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Extract Strings To Template', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Scan a bundle\'s source code for translatable strings and write a fresh POT template. Requires confirm: true because replacing a template changes what every existing translation is measured against: strings that no longer appear are dropped on the next sync, and translations attached to them go with them. Files Loco skipped for being larger than its size limit are reported, since a template can otherwise look complete while missing every string in the biggest file.', 'acrossai-abilities-manager' );
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
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'bundle' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'file'          => array( 'type' => 'string' ),
			'string_count'  => array( 'type' => 'integer' ),
			'skipped_files' => array(
				'type'        => 'integer',
				'description' => __( 'Source files Loco refused to scan because they exceed its size limit. Any string in them is missing from this template.', 'acrossai-abilities-manager' ),
			),
			'bytes'         => array( 'type' => 'integer' ),
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
		return __( 'Rebuilding the template changes what every existing translation is measured against: any string no longer found in the source is dropped on the next sync, and its translation with it. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$bundle = Bundle_Repository::find( (string) ( $input['bundle'] ?? '' ) );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$project = $this->resolve_project( $input );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$extracted = Translation_Repository::extract( $bundle, $project );

		if ( is_wp_error( $extracted ) ) {
			return $extracted;
		}

		$pot = $project->getPot();

		if ( ! $pot ) {
			return new WP_Error(
				'no_target_directory',
				__( 'Loco has no configured location for this text domain\'s template.', 'acrossai-abilities-manager' )
			);
		}

		$path = (string) $pot->getPath();

		// A template has no locale, so nothing compiles from it: no MO, no cache, no JSON. Written
		// through the repository all the same, so one method owns every write to a gettext file.
		$written = Translation_Repository::write_template( $path, $extracted['po'] );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'file'          => $path,
			'string_count'  => (int) $extracted['string_count'],
			'skipped_files' => (int) $extracted['skipped_files'],
			'bytes'         => (int) $written,
			'message'       => $extracted['skipped_files'] > 0
				? sprintf(
					/* translators: 1: string count, 2: skipped file count. */
					__( 'Template rebuilt with %1$d strings, but %2$d source file(s) were skipped for exceeding Loco\'s size limit — any string in them is missing. Raise that limit in Loco\'s settings and run this again if the count looks low.', 'acrossai-abilities-manager' ),
					(int) $extracted['string_count'],
					(int) $extracted['skipped_files']
				)
				: sprintf(
					/* translators: %d: string count. */
					__( 'Template rebuilt with %d strings.', 'acrossai-abilities-manager' ),
					(int) $extracted['string_count']
				),
		);
	}
}
