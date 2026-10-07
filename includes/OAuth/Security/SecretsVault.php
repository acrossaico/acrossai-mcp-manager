<?php
/**
 * Central random-token generation + SHA-256 hashing surface (Feature 021).
 *
 * S3 — every raw OAuth secret (client_secret, auth code, access token,
 * refresh token) goes through this one boundary. Comparisons use hash_equals.
 * Zero external dependencies.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth\Security
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth\Security;

defined( 'ABSPATH' ) || exit;

/**
 * All static — no state, no hooks. FR-DoD gate: no `new SecretsVault()`
 * anywhere in the codebase.
 */
final class SecretsVault {

	/**
	 * Generate a 256-bit random token as 64 hex characters.
	 *
	 * Used for client_secret, access_token, refresh_token, auth code.
	 * `random_bytes` uses the OS-provided CSPRNG (PHP 8.1+ guarantee).
	 *
	 * @return string 64-char hex string.
	 */
	public static function random_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * SHA-256 hash of raw secret material. Returns 64-char hex.
	 *
	 * S3 boundary — every persistence-bound secret passes through this
	 * function. Storage columns are `char(64)` (FR-040 invariant).
	 *
	 * @param string $raw Raw secret.
	 * @return string 64-char hex hash.
	 */
	public static function hash( string $raw ): string {
		return hash( 'sha256', $raw );
	}

	/**
	 * Timing-safe secret comparison.
	 *
	 * @param string $known_hash Hash retrieved from DB.
	 * @param string $raw_input  Untrusted input hashed via self::hash().
	 * @return bool True iff match.
	 */
	public static function verify( string $known_hash, string $raw_input ): bool {
		return hash_equals( $known_hash, self::hash( $raw_input ) );
	}

	/**
	 * Generate a 128-bit random client_id suffix (32 hex chars for DCR,
	 * 8 hex chars for admin `server-{id}-{slug}-{rand8}` per Q2).
	 *
	 * @param int $bytes Number of random bytes (16 for DCR, 4 for admin).
	 * @return string Hex string of length 2*$bytes.
	 */
	public static function random_hex( int $bytes ): string {
		if ( $bytes < 1 ) {
			$bytes = 16;
		}
		return bin2hex( random_bytes( $bytes ) );
	}
}
