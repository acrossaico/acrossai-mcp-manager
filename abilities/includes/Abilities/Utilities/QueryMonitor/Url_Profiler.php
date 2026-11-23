<?php
/**
 * Feature 129 — loopback profiler for debugging/profile-url.
 *
 * Fires one SSRF-safe same-host loopback request carrying a one-time token, captures
 * the request's profile from Query Monitor's processed collectors on a late shutdown
 * hook, and reads it back.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\QueryMonitor
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\QueryMonitor;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only loopback profiler.
 */
final class Url_Profiler {

	/**
	 * Default and maximum number of slowest queries to return.
	 */
	public const DEFAULT_SLOWEST = 10;
	public const MAX_SLOWEST     = 50;

	/**
	 * Loopback handshake. Defined here (always loaded) rather than on the
	 * dispatcher (loaded only when Query Monitor fires qm/dispatchers), so the
	 * ability's own request can mint a token without the dispatcher class.
	 */
	public const TOKEN_ARG     = 'acrossai_qm_token';
	public const TOKEN_PREFIX  = 'acrossai_qm_tok_';
	public const RESULT_PREFIX = 'acrossai_qm_res_';

	/**
	 * Private constructor — static-only.
	 */
	private function __construct() {}

	/**
	 * Register the shutdown capture.
	 *
	 * A late shutdown hook — not a Query Monitor dispatcher — because QM loads its
	 * dispatchers on `plugins_loaded`, before the abilities-init hook that registers
	 * this suite, so a `qm/dispatchers` filter added here would always be too late.
	 * A shutdown hook only has to exist by the time the request ends, and gates on
	 * our own one-time token, so it never fires for an ordinary visitor.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'shutdown', array( __CLASS__, 'maybe_capture' ), PHP_INT_MAX );
	}

	/**
	 * On a token-flagged loopback request, read the processed collectors and hand
	 * the profile back through a short-lived transient keyed by the one-time token.
	 *
	 * Gates on our server-minted single-use token rather than Query Monitor's
	 * user_can_view(), because our own single-use token is the gate; only
	 * debugging/profile-url (itself behind manage_options) can mint a valid token.
	 * The loopback authenticates as a capable user (see run()) so QM's dispatcher
	 * activates and runs its full collector pipeline — but capture still gates on
	 * the token, never on who the viewer is.
	 *
	 * @return void
	 */
	public static function maybe_capture(): void {
		if ( ! Query_Monitor_Guard::is_active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Server-minted one-time token, validated against a transient below; not a form submission.
		$raw = isset( $_GET[ self::TOKEN_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::TOKEN_ARG ] ) ) : '';
		if ( '' === $raw || ! preg_match( '/^[A-Za-z0-9]{16,64}$/', $raw ) ) {
			return;
		}
		if ( 1 !== (int) get_transient( self::TOKEN_PREFIX . $raw ) ) {
			return;
		}

		// One-time: burn the token so a leaked URL cannot be replayed.
		delete_transient( self::TOKEN_PREFIX . $raw );

		try {
			set_transient( self::RESULT_PREFIX . $raw, self::build_profile(), 120 );
		} catch ( \Throwable $e ) {
			set_transient( self::RESULT_PREFIX . $raw, array( 'error' => 'capture_failed' ), 120 );
		}
	}

	/**
	 * Profile one same-host URL and return the structured result.
	 *
	 * @param string $url     Absolute URL on this site.
	 * @param int    $slowest How many slowest queries to include.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function run( string $url, int $slowest ) {
		$validated = self::validate_same_host_url( $url );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$url     = $validated;
		$slowest = max( 1, min( self::MAX_SLOWEST, $slowest ) );

		$token = wp_generate_password( 40, false, false );
		set_transient( self::TOKEN_PREFIX . $token, 1, 120 );

		// Authenticate the loopback as the (manage_options) user running this
		// ability. Query Monitor only runs its full collector pipeline — the only
		// way the db, http and template panels populate — when its own dispatcher
		// activates, which it does only for a viewer who can see QM. The session
		// is single-use, short-lived, and destroyed immediately after the request.
		$auth      = self::mint_loopback_auth();
		$viewed_as = null !== $auth
			? sprintf(
				/* translators: %s: the WordPress username the loopback ran as. */
				__( 'logged in as %s', 'acrossai-abilities-manager' ),
				$auth['user_login']
			)
			: __( 'anonymous visitor (logged-out public view)', 'acrossai-abilities-manager' );

		$target   = add_query_arg( self::TOKEN_ARG, $token, $url );
		$response = wp_safe_remote_get(
			$target,
			array(
				'timeout'     => 20,
				// No redirects. The loopback must hit exactly the same-host URL we
				// validated; following a redirect (a canonical rule, a shortener,
				// a misconfigured http→external hop) could carry the minted admin
				// cookie to another host. A redirecting target is reported as its
				// own 3xx status instead.
				'redirection' => 0,
				// Verify TLS everywhere except local/development, where the site
				// certificate is typically self-signed. The request is same-host
				// only and never redirects, so the cookie cannot leave this server.
				'sslverify'   => ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ),
				'blocking'    => true,
				'cookies'     => null !== $auth ? $auth['cookies'] : array(),
				'headers'     => array( 'X-AcrossAI-QM-Loopback' => '1' ),
			)
		);

		if ( null !== $auth ) {
			self::destroy_loopback_auth( $auth );
		}

		$result = get_transient( self::RESULT_PREFIX . $token );
		delete_transient( self::RESULT_PREFIX . $token );
		delete_transient( self::TOKEN_PREFIX . $token );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'loopback_failed',
				sprintf(
					/* translators: %s: the underlying HTTP error message. */
					__( 'The loopback request to the URL failed: %s', 'acrossai-abilities-manager' ),
					$response->get_error_message()
				)
			);
		}

		$http_status = (int) wp_remote_retrieve_response_code( $response );
		$headers     = self::read_qm_headers( $response );

		if ( is_array( $result ) && empty( $result['error'] ) ) {
			$result = self::trim_slowest( $result, $slowest );
			return array(
				'profiled_url' => $url,
				'http_status'  => $http_status,
				'viewed_as'    => $viewed_as,
				'source'       => 'query-monitor collectors',
				'profile'      => $result,
			);
		}

		// Fallback: the shutdown capture stored no result (e.g. the page redirected
		// before shutdown), but QM's own X-QM-* headers may still carry the overview.
		return array(
			'profiled_url' => $url,
			'http_status'  => $http_status,
			'viewed_as'    => $viewed_as,
			'source'       => $headers ? 'X-QM response headers (lightweight fallback)' : 'unavailable',
			'profile'      => $headers ? array( 'overview' => $headers ) : array(),
			'note'         => $headers
				? __( 'Full collector capture was unavailable for this request; returning the lightweight header metrics only.', 'acrossai-abilities-manager' )
				: __( 'Query Monitor returned no profile for this URL. It may have redirected, errored, or be an admin page that requires login.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Mint a single-use logged-in cookie for the current manage_options user so
	 * the loopback request authenticates as them and Query Monitor's dispatcher
	 * activates (it runs the full collector pipeline only for a viewer who can
	 * see QM). Returns null when there is no capable current user — a guard, since
	 * the ability's permission_callback already requires manage_options.
	 *
	 * @return array{cookies:array<int,\WP_Http_Cookie>,user_id:int,token:string,user_login:string}|null
	 */
	private static function mint_loopback_auth(): ?array {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! user_can( $user, 'manage_options' ) || ! defined( 'LOGGED_IN_COOKIE' ) ) {
			return null;
		}

		$expiration = time() + ( 2 * MINUTE_IN_SECONDS );
		$manager    = \WP_Session_Tokens::get_instance( $user->ID );
		$session    = $manager->create( $expiration );
		$cookie     = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $session );

		return array(
			'cookies'    => array(
				new \WP_Http_Cookie(
					array(
						'name'  => LOGGED_IN_COOKIE,
						'value' => $cookie,
					)
				),
			),
			'user_id'    => $user->ID,
			'token'      => $session,
			'user_login' => $user->user_login,
		);
	}

	/**
	 * Destroy the single-use session minted by {@see self::mint_loopback_auth()}.
	 * Best-effort: the session also expires on its own within two minutes.
	 *
	 * @param array{user_id:int,token:string} $auth The minted auth bundle.
	 * @return void
	 */
	private static function destroy_loopback_auth( array $auth ): void {
		try {
			\WP_Session_Tokens::get_instance( (int) $auth['user_id'] )->destroy( (string) $auth['token'] );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Build the profile from the processed collectors. Called on the loopback request by maybe_capture().
	 *
	 * @return array<string,mixed>
	 */
	public static function build_profile(): array {
		// When the loopback is authenticated, Query Monitor's own dispatcher has
		// already run the full collector pipeline (tear_down → process →
		// process_concerns → post_process) the correct way, so the derived rows
		// for db/http/theme are present. We read them per collector; process() is
		// idempotent enough to re-run for the live-state collectors (overview,
		// assets) without clobbering the already-built rows.
		$overview = Query_Monitor_Guard::collector_data( 'overview' );
		$db       = Query_Monitor_Guard::collector_data( 'db_queries' );
		$http     = Query_Monitor_Guard::collector_data( 'http' );
		$errors   = Query_Monitor_Guard::collector_data( 'php_errors' );
		$diw      = Query_Monitor_Guard::collector_data( 'doing_it_wrong' );
		$theme    = Query_Monitor_Guard::collector_data( 'theme' );
		$scripts  = Query_Monitor_Guard::collector_data( 'assets_scripts' );
		$styles   = Query_Monitor_Guard::collector_data( 'assets_styles' );

		return array(
			'overview'       => self::overview_profile( $overview ),
			'database'       => self::db_profile( $db ),
			'http'           => self::http_profile( $http ),
			'php_errors'     => self::error_counts( $errors ),
			'doing_it_wrong' => self::diw_profile( $diw ),
			'template'       => self::template_profile( $theme ),
			'assets'         => self::asset_profile( $scripts, $styles ),
		);
	}

	/**
	 * @param object|null $d Overview collector data.
	 * @return array<string,mixed>
	 */
	private static function overview_profile( $d ): array {
		if ( null === $d ) {
			return array();
		}
		return array(
			'time_taken'   => isset( $d->time_taken ) ? round( (float) $d->time_taken, 4 ) : null,
			'time_limit'   => isset( $d->time_limit ) ? (float) $d->time_limit : null,
			'memory_bytes' => isset( $d->memory ) ? (int) $d->memory : null,
			'memory_limit' => isset( $d->memory_limit ) ? (int) $d->memory_limit : null,
		);
	}

	/**
	 * @param object|null $d db_queries collector data.
	 * @return array<string,mixed>
	 */
	private static function db_profile( $d ): array {
		if ( null === $d || ! isset( $d->rows ) || ! is_array( $d->rows ) ) {
			return array( 'query_count' => 0, 'total_time' => 0.0, 'slowest' => array(), 'duplicates' => 0 );
		}

		$total_time = 0.0;
		$slowest    = array();
		foreach ( $d->rows as $row ) {
			$ltime        = isset( $row['ltime'] ) ? (float) $row['ltime'] : 0.0;
			$total_time  += $ltime;
			$slowest[]    = array(
				'sql'    => isset( $row['sql'] ) ? self::truncate( (string) $row['sql'], 300 ) : '',
				'time'   => round( $ltime, 5 ),
				'rows'   => isset( $row['result'] ) && is_numeric( $row['result'] ) ? (int) $row['result'] : null,
				'caller' => isset( $row['caller'] ) ? (string) $row['caller'] : '',
			);
		}

		usort(
			$slowest,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);

		$dupes = isset( $d->dupes ) && is_array( $d->dupes ) ? count( $d->dupes ) : 0;

		return array(
			'query_count' => isset( $d->total_qs ) ? (int) $d->total_qs : count( $d->rows ),
			'total_time'  => round( $total_time, 4 ),
			'slowest'     => $slowest,
			'duplicates'  => $dupes,
		);
	}

	/**
	 * @param object|null $d http collector data.
	 * @return array<string,mixed>
	 */
	private static function http_profile( $d ): array {
		$calls = array();
		if ( null !== $d && isset( $d->http ) && is_array( $d->http ) ) {
			foreach ( $d->http as $req ) {
				$response = isset( $req['response'] ) ? $req['response'] : null;
				$code     = null;
				if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
					$code = (int) $response['response']['code'];
				}
				$calls[] = array(
					'url'    => isset( $req['url'] ) ? self::truncate( (string) $req['url'], 300 ) : '',
					'method' => isset( $req['args']['method'] ) ? (string) $req['args']['method'] : 'GET',
					'status' => $code,
					'time'   => isset( $req['ltime'] ) ? round( (float) $req['ltime'], 4 ) : null,
				);
			}
		}

		return array( 'count' => count( $calls ), 'calls' => array_slice( $calls, 0, 50 ) );
	}

	/**
	 * @param object|null $d php_errors collector data.
	 * @return array<string,int>
	 */
	private static function error_counts( $d ): array {
		$out = array();
		if ( null !== $d && isset( $d->errors ) && is_array( $d->errors ) ) {
			foreach ( $d->errors as $type => $items ) {
				$out[ (string) $type ] = is_array( $items ) ? count( $items ) : 0;
			}
		}

		return $out;
	}

	/**
	 * @param object|null $d doing_it_wrong collector data.
	 * @return array<string,mixed>
	 */
	private static function diw_profile( $d ): array {
		$count = 0;
		if ( null !== $d && isset( $d->actions ) && is_array( $d->actions ) ) {
			$count = count( $d->actions );
		}

		return array( 'count' => $count );
	}

	/**
	 * @param object|null $d theme collector data.
	 * @return array<string,mixed>
	 */
	private static function template_profile( $d ): array {
		if ( null === $d ) {
			return array();
		}
		$template = null;
		if ( isset( $d->template_path ) ) {
			$template = (string) $d->template_path;
		} elseif ( isset( $d->template_file ) ) {
			$template = (string) $d->template_file;
		}

		return array( 'template' => $template );
	}

	/**
	 * Asset counts for both collectors, with the breakdown the data already carries.
	 *
	 * @since  0.0.45
	 * @param  object|null $scripts assets_scripts collector data.
	 * @param  object|null $styles  assets_styles collector data.
	 * Public because it is a pure function of two collector payloads and is driven directly by the
	 * tests. #302 shipped because the counting logic sat private inside a method that needs Query
	 * Monitor and a live loopback, so nothing covering it could run -- the same trap as #298.
	 *
	 * @return array<string,mixed>
	 */
	public static function asset_profile( $scripts, $styles ): array {
		return array(
			'scripts'              => self::asset_count( $scripts ),
			'styles'               => self::asset_count( $styles ),
			'scripts_by_position'  => self::asset_positions( $scripts ),
			'styles_by_position'   => self::asset_positions( $styles ),
			'missing_dependencies' => array(
				'scripts' => self::missing_dependency_count( $scripts ),
				'styles'  => self::missing_dependency_count( $styles ),
			),
		);
	}

	/**
	 * How many asset handles the collector recorded.
	 *
	 * **Issue #302.** This counted the KEYS of each row instead of the rows, so every figure it ever
	 * returned was exactly eight times the truth -- eight being the number of keys in Query Monitor's
	 * asset row (`handle`, `position`, `url`, `source`, `ver`, `warning`, `dependents`,
	 * `dependencies`, set identically at both `$asset_data[]` sites in `QM_Collector_Assets`).
	 *
	 * The old code iterated `$d->assets` with the loop variable named `$group`, and that name was the
	 * mistake: `QM_Collector_Assets` groups nothing. `$this->data->assets` is a flat list, one entry
	 * per handle. Measured on two sites before the fix:
	 *
	 *     staging (56 plugins):  reported 1016 / 712  -> real 127 / 89
	 *     local (near-stock):    reported   32 / 200  -> real   4 / 25
	 *
	 * All four divide by eight exactly, which is what identified the cause.
	 *
	 * The old `'raw'` fallback is gone with it: `QM_Collector_Assets` sets only `url`, `assets` and
	 * `missing_dependencies` on its data, never `raw`, so that branch could never match. A
	 * speculative fallback to a key the source does not define hides the real shape rather than
	 * guarding anything.
	 *
	 * @since  0.0.45
	 * @param  object|null $d assets collector data.
	 * @return int
	 */
	private static function asset_count( $d ): int {
		$assets = self::asset_rows( $d );

		return count( $assets );
	}

	/**
	 * Handle counts split by where the asset loads.
	 *
	 * More useful than one total for the question these profiles get asked: a hundred handles in the
	 * footer is a different problem from a hundred blocking the head. Query Monitor already records
	 * `position` per row, so this costs nothing extra.
	 *
	 * @since  0.0.45
	 * @param  object|null $d assets collector data.
	 * @return array<string,int>
	 */
	private static function asset_positions( $d ): array {
		$positions = array();

		foreach ( self::asset_rows( $d ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$position = isset( $row['position'] ) && is_string( $row['position'] ) && '' !== $row['position']
				? $row['position']
				: 'unknown';

			$positions[ $position ] = ( $positions[ $position ] ?? 0 ) + 1;
		}

		ksort( $positions );

		return $positions;
	}

	/**
	 * How many dependencies were enqueued against assets that do not exist.
	 *
	 * A missing dependency means WordPress silently dropped an asset's ordering guarantee, which is
	 * worth surfacing in a profile and was already collected and discarded.
	 *
	 * @since  0.0.45
	 * @param  object|null $d assets collector data.
	 * @return int
	 */
	private static function missing_dependency_count( $d ): int {
		if ( null === $d || ! isset( $d->missing_dependencies ) || ! is_array( $d->missing_dependencies ) ) {
			return 0;
		}

		return count( $d->missing_dependencies );
	}

	/**
	 * The collector's flat list of asset rows.
	 *
	 * @since  0.0.45
	 * @param  object|null $d assets collector data.
	 * @return array<int,mixed>
	 */
	private static function asset_rows( $d ): array {
		if ( null === $d || ! isset( $d->assets ) || ! is_array( $d->assets ) ) {
			return array();
		}

		return array_values( $d->assets );
	}

	/**
	 * Keep only the N slowest queries in a captured result.
	 *
	 * @param array<string,mixed> $result  Captured profile.
	 * @param int                 $slowest How many to keep.
	 * @return array<string,mixed>
	 */
	private static function trim_slowest( array $result, int $slowest ): array {
		if ( isset( $result['database']['slowest'] ) && is_array( $result['database']['slowest'] ) ) {
			$result['database']['slowest'] = array_slice( $result['database']['slowest'], 0, $slowest );
		}

		return $result;
	}

	/**
	 * Read QM's lightweight X-QM-* headers from a loopback response.
	 *
	 * @param array<string,mixed>|\WP_HTTP_Response $response HTTP response.
	 * @return array<string,string>
	 */
	private static function read_qm_headers( $response ): array {
		$headers = wp_remote_retrieve_headers( $response );
		if ( empty( $headers ) ) {
			return array();
		}
		$all = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
		$out = array();
		foreach ( $all as $name => $value ) {
			if ( 0 === stripos( (string) $name, 'x-qm-' ) ) {
				$out[ strtolower( substr( (string) $name, 5 ) ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		}

		return $out;
	}

	/**
	 * Validate the URL is absolute, http(s), and on this site's host.
	 *
	 * @param string $url Candidate URL.
	 * @return string|WP_Error The normalised URL, or an error.
	 */
	private static function validate_same_host_url( string $url ) {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url ) {
			return new WP_Error( 'invalid_url', __( 'A valid http(s) URL on this site is required.', 'acrossai-abilities-manager' ) );
		}

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return new WP_Error( 'invalid_url', __( 'The URL must be an http(s) URL with a host.', 'acrossai-abilities-manager' ) );
		}

		$site_hosts = array();
		foreach ( array( home_url(), site_url() ) as $site ) {
			$h = wp_parse_url( $site, PHP_URL_HOST );
			if ( is_string( $h ) && '' !== $h ) {
				$site_hosts[] = strtolower( $h );
			}
		}

		if ( ! in_array( $host, $site_hosts, true ) ) {
			return new WP_Error(
				'off_site_url',
				__( 'Only URLs on this site can be profiled. Pass a URL whose host matches this WordPress install.', 'acrossai-abilities-manager' )
			);
		}

		return $url;
	}

	/**
	 * @param string $s   String.
	 * @param int    $max Max length.
	 * @return string
	 */
	private static function truncate( string $s, int $max ): string {
		$s = trim( preg_replace( '/\s+/', ' ', $s ) ?? $s );
		return strlen( $s ) > $max ? substr( $s, 0, $max ) . '…' : $s;
	}
}
