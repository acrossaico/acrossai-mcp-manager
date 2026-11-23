<?php
/**
 * Feature 106 — Get Sitemap Status.
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
 * seo/get-sitemap-status — Get Sitemap Status.
 */
final class Get_Sitemap_Status extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-sitemap-status';
	}

	protected function ability_label(): string {
		return __( 'Get Sitemap Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Whether Yoast is serving XML sitemaps and where the index lives. The first call when a sitemap is missing from Search Console — a disabled sitemap and a broken one look identical from outside.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-sitemap';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-sitemap-settings',
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
			'status' => array( 'type' => 'object' ),
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
		$status = Sitemap_Repository::status();

		return array(
			'status'  => $status,
			'message' => $status['enabled']
				? sprintf( /* translators: %s: sitemap index URL */ __( 'Sitemaps are on at %s.', 'acrossai-abilities-manager' ), $status['index_url'] )
				: __( 'XML sitemaps are switched off.', 'acrossai-abilities-manager' ),
		);
	}
}
