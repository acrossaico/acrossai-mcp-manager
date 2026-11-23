<?php
/**
 * Feature 116 - lists the locales in use and the ones WordPress has installed.
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
 * lists the locales in use and the ones WordPress has installed.
 *
 * @since 0.0.34
 */
final class List_Locales extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/list-locales';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Locales', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List the locales this site already has translations for, plus the languages WordPress itself has installed and the site default. Use it to discover a valid locale tag before creating a translation file, rather than guessing one that Loco will refuse.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'discovery';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'bundle' => array(
				'type'        => 'string',
				'description' => __( 'Restrict the in-use list to one bundle. Omit for the whole site.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'site_locale'       => array( 'type' => 'string' ),
			'installed'         => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Locales WordPress has language packs for.', 'acrossai-abilities-manager' ),
			),
			'in_use'            => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'description' => __( 'Locales that already have at least one PO file.', 'acrossai-abilities-manager' ),
			),
			'in_use_count'      => array( 'type' => 'integer' ),
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
		$scope = isset( $input['bundle'] ) && '' !== $input['bundle']
			? Bundle_Repository::find( (string) $input['bundle'] )
			: null;

		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$bundles = null !== $scope ? array( $scope ) : Bundle_Repository::all();

		if ( is_wp_error( $bundles ) ) {
			return $bundles;
		}

		$counts = array();

		foreach ( $bundles as $bundle ) {
			foreach ( $bundle as $project ) {
				foreach ( (array) $project->findLocaleFiles( 'po' ) as $file ) {
					$locale = Bundle_Repository::locale_from_path( (string) $file->getPath() );

					if ( '' === $locale ) {
						continue;
					}

					$counts[ $locale ] = ( $counts[ $locale ] ?? 0 ) + 1;
				}
			}
		}

		ksort( $counts );

		$rows = array();

		foreach ( $counts as $locale => $files ) {
			$rows[] = array(
				'locale'    => (string) $locale,
				'po_files'  => (int) $files,
			);
		}

		return array(
			'site_locale'  => (string) get_locale(),
			'installed'    => function_exists( 'get_available_languages' ) ? array_values( (array) get_available_languages() ) : array(),
			'in_use'       => $rows,
			'in_use_count' => count( $rows ),
		);
	}
}
