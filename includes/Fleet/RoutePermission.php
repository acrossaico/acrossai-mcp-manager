<?php
/**
 * Restricts the Fleet endpoint to credentials this site handed to a hub.
 *
 * ## Why this class has to exist
 *
 * Without it the Fleet route is not a fleet route at all — it is a second
 * admin endpoint with a different name.
 *
 * {@see \AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault}
 * answers the transport's question with a *capability*, and for a server with
 * no access-control rule that answer is `manage_options`. A capability cannot
 * distinguish one credential from another, so every Application Password
 * belonging to any administrator on this site would satisfy it — including
 * passwords minted for entirely unrelated integrations, and the site owner's
 * own. Pairing would grant nothing, and removing a host would take nothing
 * away.
 *
 * So the identity check happens here instead: the Application Password UUID
 * presented on the request must match one this site recorded when it paired a
 * hub. That check is the thing that makes "Remove" on the Fleet panel mean
 * something, because it reads the same list the panel writes.
 *
 * ## Why UUID rather than user
 *
 * Pairing mints a password on an existing administrator, so the acting user is
 * indistinguishable between hubs. The UUID is the only part that is per-hub,
 * which is why {@see Hosts} keeps it and why removing a host revokes the
 * password rather than merely forgetting it.
 *
 * ## What this deliberately does not do
 *
 * It does not replace the capability gate or the per-ability exposure gates —
 * it runs in front of them. A paired hub still has to pass everything a local
 * AI client passes. This only answers "are you a hub we know?".
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
use WP_REST_Server;

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
	 * Memoized Fleet server row for this request: id + route, or false when
	 * the row is absent. Resolved at most once however many times the filter
	 * fires.
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
	 * Returning a WP_Error short-circuits the request before the route's own
	 * callback runs, so a refused hub never reaches the MCP handler.
	 *
	 * @since 0.4.6
	 *
	 * @param mixed $result  Response to replace the request with, or null.
	 * @param mixed $server  REST server instance (unused).
	 * @param mixed $request The request being dispatched.
	 * @return mixed Unchanged `$result`, or a WP_Error refusing the call.
	 */
	public function gate_fleet_route( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature fixed by the filter.
		// Something upstream already decided the answer; do not second-guess it.
		if ( null !== $result ) {
			return $result;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$fleet = $this->fleet_server();
		if ( false === $fleet ) {
			return $result;
		}

		if ( ltrim( (string) $request->get_route(), '/' ) !== $fleet['route'] ) {
			return $result;
		}

		$uuid = $this->presented_uuid();
		if ( '' === $uuid ) {
			// No Application Password on the request. A signed-in administrator
			// with a cookie lands here too, and is refused on purpose: this
			// endpoint exists for paired sites, and the site's own AI access
			// is a different route that already works for them.
			return $this->refuse(
				__( 'This endpoint is only reachable by a site you have paired. Add it under MCP → Servers → Fleet.', 'acrossai-mcp-manager' )
			);
		}

		$host = Hosts::find_by_uuid( (int) $fleet['id'], $uuid );
		if ( null === $host ) {
			return $this->refuse(
				__( 'That credential is not paired with this site. Ask for a new pairing code.', 'acrossai-mcp-manager' )
			);
		}

		Hosts::touch( (int) $fleet['id'], (string) $host['key'] );

		return $result;
	}

	/**
	 * The Application Password UUID authenticating this request, or ''.
	 *
	 * Core sets this global from `rest_application_password_collect_status`
	 * once `wp_validate_application_password` succeeds, so its presence is
	 * proof an Application Password authenticated — not merely that one was
	 * offered.
	 *
	 * @since 0.4.6
	 */
	private function presented_uuid(): string {
		return isset( $GLOBALS['wp_rest_application_password_uuid'] )
			? (string) $GLOBALS['wp_rest_application_password_uuid']
			: '';
	}

	/**
	 * Resolve the Fleet server row, memoized per request.
	 *
	 * Matched on SLUG, not on route. The slug is plugin-managed and protected;
	 * the route is read from the row so that a reconciler change to the
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

		// Read through an array cast: the query returns a plain object whose
		// shape static analysis cannot see, and the surrounding code already
		// treats these rows as loose data.
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
	 * 403 rather than 401 throughout: the caller did authenticate, it simply
	 * is not a hub this site knows. A 401 would invite the client to retry
	 * with different credentials, which is the wrong advice — the fix is on
	 * the pairing screen, and the message says so.
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
