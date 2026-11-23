<?php
/**
 * Feature 106 — Get Robots Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-robots-settings — Get Robots Settings.
 */
final class Get_Robots_Settings extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-robots-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Robots Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Everything affecting what crawlers are told: whether WordPress itself is discouraging search engines (the setting that silently noindexes an entire site), and Yoast\'s per-content-type noindex flags. The first place to look when a site has vanished from search.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-tools';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/update-robots-settings',
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
			'discourage_search_engines' => array( 'type' => 'boolean' ),

			'noindex' => array( 'type' => 'array' ),
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
		$rows = array();

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			$rows[] = array(
				'object'  => (string) $type,
				'kind'    => 'post_type',
				'noindex' => (bool) Settings_Repository::value( 'noindex-' . $type ),
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$rows[] = array(
				'object'  => (string) $tax,
				'kind'    => 'taxonomy',
				'noindex' => (bool) Settings_Repository::value( 'noindex-tax-' . $tax ),
			);
		}

		$discouraged = '0' === (string) get_option( 'blog_public' );

		return array(
			'discourage_search_engines' => $discouraged,
			'noindex'                   => $rows,
			'message'                   => $discouraged
				? __( 'WordPress is set to discourage search engines. Nothing Yoast does will get this site indexed until that is turned off.', 'acrossai-abilities-manager' )
				: __( 'Search engines are not discouraged site-wide.', 'acrossai-abilities-manager' ),
		);
	}
}
