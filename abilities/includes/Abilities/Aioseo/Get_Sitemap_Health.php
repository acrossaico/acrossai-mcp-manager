<?php
/**
 * Feature 132 — live AIOSEO sitemap state.
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
 * Ability — aioseo/get-sitemap-health.
 *
 * AIOSEO ships no sitemap ability, and `aioseo-audit/site-get` reports only an on/off flag. So the
 * question people actually ask — "the sitemap is enabled, why does it 404?" — had no answer here.
 *
 * Returns configuration AND live state: which post types and taxonomies are really included, whether
 * the index is served and answers with XML, whether AIOSEO is writing static files or generating on
 * request, and a plain reading of what is wrong when something is.
 *
 * Read-only and idempotent, but NOT free: by default it makes one loopback HTTP request to this
 * site's own sitemap index. Pass fetch_index=false to skip it.
 *
 * @since 0.0.44
 */
class Get_Sitemap_Health extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'get-sitemap-health';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get All in One SEO Sitemap Health', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report the live state of All in One SEO\'s sitemaps: whether the XML sitemap is on, its index URL, which post types and taxonomies are actually included, the links-per-index limit, whether the RSS, HTML and llms.txt sitemaps are on, whether AIOSEO is serving static files or generating on request, and — unless you pass fetch_index=false — a real fetch of the index reporting its HTTP status and whether XML came back. Use this when the sitemap looks correctly configured and still 404s: stale rewrite rules and an intercepting server or security rule are the usual causes, and the response names which it looks like. AIOSEO has no sitemap ability of its own.', 'acrossai-abilities-manager' );
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
			'fetch_index' => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Fetch the sitemap index over HTTP to confirm it is really served. Set false to report configuration only and make no request.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'enabled'          => array( 'type' => 'boolean' ),
			'index_url'        => array( 'type' => 'string' ),
			'sitemap_urls'     => array( 'type' => 'array' ),
			'post_types'       => array( 'type' => 'array' ),
			'taxonomies'       => array( 'type' => 'array' ),
			'indexes_enabled'  => array( 'type' => 'boolean' ),
			'links_per_index'  => array( 'type' => 'integer' ),
			'filename'         => array( 'type' => 'string' ),
			'author_sitemap'   => array( 'type' => 'boolean' ),
			'date_sitemap'     => array( 'type' => 'boolean' ),
			'static_file_mode' => array( 'type' => 'string' ),
			'rss_enabled'      => array( 'type' => 'boolean' ),
			'html_enabled'     => array( 'type' => 'boolean' ),
			'llms_enabled'     => array( 'type' => 'boolean' ),
			'index_check'      => array( 'type' => array( 'object', 'null' ) ),
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
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
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
		$fetch = ! isset( $input['fetch_index'] ) || (bool) $input['fetch_index'];

		return Sitemap_Repository::health( $fetch );
	}
}
