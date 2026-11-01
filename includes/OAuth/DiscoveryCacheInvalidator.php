<?php
/**
 * Purge cached discovery documents when what they describe changes (#181).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * A discovery document describes a server's route, namespace and whether it
 * is enabled. Change any of those and every cached copy is now a lie —
 * previously for up to an hour, which is how an operator came to fix a real
 * problem, re-test, and still fail.
 *
 * The TTL is 60 seconds now and both documents send `DONOTCACHEPAGE`, so a
 * well-behaved page cache should not be holding one at all. This is for the
 * layers that do not read either signal: a host edge cache, a CDN, a
 * reverse proxy. Belt and braces, and cheap.
 *
 * ## Why the hooks rather than the save handlers
 *
 * BerlinDB fires `transition_mcp_server_{column}` from `update_item()`, so
 * hooking the transition covers EVERY writer — the admin form, the REST
 * controller, the seeder, WP-CLI, a companion plugin — including ones that
 * do not exist yet. Hooking the four save paths that exist today would have
 * left the fifth one to be discovered as a bug.
 *
 * ## Why targeted URLs rather than a full flush
 *
 * Toggling one server should not evict a large site's entire page cache.
 * Where a layer can purge a single URL it is asked to; where it cannot,
 * nothing happens rather than a site-wide flush the operator did not ask
 * for. A site that would rather flush everything can hook
 * `acrossai_mcp_discovery_cache_purge` and do exactly that.
 */
final class DiscoveryCacheInvalidator {

	/**
	 * Server columns whose value appears in, or decides, a discovery document.
	 *
	 * `server_slug` is deliberately absent: it names the server in the admin
	 * and in config files, but no discovery document contains it.
	 *
	 * @var array<int, string>
	 */
	private const WATCHED_COLUMNS = array(
		'is_enabled',
		'server_route',
		'server_route_namespace',
	);

	/**
	 * URLs already purged this request, as a set.
	 *
	 * Saving a server through the admin form transitions several columns at
	 * once and each fires its own action, so without this a single save
	 * would purge the same URL three times.
	 *
	 * A set rather than a "have we purged yet" boolean, because the URL list
	 * is not the same on every call: renaming a route makes the OLD URL
	 * stale, and that one is only knowable from the transition that carried
	 * it. A boolean would swallow it if any other column transitioned first.
	 *
	 * @var array<string, bool>
	 */
	private static $purged_this_request = array();

	/**
	 * Columns worth watching, for the wiring in Main.php.
	 *
	 * Exposed rather than wired here: Constitution A1 puts every
	 * `add_action` in Main.php, so this class owns the decision about WHICH
	 * columns matter and Main.php owns the registration.
	 *
	 * @return array<int, string>
	 */
	public static function watched_columns(): array {
		return self::WATCHED_COLUMNS;
	}

	/**
	 * A watched column changed.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $new_value New value.
	 * @param int   $item_id   Server id.
	 * @return void
	 */
	public static function on_change( $old_value, $new_value, $item_id ): void {
		unset( $new_value );

		// Which column transitioned decides whether an old value names a URL
		// that is now stale. Outside an action there is no way to know, so
		// the current server rows are all this can purge.
		$action = current_action();
		$stale  = is_string( $action )
			? self::stale_url_for_old_value( $action, (string) $old_value, (int) $item_id )
			: array();

		self::purge( $stale );
	}

	/**
	 * A server was deleted, so it must stop being advertised.
	 *
	 * @param int  $item_id Server id.
	 * @param bool $result  Whether the delete succeeded.
	 * @return void
	 */
	public static function on_delete( $item_id, $result ): void {
		unset( $item_id );

		if ( ! $result ) {
			return;
		}

		// The row is already gone, so its own path-inserted URL cannot be
		// rebuilt here. It now answers 404 with no-store, so any cached copy
		// expires within the discovery TTL rather than persisting; the bare
		// documents, which are the ones that mattered in #181, are purged.
		self::purge();
	}

	/**
	 * Ask every cache layer we can reach to drop the discovery documents.
	 *
	 * @param array<int, string> $extra URLs stale for a reason the current
	 *                                  server rows cannot show — a route
	 *                                  that was just renamed away from.
	 * @return void
	 */
	public static function purge( array $extra = array() ): void {
		$urls = array_values(
			array_diff(
				array_unique( array_merge( DiscoveryController::document_urls(), $extra ) ),
				array_keys( self::$purged_this_request )
			)
		);

		if ( array() === $urls ) {
			return;
		}

		foreach ( $urls as $already ) {
			self::$purged_this_request[ $already ] = true;
		}

		/**
		 * Fires when the discovery documents have changed and any cached
		 * copy is now stale.
		 *
		 * Hook this to purge a layer this plugin does not know about, or to
		 * flush everything on a site that would rather be safe than
		 * targeted.
		 *
		 * @since 0.4.6
		 *
		 * @param array<int, string> $urls Absolute discovery URLs.
		 */
		do_action( 'acrossai_mcp_discovery_cache_purge', $urls );

		foreach ( $urls as $url ) {
			self::purge_url( $url );
		}
	}

	/**
	 * The URL a route or namespace change just made stale.
	 *
	 * Renaming a route moves the path-inserted document: the new URL is in
	 * the server row and the old one exists nowhere but this transition's
	 * `$old_value`. Reconstructed by pairing the old value with the server's
	 * other, unchanged half.
	 *
	 * @param string $action    The transition action that fired.
	 * @param string $old_value The column's previous value.
	 * @param int    $item_id   Server id.
	 * @return array<int, string> One URL, or none.
	 */
	private static function stale_url_for_old_value( string $action, string $old_value, int $item_id ): array {
		if ( '' === $old_value || $item_id <= 0 ) {
			return array();
		}

		$server = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->get_item( $item_id );

		if ( ! is_object( $server ) ) {
			return array();
		}

		if ( 'transition_mcp_server_server_route' === $action ) {
			$namespace = ! empty( $server->server_route_namespace ) ? (string) $server->server_route_namespace : 'mcp';
			$route     = $old_value;
		} elseif ( 'transition_mcp_server_server_route_namespace' === $action ) {
			$namespace = $old_value;
			$route     = isset( $server->server_route ) ? (string) $server->server_route : '';
		} else {
			return array();
		}

		if ( '' === $route ) {
			return array();
		}

		$path = (string) wp_parse_url( rest_url( trailingslashit( $namespace ) . $route ), PHP_URL_PATH );

		// Same plain-permalink guard as DiscoveryController::document_urls().
		if ( '' === $path || false === strpos( $path, $route ) ) {
			return array();
		}

		return array( DiscoveryController::origin() . '/.well-known/oauth-protected-resource' . $path );
	}

	/**
	 * Per-URL purge across the layers that support one.
	 *
	 * Every call here is either an action nobody may be listening to — free
	 * — or guarded by `function_exists`. No layer is required to be present,
	 * and none of these throws when it is absent.
	 *
	 * @param string $url Absolute URL.
	 * @return void
	 */
	private static function purge_url( string $url ): void {
		// Nginx Helper (and the FastCGI/Redis page caches it drives).
		do_action( 'rt_nginx_helper_purge_url', $url );

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_url', $url );

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_url' ) ) {
			\w3tc_flush_url( $url );
		}

		// WP Rocket. Takes a list; called per URL for a uniform call site,
		// and the cost of a one-element array is nothing next to the I/O it
		// is about to do.
		if ( function_exists( 'rocket_clean_files' ) ) {
			\rocket_clean_files( array( $url ) );
		}

		// Cache Enabler.
		do_action( 'cache_enabler_clear_page_cache_by_url', $url );
	}

	/**
	 * Reset the per-request guard.
	 *
	 * Exists for tests, which exercise several transitions in one process
	 * and would otherwise see only the first purge.
	 *
	 * @return void
	 */
	public static function reset_request_guard(): void {
		self::$purged_this_request = array();
	}
}
