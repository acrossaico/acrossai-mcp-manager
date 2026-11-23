<?php
/**
 * Feature 104 — every LiteSpeed Cache setting the suite reads or writes.
 *
 * All persistence goes through `Conf::update_confs()`, LiteSpeed's own canonical write path. A bare
 * `update_option()` would store the value and stop there; `update_confs()` type-casts it and then
 * fires the side effects that make the change real — the conditional purge, cron cleanup,
 * `Activation::update_files()` (which rewrites `.htaccess`) and the CDN config sync. Skipping it is
 * the most likely silent failure in this whole suite: the setting reads back correctly and the site
 * behaves exactly as before.
 *
 * `AREAS` is the writable universe, and it is deliberately narrower than LiteSpeed's own. Only the
 * 150 options belonging to the eight in-scope groups appear; the CDN, Cloudflare, image-optimisation,
 * LQIP and QUIC.cloud options are absent because writing them either needs credentials this suite
 * does not handle or spends paid quota. A key outside `AREAS` is refused with `setting_not_writable`
 * rather than passed through.
 *
 * Each entry carries the option's JSON type, derived from LiteSpeed's own `$_default_options`. That
 * is what lets a writer coerce a caller's value to the shape LiteSpeed expects instead of trusting
 * whatever arrived over JSON.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over LiteSpeed's settings API.
 */
final class Settings_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Writable settings, grouped by area, as option key => JSON type.
	 *
	 * @since  0.0.34
	 * @return array<string, array<string, string>>
	 */
	public static function areas(): array {
		return array(
			'purge'                 => array(
				'purge-hook_all'  => 'array',
				'purge-post_a'    => 'boolean',
				'purge-post_all'  => 'boolean',
				'purge-post_d'    => 'boolean',
				'purge-post_f'    => 'boolean',
				'purge-post_h'    => 'boolean',
				'purge-post_m'    => 'boolean',
				'purge-post_p'    => 'boolean',
				'purge-post_pt'   => 'boolean',
				'purge-post_pwrp' => 'boolean',
				'purge-post_t'    => 'boolean',
				'purge-post_y'    => 'boolean',
				'purge-stale'     => 'boolean',
				'purge-upgrade'   => 'boolean',
			),
			'purge-timed'           => array(
				'purge-timed_urls'      => 'array',
				'purge-timed_urls_time' => 'string',
			),
			'cache-general'         => array(
				'cache'              => 'boolean',
				'esi'                => 'boolean',
				'esi-cache_admbar'   => 'boolean',
				'esi-cache_commform' => 'boolean',
				'esi-nonce'          => 'array',
			),
			'cache-scope'           => array(
				'cache-commenter'    => 'boolean',
				'cache-mobile'       => 'boolean',
				'cache-mobile_rules' => 'array',
				'cache-page_login'   => 'boolean',
				'cache-priv'         => 'boolean',
				'cache-rest'         => 'boolean',
			),
			'cache-ttl'             => array(
				'cache-ajax_ttl'      => 'array',
				'cache-ttl_feed'      => 'integer',
				'cache-ttl_frontpage' => 'integer',
				'cache-ttl_priv'      => 'integer',
				'cache-ttl_pub'       => 'integer',
				'cache-ttl_rest'      => 'integer',
				'cache-ttl_status'    => 'array',
			),
			'cache-exclusions'      => array(
				'cache-drop_qs'        => 'array',
				'cache-exc'            => 'array',
				'cache-exc_cat'        => 'array',
				'cache-exc_cookies'    => 'array',
				'cache-exc_qs'         => 'array',
				'cache-exc_roles'      => 'array',
				'cache-exc_tag'        => 'array',
				'cache-exc_useragents' => 'array',
				'cache-force_pub_uri'  => 'array',
				'cache-force_uri'      => 'array',
				'cache-priv_uri'       => 'array',
			),
			'cache-vary'            => array(
				'cache-login_cookie' => 'string',
				'cache-vary_cookies' => 'array',
				'cache-vary_group'   => 'array',
			),
			'guest'                 => array(
				'guest'      => 'boolean',
				'guest_optm' => 'boolean',
			),
			'browser'               => array(
				'cache-browser'     => 'boolean',
				'cache-ttl_browser' => 'integer',
			),
			'optimize-css'          => array(
				'optm-ccss_con'             => 'string',
				'optm-ccss_per_url'         => 'boolean',
				'optm-ccss_sep_posttype'    => 'array',
				'optm-ccss_sep_uri'         => 'array',
				'optm-ccss_whitelist'       => 'array',
				'optm-css_async'            => 'boolean',
				'optm-css_async_inline'     => 'boolean',
				'optm-css_comb'             => 'boolean',
				'optm-css_comb_ext_inl'     => 'boolean',
				'optm-css_exc'              => 'array',
				'optm-css_min'              => 'boolean',
				'optm-ucss'                 => 'boolean',
				'optm-ucss_exc'             => 'array',
				'optm-ucss_file_exc_inline' => 'array',
				'optm-ucss_inline'          => 'boolean',
				'optm-ucss_whitelist'       => 'array',
			),
			'optimize-js'           => array(
				'optm-js_comb'         => 'boolean',
				'optm-js_comb_ext_inl' => 'boolean',
				'optm-js_defer'        => 'boolean',
				'optm-js_defer_exc'    => 'array',
				'optm-js_delay_inc'    => 'array',
				'optm-js_exc'          => 'array',
				'optm-js_min'          => 'boolean',
			),
			'optimize-html'         => array(
				'optm-dns_preconnect'    => 'array',
				'optm-dns_prefetch'      => 'array',
				'optm-dns_prefetch_ctrl' => 'boolean',
				'optm-emoji_rm'          => 'boolean',
				'optm-html_lazy'         => 'array',
				'optm-html_min'          => 'boolean',
				'optm-html_skip_comment' => 'array',
				'optm-noscript_rm'       => 'boolean',
				'optm-qs_rm'             => 'boolean',
			),
			'optimize-font'         => array(
				'optm-css_font_display' => 'boolean',
				'optm-ggfonts_async'    => 'boolean',
				'optm-ggfonts_rm'       => 'boolean',
			),
			'optimize-tuning'       => array(
				'optm-exc'        => 'array',
				'optm-exc_roles'  => 'array',
				'optm-gm_js_exc'  => 'array',
				'optm-guest_only' => 'boolean',
			),
			'optimize-localization' => array(
				'discuss-avatar_cache'     => 'boolean',
				'discuss-avatar_cache_ttl' => 'integer',
				'discuss-avatar_cron'      => 'boolean',
				'optm-localize'            => 'boolean',
				'optm-localize_domains'    => 'array',
			),
			'media-lazyload'        => array(
				'media-add_missing_sizes' => 'boolean',
				'media-auto_rescale_ori'  => 'boolean',
				'media-iframe_lazy'       => 'boolean',
				'media-lazy'              => 'boolean',
				'media-lazy_placeholder'  => 'string',
			),
			'media-placeholder'     => array(
				'media-placeholder_resp'       => 'boolean',
				'media-placeholder_resp_async' => 'boolean',
				'media-placeholder_resp_color' => 'string',
				'media-placeholder_resp_svg'   => 'string',
			),
			'media-exclusions'      => array(
				'media-iframe_lazy_cls_exc'        => 'array',
				'media-iframe_lazy_parent_cls_exc' => 'array',
				'media-lazy_cls_exc'               => 'array',
				'media-lazy_exc'                   => 'array',
				'media-lazy_parent_cls_exc'        => 'array',
				'media-lazy_uri_exc'               => 'array',
			),
			'media-viewport'        => array(
				'media-vpi'      => 'boolean',
				'media-vpi_cron' => 'boolean',
			),
			'crawler'               => array(
				'crawler'                => 'boolean',
				'crawler-cookies'        => 'array',
				'crawler-crawl_interval' => 'integer',
				'crawler-load_limit'     => 'integer',
				'crawler-roles'          => 'array',
				'crawler-sitemap'        => 'string',
			),
			'object-cache'          => array(
				'object'                       => 'boolean',
				'object-admin'                 => 'boolean',
				'object-db_id'                 => 'integer',
				'object-global_groups'         => 'array',
				'object-host'                  => 'string',
				'object-kind'                  => 'boolean',
				'object-life'                  => 'integer',
				'object-non_persistent_groups' => 'array',
				'object-persistent'            => 'boolean',
				'object-port'                  => 'integer',
				'object-pswd'                  => 'string',
				'object-user'                  => 'string',
			),
			'database'              => array(
				'db_optm-revisions_age' => 'integer',
				'db_optm-revisions_max' => 'integer',
			),
			'advanced'              => array(
				'debug'                     => 'boolean',
				'debug-collapse_qs'         => 'boolean',
				'debug-disable_all'         => 'boolean',
				'debug-exc'                 => 'array',
				'debug-exc_strings'         => 'array',
				'debug-filesize'            => 'integer',
				'debug-inc'                 => 'array',
				'debug-ips'                 => 'array',
				'debug-level'               => 'boolean',
				'misc-heartbeat_back'       => 'boolean',
				'misc-heartbeat_back_ttl'   => 'integer',
				'misc-heartbeat_editor'     => 'boolean',
				'misc-heartbeat_editor_ttl' => 'integer',
				'misc-heartbeat_front'      => 'boolean',
				'misc-heartbeat_front_ttl'  => 'integer',
				'util-instant_click'        => 'boolean',
				'util-no_https_vary'        => 'boolean',
			),
		);
	}

	/**
	 * The option keys one area owns.
	 *
	 * @since  0.0.34
	 * @param  string $area Area key.
	 * @return array<string, string> option key => type. Empty when the area is unknown.
	 */
	public static function keys_for( string $area ): array {
		return self::areas()[ $area ] ?? array();
	}

	/**
	 * Whether one option key is writable by this suite.
	 *
	 * @since  0.0.34
	 * @param  string $key Option key.
	 * @return bool
	 */
	public static function is_writable( string $key ): bool {
		foreach ( self::areas() as $keys ) {
			if ( isset( $keys[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Which area owns one option key.
	 *
	 * The exclusion-list abilities need this because their key maps span areas: the optimisation
	 * exclusions alone reach into `optimize-css`, `optimize-js` and `optimize-tuning`. Hardcoding one
	 * area there produced a `setting_not_writable` for every key that lived elsewhere — found live.
	 * Resolving the area from the key makes that class of mistake impossible.
	 *
	 * @since  0.0.34
	 * @param  string $key Option key.
	 * @return string Area key, or '' when nothing owns it.
	 */
	public static function area_for( string $key ): string {
		foreach ( self::areas() as $area => $keys ) {
			if ( isset( $keys[ $key ] ) ) {
				return (string) $area;
			}
		}

		return '';
	}

	/**
	 * Read one option's current value.
	 *
	 * `Root::conf()` rather than `get_option()`: LiteSpeed resolves constants defined in wp-config
	 * over stored values, and only its own accessor knows that.
	 *
	 * @since  0.0.34
	 * @param  string $key Option key.
	 * @return mixed
	 */
	public static function value( string $key ) {
		return \LiteSpeed\Core::cls( 'Conf' )->conf( $key );
	}

	/**
	 * Describe a set of options as ROWS.
	 *
	 * A list, not a map. `[ 'cache' => true ]` is the natural shape and the wrong one: PHP encodes an
	 * associative array as a JSON object, so an output property declared `type => array` fails the
	 * ability's own output schema — after the work is done
	 * (BUG-ARRAY-TYPED-OUTPUT-IS-A-JSON-OBJECT). Every reader in this suite returns these rows, and
	 * every writer echoes them back, so a caller sees one shape throughout.
	 *
	 * @since  0.0.34
	 * @param  array<string, string> $keys option key => type.
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe( array $keys ): array {
		$rows = array();

		foreach ( $keys as $key => $type ) {
			$rows[] = array(
				'key'      => (string) $key,
				'value'    => self::cast( self::value( (string) $key ), (string) $type ),
				'type'     => (string) $type,
				'writable' => self::is_writable( (string) $key ),
			);
		}

		return $rows;
	}

	/**
	 * Describe every option in one area.
	 *
	 * @since  0.0.34
	 * @param  string $area Area key.
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe_area( string $area ): array {
		return self::describe( self::keys_for( $area ) );
	}

	/**
	 * Coerce a value to the type LiteSpeed stores for that option.
	 *
	 * @since  0.0.34
	 * @param  mixed  $value Raw value.
	 * @param  string $type  One of boolean|integer|string|array.
	 * @return mixed
	 */
	public static function cast( $value, string $type ) {
		switch ( $type ) {
			case 'boolean':
				return (bool) $value;

			case 'integer':
				return (int) $value;

			case 'array':
				if ( is_array( $value ) ) {
					return array_values( array_map( 'strval', $value ) );
				}

				// LiteSpeed's admin stores these as newline-separated text, so accept that too.
				$lines = preg_split( '/\r\n|\r|\n/', (string) $value );

				return array_values( array_filter( array_map( 'trim', is_array( $lines ) ? $lines : array() ), static fn( string $v ): bool => '' !== $v ) );

			default:
				return (string) $value;
		}
	}

	/**
	 * Apply an add/remove/replace mode to a list-valued setting.
	 *
	 * The `mode` parameter exists so a caller can add one line to an exclusion list without first
	 * reading it, changing it and writing the whole thing back — a read-modify-write an AI client
	 * gets wrong by dropping entries it did not know about.
	 *
	 * @since  0.0.34
	 * @param  array<int,string> $current Existing values.
	 * @param  array<int,string> $values  Incoming values.
	 * @param  string            $mode    replace|add|remove.
	 * @return array<int,string>
	 */
	public static function merge_list( array $current, array $values, string $mode ): array {
		$values = array_values( array_filter( array_map( 'trim', array_map( 'strval', $values ) ), static fn( string $v ): bool => '' !== $v ) );

		if ( 'add' === $mode ) {
			return array_values( array_unique( array_merge( $current, $values ) ) );
		}

		if ( 'remove' === $mode ) {
			return array_values( array_diff( $current, $values ) );
		}

		return $values;
	}

	/**
	 * Write a patch of settings belonging to one area.
	 *
	 * Refuses the whole patch when any key is outside the area — a partial write on a rejected input
	 * would leave the caller unable to tell what landed.
	 *
	 * @since  0.0.34
	 * @param  string              $area  Area key.
	 * @param  array<string,mixed> $patch option key => new value.
	 * @return array<int, string>|WP_Error Keys actually changed.
	 */
	public static function write( string $area, array $patch ) {
		$allowed = self::keys_for( $area );

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

		$matrix  = array();
		$changed = array();

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

			$cast = self::cast( $value, $allowed[ $key ] );

			if ( self::cast( self::value( $key ), $allowed[ $key ] ) === $cast ) {
				continue;
			}

			$matrix[ $key ] = $cast;
			$changed[]      = $key;
		}

		if ( array() === $matrix ) {
			return array();
		}

		self::save( $matrix );

		return $changed;
	}

	/**
	 * The ONE call site that persists a setting.
	 *
	 * Centralised so the side effects above can never be bypassed by a new ability, and so there is a
	 * single place to absorb a change in LiteSpeed's API.
	 * Test_LiteSpeed_Architecture asserts nothing else in the suite calls it.
	 *
	 * @since  0.0.34
	 * @param  array<string,mixed> $matrix option key => value.
	 * @return void
	 */
	private static function save( array $matrix ): void {
		\LiteSpeed\Core::cls( 'Conf' )->update_confs( $matrix );
	}
}
