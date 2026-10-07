<?php
/**
 * Site Health self-check for the .well-known OAuth discovery documents.
 *
 * On hosts that provision a physical `.well-known/acme-challenge/` directory
 * for Let's Encrypt auto-SSL (OVH, cPanel/AutoSSL, Plesk, and most managed-WP
 * hosts), the web server can 404 sibling `.well-known/oauth-*` paths before
 * WordPress ever runs. When that happens `parse_request` never fires, so no
 * WordPress-level code can influence the outcome — the interception is at the
 * server, before rewrite rules run. This is Trac #37201 (wontfix). This class
 * is a passive diagnostic: it issues a loopback fetch to both RFC discovery
 * documents and reports one combined Site Health status under Tools → Site
 * Health → Status.
 *
 * Wired from Main::bootstrap_oauth_hooks() (probe-guarded — silent no-op while
 * mcp-manager still owns the OAuth routes). Adapted from wp-media/mcp-oauth's
 * HealthCheck under GPL-3.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

final class DiscoveryHealthCheck {

	public const TEST_KEY      = 'acrossai_mcp_manager_wellknown_discovery';
	public const TRANSIENT_KEY = 'acrossai_mcp_manager_wellknown_health';
	public const TRANSIENT_TTL = 5 * MINUTE_IN_SECONDS;
	public const FETCH_TIMEOUT = 5;

	/**
	 * Paths served by OAuthRouter, keyed by short display name.
	 *
	 * @var array<string, string>
	 */
	private const DOCUMENTS = array(
		'oauth-protected-resource'   => '/.well-known/oauth-protected-resource',
		'oauth-authorization-server' => '/.well-known/oauth-authorization-server',
	);

	/**
	 * Status severity used to pick the combined result.
	 *
	 * @var array<string, int>
	 */
	private const STATUS_RANK = array(
		'good'        => 0,
		'recommended' => 1,
		'critical'    => 2,
	);

	/** @var DiscoveryHealthCheck|null */
	private static $instance = null;

	/**
	 * Private constructor — singleton per the Module Contract.
	 */
	private function __construct() {
	}

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register as a `direct` Site Health test.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $tests Existing tests, keyed by bucket.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function add_test( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct'][ self::TEST_KEY ] = array(
			'label' => __( 'MCP OAuth discovery documents', 'acrossai-mcp-manager' ),
			'test'  => array( $this, 'run_self_check' ),
		);

		return $tests;
	}

	/**
	 * Run the combined discovery-document self-check.
	 *
	 * @return array<string, mixed>
	 */
	public function run_self_check(): array {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return $this->build_result(
				'recommended',
				array(),
				__( 'The .well-known OAuth discovery documents are served through a rewrite rule, which requires a pretty permalink structure. Enable one under Settings → Permalinks, then re-check this test.', 'acrossai-mcp-manager' )
			);
		}

		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$worst_status = 'good';
		$failing      = array();

		foreach ( self::DOCUMENTS as $name => $path ) {
			$response = wp_safe_remote_get(
				home_url( $path ),
				array( 'timeout' => self::FETCH_TIMEOUT )
			);

			$status = $this->classify( $response );

			// A 200 with valid JSON is not enough: another plugin bundling its
			// own MCP OAuth server can win the same rewrite rule and serve a
			// perfectly well-formed document that advertises ITS server and no
			// registration endpoint. Verify the document is actually ours.
			if ( 'good' === $status && 'oauth-authorization-server' === $name
				&& ! $this->document_is_ours( $response ) ) {
				$status = 'critical';
			}

			if ( self::STATUS_RANK[ $status ] > self::STATUS_RANK[ $worst_status ] ) {
				$worst_status = $status;
			}

			if ( 'good' !== $status ) {
				$failing[] = $name;
			}
		}

		$result = $this->build_result( $worst_status, $failing );

		set_transient( self::TRANSIENT_KEY, $result, self::TRANSIENT_TTL );

		return $result;
	}

	/**
	 * Is the fetched authorization-server document the one this plugin serves?
	 *
	 * Fingerprinted on `registration_endpoint`, which only this plugin emits —
	 * the competing `wp-media/mcp-oauth` document omits it entirely (that
	 * omission is precisely why Dynamic Client Registration fails when the
	 * other implementation wins the route).
	 *
	 * @param array<string, mixed>|\WP_Error $response Fetched response.
	 * @return bool
	 */
	private function document_is_ours( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['registration_endpoint'] ) ) {
			return false;
		}

		return untrailingslashit( (string) $decoded['registration_endpoint'] )
			=== untrailingslashit( rest_url( 'acrossai-mcp-manager/v1/oauth/register' ) );
	}

	/**
	 * Classify a single fetch into a Site Health status.
	 *
	 * The 404 branch distinguishes:
	 *   - WordPress-served 404 (has X-Powered-By or X-Redirect-By: WordPress) → 'recommended'
	 *   - Bare host-level 404 (neither header) → 'critical' — the acme-challenge
	 *     interception fingerprint. Not a proof, just a strong signal.
	 *
	 * @param array<string, mixed>|\WP_Error $response
	 * @return string 'good' | 'recommended' | 'critical'
	 */
	private function classify( $response ): string {
		if ( is_wp_error( $response ) ) {
			return 'recommended';
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $status_code, array( 401, 403, 407 ), true ) ) {
			return 'recommended';
		}

		if ( 404 === $status_code ) {
			$powered_by  = (string) wp_remote_retrieve_header( $response, 'x-powered-by' );
			$redirect_by = (string) wp_remote_retrieve_header( $response, 'x-redirect-by' );

			if ( '' === $powered_by && false === stripos( $redirect_by, 'WordPress' ) ) {
				return 'critical';
			}

			return 'recommended';
		}

		if ( 200 === $status_code ) {
			$body    = (string) wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( is_array( $decoded ) ) {
				return 'good';
			}
		}

		return 'recommended';
	}

	/**
	 * Build the Site Health result payload for a given combined status.
	 *
	 * @param string        $status  'good' | 'recommended' | 'critical'.
	 * @param array<string> $failing Display names of failing documents.
	 * @param string        $summary Optional override for the leading sentence.
	 * @return array<string, mixed>
	 */
	private function build_result( string $status, array $failing, string $summary = '' ): array {
		$loopback_caveat = __( 'This check runs from the server to itself. A "Good" result here does not guarantee external clients can reach these documents: a CDN, WAF, or reverse proxy in front of the site can still 404 these paths for external traffic while the loopback bypasses it. Verify with an external <code>curl</code> after any server-config change.', 'acrossai-mcp-manager' );

		if ( '' !== $summary ) {
			$description = sprintf( '<p>%s</p>', $summary );
			$actions     = sprintf( '<p>%s</p>', $loopback_caveat );
		} elseif ( 'good' === $status ) {
			$description = sprintf(
				'<p>%s</p>',
				esc_html__( 'Both .well-known OAuth discovery documents (oauth-protected-resource and oauth-authorization-server) responded with HTTP 200 and valid JSON.', 'acrossai-mcp-manager' )
			);
			$actions     = sprintf( '<p>%s</p>', $loopback_caveat );
		} else {
			$document_list = implode( ', ', $failing );

			if ( 'critical' === $status ) {
				$description = sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: %s: comma-separated list of failing discovery documents. */
						esc_html__( 'The following .well-known discovery document(s) returned a bare 404 with no WordPress-originated response header: %s. This matches the fingerprint of a physical .well-known/acme-challenge/ directory (provisioned by the host for Let\'s Encrypt auto-SSL) intercepting the request before WordPress runs — likely the cause here, but should be confirmed against the on-disk .well-known/ layout before concluding root cause.', 'acrossai-mcp-manager' ),
						esc_html( $document_list )
					)
				);

				$actions = sprintf(
					'<p>%s</p><p>%s</p><p>%s</p>',
					esc_html__( 'The fix is a server-config change: exclude these two specific paths from the .well-known/ interception (Apache: <code>Alias</code> or <code>&lt;Location&gt;</code>; Nginx: a more-specific <code>location</code> block for acme-challenge/ only). No WordPress-level fix exists — this is Trac #37201, wontfix.', 'acrossai-mcp-manager' ),
					esc_html__( 'If a CDN or page cache is in front of the site, purge it after applying the fix — a stale cached 404 can otherwise persist for hours.', 'acrossai-mcp-manager' ),
					$loopback_caveat
				);
			} else {
				$description = sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: %s: comma-separated list of failing discovery documents. */
						esc_html__( 'The following .well-known discovery document(s) did not respond as expected: %s. This does not match the confirmed .well-known/acme-challenge/ interception fingerprint, so the cause is inconclusive (may be a timeout, an access wall such as HTTP Basic Auth or a WAF, a WordPress-served error, or plain-permalinks fallback).', 'acrossai-mcp-manager' ),
						esc_html( $document_list )
					)
				);

				$actions = sprintf(
					'<p>%s</p><p>%s</p>',
					esc_html__( 'Check your web-server access log for these paths, and rule out an authentication wall (staging Basic Auth, a WAF, bot mitigation) before assuming a routing bug.', 'acrossai-mcp-manager' ),
					$loopback_caveat
				);
			}
		}

		return array(
			'label'       => __( 'MCP OAuth discovery documents', 'acrossai-mcp-manager' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Configuration', 'acrossai-mcp-manager' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => $actions,
			'test'        => self::TEST_KEY,
		);
	}
}
