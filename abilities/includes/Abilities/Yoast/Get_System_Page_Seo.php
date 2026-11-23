<?php
/**
 * Feature 106 — Get Search Page SEO.
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
 * seo/get-system-page-seo — Get Search Page SEO.
 */
final class Get_System_Page_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-system-page-seo';
	}

	protected function ability_label(): string {
		return __( 'Get Search Page SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The SEO Yoast produces for the search results page — one of the two system pages, alongside the 404, that have no content of their own and take their title entirely from a template.',
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
		$row = Indexable_Repository::meta_for( 'search', '' );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return array(
			'indexable' => $row,
			'message'   => __( 'Resolved.', 'acrossai-abilities-manager' ),
		);
	}
}
