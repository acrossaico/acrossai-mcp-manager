<?php
/**
 * Feature 132 — rebuild AIOSEO's static sitemap files.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Sitemap_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability — aioseo/regenerate-sitemap.
 *
 * The companion to a settings write. On a site in static-file mode, changing which post types are
 * included does not change what is served until the files are rebuilt, and nothing in AIOSEO's own
 * abilities can ask for that.
 *
 * Reports honestly when there is nothing to do: on a dynamically-generated sitemap this returns
 * `scheduled: false` with the reason, rather than scheduling work that changes nothing — which would
 * read as "done" and send the caller looking in the wrong place for a stale sitemap.
 *
 * @since 0.0.44
 */
class Regenerate_Sitemap extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'regenerate-sitemap';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Regenerate All in One SEO Sitemap', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Rebuild All in One SEO\'s static sitemap files so the next request reflects the current settings. Use it after changing which post types or taxonomies are included. By default the rebuild is scheduled, which is what AIOSEO does after its own settings changes; pass immediate=true to rebuild inside this request, which can be slow on a large site. On a site that generates its sitemap on request there is nothing to rebuild and this says so rather than reporting work it did not do.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function sub_group(): string {
		return 'aioseo-sitemap';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function aioseo_cap(): string {
		return 'aioseo_sitemap_settings';
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'immediate' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Rebuild now instead of scheduling it. Slow on a large site.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'mode'      => array( 'type' => 'string' ),
			'scheduled' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * Not destructive: it rewrites derived files from the current settings and loses nothing a
	 * setting does not already describe.
	 *
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Sitemap_Repository::regenerate( ! empty( $input['immediate'] ) );
	}
}
