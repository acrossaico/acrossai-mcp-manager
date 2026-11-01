<?php
/**
 * The endpoint a hub calls once, to become a paired host.
 *
 * ## The contract with acrossai-pro
 *
 *     POST /wp-json/acrossai-mcp-manager/v1/fleet/register
 *     {
 *       "security_id": "a7f3k9x2m1qp",        // read off this site's Fleet tab
 *       "pubkey":      "-----BEGIN PUBLIC KEY-----\n…",
 *       "hub_url":     "https://hub.example.com",
 *       "hub_name":    "Agency"
 *     }
 *
 * Answers `200` with the host key this site assigned, which the hub must send
 * back on every later call:
 *
 *     { "host_key": "…", "site_name": "…", "site_url": "…", "endpoint": "…" }
 *
 * ## Why this is unauthenticated, and why that is safe
 *
 * It cannot require a credential: the hub holds none yet, and issuing one is
 * the entire point of the call. The security ID is the authentication — a
 * value only someone with access to this site's admin can read.
 *
 * Three things keep that from being thin:
 *
 *  - The ID is compared in constant time, so it cannot be discovered by
 *    timing the responses.
 *  - Attempts are rate-limited per IP, so it cannot be brute-forced. Twelve
 *    characters from a 27-letter alphabet is about 57 bits; the limiter is
 *    what makes that number mean something.
 *  - Fleet access must already be switched ON. A site that has not opted in
 *    cannot be registered against at all, whatever the caller knows.
 *
 * ## Nothing secret crosses the wire or is stored
 *
 * The hub sends a PUBLIC key and this site stores it. There is no password
 * in the request, nothing in the response that would let the recipient
 * impersonate anyone, and nothing in the database afterwards that is worth
 * stealing. That is the whole reason this shape was chosen over issuing an
 * Application Password.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Fleet
 * @since      0.4.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Fleet;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class Register {

	/**
	 * REST namespace. The plugin's existing one — a second namespace for one
	 * route would be a new public surface to version and document.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	public const REST_NAMESPACE = 'acrossai-mcp-manager/v1';

	/**
	 * Attempts allowed per IP inside the window below.
	 *
	 * @since 0.4.6
	 * @var   int
	 */
	private const MAX_ATTEMPTS = 10;

	/**
	 * Rate-limit window, in seconds.
	 *
	 * @since 0.4.6
	 * @var   int
	 */
	private const WINDOW = 900;

	/**
	 * Singleton instance.
	 *
	 * @since 0.4.6
	 * @var   self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 0.4.6
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — plugin-wide singleton convention.
	 */
	private function __construct() {}

	/**
	 * Register the route. Wired to `rest_api_init` by `Main`.
	 *
	 * @since 0.4.6
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/fleet/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Open by contract; the security ID and the rate limiter are
				// the gate. See the class docblock.
				'permission_callback' => '__return_true',
				'args'                => array(
					'security_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'pubkey'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'hub_url'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'hub_name'    => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Handle a registration attempt.
	 *
	 * @since 0.4.6
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		if ( $this->rate_limited() ) {
			return new WP_Error(
				'acrossai_mcp_fleet_rate_limited',
				__( 'Too many connection attempts. Wait a few minutes and try again.', 'acrossai-mcp-manager' ),
				array( 'status' => 429 )
			);
		}

		$this->record_attempt();

		$fleet = $this->fleet_server();
		if ( null === $fleet ) {
			return $this->refuse( __( 'This site is not set up to join a fleet.', 'acrossai-mcp-manager' ) );
		}

		// Opt-in is a precondition, not a detail. A site whose owner has not
		// switched Fleet access on cannot be registered against however
		// correct the rest of the request is.
		if ( empty( $fleet['is_enabled'] ) ) {
			return $this->refuse( __( 'Fleet access is switched off on this site. Its administrator must turn it on first.', 'acrossai-mcp-manager' ) );
		}

		$server_id = (int) $fleet['id'];

		if ( ! SecurityId::matches( $server_id, (string) $request->get_param( 'security_id' ) ) ) {
			// Deliberately the same wording whether the ID was wrong or
			// simply never generated: telling the two apart would confirm to
			// a guesser that they had found a site worth continuing against.
			return $this->refuse( __( 'That security ID does not match this site.', 'acrossai-mcp-manager' ) );
		}

		$pubkey = $this->clean_pubkey( (string) $request->get_param( 'pubkey' ) );
		if ( '' === $pubkey ) {
			return $this->refuse( __( 'That public key could not be read.', 'acrossai-mcp-manager' ) );
		}

		$hub_url  = esc_url_raw( (string) $request->get_param( 'hub_url' ) );
		$hub_host = (string) wp_parse_url( $hub_url, PHP_URL_HOST );
		if ( '' === $hub_url || '' === $hub_host ) {
			return $this->refuse( __( 'That request did not say which site it came from.', 'acrossai-mcp-manager' ) );
		}

		// The user the hub will act as. The administrator who switched Fleet
		// access on is the only defensible choice available here: nobody is
		// signed in during this call, so the alternative would be picking an
		// account on the caller's say-so.
		$user_id = $this->acting_user();
		if ( $user_id <= 0 ) {
			return $this->refuse( __( 'This site has no administrator available to act for a managing site.', 'acrossai-mcp-manager' ) );
		}

		$hub_name = sanitize_text_field( (string) $request->get_param( 'hub_name' ) );
		$host_key = $this->host_key( $hub_url );

		$stored = Hosts::add(
			$server_id,
			array(
				'key'          => $host_key,
				'host'         => '' !== $hub_name ? $hub_name : $hub_host,
				'url'          => $hub_url,
				'pubkey'       => $pubkey,
				'user_id'      => $user_id,
				'added_at'     => time(),
				'last_call_at' => 0,
			)
		);

		if ( ! $stored ) {
			return new WP_Error(
				'acrossai_mcp_fleet_store_failed',
				__( 'This site could not record the connection. Try again.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}

		// Spend the security ID. It authenticated one registration and has no
		// further purpose; leaving it live would let anyone who saw it attach
		// a second hub. The owner sees the new value on the Fleet tab.
		SecurityId::regenerate( $server_id );

		return new WP_REST_Response(
			array(
				'host_key'  => $host_key,
				'site_name' => (string) get_bloginfo( 'name' ),
				'site_url'  => home_url(),
				'endpoint'  => rest_url( $fleet['namespace'] . '/' . $fleet['route'] ),
			),
			200
		);
	}

	/**
	 * A stable per-hub key, derived from its URL.
	 *
	 * Derived rather than random so that a hub re-registering after being
	 * removed, or after its key was rotated, lands on the same record instead
	 * of accumulating duplicates the owner has to tell apart.
	 *
	 * @since 0.4.6
	 *
	 * @param string $hub_url Hub base URL.
	 */
	private function host_key( string $hub_url ): string {
		$host = (string) wp_parse_url( $hub_url, PHP_URL_HOST );

		return sanitize_key( str_replace( '.', '-', $host ) ) . '-' . substr( hash( 'sha256', $hub_url ), 0, 8 );
	}

	/**
	 * Validate and normalize a PEM public key.
	 *
	 * Parsed rather than pattern-matched: `openssl_pkey_get_public()` either
	 * understands it or does not, and storing a key that cannot be loaded
	 * would produce a host that looks paired and can never authenticate.
	 *
	 * @since 0.4.6
	 *
	 * @param string $pubkey Candidate PEM key.
	 * @return string The key as given, or '' when unusable.
	 */
	private function clean_pubkey( string $pubkey ): string {
		$pubkey = trim( $pubkey );

		if ( '' === $pubkey || ! function_exists( 'openssl_pkey_get_public' ) ) {
			return '';
		}

		return false === openssl_pkey_get_public( $pubkey ) ? '' : $pubkey;
	}

	/**
	 * The administrator a paired hub acts as.
	 *
	 * The one recorded when Fleet access was switched on, falling back to the
	 * site's oldest administrator. Both are deliberate: the first is the
	 * person who consented, the second keeps a site usable when that account
	 * has since been deleted.
	 *
	 * @since 0.4.6
	 */
	private function acting_user(): int {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		return empty( $admins ) ? 0 : (int) $admins[0];
	}

	/**
	 * The Fleet server row as a plain array, or null.
	 *
	 * @since 0.4.6
	 * @return array{id: int, is_enabled: int, namespace: string, route: string}|null
	 */
	private function fleet_server(): ?array {
		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => DefaultServerSeeder::FLEET_SLUG,
				'number'      => 1,
			)
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return null;
		}

		$row = (array) $rows[0];
		$id  = isset( $row['id'] ) ? (int) $row['id'] : 0;

		if ( $id <= 0 ) {
			return null;
		}

		return array(
			'id'         => $id,
			'is_enabled' => isset( $row['is_enabled'] ) ? (int) $row['is_enabled'] : 0,
			'namespace'  => isset( $row['server_route_namespace'] ) ? (string) $row['server_route_namespace'] : '',
			'route'      => isset( $row['server_route'] ) ? (string) $row['server_route'] : '',
		);
	}

	/**
	 * Whether this caller has had too many goes.
	 *
	 * @since 0.4.6
	 */
	private function rate_limited(): bool {
		return (int) get_transient( $this->limiter_key() ) >= self::MAX_ATTEMPTS;
	}

	/**
	 * Count one attempt against this caller.
	 *
	 * Counted before the ID is checked, so a wrong guess costs the same as a
	 * right one. Counting only failures would let an attacker probe freely by
	 * never quite failing.
	 *
	 * @since 0.4.6
	 */
	private function record_attempt(): void {
		$key = $this->limiter_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::WINDOW );
	}

	/**
	 * Per-caller limiter key.
	 *
	 * Keyed on the remote address only. `X-Forwarded-For` is caller-supplied
	 * and trivially rotated, so honouring it here would hand an attacker a
	 * way to reset their own limit.
	 *
	 * @since 0.4.6
	 */
	private function limiter_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		return 'acrossai_fleet_reg_' . md5( $ip );
	}

	/**
	 * A refusal. Always 403 and always the same shape, so a caller cannot
	 * learn more from the status than from the message.
	 *
	 * @since 0.4.6
	 *
	 * @param string $message Operator-facing explanation.
	 */
	private function refuse( string $message ): WP_Error {
		return new WP_Error( 'acrossai_mcp_fleet_register_refused', $message, array( 'status' => 403 ) );
	}
}
