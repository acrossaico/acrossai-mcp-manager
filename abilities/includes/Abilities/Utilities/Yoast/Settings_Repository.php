<?php
/**
 * Feature 106 — every Yoast SEO setting the suite reads or writes.
 *
 * All persistence goes through `WPSEO_Options::set()`, Yoast's own accessor. Each option group has
 * its own validation class under `inc/options/class-wpseo-option-*.php` — `wpseo_titles` alone
 * validates 129 keys with per-key rules — and a bare `update_option( 'wpseo_titles', … )` bypasses
 * every one of them. Yoast then either rejects the value on its next read or misinterprets it.
 *
 * `AREAS` is the writable universe, and it is deliberately narrower than Yoast's own. 214 of the 280
 * keys appear, carved into fourteen areas by the admin screen they belong to. The other 66 are
 * Yoast's internal bookkeeping — `indexing_*`, `import_cursors`, `last_known_*`, `dismiss_*_notice`,
 * `myyoast-oauth`, the `*_ignore_list` arrays — and writing those corrupts state rather than
 * configuring anything, so a key outside `AREAS` is refused with `setting_not_writable`.
 *
 * Each entry carries the key's JSON type and its option group, both derived from Yoast's own
 * `get_defaults()` rather than hand-listed, so a Yoast release that retypes a key is a regenerate
 * rather than a hunt.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over Yoast's settings API.
 */
final class Settings_Repository {

	/**
	 * Memoised result of areas().
	 *
	 * @since 0.0.34
	 * @var   array<string, array<string, array{0:string,1:string}>>|null
	 */
	private static $areas_cache = null;


	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Writable settings, grouped by area, as key => [ json type, Yoast option group ].
	 *
	 * @since  0.0.34
	 * @return array<string, array<string, array{0:string,1:string}>>
	 */
	/**
	 * Drop the memoised area map.
	 *
	 * areas() caches for the request because it walks the live wpseo_titles option. Abilities run on
	 * wp_abilities_api_init, well after init, so the registered post types are settled by then and the
	 * cache is safe. Anything that registers a post type or taxonomy afterwards must call this, or the
	 * new type's keys stay invisible for the rest of the request.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function flush(): void {
		self::$areas_cache = null;
	}

	public static function areas(): array {
		if ( null !== self::$areas_cache ) {
			return self::$areas_cache;
		}

		$blocked = self::disallowed();
		$areas   = array();

		foreach ( self::declared_areas() as $area => $keys ) {
			$areas[ $area ] = array_diff_key( $keys, array_flip( $blocked ) );
		}

		foreach ( self::dynamic_keys() as $key => $meta ) {
			if ( in_array( $key, $blocked, true ) ) {
				continue;
			}

			// Never let a derived key shadow a declared one: the literal map is authoritative
			// wherever the two overlap.
			foreach ( $areas as $existing ) {
				if ( isset( $existing[ $key ] ) ) {
					continue 2;
				}
			}

			$areas[ $meta[2] ][ $key ] = array( $meta[0], $meta[1] );
		}

		self::$areas_cache = $areas;

		return self::$areas_cache;
	}

	/**
	 * The per-post-type and per-taxonomy option keys, derived from the live install.
	 *
	 * `WPSEO_Option_Titles::enrich_defaults()` mints a family of keys for every accessible post type
	 * and public taxonomy — `title-{pt}`, `noindex-tax-{tax}`, `title-ptarchive-{pt}` and the rest.
	 * The literal map in `declared_areas()` was machine-derived from one install's defaults, so it
	 * froze whichever types existed that day: post, page, attachment, category, post_tag. Every
	 * custom post type and taxonomy was unwritable, and because no post type on that install had an
	 * archive, the map contained no `*-ptarchive-*` key at all — `seo/update-post-type-archive-seo`
	 * could not write anything on any site. Reading the option back at runtime is the only thing
	 * that tracks what the site actually registered.
	 *
	 * Families are matched longest-prefix-first, so `title-ptarchive-guide` resolves as an archive
	 * title rather than a post type named "ptarchive-guide".
	 *
	 * @since  0.0.34
	 * @return array<string, array{0:string,1:string,2:string}> key => [ type, option group, area ]
	 */
	private static function dynamic_keys(): array {
		if ( ! class_exists( '\WPSEO_Options' ) ) {
			return array();
		}

		$families = array(
			'social-description-ptarchive-' => array( 'string', 'social-defaults' ),
			'social-image-url-ptarchive-'   => array( 'string', 'social-defaults' ),
			'social-image-id-ptarchive-'    => array( 'integer', 'social-defaults' ),
			'social-title-ptarchive-'       => array( 'string', 'social-defaults' ),
			'metadesc-ptarchive-'           => array( 'string', 'title-templates' ),
			'bctitle-ptarchive-'            => array( 'string', 'breadcrumbs' ),
			'noindex-ptarchive-'            => array( 'boolean', 'archives' ),
			'title-ptarchive-'              => array( 'string', 'title-templates' ),
			'social-description-tax-'       => array( 'string', 'social-defaults' ),
			'social-image-url-tax-'         => array( 'string', 'social-defaults' ),
			'social-image-id-tax-'          => array( 'integer', 'social-defaults' ),
			'social-title-tax-'             => array( 'string', 'social-defaults' ),
			'display-metabox-tax-'          => array( 'boolean', 'archives' ),
			'metadesc-tax-'                 => array( 'string', 'title-templates' ),
			'noindex-tax-'                  => array( 'boolean', 'archives' ),
			'title-tax-'                    => array( 'string', 'title-templates' ),
			'display-metabox-pt-'           => array( 'boolean', 'archives' ),
			'schema-article-type-'          => array( 'string', 'schema' ),
			'schema-page-type-'             => array( 'string', 'schema' ),
			'social-description-'           => array( 'string', 'social-defaults' ),
			'social-image-url-'             => array( 'string', 'social-defaults' ),
			'social-image-id-'              => array( 'integer', 'social-defaults' ),
			'social-title-'                 => array( 'string', 'social-defaults' ),
			'metadesc-'                     => array( 'string', 'title-templates' ),
			'noindex-'                      => array( 'boolean', 'archives' ),
			'title-'                        => array( 'string', 'title-templates' ),
		);

		$suffixes = array(
			'-maintax'  => array( 'integer', 'archives' ),
			'-ptparent' => array( 'integer', 'archives' ),
		);

		$live = \WPSEO_Options::get_option( 'wpseo_titles' );

		if ( ! is_array( $live ) ) {
			return array();
		}

		$found = array();

		foreach ( array_keys( $live ) as $key ) {
			$key = (string) $key;

			foreach ( $suffixes as $suffix => $meta ) {
				if ( substr( $key, -strlen( $suffix ) ) === $suffix ) {
					$found[ $key ] = array( $meta[0], 'wpseo_titles', $meta[1] );
					continue 2;
				}
			}

			foreach ( $families as $prefix => $meta ) {
				if ( 0 === strpos( $key, $prefix ) && strlen( $key ) > strlen( $prefix ) ) {
					$found[ $key ] = array( $meta[0], 'wpseo_titles', $meta[1] );
					continue 2;
				}
			}
		}

		return $found;
	}

	/**
	 * Keys Yoast's own settings screen refuses to render or save.
	 *
	 * Read from `Settings_Integration::DISALLOWED_SETTINGS` rather than copied, because two of
	 * these — `semrush_tokens` and `wincher_tokens` — hold live OAuth access and refresh tokens
	 * (`Yoast\WP\SEO\Values\OAuth\OAuth_Token`). A settings reader that returns every key in an
	 * area would hand those credentials to any caller, and on an MCP install that means off-site.
	 * Yoast excludes them from its own telemetry for the same reason
	 * (`admin/tracking/class-tracking-settings-data.php`).
	 *
	 * The frozen list is the fallback for when the class is unavailable; Test_Yoast_Architecture
	 * asserts it still covers everything Yoast's live constant names, so an upstream addition
	 * fails CI instead of silently becoming readable.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public static function disallowed(): array {
		$frozen = array(
			'myyoast-oauth',
			'semrush_tokens',
			'custom_taxonomy_slugs',
			'import_cursors',
			'workouts_data',
			'configuration_finished_steps',
			'importing_completed',
			'wincher_tokens',
			'least_readability_ignore_list',
			'least_seo_score_ignore_list',
			'most_linked_ignore_list',
			'least_linked_ignore_list',
			'indexables_page_reading_list',
			'show_new_content_type_notification',
			'new_post_types',
			'new_taxonomies',
			'company_logo_meta',
			'person_logo_meta',
		);

		$class = '\\Yoast\\WP\\SEO\\Integrations\\Settings_Integration';

		if ( class_exists( $class ) && defined( $class . '::DISALLOWED_SETTINGS' ) ) {
			$live = constant( $class . '::DISALLOWED_SETTINGS' );

			if ( is_array( $live ) ) {
				foreach ( $live as $group_keys ) {
					$frozen = array_merge( $frozen, (array) $group_keys );
				}
			}
		}

		return array_values( array_unique( $frozen ) );
	}

	/**
	 * The machine-derived key map, before Yoast's disallow list is subtracted.
	 *
	 * Call `areas()` instead — this is the raw literal and exposes keys that must never be read
	 * or written. It is separate only so the subtraction has something to subtract from.
	 *
	 * @since  0.0.34
	 * @return array<string, array<string, array{0:string,1:string}>>
	 */
	private static function declared_areas(): array {
		return
		array(
			'general' => array(
				'content_analysis_active' => array( 'boolean', 'wpseo' ),
				'disableadvanced_meta' => array( 'boolean', 'wpseo' ),
				'dynamic_permalinks' => array( 'boolean', 'wpseo' ),
				'enable_admin_bar_menu' => array( 'boolean', 'wpseo' ),
				'enable_ai_generator' => array( 'boolean', 'wpseo' ),
				'enable_cornerstone_content' => array( 'boolean', 'wpseo' ),
				'enable_enhanced_slack_sharing' => array( 'boolean', 'wpseo' ),
				'enable_headless_rest_endpoints' => array( 'boolean', 'wpseo' ),
				'enable_index_now' => array( 'boolean', 'wpseo' ),
				'enable_link_suggestions' => array( 'boolean', 'wpseo' ),
				'enable_llms_txt' => array( 'boolean', 'wpseo' ),
				'enable_metabox_insights' => array( 'boolean', 'wpseo' ),
				'enable_schema' => array( 'boolean', 'wpseo' ),
				'enable_schema_aggregation_endpoint' => array( 'boolean', 'wpseo' ),
				'enable_task_list' => array( 'boolean', 'wpseo' ),
				'enable_text_link_counter' => array( 'boolean', 'wpseo' ),
				'enable_xml_sitemap' => array( 'boolean', 'wpseo' ),
				'has_multiple_authors' => array( 'string', 'wpseo' ),
				'inclusive_language_analysis_active' => array( 'boolean', 'wpseo' ),
				'keyword_analysis_active' => array( 'boolean', 'wpseo' ),
				'site_type' => array( 'string', 'wpseo' ),
				'tracking' => array( 'boolean', 'wpseo' ),
			),
			'crawl' => array(
				'clean_campaign_tracking_urls' => array( 'boolean', 'wpseo' ),
				'clean_permalinks' => array( 'boolean', 'wpseo' ),
				'clean_permalinks_extra_variables' => array( 'string', 'wpseo' ),
				'deny_adsbot_crawling' => array( 'boolean', 'wpseo' ),
				'deny_ccbot_crawling' => array( 'boolean', 'wpseo' ),
				'deny_google_extended_crawling' => array( 'boolean', 'wpseo' ),
				'deny_gptbot_crawling' => array( 'boolean', 'wpseo' ),
				'deny_search_crawling' => array( 'boolean', 'wpseo' ),
				'deny_wp_json_crawling' => array( 'boolean', 'wpseo' ),
				'remove_atom_rdf_feeds' => array( 'boolean', 'wpseo' ),
				'remove_emoji_scripts' => array( 'boolean', 'wpseo' ),
				'remove_feed_authors' => array( 'boolean', 'wpseo' ),
				'remove_feed_categories' => array( 'boolean', 'wpseo' ),
				'remove_feed_custom_taxonomies' => array( 'boolean', 'wpseo' ),
				'remove_feed_global' => array( 'boolean', 'wpseo' ),
				'remove_feed_global_comments' => array( 'boolean', 'wpseo' ),
				'remove_feed_post_comments' => array( 'boolean', 'wpseo' ),
				'remove_feed_post_types' => array( 'boolean', 'wpseo' ),
				'remove_feed_search' => array( 'boolean', 'wpseo' ),
				'remove_feed_tags' => array( 'boolean', 'wpseo' ),
				'remove_generator' => array( 'boolean', 'wpseo' ),
				'remove_oembed_links' => array( 'boolean', 'wpseo' ),
				'remove_pingback_header' => array( 'boolean', 'wpseo' ),
				'remove_powered_by_header' => array( 'boolean', 'wpseo' ),
				'remove_rest_api_links' => array( 'boolean', 'wpseo' ),
				'remove_rsd_wlw_links' => array( 'boolean', 'wpseo' ),
				'remove_shortlinks' => array( 'boolean', 'wpseo' ),
				'search_cleanup' => array( 'boolean', 'wpseo' ),
				'search_cleanup_emoji' => array( 'boolean', 'wpseo' ),
				'search_cleanup_patterns' => array( 'boolean', 'wpseo' ),
			),
			'webmaster' => array(
				'ahrefsverify' => array( 'string', 'wpseo' ),
				'baiduverify' => array( 'string', 'wpseo' ),
				'googleverify' => array( 'string', 'wpseo' ),
				'msverify' => array( 'string', 'wpseo' ),
				'yandexverify' => array( 'string', 'wpseo' ),
			),
			'integrations' => array(
				'ai_enabled_pre_default' => array( 'boolean', 'wpseo' ),
				'ai_free_sparks_started_on' => array( 'string', 'wpseo' ),
				'algolia_integration_active' => array( 'boolean', 'wpseo' ),
				'semrush_country_code' => array( 'string', 'wpseo' ),
				'semrush_integration_active' => array( 'boolean', 'wpseo' ),
				'semrush_tokens' => array( 'array', 'wpseo' ),
				'wincher_automatically_add_keyphrases' => array( 'boolean', 'wpseo' ),
				'wincher_integration_active' => array( 'boolean', 'wpseo' ),
				'wincher_tokens' => array( 'array', 'wpseo' ),
				'wincher_website_id' => array( 'string', 'wpseo' ),
			),
			'title-templates' => array(
				'metadesc-archive-wpseo' => array( 'string', 'wpseo_titles' ),
				'metadesc-attachment' => array( 'string', 'wpseo_titles' ),
				'metadesc-author-wpseo' => array( 'string', 'wpseo_titles' ),
				'metadesc-home-wpseo' => array( 'string', 'wpseo_titles' ),
				'metadesc-page' => array( 'string', 'wpseo_titles' ),
				'metadesc-post' => array( 'string', 'wpseo_titles' ),
				'metadesc-tax-category' => array( 'string', 'wpseo_titles' ),
				'metadesc-tax-post_format' => array( 'string', 'wpseo_titles' ),
				'metadesc-tax-post_tag' => array( 'string', 'wpseo_titles' ),
				'title-404-wpseo' => array( 'string', 'wpseo_titles' ),
				'title-archive-wpseo' => array( 'string', 'wpseo_titles' ),
				'title-attachment' => array( 'string', 'wpseo_titles' ),
				'title-author-wpseo' => array( 'string', 'wpseo_titles' ),
				'title-home-wpseo' => array( 'string', 'wpseo_titles' ),
				'title-page' => array( 'string', 'wpseo_titles' ),
				'title-post' => array( 'string', 'wpseo_titles' ),
				'title-search-wpseo' => array( 'string', 'wpseo_titles' ),
				'title-tax-category' => array( 'string', 'wpseo_titles' ),
				'title-tax-post_format' => array( 'string', 'wpseo_titles' ),
				'title-tax-post_tag' => array( 'string', 'wpseo_titles' ),
			),
			'archives' => array(
				'disable-attachment' => array( 'boolean', 'wpseo_titles' ),
				'disable-author' => array( 'boolean', 'wpseo_titles' ),
				'disable-date' => array( 'boolean', 'wpseo_titles' ),
				'disable-post_format' => array( 'boolean', 'wpseo_titles' ),
				'display-metabox-pt-attachment' => array( 'boolean', 'wpseo_titles' ),
				'display-metabox-pt-page' => array( 'boolean', 'wpseo_titles' ),
				'display-metabox-pt-post' => array( 'boolean', 'wpseo_titles' ),
				'display-metabox-tax-category' => array( 'boolean', 'wpseo_titles' ),
				'display-metabox-tax-post_format' => array( 'boolean', 'wpseo_titles' ),
				'display-metabox-tax-post_tag' => array( 'boolean', 'wpseo_titles' ),
				'noindex-archive-wpseo' => array( 'boolean', 'wpseo_titles' ),
				'noindex-attachment' => array( 'boolean', 'wpseo_titles' ),
				'noindex-author-noposts-wpseo' => array( 'boolean', 'wpseo_titles' ),
				'noindex-author-wpseo' => array( 'boolean', 'wpseo_titles' ),
				'noindex-page' => array( 'boolean', 'wpseo_titles' ),
				'noindex-post' => array( 'boolean', 'wpseo_titles' ),
				'noindex-tax-category' => array( 'boolean', 'wpseo_titles' ),
				'noindex-tax-post_format' => array( 'boolean', 'wpseo_titles' ),
				'noindex-tax-post_tag' => array( 'boolean', 'wpseo_titles' ),
			),
			'breadcrumbs' => array(
				'breadcrumbs-404crumb' => array( 'string', 'wpseo_titles' ),
				'breadcrumbs-archiveprefix' => array( 'string', 'wpseo_titles' ),
				'breadcrumbs-boldlast' => array( 'boolean', 'wpseo_titles' ),
				'breadcrumbs-display-blog-page' => array( 'boolean', 'wpseo_titles' ),
				'breadcrumbs-enable' => array( 'boolean', 'wpseo_titles' ),
				'breadcrumbs-home' => array( 'string', 'wpseo_titles' ),
				'breadcrumbs-prefix' => array( 'string', 'wpseo_titles' ),
				'breadcrumbs-searchprefix' => array( 'string', 'wpseo_titles' ),
				'breadcrumbs-sep' => array( 'string', 'wpseo_titles' ),
			),
			'knowledge-graph' => array(
				'alternate_website_name' => array( 'string', 'wpseo_titles' ),
				'company_alternate_name' => array( 'string', 'wpseo_titles' ),
				'company_logo' => array( 'string', 'wpseo_titles' ),
				'company_logo_id' => array( 'integer', 'wpseo_titles' ),
				'company_logo_meta' => array( 'boolean', 'wpseo_titles' ),
				'company_name' => array( 'string', 'wpseo_titles' ),
				'company_or_person' => array( 'string', 'wpseo_titles' ),
				'company_or_person_user_id' => array( 'boolean', 'wpseo_titles' ),
				'org-description' => array( 'string', 'wpseo_titles' ),
				'org-duns' => array( 'string', 'wpseo_titles' ),
				'org-email' => array( 'string', 'wpseo_titles' ),
				'org-founding-date' => array( 'string', 'wpseo_titles' ),
				'org-iso' => array( 'string', 'wpseo_titles' ),
				'org-legal-name' => array( 'string', 'wpseo_titles' ),
				'org-leicode' => array( 'string', 'wpseo_titles' ),
				'org-naics' => array( 'string', 'wpseo_titles' ),
				'org-number-employees' => array( 'string', 'wpseo_titles' ),
				'org-phone' => array( 'string', 'wpseo_titles' ),
				'org-tax-id' => array( 'string', 'wpseo_titles' ),
				'org-vat-id' => array( 'string', 'wpseo_titles' ),
				'person_logo' => array( 'string', 'wpseo_titles' ),
				'person_logo_id' => array( 'integer', 'wpseo_titles' ),
				'person_logo_meta' => array( 'boolean', 'wpseo_titles' ),
				'person_name' => array( 'string', 'wpseo_titles' ),
				'website_name' => array( 'string', 'wpseo_titles' ),
			),
			'social-defaults' => array(
				'open_graph_frontpage_desc' => array( 'string', 'wpseo_titles' ),
				'open_graph_frontpage_image' => array( 'string', 'wpseo_titles' ),
				'open_graph_frontpage_image_id' => array( 'integer', 'wpseo_titles' ),
				'open_graph_frontpage_title' => array( 'string', 'wpseo_titles' ),
				'social-description-archive-wpseo' => array( 'string', 'wpseo_titles' ),
				'social-description-author-wpseo' => array( 'string', 'wpseo_titles' ),
				'social-description-page' => array( 'string', 'wpseo_titles' ),
				'social-description-post' => array( 'string', 'wpseo_titles' ),
				'social-description-tax-category' => array( 'string', 'wpseo_titles' ),
				'social-description-tax-post_format' => array( 'string', 'wpseo_titles' ),
				'social-description-tax-post_tag' => array( 'string', 'wpseo_titles' ),
				'social-image-id-archive-wpseo' => array( 'integer', 'wpseo_titles' ),
				'social-image-id-author-wpseo' => array( 'integer', 'wpseo_titles' ),
				'social-image-id-page' => array( 'integer', 'wpseo_titles' ),
				'social-image-id-post' => array( 'integer', 'wpseo_titles' ),
				'social-image-id-tax-category' => array( 'integer', 'wpseo_titles' ),
				'social-image-id-tax-post_format' => array( 'integer', 'wpseo_titles' ),
				'social-image-id-tax-post_tag' => array( 'integer', 'wpseo_titles' ),
				'social-image-url-archive-wpseo' => array( 'string', 'wpseo_titles' ),
				'social-image-url-author-wpseo' => array( 'string', 'wpseo_titles' ),
				'social-image-url-page' => array( 'string', 'wpseo_titles' ),
				'social-image-url-post' => array( 'string', 'wpseo_titles' ),
				'social-image-url-tax-category' => array( 'string', 'wpseo_titles' ),
				'social-image-url-tax-post_format' => array( 'string', 'wpseo_titles' ),
				'social-image-url-tax-post_tag' => array( 'string', 'wpseo_titles' ),
				'social-title-archive-wpseo' => array( 'string', 'wpseo_titles' ),
				'social-title-author-wpseo' => array( 'string', 'wpseo_titles' ),
				'social-title-page' => array( 'string', 'wpseo_titles' ),
				'social-title-post' => array( 'string', 'wpseo_titles' ),
				'social-title-tax-category' => array( 'string', 'wpseo_titles' ),
				'social-title-tax-post_format' => array( 'string', 'wpseo_titles' ),
				'social-title-tax-post_tag' => array( 'string', 'wpseo_titles' ),
			),
			'schema' => array(
				'schema-article-type-attachment' => array( 'string', 'wpseo_titles' ),
				'schema-article-type-page' => array( 'string', 'wpseo_titles' ),
				'schema-article-type-post' => array( 'string', 'wpseo_titles' ),
				'schema-page-type-attachment' => array( 'string', 'wpseo_titles' ),
				'schema-page-type-page' => array( 'string', 'wpseo_titles' ),
				'schema-page-type-post' => array( 'string', 'wpseo_titles' ),
			),
			'rss' => array(
				'rssafter' => array( 'string', 'wpseo_titles' ),
				'rssbefore' => array( 'string', 'wpseo_titles' ),
			),
			'social-profiles' => array(
				'facebook_site' => array( 'string', 'wpseo_social' ),
				'instagram_url' => array( 'string', 'wpseo_social' ),
				'linkedin_url' => array( 'string', 'wpseo_social' ),
				'mastodon_url' => array( 'string', 'wpseo_social' ),
				'myspace_url' => array( 'string', 'wpseo_social' ),
				'og_default_image' => array( 'string', 'wpseo_social' ),
				'og_default_image_id' => array( 'string', 'wpseo_social' ),
				'og_frontpage_desc' => array( 'string', 'wpseo_social' ),
				'og_frontpage_image' => array( 'string', 'wpseo_social' ),
				'og_frontpage_image_id' => array( 'string', 'wpseo_social' ),
				'og_frontpage_title' => array( 'string', 'wpseo_social' ),
				'opengraph' => array( 'boolean', 'wpseo_social' ),
				'other_social_urls' => array( 'array', 'wpseo_social' ),
				'pinterest_url' => array( 'string', 'wpseo_social' ),
				'pinterestverify' => array( 'string', 'wpseo_social' ),
				'twitter' => array( 'boolean', 'wpseo_social' ),
				'twitter_card_type' => array( 'string', 'wpseo_social' ),
				'twitter_site' => array( 'string', 'wpseo_social' ),
				'wikipedia_url' => array( 'string', 'wpseo_social' ),
				'youtube_url' => array( 'string', 'wpseo_social' ),
			),
			'llms' => array(
				'about_us_page' => array( 'integer', 'wpseo_llmstxt' ),
				'contact_page' => array( 'integer', 'wpseo_llmstxt' ),
				'llms_txt_selection_mode' => array( 'string', 'wpseo_llmstxt' ),
				'other_included_pages' => array( 'array', 'wpseo_llmstxt' ),
				'privacy_policy_page' => array( 'integer', 'wpseo_llmstxt' ),
				'shop_page' => array( 'integer', 'wpseo_llmstxt' ),
				'terms_page' => array( 'integer', 'wpseo_llmstxt' ),
			),
			'advanced' => array(
				'category_base_url' => array( 'string', 'wpseo' ),
				'custom_taxonomy_slugs' => array( 'array', 'wpseo' ),
				'default_seo_meta_desc' => array( 'array', 'wpseo' ),
				'default_seo_title' => array( 'array', 'wpseo' ),
				'forcerewritetitle' => array( 'boolean', 'wpseo_titles' ),
				'stripcategorybase' => array( 'boolean', 'wpseo_titles' ),
				'tag_base_url' => array( 'string', 'wpseo' ),
			),
		);
	}

	/**
	 * The keys one area owns, as key => json type.
	 *
	 * @since  0.0.34
	 * @param  string $area Area key.
	 * @return array<string, string>
	 */
	public static function keys_for( string $area ): array {
		$out = array();

		foreach ( self::areas()[ $area ] ?? array() as $key => $meta ) {
			$out[ $key ] = $meta[0];
		}

		return $out;
	}

	/**
	 * Which area owns one key, or '' when nothing does.
	 *
	 * @since  0.0.34
	 * @param  string $key Option key.
	 * @return string
	 */
	/**
	 * The ability that writes an area, or '' when nothing does.
	 *
	 * Declared, not derived. `List_Settings_Areas` previously built these by concatenating
	 * "seo/update-" onto the area name, which resolved for only 5 of the 14 areas and advertised
	 * nine abilities that do not exist. Every value here is asserted resolvable by
	 * Test_Yoast_Suite_Contract, and Test_Yoast_Architecture asserts the map covers every area.
	 *
	 * @since  0.0.34
	 * @param  string $area Area key.
	 * @return string Full ability slug, or ''.
	 */
	public static function writer_for( string $area ): string {
		$writers = array(
			'general'         => 'seo/update-general-settings',
			'crawl'           => 'seo/update-crawl-settings',
			'webmaster'       => 'seo/update-webmaster-verification',
			'integrations'    => 'seo/update-integration-settings',
			'title-templates' => 'seo/update-title-templates',
			'archives'        => 'seo/update-archive-settings',
			'breadcrumbs'     => 'seo/update-breadcrumb-settings',
			'knowledge-graph' => 'seo/update-knowledge-graph',
			'social-defaults' => 'seo/update-social-defaults',
			'schema'          => 'seo/update-schema-settings',
			'rss'             => 'seo/update-rss-settings',
			'social-profiles' => 'seo/update-social-profiles',
			'llms'            => 'seo/update-llms-settings',
			'advanced'        => 'seo/update-advanced-settings',
		);

		return $writers[ $area ] ?? '';
	}

	public static function area_for( string $key ): string {
		foreach ( self::areas() as $area => $keys ) {
			if ( isset( $keys[ $key ] ) ) {
				return (string) $area;
			}
		}

		return '';
	}

	/**
	 * Read one option's current value through Yoast's accessor.
	 *
	 * @since  0.0.34
	 * @param  string $key Option key.
	 * @return mixed
	 */
	public static function value( string $key ) {
		return \WPSEO_Options::get( $key );
	}

	/**
	 * Describe a set of options as ROWS.
	 *
	 * A list, not a map. `[ 'title-post' => '%%title%%' ]` is the natural shape and the wrong one:
	 * PHP encodes an associative array as a JSON object, so an output property declared
	 * `type => array` fails the ability's own output schema after the work is done
	 * (BUG-ARRAY-TYPED-OUTPUT-IS-A-JSON-OBJECT — three features running). Every reader in this suite
	 * returns these rows and every writer echoes them back.
	 *
	 * @since  0.0.34
	 * @param  string $area Area key.
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe_area( string $area ): array {
		$rows = array();

		foreach ( self::areas()[ $area ] ?? array() as $key => $meta ) {
			$rows[] = array(
				'key'      => (string) $key,
				'value'    => self::cast( self::value( (string) $key ), $meta[0] ),
				'type'     => $meta[0],
				'group'    => $meta[1],
				'writable' => '' !== self::writer_for( $area ),
			);
		}

		return $rows;
	}

	/**
	 * Coerce a value to the type Yoast stores.
	 *
	 * @since  0.0.34
	 * @param  mixed  $value Raw value.
	 * @param  string $type  boolean|integer|string|array.
	 * @return mixed
	 */
	public static function cast( $value, string $type ) {
		switch ( $type ) {
			case 'boolean':
				return (bool) $value;

			case 'integer':
				return (int) $value;

			case 'array':
				return is_array( $value ) ? array_values( $value ) : array();

			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * Write a patch of settings belonging to one area.
	 *
	 * Refuses the whole patch when any key is outside the area, so a caller can always tell what
	 * landed. A partial write on a rejected input is the harder failure to reason about.
	 *
	 * @since  0.0.34
	 * @param  string              $area  Area key.
	 * @param  array<string,mixed> $patch Option key => new value.
	 * @return array<int, string>|WP_Error Keys actually changed.
	 */
	public static function write( string $area, array $patch ) {
		$allowed = self::areas()[ $area ] ?? array();

		if ( array() === $allowed ) {
			return new WP_Error(
				'unknown_settings_area',
				sprintf(
					/* translators: 1: requested area, 2: comma-separated known areas */
					__( '"%1$s" is not a settings area. Known areas: %2$s.', 'acrossai-abilities-manager' ),
					$area,
					implode( ', ', array_keys( self::areas() ) )
				)
			);
		}

		if ( array() === $patch ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one setting to change.', 'acrossai-abilities-manager' ) );
		}

		$changed  = array();
		$rejected = array();

		foreach ( $patch as $key => $value ) {
			$key = (string) $key;

			if ( ! isset( $allowed[ $key ] ) ) {
				return new WP_Error(
					'setting_not_writable',
					sprintf(
						/* translators: 1: setting key, 2: area, 3: comma-separated writable keys */
						__( '"%1$s" is not writable in the "%2$s" area. That area accepts: %3$s.', 'acrossai-abilities-manager' ),
						$key,
						$area,
						implode( ', ', array_keys( $allowed ) )
					)
				);
			}

			$cast = self::cast( $value, $allowed[ $key ][0] );

			if ( self::cast( self::value( $key ), $allowed[ $key ][0] ) === $cast ) {
				continue;
			}

			self::save( $key, $cast, $allowed[ $key ][1] );

			// Yoast validates per option group and silently keeps the old value when the new one
			// fails — enum keys like schema-article-type-* and llms_txt_selection_mode do this.
			// Without this read-back the ability answers "Updated: <key>" for a write that never
			// happened, which is worse than an error because the caller stops checking.
			$stored = self::cast( self::value( $key ), $allowed[ $key ][0] );

			if ( $stored === $cast ) {
				$changed[] = $key;
				continue;
			}

			$rejected[] = array(
				'key'       => $key,
				'requested' => $cast,
				'kept'      => $stored,
			);
		}

		if ( array() !== $rejected ) {
			$names = array();

			foreach ( $rejected as $row ) {
				$names[] = sprintf(
					'%1$s (requested %2$s, kept %3$s)',
					$row['key'],
					wp_json_encode( $row['requested'] ),
					wp_json_encode( $row['kept'] )
				);
			}

			return new WP_Error(
				'setting_rejected',
				sprintf(
					/* translators: 1: rejected keys with values, 2: applied keys or "none" */
					__( 'Yoast refused these values and kept the existing ones: %1$s. Applied in the same call: %2$s. Check the accepted values for those keys — several are restricted to a fixed set.', 'acrossai-abilities-manager' ),
					implode( '; ', $names ),
					array() === $changed ? __( 'none', 'acrossai-abilities-manager' ) : implode( ', ', $changed )
				),
				array(
					'applied'  => $changed,
					'rejected' => $rejected,
				)
			);
		}

		return $changed;
	}

	/**
	 * The ONE call site that persists a setting.
	 *
	 * Centralised so no future ability can reach past Yoast's per-group validation, and so there is a
	 * single place to absorb a change in its API. Test_Yoast_Architecture asserts nothing else in the
	 * suite calls it.
	 *
	 * @since  0.0.34
	 * @param  string $key   Option key.
	 * @param  mixed  $value Cast value.
	 * @param  string $group Yoast option group.
	 * @return void
	 */
	private static function save( string $key, $value, string $group ): void {
		\WPSEO_Options::set( $key, $value, $group );
	}
}
