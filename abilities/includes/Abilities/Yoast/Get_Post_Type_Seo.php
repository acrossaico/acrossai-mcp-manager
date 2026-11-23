<?php
/**
 * Feature 106 — Get Post Type SEO Settings.
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
 * seo/get-post-type-seo — Get Post Type SEO Settings.
 */
final class Get_Post_Type_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-post-type-seo';
	}

	protected function ability_label(): string {
		return __( 'Get Post Type SEO Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every Yoast setting that applies to one post type: its title and meta description templates, whether it is indexed, whether the metabox and schema controls show, and its default schema page and article types. Yoast keys its per-content-type settings by suffix — title-{type}, metadesc-{type}, noindex-{type}, schema-page-type-{type} and so on — spread across several settings areas. This gathers the ones belonging to a single post type or taxonomy into one view, which is how the admin presents them and how a caller thinks about them.',
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
				'description' => __( 'The post type name, e.g. post or page.', 'acrossai-abilities-manager' ),
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

		if ( ! post_type_exists( $name ) ) {
			return new WP_Error(
				'unknown_post_type',
				sprintf(
					/* translators: %s: name */
					__( 'No post type named "%s". Call seo/list-content-type-settings to see what exists.', 'acrossai-abilities-manager' ),
					$name
				)
			);
		}

		$rows = array();

		foreach ( array( 'title-', 'metadesc-', 'noindex-', 'display-metabox-pt-', 'schema-page-type-', 'schema-article-type-', 'social-title-', 'social-description-', 'social-image-url-' ) as $prefix ) {
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
