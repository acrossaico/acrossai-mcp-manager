<?php
/**
 * Feature 106 — Update System Page SEO.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-system-page-seo — Update System Page SEO.
 */
final class Update_System_Page_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/update-system-page-seo';
	}

	protected function ability_label(): string {
		return __( 'Update System Page SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the SEO title templates for the search results and 404 pages. These have no content of their own, so the template is the only thing that decides what a search engine or a browser tab shows.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-indexables';
	}

	protected function is_writer(): bool {
		return true;
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-system-page-seo',
		);
	}

	protected function input_properties(): array {
		return array(
			'page' => array(
				'type'        => 'string',
				'enum'        => array( 'search', '404' ),
				'description' => __( 'Which system page.', 'acrossai-abilities-manager' ),
			),

			'title' => array(
				'type'        => 'string',
				'description' => __( 'SEO title template.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'page',
			'title',
		);
	}

	protected function output_properties(): array {
		return array(
			'page' => array( 'type' => 'string' ),

			'updated' => array( 'type' => 'array' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$page = (string) $input['page'];
		$key  = 'search' === $page ? 'title-search-wpseo' : 'title-404-wpseo';

		$updated = Settings_Repository::write(
			'title-templates',
			array( $key => Slash_Input::slash( (string) $input['title'], $input ) )
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'page'    => $page,
			'updated' => $updated,
			'message' => sprintf(
				/* translators: %s: system page */
				__( 'Updated the %s page title template.', 'acrossai-abilities-manager' ),
				$page
			),
		);
	}
}
