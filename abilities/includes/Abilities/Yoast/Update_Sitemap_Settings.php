<?php
/**
 * Feature 106 — Update Sitemap Settings.
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
 * seo/update-sitemap-settings — Update Sitemap Settings.
 */
final class Update_Sitemap_Settings extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/update-sitemap-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Sitemap Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Turn the XML sitemap on or off. To change WHICH content appears in it, change the indexing settings for that post type or taxonomy with seo/update-archive-settings — Yoast derives sitemap membership from noindex rather than keeping a separate list.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-sitemap';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-sitemap-status',
		);
	}

	protected function input_properties(): array {
		return array(
			'enabled' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether XML sitemaps are served.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'enabled',
		);
	}

	protected function output_properties(): array {
		return array(
			'enabled' => array( 'type' => 'boolean' ),

			'changed' => array( 'type' => 'boolean' ),
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
		$enabled = ! empty( $input['enabled'] );
		$written = Settings_Repository::write( 'general', array( 'enable_xml_sitemap' => $enabled ) );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'enabled' => (bool) Settings_Repository::value( 'enable_xml_sitemap' ),
			'changed' => array() !== $written,
			'message' => $enabled
				? __( 'XML sitemaps are on.', 'acrossai-abilities-manager' )
				: __( 'XML sitemaps are off. Search engines will stop discovering new content through them.', 'acrossai-abilities-manager' ),
		);
	}
}
