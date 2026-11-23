<?php
/**
 * Feature 069 — Rank Math Instant Indexing (IndexNow) access.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only accessor for Rank Math's IndexNow API.
 *
 * \RankMath\Instant_Indexing\Api::get() is a static singleton accessor; the
 * methods on it are instance methods.
 *
 * Note on throttling: Instant_Indexing::THROTTLE_LIMIT = 5 applies to
 * Instant_Indexing::submit_url(), the auto-submit-on-save path — NOT to
 * Api::submit(), which is what we call. Manual submissions are not throttled.
 */
final class Instant_Indexing_Repository {

	/**
	 * Module slug.
	 */
	public const MODULE = 'instant-indexing';

	/**
	 * HTTP status returned by IndexNow => our error code.
	 *
	 * @see seo-by-rank-math/includes/modules/instant-indexing/class-api.php:363-370
	 */
	private const CODE_MAP = array(
		400 => 'indexnow_400',
		403 => 'indexnow_403_invalid_key',
		422 => 'indexnow_422',
		429 => 'indexnow_429_rate_limited',
		500 => 'indexnow_500',
	);

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The derived IndexNow key URL.
	 *
	 * Computed by Api::get_key_location() from home_url() plus the key, so it is
	 * never stored and never writable.
	 *
	 * @return string Empty string when Instant Indexing is unavailable.
	 */
	public static function key_location(): string {
		$api = self::api();
		if ( null === $api || ! method_exists( $api, 'get_key_location' ) ) {
			return '';
		}
		return (string) $api->get_key_location();
	}

	/**
	 * The current IndexNow key.
	 *
	 * @return string
	 */
	public static function key(): string {
		$api = self::api();
		if ( null === $api || ! method_exists( $api, 'get_key' ) ) {
			return '';
		}
		return (string) $api->get_key();
	}

	/**
	 * Submit URLs to IndexNow as a manual submission.
	 *
	 * @param string[] $urls Absolute URLs.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function submit( array $urls ) {
		$api = self::api();
		if ( null === $api || ! method_exists( $api, 'submit' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math Instant Indexing module is not available.', 'acrossai-abilities-manager' ) );
		}

		$clean = array();
		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ) );
			if ( '' === $url ) {
				continue;
			}
			if ( ! wp_http_validate_url( $url ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %s: submitted URL */
						__( '"%s" is not a valid URL.', 'acrossai-abilities-manager' ),
						$url
					)
				);
			}
			$clean[] = $url;
		}

		if ( array() === $clean ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one URL to submit.', 'acrossai-abilities-manager' ) );
		}

		// $manual = true records the submission as manual in Rank Math's log and
		// bypasses the auto-submit throttle entirely.
		$accepted = (bool) $api->submit( $clean, true );

		$code    = method_exists( $api, 'get_response_code' ) ? (int) $api->get_response_code() : 0;
		$message = method_exists( $api, 'get_error' ) ? (string) $api->get_error() : '';

		if ( ! $accepted ) {
			return new WP_Error(
				self::CODE_MAP[ $code ] ?? 'invalid_input',
				sprintf(
					/* translators: 1: HTTP status code, 2: error detail from IndexNow */
					__( 'IndexNow rejected the submission (HTTP %1$d): %2$s', 'acrossai-abilities-manager' ),
					$code,
					'' !== $message ? $message : __( 'no detail provided', 'acrossai-abilities-manager' )
				),
				array( 'response_code' => $code )
			);
		}

		return array(
			'submitted'     => $clean,
			'accepted'      => true,
			'response_code' => $code,
			'key_location'  => self::key_location(),
		);
	}

	/**
	 * The submission log, newest first.
	 *
	 * Mirrors Rank Math's own Rest::get_log() enrichment and filtering
	 * (instant-indexing/class-rest.php:167-184): human-readable timestamps, then
	 * filter, then reverse. `total` is the unfiltered count, as Rank Math reports it.
	 *
	 * @param string $filter 'all' | 'manual' | 'auto'.
	 * @param int    $limit  Maximum entries to return.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function log( string $filter = 'all', int $limit = 50 ) {
		$api = self::api();
		if ( null === $api || ! method_exists( $api, 'get_log' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math Instant Indexing module is not available.', 'acrossai-abilities-manager' ) );
		}

		$log   = $api->get_log();
		$log   = is_array( $log ) ? $log : array();
		$total = count( $log );

		$entries = array();
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$manual = ! empty( $entry['manual_submission'] );
			if ( 'manual' === $filter && ! $manual ) {
				continue;
			}
			if ( 'auto' === $filter && $manual ) {
				continue;
			}

			$time      = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
			$entries[] = array(
				'urls'          => isset( $entry['urls'] ) ? (array) $entry['urls'] : array(),
				'time'          => $time,
				'time_formatted' => $time > 0 ? (string) wp_date( 'Y-m-d H:i:s', $time ) : '',
				'time_human'    => $time > 0 ? sprintf(
					/* translators: %s: human-readable time difference, e.g. "1 hour" */
					__( '%s ago', 'acrossai-abilities-manager' ),
					human_time_diff( $time )
				) : '',
				'manual'        => $manual,
				'response_code' => isset( $entry['code'] ) ? (int) $entry['code'] : null,
				'message'       => isset( $entry['message'] ) ? (string) $entry['message'] : '',
			);
		}

		$entries = array_values( array_reverse( $entries ) );

		return array(
			'entries'  => array_slice( $entries, 0, max( 1, $limit ) ),
			'count'    => min( count( $entries ), max( 1, $limit ) ),
			'filtered' => count( $entries ),
			'total'    => $total,
			'filter'   => $filter,
		);
	}

	/**
	 * Clear the submission log.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function clear_log() {
		$api = self::api();
		if ( null === $api || ! method_exists( $api, 'clear_log' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math Instant Indexing module is not available.', 'acrossai-abilities-manager' ) );
		}

		$before = is_array( $api->get_log() ) ? count( $api->get_log() ) : 0;
		$api->clear_log();

		return array(
			'cleared'         => true,
			'entries_removed' => $before,
		);
	}

	/**
	 * Regenerate the IndexNow key.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function reset_key() {
		$api = self::api();
		if ( null === $api || ! method_exists( $api, 'reset_key' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math Instant Indexing module is not available.', 'acrossai-abilities-manager' ) );
		}

		$previous = self::key();
		$api->reset_key();

		return array(
			'previous_key' => $previous,
			'key'          => self::key(),
			'key_location' => self::key_location(),
		);
	}

	/**
	 * The Instant Indexing API instance, or null when unavailable.
	 *
	 * @return object|null
	 */
	private static function api(): ?object {
		if ( ! class_exists( '\RankMath\Instant_Indexing\Api' ) || ! method_exists( '\RankMath\Instant_Indexing\Api', 'get' ) ) {
			return null;
		}
		$api = \RankMath\Instant_Indexing\Api::get();
		return is_object( $api ) ? $api : null;
	}
}
