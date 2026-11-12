<?php
/**
 * Records WHO connected, on every MCP request (Feature 104).
 *
 * ## Why this is the parent and not a sibling
 *
 * The adapter accepts exactly one observability handler per server, created as
 * `new $class()` with no arguments. Two independent handlers is therefore not
 * an option the seam offers. So the two concerns stack: this class records the
 * client row and nothing else, and {@see ActivityObservabilityHandler} extends
 * it to add the log row.
 *
 * ## Why the registry cannot live inside the activity handler
 *
 * {@see HandlerResolver} hands the adapter `NullMcpObservabilityHandler`
 * whenever request logging is off, and says so plainly: the recording path is
 * then *"never reached at all"*. Had the client row been written from inside
 * the activity handler, switching activity logging off would silently stop
 * recording who connects to the site — a coupling no operator would guess at,
 * between a diagnostic toggle and an access record. The split is what makes
 * the resolver able to return this class when logging is off and the subclass
 * when it is on, so the log keeps its guarantee and the registry is
 * unconditional.
 *
 * ## `ping` is recorded here and excluded from the log
 *
 * Deliberately divergent. For the log, keepalives are most of the volume and
 * none of the value. For the registry they are the opposite: a client that has
 * only pinged is still a client that is connected right now, and excluding it
 * would show a stale last-seen for the one client we are most certain about.
 * The exclusion filter is named for the activity log, and stays there.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Observability
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Observability;

use AcrossAI_MCP_Manager\Includes\Database\Clients\Recorder as ClientsRecorder;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `mcp.request` by recording a client sighting.
 *
 * @since 0.4.8
 */
class ConnectionObservabilityHandler implements McpObservabilityHandlerInterface {

	/**
	 * Record one adapter event.
	 *
	 * Wrapped whole, for the same reason the activity handler is: the adapter
	 * calls this on all four exit paths of every dispatch, so a throw here
	 * would surface to an AI client as a broken tool call. A registry that
	 * cannot record must fail quietly rather than take the request with it.
	 *
	 * @since  0.4.8
	 * @param  string               $event       Event name.
	 * @param  array<string, mixed> $tags        Event tags.
	 * @param  float|null           $duration_ms Duration in milliseconds.
	 * @return void
	 */
	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		unset( $duration_ms );

		try {
			if ( 'mcp.request' !== $event ) {
				return;
			}

			ClientsRecorder::record( $this->signals( $tags ) );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A registry failure must never reach the client.
			error_log( '[acrossai-mcp] ConnectionObservabilityHandler failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Everything the registry needs to know about this request.
	 *
	 * Assembled from request scope rather than from the tags, because the tags
	 * carry what the adapter measured and none of what identifies the caller.
	 * `server_id` and the session id are the only two that come from tags.
	 *
	 * @since  0.4.8
	 * @param  array<string, mixed> $tags Raw event tags.
	 * @return array<string, mixed>
	 */
	protected function signals( array $tags ): array {
		$slug = $this->tag( $tags, 'server_id' );

		// On `initialize` the `session_id` tag is absent and the id arrives as
		// `new_session_id` — and `initialize` is the ONE request that carries
		// clientInfo. Without this coalesce the registry would never learn a
		// single client's name.
		$session_id = $this->tag( $tags, 'session_id' );

		if ( '' === $session_id ) {
			$session_id = $this->tag( $tags, 'new_session_id' );
		}

		$user_id = get_current_user_id();
		$client  = ConnectionContext::client_info( $session_id, $user_id );

		return array(
			'server_id'       => ConnectionContext::server_id_for_slug( $slug ),
			'connection_type' => ConnectionContext::resolve_type(),
			'connector_slug'  => ConnectionContext::connector_slug(),
			'client_id'       => ConnectionContext::client_id(),
			'fleet_host_key'  => ConnectionContext::fleet_host_key(),
			'client_name'     => $client['name'],
			'client_version'  => $client['version'],
			'credential_id'   => ConnectionContext::credential_id(),
			'credential_hint' => ConnectionContext::credential_hint( $user_id ),
			'user_id'         => $user_id,
			'ip_address'      => ConnectionContext::ip_address(),
			'user_agent'      => ConnectionContext::user_agent(),
		);
	}

	/**
	 * Read one tag as a trimmed string.
	 *
	 * Defensive by policy: these names live inside the vendor RequestRouter, so
	 * a renamed tag must degrade one field to empty rather than fatal inside a
	 * recording path. The 0.6.1 → 0.7.0 meta-ability episode is the precedent.
	 *
	 * @since  0.4.8
	 * @param  array<string, mixed> $tags Event tags.
	 * @param  string               $key  Tag name.
	 * @return string
	 */
	protected function tag( array $tags, string $key ): string {
		return isset( $tags[ $key ] ) && is_scalar( $tags[ $key ] ) ? trim( (string) $tags[ $key ] ) : '';
	}
}
