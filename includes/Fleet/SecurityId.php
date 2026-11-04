<?php
/**
 * The short code a site owner reads off this screen and gives to a hub.
 *
 * Pairing needs one piece of out-of-band proof: something the person adding
 * this site to a fleet could only know by having access to it. This is that.
 *
 * ## Why the code goes this way round
 *
 * It is generated HERE and copied TO the managing site, not the other way
 * about. Two consequences follow, and both are the reason:
 *
 *  - A code handed to a child could be phished. Someone who talks an
 *    administrator into pasting an attacker's code gets that site to hand
 *    over a credential. A code read OUT of a site proves possession of the
 *    site and grants nothing to whoever receives it — the worst a stolen one
 *    does is let a hub attempt a registration this site can still refuse.
 *  - This site never has to call out. The hub comes here, which is the same
 *    direction every later fleet request travels, so there is exactly one
 *    network path to reason about instead of two.
 *
 * ## It is a one-time proof, not a password
 *
 * The code authenticates a single registration. Everything after that is
 * signed with a key pair, so the code's value ends the moment a host is
 * paired — which is why it can be shown in plain text on an admin screen and
 * rotated freely.
 *
 * Twelve characters from an unambiguous alphabet: long enough that guessing
 * is hopeless against a rate-limited single-use endpoint, short enough to
 * read down a phone line, and with no characters a human confuses.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Fleet
 * @since      0.4.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Fleet;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as MCPServerMetaQuery;

defined( 'ABSPATH' ) || exit;

final class SecurityId {

	/**
	 * Meta key holding the current code.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	public const META_KEY = '_fleet_security_id';

	/**
	 * Alphabet with the look-alikes removed — no 0/O, 1/l/I, 5/S, 2/Z.
	 * This gets read aloud and retyped, so a character set that cannot be
	 * misheard is worth more than the handful of bits it costs.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	private const ALPHABET = 'abcdefghjkmnpqrtuvwxy346789';

	/**
	 * Length in characters.
	 *
	 * @since 0.4.6
	 * @var   int
	 */
	private const LENGTH = 12;

	/**
	 * The current code, generating one on first read.
	 *
	 * Lazily created rather than written at seed time: the overwhelming
	 * majority of sites will never join a fleet, and a code nobody has asked
	 * for is a secret with no owner.
	 *
	 * @since 0.4.6
	 *
	 * @param int $server_id The Fleet server's primary key.
	 */
	public static function get( int $server_id ): string {
		if ( $server_id <= 0 ) {
			return '';
		}

		$existing = MCPServerMetaQuery::get_meta( $server_id, self::META_KEY );
		if ( is_string( $existing ) && '' !== $existing ) {
			return $existing;
		}

		return self::regenerate( $server_id );
	}

	/**
	 * Replace the code with a fresh one.
	 *
	 * Already-paired hosts are unaffected: they authenticate with a signature
	 * from here on and never present this code again. Rotating it only
	 * invalidates registrations that have not happened yet, which is exactly
	 * what an owner who thinks a code leaked wants.
	 *
	 * @since 0.4.6
	 *
	 * @param int $server_id The Fleet server's primary key.
	 */
	public static function regenerate( int $server_id ): string {
		if ( $server_id <= 0 ) {
			return '';
		}

		$code = self::generate();
		MCPServerMetaQuery::update_meta( $server_id, self::META_KEY, $code );

		return $code;
	}

	/**
	 * Constant-time comparison against the stored code.
	 *
	 * `hash_equals` because this is checked by an unauthenticated endpoint:
	 * a character-by-character comparison there leaks the code's prefix to
	 * anyone willing to time the responses.
	 *
	 * @since 0.4.6
	 *
	 * @param int    $server_id The Fleet server's primary key.
	 * @param string $presented Code supplied by the caller.
	 */
	public static function matches( int $server_id, string $presented ): bool {
		$stored = MCPServerMetaQuery::get_meta( $server_id, self::META_KEY );

		if ( ! is_string( $stored ) || '' === $stored || '' === $presented ) {
			return false;
		}

		return hash_equals( $stored, strtolower( trim( $presented ) ) );
	}

	/**
	 * Build a code from cryptographically secure randomness.
	 *
	 * `random_int`, not `wp_rand`: this is authentication material, and
	 * `wp_rand` falls back to `mt_rand` on some configurations.
	 *
	 * @since 0.4.6
	 */
	private static function generate(): string {
		$alphabet = self::ALPHABET;
		$max      = strlen( $alphabet ) - 1;
		$code     = '';

		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$code .= $alphabet[ random_int( 0, $max ) ];
		}

		return $code;
	}
}
