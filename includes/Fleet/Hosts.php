<?php
/**
 * The hubs allowed to manage this site, and the keys that prove them.
 *
 * A "host" is another WordPress site that may call this site's Fleet
 * endpoint. The list lives in the existing per-server meta table against the
 * Fleet server's own row, so this adds no schema: one key, one JSON array,
 * read and written here and nowhere else.
 *
 * ## There is no secret in here
 *
 * Each host record holds that hub's **public** key. The hub keeps the private
 * half and signs every request with it; this site only ever verifies. So the
 * worst a database leak on a managed site yields is a list of public keys,
 * which are useless to whoever steals them — they cannot be used to sign
 * anything, and they are not reusable against any other site.
 *
 * That is the whole reason the signature design was chosen over handing each
 * hub an Application Password. Do not reintroduce a stored credential here;
 * if a future caller seems to need one, the design has gone wrong upstream.
 *
 * ## What the user id is for
 *
 * A verified signature has to become a WordPress user before the MCP stack
 * can check a capability or attribute a log entry. Each host records which
 * user it acts as, chosen by the administrator who paired it. A paired hub is
 * therefore exactly as powerful as that user — no more, and no less — and
 * removing the host ends it.
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
	 * @since 0.4.6
	 * @var   string
	 */
	public const META_KEY = '_fleet_hosts';

	/**
	 * Every host currently allowed to manage this site.
	 *
	 * Malformed entries are dropped rather than repaired. A record that
	 * cannot be read cannot be removed either, so showing it would offer the
	 * owner a control that does nothing.
	 *
	 * @since 0.4.6
	 *
	 * @param int $server_id The Fleet server's primary key.
	 * @return array<int, array{key: string, host: string, url: string, pubkey: string, user_id: int, added_at: int, last_call_at: int}>
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
			if ( hash_equals( $host['key'], $key ) ) {
				return $host;
			}
		}

		return null;
	}

	/**
	 * Add a host, or replace one already recorded under the same key.
	 *
	 * Re-registering an existing hub replaces rather than appends: two
	 * entries for one hub would leave the owner unsure which Remove button
	 * cuts it off, and the stale entry's key would keep working after the
	 * visible one was gone.
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
	 * Nothing to revoke elsewhere: the hub's access WAS this record. Once it
	 * is gone its signatures verify against nothing and the next call is
	 * refused, with no credential left anywhere to clean up.
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
	 * Coerce one stored entry into the documented shape, or reject it.
	 *
	 * `key`, `url` and `pubkey` cannot be defaulted: without a key the entry
	 * cannot be addressed by the Remove button, without a URL there is
	 * nothing to show the owner about who is reaching their site, and without
	 * a public key the record could never authenticate anything — an entry
	 * missing it would be a host that appears connected and is not.
	 *
	 * @since 0.4.6
	 *
	 * @param mixed $entry Candidate entry.
	 * @return array{key: string, host: string, url: string, pubkey: string, user_id: int, added_at: int, last_call_at: int}|null
	 */
	private static function normalize( $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}

		$key    = isset( $entry['key'] ) && is_string( $entry['key'] ) ? sanitize_key( $entry['key'] ) : '';
		$url    = isset( $entry['url'] ) && is_string( $entry['url'] ) ? esc_url_raw( $entry['url'] ) : '';
		$pubkey = isset( $entry['pubkey'] ) && is_string( $entry['pubkey'] ) ? trim( $entry['pubkey'] ) : '';

		if ( '' === $key || '' === $url || '' === $pubkey ) {
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
			'pubkey'       => $pubkey,
			'user_id'      => isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : 0,
			'added_at'     => isset( $entry['added_at'] ) ? absint( $entry['added_at'] ) : 0,
			'last_call_at' => isset( $entry['last_call_at'] ) ? absint( $entry['last_call_at'] ) : 0,
		);
	}

	/**
	 * Persist the list, deleting the row when it empties.
	 *
	 * Absence is the canonical "no hosts" state, so an empty array removes
	 * the row rather than storing `[]` — the two would otherwise read
	 * differently to anything inspecting the meta table while meaning the
	 * same thing.
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
