<?php
/**
 * Feature 106 — Update Post Type Archive SEO.
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
 * seo/update-post-type-archive-seo — Update Post Type Archive SEO.
 */
final class Update_Post_Type_Archive_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/update-post-type-archive-seo';
	}

	protected function ability_label(): string {
		return __( 'Update Post Type Archive SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change the SEO title and meta description templates for one post type\'s archive, and whether that archive is indexed. Only applies to post types registered with has_archive.',
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
			'seo/get-post-type-archive-seo',
		);
	}

	protected function input_properties(): array {
		return array(
			'post_type' => array(
				'type'        => 'string',
				'description' => __( 'The post type name.', 'acrossai-abilities-manager' ),
			),

			'title' => array(
				'type'        => 'string',
				'description' => __( 'SEO title template for the archive.', 'acrossai-abilities-manager' ),
			),

			'meta_description' => array(
				'type'        => 'string',
				'description' => __( 'Meta description template for the archive.', 'acrossai-abilities-manager' ),
			),

			'noindex' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether to keep the archive out of search results.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'post_type',
		);
	}

	protected function output_properties(): array {
		return array(
			'post_type' => array( 'type' => 'string' ),

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
		$type = (string) $input['post_type'];

		if ( ! post_type_exists( $type ) ) {
			return new WP_Error(
				'unknown_post_type',
				sprintf( /* translators: %s: post type */ __( 'No post type named "%s".', 'acrossai-abilities-manager' ), $type )
			);
		}

		$templates = array();
		$archives  = array();

		if ( isset( $input['title'] ) ) {
			$templates[ 'title-ptarchive-' . $type ] = Slash_Input::slash( (string) $input['title'], $input );
		}

		if ( isset( $input['meta_description'] ) ) {
			$templates[ 'metadesc-ptarchive-' . $type ] = Slash_Input::slash( (string) $input['meta_description'], $input );
		}

		if ( isset( $input['noindex'] ) ) {
			$archives[ 'noindex-ptarchive-' . $type ] = (bool) $input['noindex'];
		}

		if ( array() === $templates && array() === $archives ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one of title, meta_description or noindex.', 'acrossai-abilities-manager' ) );
		}

		$updated = array();

		foreach ( array( 'title-templates' => $templates, 'archives' => $archives ) as $area => $patch ) {
			if ( array() === $patch ) {
				continue;
			}

			$written = Settings_Repository::write( (string) $area, $patch );

			if ( is_wp_error( $written ) ) {
				return $written;
			}

			$updated = array_merge( $updated, $written );
		}

		return array(
			'post_type' => $type,
			'updated'   => $updated,
			'message'   => sprintf(
				/* translators: 1: comma-separated keys, 2: post type */
				__( 'Updated %1$s on the %2$s archive.', 'acrossai-abilities-manager' ),
				implode( ', ', $updated ),
				$type
			),
		);
	}
}
