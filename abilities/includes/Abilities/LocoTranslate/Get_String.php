<?php
/**
 * Feature 116 - reads one string from a translation file.
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
 * reads one string from a translation file.
 *
 * @since 0.0.34
 */
final class Get_String extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/get-string';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Translation String', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read one string from a bundle\'s POT or translation by its exact source text, with its translation, context, source-code references and whether it is flagged fuzzy. Use it to confirm a string exists and read its current value before overwriting it.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'strings';
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
				'description' => __( 'Locale such as de_DE. Omit to address the POT template instead of a translation.', 'acrossai-abilities-manager' ),
			),
			'source'  => array(
				'type'        => 'string',
				'description' => __( 'The exact source text, as it appears in the file.', 'acrossai-abilities-manager' ),
			),
			'context' => array(
				'type'        => 'string',
				'description' => __( 'Gettext context, when the same source text appears more than once.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'bundle', 'source' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'string' => array( 'type' => 'object', 'additionalProperties' => true ),
			'file'   => array( 'type' => 'string' ),
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
		$file = $this->resolve_file( $input );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$po = Translation_Repository::load( $file );

		if ( is_wp_error( $po ) ) {
			return $po;
		}

		$source  = (string) ( $input['source'] ?? '' );
		$context = (string) ( $input['context'] ?? '' );

		foreach ( Translation_Repository::messages( $po, '', PHP_INT_MAX ) as $row ) {
			if ( $row['source'] === $source && $row['context'] === $context ) {
				return array(
					'string' => $row,
					'file'   => $file,
				);
			}
		}

		return new WP_Error(
			'unknown_string',
			sprintf(
				/* translators: %s: the source text searched for. */
				__( 'No string with that exact source text in this file. Source text must match byte for byte, including punctuation and placeholders; call translations/list-strings with a search term to find it. Searched for: %s', 'acrossai-abilities-manager' ),
				$source
			)
		);
	}
}
