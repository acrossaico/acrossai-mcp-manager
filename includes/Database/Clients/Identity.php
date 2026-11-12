<?php
/**
 * What makes two requests the same client.
 *
 * This is the only interesting decision in the Clients feature, and the one
 * most likely to go quietly wrong — because the table is durable, a bad key
 * is not a glitch that clears on the next request, it is a row that stays
 * wrong forever.
 *
 * ## Three ways the obvious key fragments
 *
 * Measured against this site's own log before any of it was written:
 *
 *  1. `client_name` is empty on a large share of requests — it arrives only
 *     on the MCP `initialize` call and is recovered from the adapter session
 *     afterwards. Keying on it alone merged five genuinely different callers
 *     (curl, two Claude Code builds, a Python script, WP-CLI) into one row.
 *  2. The user agent carries a VERSION, and versions move. The same client
 *     split in two the day `mcp-wordpress-remote` went 0.5.4 → 0.5.5. So the
 *     key is the product TOKEN, never the raw agent.
 *  3. A fleet peer was recorded under two spellings thirty-three seconds
 *     apart — its site name, then its hostname — so `fleet_peer` is a label,
 *     not an identity. Fleet keys on the stable host key instead.
 *
 * ## Shape
 *
 * `resolve()` returns the identity STRING, which is stored as-is so a human
 * has something to read when the display name is blank. `hash()` turns it
 * into the fixed-width value the unique index actually keys on. The two are
 * separate because the raw value is for people and the hash is for MySQL.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\Clients
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\Clients;

use AcrossAI_MCP_Manager\Includes\Observability\ConnectionContext;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a request to a stable client identity.
 *
 * @since 0.4.8
 */
final class Identity {

	/**
	 * Identity used when nothing else distinguishes the caller.
	 *
	 * Deliberately a real value rather than an empty string: an empty
	 * identity would hash to a single row that silently absorbs every
	 * unidentifiable caller on the site, which is the merge failure this
	 * class exists to avoid. One row called "unknown" is honest; one row
	 * called "" pretending to be a client is not.
	 *
	 * @since 0.4.8
	 * @var   string
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Longest identity we store, matching the column width.
	 *
	 * @since 0.4.8
	 * @var   int
	 */
	public const MAX_LENGTH = 191;

	/**
	 * Agent tokens that name a transport rather than a client.
	 *
	 * `mcp-wordpress-remote` is the npx bridge: it appears in the agent of
	 * every client that routes through it, so treating it as the product
	 * would collapse Claude Code, Cursor and everything else into one row.
	 * It is read separately by {@see self::route()} and skipped here.
	 *
	 * @since 0.4.8
	 * @var   array<int, string>
	 */
	private const TRANSPORT_TOKENS = array( 'mcp-wordpress-remote' );

	/**
	 * Resolve the identity string for one request.
	 *
	 * First match wins, most specific first. The order matters: an OAuth
	 * caller also has a user agent, and keying it on the agent rather than
	 * its client id would split one registered client across every machine
	 * that used it.
	 *
	 * @since  0.4.8
	 * @param  array<string, mixed> $signals Request facts. Recognised keys:
	 *                                       `connection_type`, `client_id`,
	 *                                       `fleet_host_key`, `client_name`,
	 *                                       `user_agent`, `user_id`.
	 * @return string Never empty.
	 */
	public static function resolve( array $signals ): string {
		$type = isset( $signals['connection_type'] ) ? (string) $signals['connection_type'] : '';

		// OAuth and connector callers carry a registered client id, which is
		// already unique per server and survives both a changed agent and a
		// renamed client.
		if ( ConnectionContext::TYPE_OAUTH === $type || ConnectionContext::TYPE_AI_CONNECTOR === $type ) {
			$client_id = self::text( $signals['client_id'] ?? '' );

			if ( '' !== $client_id ) {
				return $client_id;
			}
		}

		// Fleet keys on the stored host key, not on the peer label. The label
		// is whatever the other site called itself at the time, and it has
		// been observed changing mid-conversation.
		if ( ConnectionContext::TYPE_FLEET === $type ) {
			$host_key = self::text( $signals['fleet_host_key'] ?? '' );

			if ( '' !== $host_key ) {
				return $host_key;
			}
		}

		$client_name = self::text( $signals['client_name'] ?? '' );

		if ( '' !== $client_name ) {
			return $client_name;
		}

		$token = self::ua_token( self::text( $signals['user_agent'] ?? '' ) );

		if ( '' !== $token ) {
			return $token;
		}

		$user_id = isset( $signals['user_id'] ) ? (int) $signals['user_id'] : 0;

		return $user_id > 0 ? 'user-' . $user_id : self::UNKNOWN;
	}

	/**
	 * The product name out of a user agent, with the version discarded.
	 *
	 * A user agent is a space-separated list of `product/version` pairs with
	 * optional parenthesised comments. We want the first product that is not
	 * a transport, because that is the thing a person would call "the
	 * client" — `claude-code/2.1.236 mcp-wordpress-remote/0.5.4` is Claude
	 * Code going through the bridge, not a client called mcp-wordpress-remote.
	 *
	 * Dropping the version is the whole point: keeping it meant one client
	 * became two rows the day it updated.
	 *
	 * @since  0.4.8
	 * @param  string $user_agent Raw agent header.
	 * @return string Lower-cased product token, or '' when there is none.
	 */
	public static function ua_token( string $user_agent ): string {
		// Parenthesised comments hold URLs and OS strings — never the product,
		// and often slashes that would be mistaken for one.
		$agent = (string) preg_replace( '/\([^)]*\)/', ' ', $user_agent );
		$agent = trim( $agent );

		if ( '' === $agent ) {
			return '';
		}

		$parts = preg_split( '/\s+/', $agent );
		$parts = is_array( $parts ) ? $parts : array();
		$words = array();

		foreach ( $parts as $part ) {
			$has_version = false !== strpos( $part, '/' );
			$product     = self::word( explode( '/', $part, 2 )[0] );

			// A bare version number ends a space-separated product name:
			// "WP CLI 2.12.0" is the WP-CLI product, then its version.
			if ( ! $has_version && '' !== $part && ctype_digit( $part[0] ) ) {
				break;
			}

			if ( '' === $product ) {
				continue;
			}

			// The bridge names itself in the agent of everything it carries,
			// so it can never be the product. Skipping it mid-name would
			// splice two unrelated words together, so it only skips when
			// nothing has been collected yet.
			if ( in_array( $product, self::TRANSPORT_TOKENS, true ) ) {
				if ( array() === $words ) {
					continue;
				}

				break;
			}

			$words[] = $product;

			// `product/version` is the conventional form and ends the name.
			// Anything before it was a space-separated prefix of the same
			// product — "WP Mail SMTP/1.0" is one client, not three.
			if ( $has_version ) {
				break;
			}
		}

		return array() === $words ? '' : self::clamp( implode( '-', $words ) );
	}

	/**
	 * One agent word, reduced to what may appear in an identity.
	 *
	 * @since  0.4.8
	 * @param  string $raw Agent fragment.
	 * @return string
	 */
	private static function word( string $raw ): string {
		return (string) preg_replace( '/[^a-z0-9._+-]/', '', strtolower( trim( $raw ) ) );
	}

	/**
	 * How the caller reached us, for display.
	 *
	 * Reuses the Connect tab's own vocabulary rather than inventing words for
	 * the same four routes an operator already chose between when setting the
	 * client up.
	 *
	 * @since  0.4.8
	 * @param  array<string, mixed> $signals Same shape as {@see self::resolve()}.
	 * @return string One of: connector, npm, wp-cli, config-file, fleet, browser, direct.
	 */
	public static function route( array $signals ): string {
		$type = isset( $signals['connection_type'] ) ? (string) $signals['connection_type'] : '';

		if ( ConnectionContext::TYPE_FLEET === $type ) {
			return 'fleet';
		}

		if ( ConnectionContext::TYPE_OAUTH === $type || ConnectionContext::TYPE_AI_CONNECTOR === $type ) {
			return 'connector';
		}

		$agent = strtolower( self::text( $signals['user_agent'] ?? '' ) );

		// The bridge names itself in the agent of everything it carries, which
		// is exactly what makes it useless as an identity and useful here.
		foreach ( self::TRANSPORT_TOKENS as $transport ) {
			if ( '' !== $agent && false !== strpos( $agent, $transport ) ) {
				return 'npm';
			}
		}

		if ( '' !== $agent && false !== strpos( $agent, 'wp cli' ) ) {
			return 'wp-cli';
		}

		if ( ConnectionContext::TYPE_BROWSER === $type ) {
			return 'browser';
		}

		// An application password reaching us without the bridge is a client
		// configured from a file — the third of the Connect tab's four routes.
		if ( ConnectionContext::TYPE_APP_PASSWORD === $type ) {
			return 'config-file';
		}

		return 'direct';
	}

	/**
	 * The fixed-width value the unique index keys on.
	 *
	 * Scoped by server and connection type so the same name reaching two
	 * servers, or the same string arriving over two different routes, stays
	 * two rows. Those are genuinely different grants and merging them would
	 * make one Revoke appear to cover both.
	 *
	 * @since  0.4.8
	 * @param  int    $server_id       Server row id.
	 * @param  string $connection_type A {@see ConnectionContext} TYPE_* value.
	 * @param  string $identity        Output of {@see self::resolve()}.
	 * @return string 40-character sha1.
	 */
	public static function hash( int $server_id, string $connection_type, string $identity ): string {
		return sha1( $server_id . '|' . $connection_type . '|' . $identity );
	}

	/**
	 * Coerce a signal to a trimmed string without tripping on arrays.
	 *
	 * @since  0.4.8
	 * @param  mixed $value Raw signal.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Keep an identity inside the column it has to fit.
	 *
	 * @since  0.4.8
	 * @param  string $value Identity candidate.
	 * @return string
	 */
	private static function clamp( string $value ): string {
		return strlen( $value ) > self::MAX_LENGTH ? substr( $value, 0, self::MAX_LENGTH ) : $value;
	}
}
