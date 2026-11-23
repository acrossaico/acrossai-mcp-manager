<?php
/**
 * Feature 116 - lists the official translations WordPress.org offers for a bundle.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LocoTranslate;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Bundle_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * lists the official translations WordPress.org offers for a bundle.
 *
 * @since 0.0.34
 */
final class List_Available_Languages extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/list-available-languages';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Available Languages', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List the official translations WordPress.org publishes for a plugin, theme or core, with how complete each one is and whether this site already has it. Nothing is downloaded. Use it before fetch-translations to see whether an official pack exists at all - most plugins have none, and translating by hand is then the only route.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'wordpress';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'bundle' => array(
				'type'        => 'string',
				'description' => __( 'Bundle id, handle or slug. Use core for WordPress itself.', 'acrossai-abilities-manager' ),
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
			'languages'     => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'count'         => array( 'type' => 'integer' ),
			'bundle'        => array( 'type' => 'string' ),
			'has_packs'     => array(
				'type'        => 'boolean',
				'description' => __( 'False when WordPress.org publishes no translations for this bundle at all.', 'acrossai-abilities-manager' ),
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
		$bundle = Bundle_Repository::find( (string) ( $input['bundle'] ?? '' ) );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		if ( ! class_exists( 'Loco_api_WordPressTranslations' ) ) {
			return new WP_Error(
				'api_unavailable',
				__( 'Loco\'s WordPress.org translations client is not available on this site.', 'acrossai-abilities-manager' )
			);
		}

		$type    = strtolower( (string) $bundle->getType() );
		$api     = new \Loco_api_WordPressTranslations();
		$header  = $bundle->getHeaderInfo();
		$version = ( $header && isset( $header->Version ) ) ? (string) $header->Version : '';
		$args    = array( 'version' => $version );

		// The API pluralises everything except core, and keys non-core lookups on the slug.
		if ( 'core' !== $type ) {
			$args['slug'] = (string) $bundle->getSlug();
			$type        .= 's';
		}

		try {
			$result = $api->apiGet( $type, $args );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'api_request_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'The WordPress.org translations API could not be reached: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		$installed = function_exists( 'get_available_languages' ) ? (array) get_available_languages() : array();
		$rows      = array();

		foreach ( (array) ( $result['translations'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['language'] ) ) {
				continue;
			}

			$rows[] = array(
				'locale'          => (string) $row['language'],
				'english_name'    => (string) ( $row['english_name'] ?? '' ),
				'native_name'     => (string) ( $row['native_name'] ?? '' ),
				'updated'         => (string) ( $row['updated'] ?? '' ),
				'installed'       => in_array( (string) $row['language'], $installed, true ),
			);
		}

		return array(
			'languages' => $rows,
			'count'     => count( $rows ),
			'bundle'    => (string) $bundle->getId(),
			'has_packs' => ! empty( $rows ),
			'message'   => empty( $rows )
				? __( 'WordPress.org publishes no translations for this bundle. Create one with translations/create-translation-file and translate it here.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of languages. */
					_n( '%d official translation available.', '%d official translations available.', count( $rows ), 'acrossai-abilities-manager' ),
					count( $rows )
				),
		);
	}
}
