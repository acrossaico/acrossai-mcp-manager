<?php
/**
 * Feature 069 — declarative field-spec tables for Rank Math's settings panels.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data. The reason this class exists is research F2 — read it before editing.
 *
 * Short version, verified against Rank Math 1.0.276:
 *
 * 1. Rank Math's own per-field types live only in CMB2 definitions that are dead
 *    code in React mode, so there is nothing on the server to ask. Its sanitizer
 *    defaults any field it was not told about to 'text', which collapses
 *    newlines.
 * 2. The sanitizer's switch cases are the REACT type names, while the definitions
 *    in includes/settings/** use LEGACY CMB2 names. 11 of the 19 legacy names
 *    match no case and fall through to the lossy default branch.
 *
 * So each FIELDS entry records the LEGACY type verbatim — that keeps the table a
 * faithful mirror of the Rank Math source, so a version upgrade can be re-diffed
 * directly — and LEGACY_TO_SANITIZER performs the one translation that matters,
 * in exactly one place.
 *
 * Every panel carries a 'source' citation. Keep it accurate; quickstart.md's
 * re-diff procedure depends on it.
 */
final class Settings_Registry {

	// Sanitizer vocabulary — these are the values we EMIT to
	// Sanitize_Settings::sanitize(), i.e. the switch cases it recognises.
	public const TYPE_TEXT         = 'text';         // sanitize_textfield — COLLAPSES newlines.
	public const TYPE_TEXTAREA     = 'textarea';     // wp_kses_post — preserves newlines.
	public const TYPE_TOGGLE       = 'toggle';       // stored as 'on' | 'off'.
	public const TYPE_NUMBER       = 'number';       // intval.
	public const TYPE_SELECT       = 'select';       // sanitize_textfield on a scalar.
	public const TYPE_CHECKBOXLIST = 'checkboxlist'; // array of scalars.
	public const TYPE_FILE         = 'file';         // esc_url_raw.
	public const TYPE_GROUP        = 'group';        // repeatable; MUST be a sequential list.

	/**
	 * Legacy CMB2 type => the sanitizer type we emit for it.
	 *
	 * Anything absent from this map is treated as read-only (see READONLY_TYPES)
	 * or rejected, rather than passed through to the lossy default branch.
	 *
	 * The load-bearing row is textarea_small => textarea. Emitting
	 * 'textarea_small' verbatim sends Rank Math down its default branch and
	 * destroys every multi-line value: nofollow_domains,
	 * nofollow_exclude_domains, rss_before_content, rss_after_content,
	 * social_additional_profiles, local_address_format,
	 * pt_{type}_image_customfields.
	 */
	private const LEGACY_TO_SANITIZER = array(
		'text'              => self::TYPE_TEXT,
		'text_url'          => self::TYPE_TEXT,
		'password'          => self::TYPE_TEXT,
		'textarea'          => self::TYPE_TEXTAREA,
		'textarea_small'    => self::TYPE_TEXTAREA,
		'toggle'            => self::TYPE_TOGGLE,
		'switch'            => self::TYPE_TOGGLE,
		'number'            => self::TYPE_NUMBER,
		'select'            => self::TYPE_SELECT,
		'radio'             => self::TYPE_SELECT,
		'radio_inline'      => self::TYPE_SELECT,
		'multicheck'        => self::TYPE_CHECKBOXLIST,
		'multicheck_inline' => self::TYPE_CHECKBOXLIST,
		'file'              => self::TYPE_FILE,
		'group'             => self::TYPE_GROUP,
		'address'           => self::TYPE_GROUP,
	);

	/**
	 * Legacy types that render information rather than accept input. Readable,
	 * never writable.
	 */
	private const READONLY_TYPES = array( 'notice', 'raw' );

	/**
	 * Keys that must never be written through this path.
	 *
	 * save_settings() strips these itself at class-option-center.php:388-400, but
	 * maybe_update_htaccess() runs BEFORE that strip and maybe_update_analytics()
	 * fires when $updated names searchConsole/analyticsData — so submitting them
	 * has side effects even though the value is discarded. Deny them outright.
	 *
	 * htaccess is additionally out of scope for Feature 069 by product decision.
	 */
	public const DENIED_KEYS = array(
		'htaccess_allow_editing',
		'htaccess_content',
		'searchConsole',
		'analyticsData',
		'analytics',
		'usage_tracking',
	);

	/**
	 * Rank Math robots directives. Verified against Helper::choices_robots().
	 */
	private const ROBOTS = array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );

	/**
	 * Placeholder substituted with the post type or taxonomy on dynamic panels.
	 */
	public const OBJECT_TOKEN = '{object}';

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * All settings panels.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function panels(): array {
		return array(
			// ---------------------------------------------------------------
			// general — option blob rank-math-options-general, cap rank_math_general
			// ---------------------------------------------------------------
			'general-links'             => array(
				'option_type' => 'general',
				'cap'         => 'general',
				'dynamic'     => null,
				'source'      => 'includes/settings/general/links.php',
				'fields'      => array(
					'strip_category_base'         => array( 'type' => 'toggle' ),
					'attachment_redirect_urls'    => array( 'type' => 'toggle' ),
					'attachment_redirect_default' => array( 'type' => 'text' ),
					'nofollow_external_links'     => array( 'type' => 'toggle' ),
					'nofollow_image_links'        => array( 'type' => 'toggle' ),
					'nofollow_domains'            => array( 'type' => 'textarea_small' ),
					'nofollow_exclude_domains'    => array( 'type' => 'textarea_small' ),
					'new_window_external_links'   => array( 'type' => 'toggle' ),
				),
			),
			'general-breadcrumbs'       => array(
				'option_type' => 'general',
				'cap'         => 'general',
				'dynamic'     => null,
				'source'      => 'includes/settings/general/breadcrumbs.php',
				'fields'      => array(
					'breadcrumbs'                    => array( 'type' => 'toggle' ),
					'breadcrumbs_separator'          => array( 'type' => 'radio_inline' ),
					'breadcrumbs_home'               => array( 'type' => 'toggle' ),
					'breadcrumbs_home_label'         => array( 'type' => 'text' ),
					'breadcrumbs_home_link'          => array( 'type' => 'text' ),
					'breadcrumbs_prefix'             => array( 'type' => 'text' ),
					'breadcrumbs_archive_format'     => array( 'type' => 'text' ),
					'breadcrumbs_search_format'      => array( 'type' => 'text' ),
					'breadcrumbs_404_label'          => array( 'type' => 'text' ),
					'breadcrumbs_remove_post_title'  => array( 'type' => 'toggle' ),
					'breadcrumbs_ancestor_categories' => array( 'type' => 'toggle' ),
					'breadcrumbs_hide_taxonomy_name' => array( 'type' => 'toggle' ),
					'breadcrumbs_blog_page'          => array( 'type' => 'toggle' ),
				),
			),
			'general-webmaster'         => array(
				'option_type' => 'general',
				'cap'         => 'general',
				'dynamic'     => null,
				'source'      => 'includes/settings/general/webmaster.php',
				'fields'      => array(
					// These seven are ID-protected by sanitize_by_field_id(), so
					// the emitted type is advisory for them. Declared anyway so
					// unknown-field rejection still covers the panel.
					'google_verify'         => array( 'type' => 'text' ),
					'bing_verify'           => array( 'type' => 'text' ),
					'baidu_verify'          => array( 'type' => 'text' ),
					'yandex_verify'         => array( 'type' => 'text' ),
					'pinterest_verify'      => array( 'type' => 'text' ),
					'norton_verify'         => array( 'type' => 'text' ),
					'custom_webmaster_tags' => array( 'type' => 'textarea' ),
				),
			),
			'general-image-seo'         => array(
				'option_type' => 'general',
				'cap'         => 'general',
				'dynamic'     => null,
				'source'      => 'includes/modules/image-seo/options.php',
				'fields'      => array(
					'add_img_alt'      => array( 'type' => 'toggle' ),
					'img_alt_format'   => array( 'type' => 'text' ),
					'add_img_title'    => array( 'type' => 'toggle' ),
					'img_title_format' => array( 'type' => 'text' ),
				),
			),
			'general-404-monitor'       => array(
				'option_type' => 'general',
				'cap'         => 'general',
				'dynamic'     => null,
				'source'      => 'includes/modules/404-monitor/views/options.php',
				'fields'      => array(
					'404_advanced_monitor'                   => array( 'type' => 'notice' ),
					'404_monitor_mode'                       => array( 'type' => 'radio_inline', 'enum' => array( 'simple', 'advanced' ) ),
					// 'text' in Rank Math's definition, but semantically a count.
					// Emitting 'text' preserves byte-parity with what the admin UI
					// stores; the integer range is enforced by our own validate().
					'404_monitor_limit'                      => array( 'type' => 'text', 'min' => 1, 'max' => 99999 ),
					'404_monitor_exclude'                    => array(
						'type'         => 'group',
						'group_schema' => array(
							'exclude'    => array( 'type' => 'text' ),
							'comparison' => array( 'type' => 'select', 'enum' => array( 'exact', 'contains', 'start', 'end', 'regex' ) ),
						),
					),
					'404_monitor_ignore_query_parameters'    => array( 'type' => 'toggle' ),
				),
			),
			'general-redirections'      => array(
				'option_type' => 'general',
				'cap'         => 'general',
				'dynamic'     => null,
				'source'      => 'includes/modules/redirections/views/options.php',
				'fields'      => array(
					'redirections_debug'         => array( 'type' => 'toggle' ),
					'redirections_fallback'      => array( 'type' => 'radio' ),
					'redirections_custom_url'    => array( 'type' => 'text' ),
					'redirections_header_code'   => array( 'type' => 'select', 'enum' => array( '301', '302', '307', '410', '451' ) ),
					'redirections_post_redirect' => array( 'type' => 'toggle' ),
				),
			),
			'general-robots-txt'        => array(
				'option_type' => 'general',
				'cap'         => 'general',
				'dynamic'     => null,
				'source'      => 'includes/modules/robots-txt/options.php',
				'fields'      => array(
					'robots_txt_content' => array( 'type' => 'textarea' ),
					'edit_disabled'      => array( 'type' => 'notice' ),
					'robots_locked'      => array( 'type' => 'notice' ),
					'site_not_public'    => array( 'type' => 'notice' ),
					'robots_tester'      => array( 'type' => 'notice' ),
				),
			),
			'general-others'            => array(
				'option_type' => 'general',
				'cap'         => 'general',
				'dynamic'     => null,
				'source'      => 'includes/settings/general/others.php',
				'fields'      => array(
					'headless_support'                 => array( 'type' => 'toggle' ),
					'frontend_seo_score'               => array( 'type' => 'toggle' ),
					'frontend_seo_score_post_types'    => array( 'type' => 'multicheck' ),
					'frontend_seo_score_template'      => array( 'type' => 'radio_inline' ),
					'frontend_seo_score_position'      => array( 'type' => 'radio_inline' ),
					'support_rank_math'                => array( 'type' => 'toggle' ),
					'rss_before_content'               => array( 'type' => 'textarea_small' ),
					'rss_after_content'                => array( 'type' => 'textarea_small' ),
					'rank_math_rss_vars'               => array( 'type' => 'raw' ),
					// usage_tracking is deliberately absent — it is on DENIED_KEYS.
				),
			),
			'general-instant-indexing'  => array(
				'option_type' => 'instant_indexing',
				'cap'         => 'general',
				'dynamic'     => null,
				'source'      => 'includes/modules/instant-indexing/views/options.php',
				'fields'      => array(
					'bing_post_types'            => array( 'type' => 'multicheck' ),
					'indexnow_api_key'           => array( 'type' => 'text' ),
					// Derived by Api::get_key_location() from home_url(), never stored.
					'indexnow_api_key_location'  => array( 'type' => 'raw' ),
				),
			),

			// ---------------------------------------------------------------
			// titles — option blob rank-math-options-titles, cap rank_math_titles
			// ---------------------------------------------------------------
			'titles-global'             => array(
				'option_type' => 'titles',
				'cap'         => 'titles',
				'dynamic'     => null,
				'source'      => 'includes/settings/titles/global.php',
				'fields'      => array(
					'robots_global'             => array( 'type' => 'multicheck', 'enum' => self::ROBOTS ),
					'advanced_robots_global'    => array( 'type' => 'advanced_robots' ),
					'noindex_empty_taxonomies'  => array( 'type' => 'toggle' ),
					'title_separator'           => array( 'type' => 'radio_inline' ),
					'rewrite_title'             => array( 'type' => 'toggle' ),
					'capitalize_titles'         => array( 'type' => 'toggle' ),
					'open_graph_image'          => array( 'type' => 'file' ),
					'twitter_card_type'         => array( 'type' => 'select', 'enum' => array( 'summary_large_image', 'summary' ) ),
				),
			),
			'titles-homepage'           => array(
				'option_type' => 'titles',
				'cap'         => 'titles',
				'dynamic'     => null,
				'source'      => 'includes/settings/titles/homepage.php',
				'fields'      => array(
					'static_homepage_notice'          => array( 'type' => 'notice' ),
					'homepage_title'                 => array( 'type' => 'text' ),
					'homepage_description'           => array( 'type' => 'textarea_small' ),
					'homepage_custom_robots'         => array( 'type' => 'toggle' ),
					'homepage_robots'                => array( 'type' => 'multicheck', 'enum' => self::ROBOTS ),
					'homepage_advanced_robots'       => array( 'type' => 'advanced_robots' ),
					'homepage_facebook_title'        => array( 'type' => 'text' ),
					'homepage_facebook_description'  => array( 'type' => 'textarea_small' ),
					'homepage_facebook_image'        => array( 'type' => 'file' ),
				),
			),
			'titles-author'             => array(
				'option_type' => 'titles',
				'cap'         => 'titles',
				'dynamic'     => null,
				'source'      => 'includes/settings/titles/author.php',
				'fields'      => array(
					'disable_author_archives'      => array( 'type' => 'switch' ),
					'url_author_base'              => array( 'type' => 'text' ),
					'author_custom_robots'         => array( 'type' => 'toggle' ),
					'author_robots'                => array( 'type' => 'multicheck', 'enum' => self::ROBOTS ),
					'author_advanced_robots'       => array( 'type' => 'advanced_robots' ),
					'author_archive_title'         => array( 'type' => 'text' ),
					'author_archive_description'   => array( 'type' => 'textarea_small' ),
					'author_slack_enhanced_sharing' => array( 'type' => 'toggle' ),
					'author_add_meta_box'          => array( 'type' => 'toggle' ),
				),
			),
			'titles-misc'               => array(
				'option_type' => 'titles',
				'cap'         => 'titles',
				'dynamic'     => null,
				'source'      => 'includes/settings/titles/misc.php',
				'fields'      => array(
					'disable_date_archives'      => array( 'type' => 'switch' ),
					'date_archive_title'         => array( 'type' => 'text' ),
					'date_archive_description'   => array( 'type' => 'textarea_small' ),
					'date_archive_robots'        => array( 'type' => 'multicheck', 'enum' => self::ROBOTS ),
					'date_advanced_robots'       => array( 'type' => 'advanced_robots' ),
					'404_title'                  => array( 'type' => 'text' ),
					'search_title'               => array( 'type' => 'text' ),
					'noindex_search'             => array( 'type' => 'toggle' ),
					'noindex_archive_subpages'   => array( 'type' => 'toggle' ),
					'noindex_paginated_pages'    => array( 'type' => 'toggle' ),
					'noindex_password_protected' => array( 'type' => 'toggle' ),
				),
			),
			'titles-social'             => array(
				'option_type' => 'titles',
				'cap'         => 'titles',
				'dynamic'     => null,
				'source'      => 'includes/settings/titles/social.php',
				'fields'      => array(
					'social_url_facebook'        => array( 'type' => 'text_url' ),
					'facebook_author_urls'       => array( 'type' => 'text_url' ),
					'facebook_admin_id'          => array( 'type' => 'text' ),
					'facebook_app_id'            => array( 'type' => 'text' ),
					'facebook_secret'            => array( 'type' => 'text' ),
					'twitter_author_names'       => array( 'type' => 'text' ),
					'social_additional_profiles' => array( 'type' => 'textarea_small' ),
				),
			),
			'titles-local-seo'          => array(
				'option_type' => 'titles',
				'cap'         => 'titles',
				'dynamic'     => null,
				'source'      => 'includes/modules/local-seo/views/titles-options.php',
				'fields'      => array(
					'knowledgegraph_type'      => array( 'type' => 'radio_inline', 'enum' => array( 'company', 'person' ) ),
					'website_name'             => array( 'type' => 'text' ),
					'website_alternate_name'   => array( 'type' => 'text' ),
					'knowledgegraph_name'      => array( 'type' => 'text' ),
					'organization_description' => array( 'type' => 'textarea_small' ),
					'knowledgegraph_logo'      => array( 'type' => 'file' ),
					'url'                      => array( 'type' => 'text_url' ),
					'email'                    => array( 'type' => 'text' ),
					'phone'                    => array( 'type' => 'text' ),
					'local_address'            => array( 'type' => 'address' ),
					'local_address_format'     => array( 'type' => 'textarea_small' ),
					'local_business_type'      => array( 'type' => 'select' ),
					'opening_hours_format'     => array( 'type' => 'switch' ),
					'opening_hours'            => array(
						'type'         => 'group',
						'group_schema' => array(
							'day'  => array( 'type' => 'select', 'enum' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ) ),
							// Local_Seo::get_opening_hours() keys rows by 'time' and
							// skips any row whose time is empty, so an empty value
							// silently drops the row. Require the HH:MM-HH:MM shape.
							'time' => array( 'type' => 'text', 'pattern' => '/^\d{2}:\d{2}-\d{2}:\d{2}$/', 'required' => true ),
						),
					),
					'phone_numbers'            => array(
						'type'         => 'group',
						'group_schema' => array(
							'type'   => array( 'type' => 'select' ),
							'number' => array( 'type' => 'text' ),
						),
					),
					'price_range'              => array( 'type' => 'text' ),
					'additional_info'          => array(
						'type'         => 'group',
						'group_schema' => array(
							'type'  => array( 'type' => 'select' ),
							'value' => array( 'type' => 'text' ),
						),
					),
					'local_seo_about_page'     => array( 'type' => 'select' ),
					'local_seo_contact_page'   => array( 'type' => 'select' ),
					'maps_api_key'             => array( 'type' => 'password' ),
					'geo'                      => array( 'type' => 'text' ),
				),
			),
			'titles-post-type'          => array(
				'option_type' => 'titles',
				'cap'         => 'titles',
				'dynamic'     => 'post_type',
				'source'      => 'includes/settings/titles/post-types.php',
				'fields'      => array(
					'pt_{object}_title'                => array( 'type' => 'text' ),
					'pt_{object}_description'          => array( 'type' => 'textarea_small' ),
					'pt_{object}_archive_title'        => array( 'type' => 'text' ),
					'pt_{object}_archive_description'  => array( 'type' => 'textarea_small' ),
					'pt_{object}_default_rich_snippet' => array( 'type' => 'radio_inline' ),
					'pt_{object}_default_snippet_name' => array( 'type' => 'text' ),
					'pt_{object}_default_snippet_desc' => array( 'type' => 'textarea' ),
					'pt_{object}_default_article_type' => array( 'type' => 'radio_inline' ),
					'pt_{object}_custom_robots'        => array( 'type' => 'toggle' ),
					'pt_{object}_robots'               => array( 'type' => 'multicheck', 'enum' => self::ROBOTS ),
					'pt_{object}_advanced_robots'      => array( 'type' => 'advanced_robots' ),
					'pt_{object}_link_suggestions'     => array( 'type' => 'toggle' ),
					'pt_{object}_ls_use_fk'            => array( 'type' => 'radio_inline' ),
					'pt_{object}_primary_taxonomy'     => array( 'type' => 'select' ),
					'pt_{object}_facebook_image'       => array( 'type' => 'file' ),
					'pt_{object}_bulk_editing'         => array( 'type' => 'radio_inline' ),
					'pt_{object}_slack_enhanced_sharing' => array( 'type' => 'toggle' ),
					'pt_{object}_add_meta_box'         => array( 'type' => 'toggle' ),
					'pt_{object}_analyze_fields'       => array( 'type' => 'textarea_small' ),
				),
			),
			'titles-taxonomy'           => array(
				'option_type' => 'titles',
				'cap'         => 'titles',
				'dynamic'     => 'taxonomy',
				'source'      => 'includes/settings/titles/taxonomies.php',
				'fields'      => array(
					'tax_{object}_title'                 => array( 'type' => 'text' ),
					'tax_{object}_description'           => array( 'type' => 'textarea_small' ),
					'tax_{object}_custom_robots'         => array( 'type' => 'toggle' ),
					'tax_{object}_robots'                => array( 'type' => 'multicheck', 'enum' => self::ROBOTS ),
					'tax_{object}_advanced_robots'       => array( 'type' => 'advanced_robots' ),
					'tax_{object}_slack_enhanced_sharing' => array( 'type' => 'toggle' ),
					'tax_{object}_add_meta_box'          => array( 'type' => 'toggle' ),
					'remove_{object}_snippet_data'       => array( 'type' => 'toggle' ),
				),
			),

			// ---------------------------------------------------------------
			// sitemap — option blob rank-math-options-sitemap, cap rank_math_sitemap
			// ---------------------------------------------------------------
			'sitemap-general'           => array(
				'option_type' => 'sitemap',
				'cap'         => 'sitemap',
				'dynamic'     => null,
				'source'      => 'includes/modules/sitemap/settings/general.php',
				'fields'      => array(
					'multilingual_sitemap_notice' => array( 'type' => 'notice' ),
					'items_per_page'              => array( 'type' => 'text', 'min' => 1, 'max' => 5000 ),
					'include_images'              => array( 'type' => 'toggle' ),
					'include_featured_image'      => array( 'type' => 'toggle' ),
					'exclude_posts'               => array( 'type' => 'text' ),
					'exclude_terms'               => array( 'type' => 'text' ),
				),
			),
			'sitemap-post-type'         => array(
				'option_type' => 'sitemap',
				'cap'         => 'sitemap',
				'dynamic'     => 'post_type',
				'source'      => 'includes/modules/sitemap/settings/post-types.php',
				'fields'      => array(
					'attachment_redirect_urls_notice' => array( 'type' => 'notice' ),
					'pt_{object}_sitemap'             => array( 'type' => 'toggle' ),
					'pt_{object}_html_sitemap'        => array( 'type' => 'toggle' ),
					'pt_{object}_image_customfields'  => array( 'type' => 'textarea_small' ),
				),
			),
			'sitemap-taxonomy'          => array(
				'option_type' => 'sitemap',
				'cap'         => 'sitemap',
				'dynamic'     => 'taxonomy',
				'source'      => 'includes/modules/sitemap/settings/taxonomies.php',
				'fields'      => array(
					'tax_{object}_sitemap'       => array( 'type' => 'toggle' ),
					'tax_{object}_html_sitemap'  => array( 'type' => 'toggle' ),
					'tax_{object}_include_empty' => array( 'type' => 'toggle' ),
				),
			),
		);
	}

	/**
	 * Panel slugs, in a stable order. This is the input enum for get-settings.
	 *
	 * @return string[]
	 */
	public static function panel_slugs(): array {
		return array_keys( self::panels() );
	}

	/**
	 * One panel definition.
	 *
	 * @param string $slug Panel slug.
	 * @return array<string,mixed>|null
	 */
	public static function panel( string $slug ): ?array {
		$panels = self::panels();
		return $panels[ $slug ] ?? null;
	}

	/**
	 * Resolve a panel's fields, substituting {object} on dynamic panels.
	 *
	 * @param string $panel  Panel slug.
	 * @param string $object Post type or taxonomy name; ignored on static panels.
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields_for( string $panel, string $object = '' ): array {
		$definition = self::panel( $panel );
		if ( null === $definition ) {
			return array();
		}
		if ( null === $definition['dynamic'] || '' === $object ) {
			return $definition['fields'];
		}

		$resolved = array();
		foreach ( $definition['fields'] as $id => $spec ) {
			$resolved[ str_replace( self::OBJECT_TOKEN, $object, $id ) ] = $spec;
		}
		return $resolved;
	}

	/**
	 * The $field_types argument for Option_Center::save_settings().
	 *
	 * Read-only fields are omitted — they are never part of a write.
	 *
	 * @param string $panel  Panel slug.
	 * @param string $object Post type or taxonomy name.
	 * @return array<string,string>
	 */
	public static function field_types_for( string $panel, string $object = '' ): array {
		$types = array();
		foreach ( self::fields_for( $panel, $object ) as $id => $spec ) {
			$emitted = self::emitted_type( (string) $spec['type'] );
			if ( null !== $emitted ) {
				$types[ $id ] = $emitted;
			}
		}
		return $types;
	}

	/**
	 * Translate a legacy CMB2 type into the type we emit to Rank Math.
	 *
	 * @param string $legacy Legacy CMB2 type from the Rank Math source.
	 * @return string|null Null when the field is not writable.
	 */
	public static function emitted_type( string $legacy ): ?string {
		return self::LEGACY_TO_SANITIZER[ $legacy ] ?? null;
	}

	/**
	 * Whether a legacy type is display-only.
	 *
	 * @param string $legacy Legacy CMB2 type.
	 * @return bool
	 */
	public static function is_readonly_type( string $legacy ): bool {
		return in_array( $legacy, self::READONLY_TYPES, true ) || ! isset( self::LEGACY_TO_SANITIZER[ $legacy ] );
	}

	/**
	 * Whether a field id is on the deny list.
	 *
	 * @param string $field_id Field id.
	 * @return bool
	 */
	public static function is_denied( string $field_id ): bool {
		return in_array( $field_id, self::DENIED_KEYS, true );
	}

	/**
	 * Validate and normalise a set of values against a panel.
	 *
	 * Rejects the whole write on the first problem rather than partially
	 * applying — FR-007. Runs BEFORE Rank Math's sanitizer, which is our second
	 * line of defence, not our first.
	 *
	 * @param string              $panel  Panel slug.
	 * @param string              $object Post type or taxonomy name.
	 * @param array<string,mixed> $values Submitted values.
	 * @return array<string,mixed>|WP_Error Normalised values, or the first error.
	 */
	public static function validate( string $panel, string $object, array $values ) {
		$definition = self::panel( $panel );
		if ( null === $definition ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: panel slug */
					__( 'Unknown settings panel "%s".', 'acrossai-abilities-manager' ),
					$panel
				)
			);
		}
		if ( null !== $definition['dynamic'] && '' === $object ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: panel slug, 2: the kind of object required */
					__( 'Panel "%1$s" requires an object (%2$s).', 'acrossai-abilities-manager' ),
					$panel,
					$definition['dynamic']
				)
			);
		}
		if ( array() === $values ) {
			return new WP_Error( 'invalid_input', __( 'No settings were supplied.', 'acrossai-abilities-manager' ) );
		}

		$fields     = self::fields_for( $panel, $object );
		$normalised = array();

		foreach ( $values as $id => $value ) {
			$id = (string) $id;

			if ( self::is_denied( $id ) ) {
				return new WP_Error(
					'protected_field',
					sprintf(
						/* translators: %s: field id */
						__( 'The field "%s" cannot be written through this ability.', 'acrossai-abilities-manager' ),
						$id
					)
				);
			}

			if ( ! isset( $fields[ $id ] ) ) {
				return new WP_Error(
					'unknown_field',
					sprintf(
						/* translators: 1: field id, 2: panel slug */
						__( 'The field "%1$s" does not belong to panel "%2$s". Read the panel with rank-math/get-settings-panel to see its fields.', 'acrossai-abilities-manager' ),
						$id,
						$panel
					)
				);
			}

			$spec = $fields[ $id ];
			if ( self::is_readonly_type( (string) $spec['type'] ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %s: field id */
						__( 'The field "%s" is read-only.', 'acrossai-abilities-manager' ),
						$id
					)
				);
			}

			$clean = self::validate_value( $id, $value, $spec );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$normalised[ $id ] = $clean;
		}

		return $normalised;
	}

	/**
	 * Validate and normalise one value against its field spec.
	 *
	 * @param string               $id    Field id, for error messages.
	 * @param mixed                $value Submitted value.
	 * @param array<string,mixed>  $spec  Field spec.
	 * @return mixed|WP_Error
	 */
	private static function validate_value( string $id, $value, array $spec ) {
		$emitted = self::emitted_type( (string) $spec['type'] );

		switch ( $emitted ) {
			case self::TYPE_TOGGLE:
				if ( is_bool( $value ) ) {
					return $value ? 'on' : 'off';
				}
				if ( in_array( $value, array( 'on', 'off' ), true ) ) {
					return $value;
				}
				return self::invalid( $id, __( 'expected true, false, "on" or "off"', 'acrossai-abilities-manager' ) );

			case self::TYPE_NUMBER:
				return self::validate_number( $id, $value, $spec );

			case self::TYPE_SELECT:
				if ( is_array( $value ) ) {
					return self::invalid( $id, __( 'expected a single value, not a list', 'acrossai-abilities-manager' ) );
				}
				$value = (string) $value;
				if ( ! empty( $spec['enum'] ) && ! in_array( $value, $spec['enum'], true ) ) {
					return self::invalid(
						$id,
						sprintf(
							/* translators: %s: comma-separated allowed values */
							__( 'expected one of: %s', 'acrossai-abilities-manager' ),
							implode( ', ', $spec['enum'] )
						)
					);
				}
				return $value;

			case self::TYPE_CHECKBOXLIST:
				if ( ! is_array( $value ) ) {
					return self::invalid( $id, __( 'expected a list', 'acrossai-abilities-manager' ) );
				}
				$list = array();
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						return self::invalid( $id, __( 'expected a flat list of values', 'acrossai-abilities-manager' ) );
					}
					$item = (string) $item;
					if ( ! empty( $spec['enum'] ) && ! in_array( $item, $spec['enum'], true ) ) {
						return self::invalid(
							$id,
							sprintf(
								/* translators: 1: submitted value, 2: comma-separated allowed values */
								__( 'value "%1$s" is not allowed; expected one of: %2$s', 'acrossai-abilities-manager' ),
								$item,
								implode( ', ', $spec['enum'] )
							)
						);
					}
					$list[] = $item;
				}
				// Re-index so the list stays sequential.
				return array_values( $list );

			case self::TYPE_GROUP:
				return self::validate_group( $id, $value, $spec );

			case self::TYPE_FILE:
			case self::TYPE_TEXT:
			case self::TYPE_TEXTAREA:
				if ( is_array( $value ) || is_object( $value ) ) {
					return self::invalid( $id, __( 'expected a string', 'acrossai-abilities-manager' ) );
				}
				$value = (string) $value;
				if ( ! empty( $spec['pattern'] ) && ! preg_match( (string) $spec['pattern'], $value ) ) {
					return self::invalid(
						$id,
						sprintf(
							/* translators: %s: regular expression */
							__( 'value does not match the required format %s', 'acrossai-abilities-manager' ),
							(string) $spec['pattern']
						)
					);
				}
				// A 'text'-typed count field: enforce the range without changing
				// the emitted type, so storage stays byte-identical to the UI.
				if ( isset( $spec['min'] ) || isset( $spec['max'] ) ) {
					$checked = self::validate_number( $id, $value, $spec );
					if ( is_wp_error( $checked ) ) {
						return $checked;
					}
					return (string) $checked;
				}
				return $value;
		}

		return self::invalid( $id, __( 'field type is not writable', 'acrossai-abilities-manager' ) );
	}

	/**
	 * Validate an integer against optional min/max bounds.
	 *
	 * @param string              $id    Field id.
	 * @param mixed               $value Submitted value.
	 * @param array<string,mixed> $spec  Field spec.
	 * @return int|WP_Error
	 */
	private static function validate_number( string $id, $value, array $spec ) {
		if ( ! is_numeric( $value ) ) {
			return self::invalid( $id, __( 'expected a number', 'acrossai-abilities-manager' ) );
		}
		$number = (int) $value;
		if ( isset( $spec['min'] ) && $number < (int) $spec['min'] ) {
			return self::invalid(
				$id,
				sprintf(
					/* translators: %d: minimum value */
					__( 'must be at least %d', 'acrossai-abilities-manager' ),
					(int) $spec['min']
				)
			);
		}
		if ( isset( $spec['max'] ) && $number > (int) $spec['max'] ) {
			return self::invalid(
				$id,
				sprintf(
					/* translators: %d: maximum value */
					__( 'must be at most %d', 'acrossai-abilities-manager' ),
					(int) $spec['max']
				)
			);
		}
		return $number;
	}

	/**
	 * Validate a repeatable group.
	 *
	 * The array_values() re-index is mandatory, not cosmetic:
	 * Sanitize_Settings::sanitize_group_value() distinguishes a repeatable group
	 * from a single one by testing array_keys($v) === range(0, count($v) - 1). A
	 * gapped or string-keyed payload — which JSON decoding readily produces —
	 * is treated as ONE group and silently collapses every row into the first.
	 *
	 * @param string              $id    Field id.
	 * @param mixed               $value Submitted value.
	 * @param array<string,mixed> $spec  Field spec.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private static function validate_group( string $id, $value, array $spec ) {
		if ( ! is_array( $value ) ) {
			return self::invalid( $id, __( 'expected a list of rows', 'acrossai-abilities-manager' ) );
		}

		$schema = isset( $spec['group_schema'] ) && is_array( $spec['group_schema'] ) ? $spec['group_schema'] : array();
		$rows   = array();

		foreach ( array_values( $value ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return self::invalid( $id, __( 'each row must be an object', 'acrossai-abilities-manager' ) );
			}

			$clean_row = array();
			foreach ( $row as $sub_id => $sub_value ) {
				$sub_id = (string) $sub_id;
				if ( array() !== $schema && ! isset( $schema[ $sub_id ] ) ) {
					return new WP_Error(
						'unknown_field',
						sprintf(
							/* translators: 1: sub-field id, 2: field id, 3: row number */
							__( 'Unknown sub-field "%1$s" in "%2$s" row %3$d.', 'acrossai-abilities-manager' ),
							$sub_id,
							$id,
							$index + 1
						)
					);
				}
				$sub_spec  = $schema[ $sub_id ] ?? array( 'type' => 'text' );
				$sub_clean = self::validate_value( $id . '[' . $index . '][' . $sub_id . ']', $sub_value, $sub_spec );
				if ( is_wp_error( $sub_clean ) ) {
					return $sub_clean;
				}
				$clean_row[ $sub_id ] = $sub_clean;
			}

			foreach ( $schema as $sub_id => $sub_spec ) {
				if ( empty( $sub_spec['required'] ) ) {
					continue;
				}
				if ( ! isset( $clean_row[ $sub_id ] ) || '' === $clean_row[ $sub_id ] ) {
					return self::invalid(
						$id . '[' . $index . '][' . $sub_id . ']',
						__( 'is required and must not be empty', 'acrossai-abilities-manager' )
					);
				}
			}

			$rows[] = $clean_row;
		}

		return array_values( $rows );
	}

	/**
	 * Build an invalid_input error naming the field and the expectation.
	 *
	 * @param string $id     Field id.
	 * @param string $expect What was expected.
	 * @return WP_Error
	 */
	private static function invalid( string $id, string $expect ): WP_Error {
		return new WP_Error(
			'invalid_input',
			sprintf(
				/* translators: 1: field id, 2: description of what was expected */
				__( 'Invalid value for "%1$s": %2$s.', 'acrossai-abilities-manager' ),
				$id,
				$expect
			)
		);
	}
}
