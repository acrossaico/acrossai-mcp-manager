<?php
/**
 * Feature 106 — Get Sitemap Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Sitemap_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-sitemap-settings — Get Sitemap Settings.
 */
final class Get_Sitemap_Settings extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-sitemap-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Sitemap Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The settings that shape the XML sitemap: whether it is enabled, and which content types and taxonomies are indexed and therefore included.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-sitemap';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/update-sitemap-settings',
		);
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'settings' => array( 'type' => 'array' ),

			'coverage' => array( 'type' => 'array' ),
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
		return array(
			'settings' => array(
				array(
					'key'   => 'enable_xml_sitemap',
					'value' => (bool) Settings_Repository::value( 'enable_xml_sitemap' ),
					'area'  => 'general',
				),
			),
			'coverage' => Sitemap_Repository::coverage(),
			'message'  => __( 'Sitemap settings and coverage.', 'acrossai-abilities-manager' ),
		);
	}
}
