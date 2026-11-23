<?php
/**
 * Feature 106 — Update Home Page SEO.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Indexable_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-homepage-seo — Update Home Page SEO.
 */
final class Update_Homepage_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/update-homepage-seo';
	}

	protected function ability_label(): string {
		return __( 'Update Home Page SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the SEO title and meta description templates used for the site front page. Note these apply when the front page shows the latest posts; if a static page is set as the front page, its own per-page SEO wins and yoast-seo/update-post-seo-data is the ability to use instead.',
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
			'seo/get-homepage-seo',
		);
	}

	protected function input_properties(): array {
		return array(
			'title' => array(
				'type'        => 'string',
				'description' => __( 'SEO title template for the front page. Supports Yoast variables such as %%sitename%%.', 'acrossai-abilities-manager' ),
			),

			'meta_description' => array(
				'type'        => 'string',
				'description' => __( 'Meta description template for the front page.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'updated' => array( 'type' => 'array' ),

			'indexable' => array( 'type' => 'object' ),
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
		$patch = array();

		if ( isset( $input['title'] ) ) {
			$patch['title-home-wpseo'] = Slash_Input::slash( (string) $input['title'], $input );
		}

		if ( isset( $input['meta_description'] ) ) {
			$patch['metadesc-home-wpseo'] = Slash_Input::slash( (string) $input['meta_description'], $input );
		}

		if ( array() === $patch ) {
			return new WP_Error( 'invalid_input', __( 'Supply a title or a meta_description.', 'acrossai-abilities-manager' ) );
		}

		$updated = Settings_Repository::write( 'title-templates', $patch );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$row = Indexable_Repository::meta_for( 'home-page' );

		return array(
			'updated'   => $updated,
			'indexable' => is_wp_error( $row ) ? null : $row,
			'message'   => sprintf(
				/* translators: %s: comma-separated keys */
				__( 'Updated %s on the front page.', 'acrossai-abilities-manager' ),
				implode( ', ', $updated )
			),
		);
	}
}
