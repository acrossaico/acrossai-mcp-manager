<?php
/**
 * Feature 106 — Get Home Page SEO.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Indexable_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-homepage-seo — Get Home Page SEO.
 */
final class Get_Homepage_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-homepage-seo';
	}

	protected function ability_label(): string {
		return __( 'Get Home Page SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The SEO title, description, canonical and social tags Yoast produces for the site front page. When a static page is the front page these come from that page; otherwise they come from the site-wide templates, which is why editing "the home page" sometimes appears to do nothing.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-indexables';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-seo-settings',
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
			'indexable' => array( 'type' => 'object' ),
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
		$row = Indexable_Repository::meta_for( 'home-page', '' );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return array(
			'indexable' => $row,
			'message'   => __( 'Resolved.', 'acrossai-abilities-manager' ),
		);
	}
}
