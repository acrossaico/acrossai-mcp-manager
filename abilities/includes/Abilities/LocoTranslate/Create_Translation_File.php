<?php
/**
 * Feature 116 - starts a new translation for a bundle and locale.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LocoTranslate;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Bundle_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Loco_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Translation_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * starts a new translation for a bundle and locale.
 *
 * @since 0.0.34
 */
final class Create_Translation_File extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/create-translation-file';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Create Translation File', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Start a new translation for a bundle in a given locale, seeded from its POT template so every translatable string is present and empty. Loco decides where the file belongs - which depends on the text domain and whether the bundle is a theme - so this does not take a path. Refuses if a translation for that locale already exists, rather than overwriting work.', 'acrossai-abilities-manager' );
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
				'description' => __( 'Locale to create, such as de_DE. Call translations/list-locales for valid tags.', 'acrossai-abilities-manager' ),
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
				'description'          => __( 'Bytes written per artefact: po_bytes, mo_bytes, php_bytes, json_files, json_bytes. Read back from disk, not reported by Loco - a zero mo_bytes means the site is still serving the old strings.', 'acrossai-abilities-manager' ),
			),
			'file'          => array( 'type' => 'string' ),
			'string_count'  => array( 'type' => 'integer' ),
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
		$existing = $this->resolve_file( $input );

		if ( ! is_wp_error( $existing ) ) {
			return new WP_Error(
				'translation_file_exists',
				sprintf(
					/* translators: %s: file path. */
					__( 'A translation already exists at %s. Use translations/update-strings to change it, or delete it first.', 'acrossai-abilities-manager' ),
					$existing
				)
			);
		}

		if ( 'unknown_translation_file' !== $existing->get_error_code() ) {
			return $existing;
		}

		$target = $this->resolve_file( $input, false );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$project = $this->resolve_project( $input );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$locale = Loco_Guard::parse_locale( (string) ( $input['locale'] ?? '' ) );

		if ( is_wp_error( $locale ) ) {
			return $locale;
		}

		$pot = $project->getPot();

		if ( ! $pot || ! $pot->exists() ) {
			return new WP_Error(
				'no_pot',
				__( 'This text domain has no POT template to seed a translation from. Run translations/extract-strings first to build one from the source.', 'acrossai-abilities-manager' )
			);
		}

		$template = Translation_Repository::load( (string) $pot->getPath() );

		if ( is_wp_error( $template ) ) {
			return $template;
		}

		// localize() converts a template into a translation for one locale: it rewrites the headers,
		// sets the plural rules for that language, and leaves every target empty.
		$po      = $template->localize( $locale );
		$written = Translation_Repository::write( $target, $po, $project );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'artefacts'    => $written,
			'file'         => $target,
			'string_count' => count( Translation_Repository::messages( $po, '', PHP_INT_MAX ) ),
			'message'      => sprintf(
				/* translators: 1: locale, 2: file path. */
				__( 'Created an empty %1$s translation at %2$s. Use translations/update-strings to fill it in.', 'acrossai-abilities-manager' ),
				(string) $input['locale'],
				$target
			),
		);
	}
}
