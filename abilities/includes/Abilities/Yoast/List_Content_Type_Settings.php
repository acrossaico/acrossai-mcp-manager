<?php
/**
 * Feature 106 — List Content Type SEO Settings.
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
 * seo/list-content-type-settings — List Content Type SEO Settings.
 */
final class List_Content_Type_Settings extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/list-content-type-settings';
	}

	protected function ability_label(): string {
		return __( 'List Content Type SEO Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every public post type and taxonomy on the site with a summary of its Yoast SEO configuration: whether it is indexed, whether it shows in the admin, and whether it has a title and description template. The orientation call before tuning any single type. Yoast keys its per-content-type settings by suffix — title-{type}, metadesc-{type}, noindex-{type}, schema-page-type-{type} and so on — spread across several settings areas. This gathers the ones belonging to a single post type or taxonomy into one view, which is how the admin presents them and how a caller thinks about them.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content-types';
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
			'content_types' => array( 'type' => 'array' ),

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
		$rows = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			$rows[] = array(
				'object'        => (string) $type->name,
				'kind'          => 'post_type',
				'label'         => (string) $type->label,
				'indexed'       => ! (bool) Settings_Repository::value( 'noindex-' . $type->name ),
				'title_template'=> (string) Settings_Repository::value( 'title-' . $type->name ),
				'has_metadesc'  => '' !== (string) Settings_Repository::value( 'metadesc-' . $type->name ),
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$rows[] = array(
				'object'        => (string) $tax->name,
				'kind'          => 'taxonomy',
				'label'         => (string) $tax->label,
				'indexed'       => ! (bool) Settings_Repository::value( 'noindex-tax-' . $tax->name ),
				'title_template'=> (string) Settings_Repository::value( 'title-tax-' . $tax->name ),
				'has_metadesc'  => '' !== (string) Settings_Repository::value( 'metadesc-tax-' . $tax->name ),
			);
		}

		return array(
			'content_types' => $rows,
			'count'         => count( $rows ),
			'message'       => sprintf(
				/* translators: %d: number of content types */
				__( '%d public post types and taxonomies.', 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
