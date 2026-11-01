<?php
/**
 * The pairing code an operator copies from a hub into a managed site.
 *
 * ## Why the code carries the address
 *
 * A code has to tell this site two things: which hub to register with, and a
 * secret proving the operator was invited. Splitting those into two fields
 * doubles the chance of a mismatched paste and gives the site owner a second
 * thing to get wrong, so both travel in one opaque token and the screen asks
 * for one value.
 *
 * The cost is honest: the token is long, roughly 90 characters, not the short
 * grouped code a human could read down a phone line. That is the right trade
 * for something always moved by copy and paste.
 *
 * ## The format is a wire contract
 *
 *     fleet_<base64url( {"v":1,"u":"<hub url>","s":"<secret>"} )>
 *
 * acrossai-pro writes these and this class reads them, so the two plugins must
 * agree. `v` exists so a later shape can be told apart from this one instead
 * of failing as corruption. Base64url, not plain base64: a token lands in
 * query strings and form fields where `+` and `/` are hazards.
 *
 * ## Not encryption
 *
 * Anyone holding the token can read the hub URL out of it. That is fine — the
 * URL is not a secret, and the part that is one is useless without reaching
 * the hub. Do not add obfuscation here and imply otherwise.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Fleet
 * @since      0.4.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Fleet;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PairingCode {

	/**
	 * Token prefix, so a paste that is plainly not a pairing code can be
	 * refused with a useful message rather than a decode failure.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	public const PREFIX = 'fleet_';

	/**
	 * The only format version this release understands.
	 *
	 * @since 0.4.6
	 * @var   int
	 */
	public const VERSION = 1;

	/**
	 * Read a pasted code.
	 *
	 * Every failure says what to do next, because this is the screen where
	 * pairing goes wrong and "invalid code" sends the operator nowhere.
	 *
	 * @since 0.4.6
	 *
	 * @param string $code Raw operator input.
	 * @return array{url: string, secret: string}|WP_Error
	 */
	public static function parse( string $code ) {
		// Operators paste from chat clients and spreadsheets, which add
		// whitespace and occasionally a zero-width character. Strip rather
		// than refuse: the code is still unambiguous underneath.
		$code = trim( preg_replace( '/\s+|\x{200B}/u', '', $code ) ?? '' );

		if ( '' === $code ) {
			return new WP_Error(
				'acrossai_mcp_fleet_code_empty',
				__( 'Paste the pairing code from the site that will manage this one.', 'acrossai-mcp-manager' )
			);
		}

		if ( 0 !== strpos( $code, self::PREFIX ) ) {
			return new WP_Error(
				'acrossai_mcp_fleet_code_shape',
				__( 'That does not look like a pairing code. It should begin with “fleet_”.', 'acrossai-mcp-manager' )
			);
		}

		$payload = substr( $code, strlen( self::PREFIX ) );
		$json    = self::base64url_decode( $payload );

		if ( null === $json ) {
			return new WP_Error(
				'acrossai_mcp_fleet_code_corrupt',
				__( 'That pairing code looks truncated. Copy the whole thing and try again.', 'acrossai-mcp-manager' )
			);
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'acrossai_mcp_fleet_code_corrupt',
				__( 'That pairing code could not be read. Ask for a new one.', 'acrossai-mcp-manager' )
			);
		}

		$version = isset( $decoded['v'] ) ? (int) $decoded['v'] : 0;
		if ( self::VERSION !== $version ) {
			return new WP_Error(
				'acrossai_mcp_fleet_code_version',
				__( 'That pairing code was made by a newer version. Update this plugin, or ask for a code from an older one.', 'acrossai-mcp-manager' )
			);
		}

		$url    = isset( $decoded['u'] ) && is_string( $decoded['u'] ) ? trim( $decoded['u'] ) : '';
		$secret = isset( $decoded['s'] ) && is_string( $decoded['s'] ) ? trim( $decoded['s'] ) : '';

		if ( '' === $url || '' === $secret ) {
			return new WP_Error(
				'acrossai_mcp_fleet_code_corrupt',
				__( 'That pairing code is missing part of itself. Ask for a new one.', 'acrossai-mcp-manager' )
			);
		}

		return array(
			'url'    => untrailingslashit( $url ),
			'secret' => $secret,
		);
	}

	/**
	 * Build a code. Present so both sides of the contract live in one file
	 * and the tests can round-trip it; the hub is what calls this in anger.
	 *
	 * @since 0.4.6
	 *
	 * @param string $hub_url Hub base URL.
	 * @param string $secret  One-time secret the hub will recognise.
	 */
	public static function build( string $hub_url, string $secret ): string {
		$json = wp_json_encode(
			array(
				'v' => self::VERSION,
				'u' => untrailingslashit( $hub_url ),
				's' => $secret,
			)
		);

		if ( false === $json ) {
			return '';
		}

		return self::PREFIX . self::base64url_encode( $json );
	}

	/**
	 * Base64url encode — RFC 4648 §5, padding stripped.
	 *
	 * @since 0.4.6
	 *
	 * @param string $raw Bytes to encode.
	 */
	private static function base64url_encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding, not obfuscation.
	}

	/**
	 * Base64url decode, or null when the input is not valid base64url.
	 *
	 * Strict decoding on purpose: a near-miss paste should be reported as
	 * truncated rather than silently decoded into nonsense that then fails as
	 * "corrupt JSON" and sends the operator looking in the wrong place.
	 *
	 * @since 0.4.6
	 *
	 * @param string $encoded Token payload.
	 */
	private static function base64url_decode( string $encoded ): ?string {
		$padded  = strtr( $encoded, '-_', '+/' );
		$remain  = strlen( $padded ) % 4;
		$padded .= 0 === $remain ? '' : str_repeat( '=', 4 - $remain );

		$decoded = base64_decode( $padded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Transport encoding, not obfuscation.

		return false === $decoded ? null : $decoded;
	}
}
