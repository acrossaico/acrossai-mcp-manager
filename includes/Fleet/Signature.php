<?php
/**
 * The signature a hub puts on every fleet request, and how this site checks it.
 *
 * ## What is signed
 *
 * A canonical string, newline-joined, in this exact order:
 *
 *     {host key}\n{timestamp}\n{nonce}\n{sha256 of the raw body, hex}
 *
 * Every part earns its place:
 *
 *  - **host key** binds the signature to one paired hub, so a signature
 *    lifted from one host cannot be replayed as another.
 *  - **timestamp** bounds how long a captured request stays usable.
 *  - **nonce** closes the window the timestamp leaves open — without it a
 *    request captured in transit could be replayed freely for five minutes.
 *  - **body hash** is what makes this a signature over the REQUEST rather
 *    than over a token. Omit it and a valid signature becomes a bearer
 *    credential: anyone who captures one could swap the body and run
 *    whatever they liked with it.
 *
 * Signing the body hash rather than the body keeps the string short and
 * avoids holding a second copy of a large payload in memory.
 *
 * ## Replay protection is deliberately two-part
 *
 * A clock skew window alone lets a captured request be replayed until it
 * expires. A nonce store alone must grow forever, because without a time
 * bound there is never a safe moment to forget one. Together, the nonce
 * store only has to remember one window's worth — so it is a transient with
 * exactly that lifetime and nothing has to clean up after it.
 *
 * ## Why SHA-256 and no negotiation
 *
 * One algorithm, pinned. An algorithm field in the request is a field an
 * attacker can set, and downgrade negotiation is how signature schemes
 * usually fail. If this ever needs to change, change it on both sides in one
 * release rather than letting the caller choose.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Fleet
 * @since      0.4.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Fleet;

defined( 'ABSPATH' ) || exit;

final class Signature {

	/**
	 * Header carrying the calling host's key.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	public const HEADER_HOST = 'x-acrossai-fleet-host';

	/**
	 * Header carrying the Unix timestamp the request was signed at.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	public const HEADER_TIMESTAMP = 'x-acrossai-fleet-timestamp';

	/**
	 * Header carrying the per-request nonce.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	public const HEADER_NONCE = 'x-acrossai-fleet-nonce';

	/**
	 * Header carrying the base64 signature.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	public const HEADER_SIGNATURE = 'x-acrossai-fleet-signature';

	/**
	 * How far a request's timestamp may be from this site's clock.
	 *
	 * Five minutes each way. Generous enough to survive two WordPress servers
	 * whose clocks have drifted — which is common and not the operator's
	 * fault — and short enough that a captured request is worthless quickly.
	 *
	 * @since 0.4.6
	 * @var   int
	 */
	public const CLOCK_SKEW = 300;

	/**
	 * Transient prefix for spent nonces.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	private const NONCE_PREFIX = 'acrossai_fleet_n_';

	/**
	 * Build the canonical string both sides sign and verify.
	 *
	 * Public because the hub has to produce byte-identical input. A private
	 * copy of this logic on each side is how signature schemes drift apart.
	 *
	 * @since 0.4.6
	 *
	 * @param string $host_key  Paired host key.
	 * @param int    $timestamp Unix timestamp.
	 * @param string $nonce     Per-request nonce.
	 * @param string $body      Raw request body.
	 */
	public static function canonical( string $host_key, int $timestamp, string $nonce, string $body ): string {
		return implode(
			"\n",
			array(
				$host_key,
				(string) $timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Verify a signature against a stored public key.
	 *
	 * Returns a reason string on failure rather than false, because these
	 * failures are operationally distinct — a clock problem and a wrong key
	 * need different fixes, and collapsing them makes the logs useless.
	 *
	 * @since 0.4.6
	 *
	 * @param string $pubkey    PEM public key stored for the host.
	 * @param string $host_key  Paired host key.
	 * @param int    $timestamp Unix timestamp from the request.
	 * @param string $nonce     Nonce from the request.
	 * @param string $body      Raw request body.
	 * @param string $signature Base64 signature from the request.
	 * @return true|string True when valid, else a short machine-readable reason.
	 */
	public static function verify(
		string $pubkey,
		string $host_key,
		int $timestamp,
		string $nonce,
		string $body,
		string $signature
	) {
		if ( ! function_exists( 'openssl_verify' ) ) {
			// Fail closed. Without OpenSSL nothing here can be checked, and
			// letting the request through would turn a missing extension into
			// an open endpoint.
			return 'no_openssl';
		}

		if ( '' === $pubkey || '' === $nonce || '' === $signature ) {
			return 'incomplete';
		}

		$drift = abs( time() - $timestamp );
		if ( $drift > self::CLOCK_SKEW ) {
			return 'clock_skew';
		}

		if ( self::nonce_seen( $nonce ) ) {
			return 'replay';
		}

		$raw = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Signature wire format.
		if ( false === $raw ) {
			return 'malformed';
		}

		$key = openssl_pkey_get_public( $pubkey );
		if ( false === $key ) {
			return 'bad_key';
		}

		$result = openssl_verify(
			self::canonical( $host_key, $timestamp, $nonce, $body ),
			$raw,
			$key,
			OPENSSL_ALGO_SHA256
		);

		if ( 1 !== $result ) {
			return 'mismatch';
		}

		// Burned only after the signature checks out. Spending it earlier
		// would let anyone with a guessed nonce lock a hub out of using it.
		self::burn_nonce( $nonce );

		return true;
	}

	/**
	 * Whether this nonce has already been used inside the current window.
	 *
	 * @since 0.4.6
	 *
	 * @param string $nonce Per-request nonce.
	 */
	private static function nonce_seen( string $nonce ): bool {
		return false !== get_transient( self::NONCE_PREFIX . md5( $nonce ) );
	}

	/**
	 * Record a nonce as spent for twice the skew window.
	 *
	 * Twice, not once: a request signed at the far edge of the allowed skew
	 * is still acceptable a full window later, so remembering for only one
	 * window would let the very oldest acceptable requests be replayed.
	 *
	 * @since 0.4.6
	 *
	 * @param string $nonce Per-request nonce.
	 */
	private static function burn_nonce( string $nonce ): void {
		set_transient( self::NONCE_PREFIX . md5( $nonce ), 1, self::CLOCK_SKEW * 2 );
	}
}
