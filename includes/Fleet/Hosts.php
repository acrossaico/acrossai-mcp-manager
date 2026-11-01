<?php
/**
 * The list of hubs allowed to manage this site.
 *
 * A "host" is another WordPress site that may call this site's Fleet endpoint.
 * The list lives in the existing per-server meta table against the Fleet
 * server's own row, so this adds no schema: one key, one JSON array, read and
 * written here and nowhere else.
 *
 * ## What is NOT stored here
 *
 * No credential. When a host is added, this site mints an Application Password
 * and sends the secret to the hub — the hub needs it to make calls, this site
 * does not. What stays behind is the password's **UUID**, which is the only
 * thing required to revoke it.
 *
 * That asymmetry is the security property worth protecting: a leaked database
 * on a managed site exposes no credential for any hub, because the secret was
 * never written down here. Do not "improve" this by caching the secret for
 * display — there is no screen that needs it, and a site owner who wants a
 * host gone removes it, which revokes rather than reveals.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Fleet
 * @since      0.4.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Fleet;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as MCPServerMetaQuery;

defined( 'ABSPATH' ) || exit;

final class Hosts {

	/**
	 * Meta key holding the JSON array of hosts.
	 *
	 * Prefixed `_fleet_` rather than `_acrossai_pro_`: this is the free
	 * plugin's own data on the free plugin's own server row. Pro reads none
	 * of it.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	public const META_KEY = '_fleet_hosts';

	/**
	 * Every host currently allowed to manage this site.
	 *
	 * Malformed entries are dropped rather than repaired. A host record that
	 * cannot be read cannot be revoked either, so surfacing it in the UI would
	 * offer the owner a control that does nothing.
	 *
	 * @since 0.4.6
	 *
	 * @param int $server_id The Fleet server's primary key.
	 * @return array<int, array{key: string, host: string, url: string, uuid: string, added_at: int, last_call_at: int}>
	 */
	public static function all( int $server_id ): array {
		if ( $server_id <= 0 ) {
			return array();
		}

		$raw = MCPServerMetaQuery::get_meta( $server_id, self::META_KEY );
		if ( null === $raw || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$hosts = array();
		foreach ( $decoded as $entry ) {
			$host = self::normalize( $entry );
			if ( null !== $host ) {
				$hosts[] = $host;
			}
		}

		return $hosts;
	}

	/**
	 * One host by its key, or null.
	 *
	 * @since 0.4.6
	 *
	 * @param int    $server_id The Fleet server's primary key.
	 * @param string $key       Host key.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $server_id, string $key ): ?array {
		foreach ( self::all( $server_id ) as $host ) {
			if ( $host['key'] === $key ) {
				return $host;
			}
		}

		return null;
	}

	/**
	 * Add a host, or replace one already recorded under the same key.
	 *
	 * Re-pairing an existing host is a replace rather than a second row: two
	 * entries for one hub would leave the owner unsure which Remove button
	 * cuts it off, and the stale entry's UUID would keep a live credential
	 * alive after the visible one was removed.
	 *
	 * @since 0.4.6
	 *
	 * @param int                  $server_id The Fleet server's primary key.
	 * @param array<string, mixed> $host      Host record; see {@see self::all()}.
	 * @return bool True when the list was written.
	 */
	public static function add( int $server_id, array $host ): bool {
		$normalized = self::normalize( $host );
		if ( $server_id <= 0 || null === $normalized ) {
			return false;
		}

		$kept = array();
		foreach ( self::all( $server_id ) as $existing ) {
			if ( $existing['key'] !== $normalized['key'] ) {
				$kept[] = $existing;
			}
		}

		$kept[] = $normalized;

		return self::write( $server_id, $kept );
	}

	/**
	 * Remove a host.
	 *
	 * Only forgets the record. Revoking the Application Password is the
	 * caller's job and must happen first — a forgotten UUID is a credential
	 * nobody can revoke, which is strictly worse than a remembered one.
	 *
	 * @since 0.4.6
	 *
	 * @param int    $server_id The Fleet server's primary key.
	 * @param string $key       Host key.
	 * @return bool True when a host was removed.
	 */
	public static function remove( int $server_id, string $key ): bool {
		$kept    = array();
		$removed = false;

		foreach ( self::all( $server_id ) as $existing ) {
			if ( $existing['key'] === $key ) {
				$removed = true;
				continue;
			}
			$kept[] = $existing;
		}

		if ( ! $removed ) {
			return false;
		}

		return self::write( $server_id, $kept );
	}

	/**
	 * Stamp a host's last-call time.
	 *
	 * Rate-limited to one write a minute. This runs on the request path, and
	 * an UPDATE per forwarded call would make the busiest fleets pay the most
	 * for a timestamp nobody reads at that resolution.
	 *
	 * @since 0.4.6
	 *
	 * @param int    $server_id The Fleet server's primary key.
	 * @param string $key       Host key.
	 * @param int    $now       Current timestamp; injectable for tests.
	 * @return void
	 */
	public static function touch( int $server_id, string $key, int $now = 0 ): void {
		$now   = $now > 0 ? $now : time();
		$hosts = self::all( $server_id );
		$dirty = false;

		foreach ( $hosts as $i => $host ) {
			if ( $host['key'] !== $key ) {
				continue;
			}
			if ( $now - (int) $host['last_call_at'] < MINUTE_IN_SECONDS ) {
				return;
			}
			$hosts[ $i ]['last_call_at'] = $now;
			$dirty                       = true;
			break;
		}

		if ( $dirty ) {
			self::write( $server_id, $hosts );
		}
	}

	/**
	 * Whether an Application Password UUID belongs to a paired host.
	 *
	 * The question {@see RoutePermission} asks on every fleet request, so it
	 * reads the same list the admin screen shows — removing a host on screen
	 * closes the door on the next call, with nothing to invalidate.
	 *
	 * @since 0.4.6
	 *
	 * @param int    $server_id The Fleet server's primary key.
	 * @param string $uuid      Application Password UUID presented by the caller.
	 * @return array<string, mixed>|null The matching host, or null.
	 */
	public static function find_by_uuid( int $server_id, string $uuid ): ?array {
		if ( '' === $uuid ) {
			return null;
		}

		foreach ( self::all( $server_id ) as $host ) {
			if ( '' !== $host['uuid'] && hash_equals( $host['uuid'], $uuid ) ) {
				return $host;
			}
		}

		return null;
	}

	/**
	 * Coerce one stored entry into the documented shape, or reject it.
	 *
	 * `key` and `url` are the two that cannot be defaulted: without a key the
	 * entry cannot be addressed by the Remove button, and without a URL there
	 * is nothing to show the owner about who is reaching their site.
	 *
	 * @since 0.4.6
	 *
	 * @param mixed $entry Candidate entry.
	 * @return array{key: string, host: string, url: string, uuid: string, added_at: int, last_call_at: int}|null
	 */
	private static function normalize( $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$key = isset( $entry['key'] ) && is_string( $entry['key'] ) ? sanitize_key( $entry['key'] ) : '';
		$url = isset( $entry['url'] ) && is_string( $entry['url'] ) ? esc_url_raw( $entry['url'] ) : '';

		if ( '' === $key || '' === $url ) {
			return null;
		}

		$host = isset( $entry['host'] ) && is_string( $entry['host'] ) ? $entry['host'] : '';
		if ( '' === $host ) {
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		}

		return array(
			'key'          => $key,
			'host'         => $host,
			'url'          => $url,
			'uuid'         => isset( $entry['uuid'] ) && is_string( $entry['uuid'] ) ? $entry['uuid'] : '',
			'added_at'     => isset( $entry['added_at'] ) ? absint( $entry['added_at'] ) : 0,
			'last_call_at' => isset( $entry['last_call_at'] ) ? absint( $entry['last_call_at'] ) : 0,
		);
	}

	/**
	 * Persist the list, deleting the row when it empties.
	 *
	 * Absence is the canonical "no hosts" state, so an empty array removes the
	 * row rather than storing `[]` — the two would otherwise read differently
	 * to anything inspecting the meta table while meaning the same thing.
	 *
	 * @since 0.4.6
	 *
	 * @param int                              $server_id The Fleet server's primary key.
	 * @param array<int, array<string, mixed>> $hosts     Normalized host list.
	 * @return bool
	 */
	private static function write( int $server_id, array $hosts ): bool {
		if ( empty( $hosts ) ) {
			MCPServerMetaQuery::delete_meta( $server_id, self::META_KEY );
			return true;
		}

		$encoded = wp_json_encode( array_values( $hosts ) );
		if ( false === $encoded ) {
			return false; // Refuse to persist garbage over a good list.
		}

		return MCPServerMetaQuery::update_meta( $server_id, self::META_KEY, $encoded );
	}
}
