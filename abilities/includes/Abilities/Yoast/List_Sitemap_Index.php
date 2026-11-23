<?php
/**
 * Feature 106 — List Sitemap Coverage.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Sitemap_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/list-sitemap-index — List Sitemap Coverage.
 */
final class List_Sitemap_Index extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/list-sitemap-index';
	}

	protected function ability_label(): string {
		return __( 'List Sitemap Coverage', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Which post types and taxonomies are included in the XML sitemap and which are left out. Yoast has no separate sitemap-exclusion list — it excludes whatever is noindexed — so a content type missing from the sitemap is really a noindex setting, which is worth knowing before hunting for a switch that does not exist.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-sitemap';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/update-archive-settings',
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
			'coverage' => array( 'type' => 'array' ),

			'count' => array( 'type' => 'integer' ),
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
		$rows = Sitemap_Repository::coverage();

		return array(
			'coverage' => $rows,
			'count'    => count( $rows ),
			'message'  => sprintf(
				/* translators: 1: included count, 2: total */
				__( '%1$d of %2$d content types are in the sitemap.', 'acrossai-abilities-manager' ),
				count( array_filter( $rows, static fn( array $r ): bool => (bool) $r['included'] ) ),
				count( $rows )
			),
		);
	}
}
