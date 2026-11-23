<?php
/**
 * Feature 106 — Get Taxonomy SEO Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-taxonomy-seo — Get Taxonomy SEO Settings.
 */
final class Get_Taxonomy_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-taxonomy-seo';
	}

	protected function ability_label(): string {
		return __( 'Get Taxonomy SEO Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every Yoast setting that applies to one taxonomy: its title and meta description templates, whether its archives are indexed, and whether the metabox shows on its terms. Distinct from taxonomies/get-term-seo, which reads one individual term.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content-types';
	}

	protected function input_properties(): array {
		return array(
			'name' => array(
				'type'        => 'string',
				'description' => __( 'The taxonomy name, e.g. category or post_tag.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'name',
		);
	}

	protected function output_properties(): array {
		return array(
			'name' => array( 'type' => 'string' ),

			'settings' => array( 'type' => 'array' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$name = (string) $input['name'];

		if ( ! taxonomy_exists( $name ) ) {
			return new WP_Error(
				'unknown_taxonomy',
				sprintf(
					/* translators: %s: name */
					__( 'No taxonomy named "%s". Call seo/list-content-type-settings to see what exists.', 'acrossai-abilities-manager' ),
					$name
				)
			);
		}

		$rows = array();

		foreach ( array( 'title-tax-', 'metadesc-tax-', 'noindex-tax-', 'display-metabox-tax-', 'social-title-tax-', 'social-description-tax-' ) as $prefix ) {
			$key  = $prefix . $name;
			$area = Settings_Repository::area_for( $key );

			if ( '' === $area ) {
				continue;
			}

			$rows[] = array(
				'key'   => $key,
				'area'  => $area,
				'value' => Settings_Repository::value( $key ),
			);
		}

		return array(
			'name'     => $name,
			'settings' => $rows,
			'message'  => sprintf(
				/* translators: 1: number of settings, 2: name */
				__( '%1$d setting(s) for "%2$s".', 'acrossai-abilities-manager' ),
				count( $rows ),
				$name
			),
		);
	}
}
