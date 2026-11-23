<?php
/**
 * The integration catalogue, fetched from acrossai.co.
 *
 * The Integrations screen answers two different questions about the same list, and only one of them
 * can be answered locally. "Is this integration on, and how many abilities does it contribute?" is
 * site state, and `AcrossAI_Integration_Settings` already knows it. "What is this integration, what
 * category is it, is it free or Pro, where do I read about it?" is catalogue copy that changes when
 * an integration is written — which happens between plugin releases, so a hardcoded array here
 * would be stale the week after it shipped.
 *
 * So the catalogue is fetched. That makes this the plugin's first outbound HTTP request from PHP,
 * and the rules it is held to are correspondingly narrow:
 *
 * - It runs only when an administrator opens the screen. Never on a front-end request, never on
 *   cron, never on activation, and never for a user who could not see the result anyway.
 * - It sends nothing. No query arguments, no headers, no body — the request carries no site URL, no
 *   licence key, no user identity, nothing that distinguishes this site from any other. The reply
 *   is the same bytes for everybody, which is what makes it cacheable and what makes the
 *   "External services" disclosure in readme.txt short.
 * - It never blocks the screen. A failure is normal operation, not an error state: a copy of the
 *   catalogue ships in the plugin and renders instead, flagged stale so the UI can say so quietly.
 * - It trusts nothing it reads. `normalise()` is a whitelist, not a filter — every field is coerced
 *   to an expected scalar, length-capped, and dropped if it cannot be made safe. Anything not named
 *   in the schema is discarded rather than passed through. A compromised or simply changed endpoint
 *   can make this screen wrong, but it cannot make it dangerous.
 *
 * The join itself is not done here. This class answers only "what does the catalogue say", and the
 * REST controller merges that onto local truth — which means a catalogue entry can never invent an
 * integration the site does not have, nor claim one is enabled when it is not.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/Includes/Modules/Library
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Modules\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the integration catalogue, from acrossai.co or from the bundled copy.
 */
class AcrossAI_Integration_Directory {

	/**
	 * Catalogue endpoint.
	 *
	 * A purpose-built route rather than `/wp/v2/integrations`: core's route returns rendered post
	 * content, and those posts are 11-13 KB each, so the standard collection would ship a quarter
	 * of a megabyte of HTML to deliver six fields per row.
	 *
	 * @var string
	 */
	public const ENDPOINT = 'https://acrossai.co/wp-json/acrossai/v1/integrations';

	/**
	 * Transient holding the last good fetch.
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'acrossai_integration_directory';

	/**
	 * How long a good fetch is trusted.
	 *
	 * Twelve hours: the catalogue changes when an integration page is written, which is a
	 * human-scale event, and nothing on this screen is worth a shorter interval.
	 *
	 * @var int
	 */
	public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Transient marking a recent failure, so a dead endpoint is asked once per interval.
	 *
	 * Without this, every page load during an outage pays the full timeout. The screen would still
	 * render — the bundled copy covers it — but it would take seconds to do so, repeatedly.
	 *
	 * @var string
	 */
	public const BACKOFF_KEY = 'acrossai_integration_directory_backoff';

	/**
	 * How long to wait after a failure before trying again.
	 *
	 * @var int
	 */
	public const BACKOFF_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Request timeout. Short: nothing here is worth making an admin wait.
	 *
	 * @var int
	 */
	public const TIMEOUT = 5;

	/**
	 * Largest response body accepted, in bytes.
	 *
	 * The real feed is a few kilobytes. This is a cap on how much a hostile or broken endpoint can
	 * make this site parse, checked before `json_decode()` rather than after.
	 *
	 * @var int
	 */
	public const MAX_BYTES = 262144;

	/**
	 * Most entries accepted from one response.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 200;

	/**
	 * Bundled copy of the catalogue, relative to the plugin root.
	 *
	 * @var string
	 */
	public const FALLBACK_FILE = 'assets/abilities/integrations.json';

	/**
	 * Per-request memo, so one page load fetches at most once.
	 *
	 * @var array{entries: array<string, array<string, string>>, stale: bool}|null
	 */
	private static $memo = null;

	/**
	 * The catalogue, and whether it is the bundled copy.
	 *
	 * @since  0.0.42
	 * @param  bool $force Bypass the request memo and the transient, for an explicit refresh.
	 * @return array{entries: array<string, array<string, string>>, stale: bool}
	 */
	public static function get( bool $force = false ): array {
		if ( ! $force && null !== self::$memo ) {
			return self::$memo;
		}

		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );

			if ( is_array( $cached ) && array() !== $cached ) {
				self::$memo = array(
					'entries' => $cached,
					'stale'   => false,
				);

				return self::$memo;
			}
		}

		$fetched = self::fetch( $force );

		if ( array() !== $fetched ) {
			set_site_transient( self::CACHE_KEY, $fetched, self::CACHE_TTL );
			delete_site_transient( self::BACKOFF_KEY );

			self::$memo = array(
				'entries' => $fetched,
				'stale'   => false,
			);

			return self::$memo;
		}

		self::$memo = array(
			'entries' => self::bundled(),
			'stale'   => true,
		);

		return self::$memo;
	}

	/**
	 * Drop the cached catalogue.
	 *
	 * @since  0.0.42
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = null;

		delete_site_transient( self::CACHE_KEY );
		delete_site_transient( self::BACKOFF_KEY );
	}

	/**
	 * Request the catalogue.
	 *
	 * Returns an empty array for every failure mode — unreachable, non-200, oversized, unparseable,
	 * schema mismatch. The caller treats all of them the same way, because from the screen's point
	 * of view they are the same thing: no fresh catalogue, use the bundled one.
	 *
	 * @since  0.0.42
	 * @param  bool $force Ignore the failure backoff.
	 * @return array<string, array<string, string>>
	 */
	private static function fetch( bool $force = false ): array {
		if ( ! $force && false !== get_site_transient( self::BACKOFF_KEY ) ) {
			return array();
		}

		/**
		 * Filters the catalogue endpoint.
		 *
		 * Exists so a site that cannot reach acrossai.co — an air-gapped install, or one behind a
		 * proxy that mirrors the feed — can point this somewhere it can reach, and so the test
		 * suite can serve a fixture. Returning an empty string disables fetching entirely and
		 * pins the screen to the bundled copy.
		 *
		 * @since 0.0.42
		 * @param string $endpoint Catalogue URL.
		 */
		$endpoint = (string) apply_filters( 'acrossai_integration_directory_endpoint', self::ENDPOINT );

		if ( '' === $endpoint ) {
			return array();
		}

		// No arguments, no cookies, no identifying headers. `wp_safe_remote_get()` rather than
		// `wp_remote_get()` so the URL is held to WordPress's own SSRF protections even though it
		// is a constant here — the filter above means it is not always one.
		$response = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				'sslverify'   => true,
				'cookies'     => array(),
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::BACKOFF_KEY, 1, self::BACKOFF_TTL );

			return array();
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body || strlen( $body ) > self::MAX_BYTES ) {
			set_site_transient( self::BACKOFF_KEY, 1, self::BACKOFF_TTL );

			return array();
		}

		$entries = self::normalise( json_decode( $body, true ) );

		if ( array() === $entries ) {
			set_site_transient( self::BACKOFF_KEY, 1, self::BACKOFF_TTL );
		}

		return $entries;
	}

	/**
	 * The copy that ships with the plugin.
	 *
	 * Passed through the same `normalise()` as the remote body. It is our own file, but running it
	 * through the identical whitelist means the screen only ever handles one shape, and a mistake
	 * in the bundled JSON degrades the same way a bad response does rather than reaching the view.
	 *
	 * @since  0.0.42
	 * @return array<string, array<string, string>>
	 */
	private static function bundled(): array {
		$path = defined( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH' )
			? \ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH . self::FALLBACK_FILE
			: dirname( __DIR__, 4 ) . '/' . self::FALLBACK_FILE;

		if ( ! is_readable( $path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a file shipped inside this plugin, not a remote resource.
		$raw = file_get_contents( $path );

		if ( false === $raw ) {
			return array();
		}

		return self::normalise( json_decode( $raw, true ) );
	}

	/**
	 * Coerce a decoded response into the catalogue shape, or nothing.
	 *
	 * A whitelist by construction: the returned rows are built field by field from scratch rather
	 * than by filtering the input, so no key that is not named below can survive into the view.
	 *
	 * @since  0.0.42
	 * @param  mixed $decoded Decoded JSON.
	 * @return array<string, array<string, string>> Keyed by slug.
	 */
	private static function normalise( $decoded ): array {
		if ( ! is_array( $decoded ) || ! isset( $decoded['integrations'] ) || ! is_array( $decoded['integrations'] ) ) {
			return array();
		}

		$out = array();

		foreach ( $decoded['integrations'] as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$slug = isset( $raw['slug'] ) && is_string( $raw['slug'] ) ? sanitize_key( $raw['slug'] ) : '';
			$name = isset( $raw['name'] ) && is_string( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';

			// A row with no slug cannot be joined onto anything, and a row with no name has nothing
			// to show. Either way there is no partial version worth keeping.
			if ( '' === $slug || '' === $name ) {
				continue;
			}

			$out[ $slug ] = array(
				'slug'        => $slug,
				'name'        => self::clip( $name, 80 ),
				'description' => self::clip(
					isset( $raw['description'] ) && is_string( $raw['description'] ) ? sanitize_text_field( $raw['description'] ) : '',
					300
				),
				'category'    => self::clip(
					isset( $raw['category'] ) && is_string( $raw['category'] ) ? sanitize_text_field( $raw['category'] ) : '',
					60
				),
				'product'     => isset( $raw['product'] ) && 'pro' === $raw['product'] ? 'pro' : 'free',
				'wporg_slug'  => isset( $raw['wporg_slug'] ) && is_string( $raw['wporg_slug'] ) ? sanitize_key( $raw['wporg_slug'] ) : '',
				'url'         => self::url( isset( $raw['url'] ) && is_string( $raw['url'] ) ? $raw['url'] : '' ),
				// A hint, not an authority. The live count comes from the registry when the host
				// plugin is actually here; this covers the rows that are listed but not installed,
				// which otherwise could only be shown as "0 abilities".
				'abilities'   => isset( $raw['abilities'] ) && is_numeric( $raw['abilities'] )
					? (string) max( 0, min( 9999, (int) $raw['abilities'] ) )
					: '0',
			);

			if ( count( $out ) >= self::MAX_ENTRIES ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Accept a link only if it points at acrossai.co over HTTPS.
	 *
	 * The screen renders these as links an administrator may click, so the catalogue is not allowed
	 * to aim them anywhere it likes. Anything else becomes an empty string and the view omits the
	 * link rather than rendering a dead or hostile one.
	 *
	 * @since  0.0.42
	 * @param  string $url Candidate URL.
	 * @return string Safe URL, or an empty string.
	 */
	private static function url( string $url ): string {
		$clean = esc_url_raw( $url );

		if ( '' === $clean ) {
			return '';
		}

		$parts = wp_parse_url( $clean );

		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return '';
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		return ( 'acrossai.co' === $host || 'www.acrossai.co' === $host ) ? $clean : '';
	}

	/**
	 * Hard-limit a string's length.
	 *
	 * @since  0.0.42
	 * @param  string $value Text.
	 * @param  int    $max   Maximum characters.
	 * @return string
	 */
	private static function clip( string $value, int $max ): string {
		$value = trim( $value );

		return mb_strlen( $value ) > $max ? rtrim( mb_substr( $value, 0, $max ) ) : $value;
	}
}
