<?php
/**
 * Lets a signed request from a paired hub through, and nothing else.
 *
 * ## Why this exists at all
 *
 * {@see \AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault}
 * answers the transport's question with a *capability*, and for a server with
 * no access-control rule that answer is `manage_options`. A capability cannot
 * distinguish one caller from another, so without this class the Fleet route
 * would be satisfied by any administrator credential on the site. Pairing
 * would grant nothing and removing a host would take nothing away.
 *
 * ## It also supplies the user
 *
 * A signature is not a WordPress login, and everything downstream — the
 * capability check, the per-ability exposure gates, the activity log — needs
 * one. So a verified signature is exchanged here for the user the host was
 * paired as, via `wp_set_current_user()`.
 *
 * That exchange is the real grant, and it is worth being plain about: a
 * paired hub can do exactly what that user can do. The limit is chosen when
 * the host is paired, enforced by every gate that already exists downstream,
 * and ended by removing the host.
 *
 * ## Why `rest_pre_dispatch`
 *
 * It runs after authentication and before `permission_callback`, which is the
 * only window where the user can still be set in time to be checked. Later is
 * too late; earlier runs for every REST request on the site.
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

defined( 'ABSPATH' ) || exit;

final class RoutePermission {

	/**
	 * Singleton instance.
	 *
	 * @since 0.4.6
	 * @var   self|null
	 */
	private static ?self $instance = null;

	/**
	 * Memoized Fleet server for this request: id + route, or false when the
	 * row is absent.
	 *
	 * @since 0.4.6
	 * @var   array{id: int, route: string}|false|null
	 */
	private $fleet = null;

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
	 * Filter callback for `rest_pre_dispatch`.
	 *
	 * @since 0.4.6
	 *
	 * @param mixed $result  Response to replace the request with, or null.
	 * @param mixed $server  REST server instance (unused).
	 * @param mixed $request The request being dispatched.
	 * @return mixed Unchanged `$result`, or a WP_Error refusing the call.
	 */
	public function gate_fleet_route( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature fixed by the filter.
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$fleet = $this->fleet_server();
		if ( false === $fleet || ltrim( (string) $request->get_route(), '/' ) !== $fleet['route'] ) {
			return $result;
		}

		$host_key = (string) $request->get_header( Signature::HEADER_HOST );
		if ( '' === $host_key ) {
			return $this->refuse(
				__( 'This endpoint is only reachable by a site you have paired. Add this site to a fleet under MCP → Servers → Fleet.', 'acrossai-mcp-manager' )
			);
		}

		$host = Hosts::find( (int) $fleet['id'], $host_key );
		if ( null === $host ) {
			return $this->refuse(
				__( 'That managing site is not paired with this one. Ask it to connect again.', 'acrossai-mcp-manager' )
			);
		}

		$verdict = Signature::verify(
			(string) $host['pubkey'],
			$host_key,
			(int) $request->get_header( Signature::HEADER_TIMESTAMP ),
			(string) $request->get_header( Signature::HEADER_NONCE ),
			(string) $request->get_body(),
			(string) $request->get_header( Signature::HEADER_SIGNATURE )
		);

		if ( true !== $verdict ) {
			return $this->refuse( $this->explain( (string) $verdict ) );
		}

		// The user the host was paired as. Absent or deleted means the grant
		// no longer has anything behind it — refuse rather than fall through
		// to whatever user context happens to be lying around.
		$user_id = (int) $host['user_id'];
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return $this->refuse(
				__( 'The account this managing site was connected as no longer exists. Remove the host and connect again.', 'acrossai-mcp-manager' )
			);
		}

		wp_set_current_user( $user_id );

		Hosts::touch( (int) $fleet['id'], (string) $host['key'] );

		return $result;
	}

	/**
	 * Turn a verification reason into something an operator can act on.
	 *
	 * These are distinct problems with distinct fixes, which is why
	 * {@see Signature::verify()} reports them separately instead of
	 * returning false.
	 *
	 * @since 0.4.6
	 *
	 * @param string $reason Machine-readable reason.
	 */
	private function explain( string $reason ): string {
		switch ( $reason ) {
			case 'clock_skew':
				return __( 'That request was signed too long ago, or the two sites’ clocks disagree. Check the server time on both.', 'acrossai-mcp-manager' );
			case 'replay':
				return __( 'That request has already been used once and cannot be repeated.', 'acrossai-mcp-manager' );
			case 'no_openssl':
				return __( 'This site cannot verify signatures because the OpenSSL extension is unavailable. Ask your host to enable it.', 'acrossai-mcp-manager' );
			case 'bad_key':
				return __( 'The key stored for that managing site is unreadable. Remove the host and connect again.', 'acrossai-mcp-manager' );
			default:
				return __( 'That request was not signed by the site it claims to come from.', 'acrossai-mcp-manager' );
		}
	}

	/**
	 * Resolve the Fleet server row, memoized per request.
	 *
	 * Matched on SLUG, not route: the slug is plugin-managed and protected,
	 * while the route is read from the row, so a reconciler change to the
	 * address cannot leave this gate watching the wrong one.
	 *
	 * @since 0.4.6
	 * @return array{id: int, route: string}|false
	 */
	private function fleet_server() {
		if ( null !== $this->fleet ) {
			return $this->fleet;
		}

		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => DefaultServerSeeder::FLEET_SLUG,
				'number'      => 1,
			)
		);

		// `query()` returns a count for some argument shapes, so the array
		// check is load-bearing rather than defensive noise.
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			$this->fleet = false;
			return false;
		}

		$row       = (array) $rows[0];
		$id        = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$namespace = isset( $row['server_route_namespace'] ) ? (string) $row['server_route_namespace'] : '';
		$route     = isset( $row['server_route'] ) ? (string) $row['server_route'] : '';

		if ( $id <= 0 || '' === $namespace || '' === $route ) {
			$this->fleet = false;
			return false;
		}

		$this->fleet = array(
			'id'    => $id,
			'route' => $namespace . '/' . $route,
		);

		return $this->fleet;
	}

	/**
	 * Build the refusal.
	 *
	 * 403 throughout, never 401: a 401 invites the client to retry with
	 * different credentials, and there are no credentials to retry with. The
	 * fix is always on the pairing screen, and the message says so.
	 *
	 * @since 0.4.6
	 *
	 * @param string $message Operator-facing explanation.
	 */
	private function refuse( string $message ): WP_Error {
		return new WP_Error(
			'acrossai_mcp_fleet_not_paired',
			$message,
			array( 'status' => 403 )
		);
	}

	/**
	 * Reset the memoized row. Test seam only.
	 *
	 * @since 0.4.6
	 */
	public function flush_cache(): void {
		$this->fleet = null;
	}
}
