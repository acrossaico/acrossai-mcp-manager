<?php
/**
 * Feature 116 - lists the strings in a translation file.
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
 * lists the strings in a translation file.
 *
 * @since 0.0.34
 */
final class List_Strings extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/list-strings';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Translation Strings', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List the strings in a bundle\'s POT template or one of its translations, with the source text, the current translation, any context and the source-code references. Filter to untranslated or fuzzy to find the work. Strings are addressed by their source text and context, never by position, because a sync reorders everything and a stored index would then point at the wrong string.', 'acrossai-abilities-manager' );
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
			'filter'   => array(
				'type'        => 'string',
				'enum'        => array( 'all', 'untranslated', 'fuzzy' ),
				'default'     => 'all',
				'description' => __( 'Narrow to strings needing work.', 'acrossai-abilities-manager' ),
			),
			'search'   => array(
				'type'        => 'string',
				'description' => __( 'Case-insensitive match against source or translation.', 'acrossai-abilities-manager' ),
			),
			'per_page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 500,
				'default'     => 100,
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
			'strings' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'count'   => array( 'type' => 'integer' ),
			'total'   => array( 'type' => 'integer' ),
			'file'    => array( 'type' => 'string' ),
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

		$filter = isset( $input['filter'] ) ? (string) $input['filter'] : 'all';
		$all    = Translation_Repository::messages( $po, 'all' === $filter ? '' : $filter, PHP_INT_MAX );
		$needle = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';

		if ( '' !== $needle ) {
			$all = array_values(
				array_filter(
					$all,
					static function ( array $row ) use ( $needle ): bool {
						return false !== strpos( strtolower( $row['source'] . ' ' . $row['target'] ), $needle );
					}
				)
			);
		}

		$limit = isset( $input['per_page'] ) ? (int) $input['per_page'] : 100;

		return array(
			'strings' => array_slice( $all, 0, $limit ),
			'count'   => min( count( $all ), $limit ),
			'total'   => count( $all ),
			'file'    => $file,
		);
	}
}
