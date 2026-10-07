<?php
/**
 * PKCE S256 verifier implementation (Feature 021).
 *
 * RFC 7636 §4.6 — verify a raw code_verifier produces the stored
 * base64url-encoded SHA-256 hash of that verifier. `plain` is explicitly
 * NOT supported. Anthropic Connector spec + FR-005 mandate S256.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * All static — pure functions, no state.
 */
final class PKCE {

	/**
	 * Verify RFC 7636 §4.6 S256 challenge from a raw verifier.
	 *
	 * Formula: challenge = BASE64URL-ENCODE( SHA256( ASCII( code_verifier ) ) ).
	 *
	 * Base64url = base64 with `+` → `-`, `/` → `_`, and `=` padding stripped.
	 *
	 * @param string $verifier  Raw code_verifier submitted by client (43-128 chars).
	 * @param string $challenge Stored code_challenge (43 chars — F011/F021 invariant).
	 * @return bool True iff the verifier's S256 encoding equals the stored challenge.
	 */
	public static function verify_s256( string $verifier, string $challenge ): bool {
		// RFC 7636 §4.1 verifier length constraint.
		$len = strlen( $verifier );
		if ( $len < 43 || $len > 128 ) {
			return false;
		}
		if ( 43 !== strlen( $challenge ) ) {
			return false;
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 §4.6 mandates base64url encoding; not obfuscation.
		$sha_bytes  = hash( 'sha256', $verifier, true );
		$base64     = base64_encode( $sha_bytes );
		$base64_url = strtr( $base64, '+/', '-_' );
		$computed   = rtrim( $base64_url, '=' );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return hash_equals( $challenge, $computed );
	}

	/**
	 * Return true iff the given method name is exactly `S256`. Convenience
	 * for the authorize handler — reject anything else early with
	 * `invalid_request&error_description=PKCE+S256+required`.
	 *
	 * @param string $method Client-supplied code_challenge_method.
	 * @return bool
	 */
	public static function is_s256( string $method ): bool {
		return 'S256' === $method;
	}
}
