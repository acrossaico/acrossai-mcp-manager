<?php
/**
 * UTC-anchored replacement for BerlinDB's datetime column validator.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\Support
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Validator for every `datetime` column this plugin owns.
 *
 * ---------------------------------------------------------------------------
 * Why this class exists
 * ---------------------------------------------------------------------------
 * BerlinDB round-trips every datetime value through an unanchored
 * `strtotime()` on its way to the database:
 *
 *     // vendor/berlindb/core/src/Database/Kern/Column.php::validate_datetime()
 *     $timestamp = strtotime( $value );
 *     $value     = gmdate( 'Y-m-d H:i:s', $timestamp );
 *
 * `strtotime()` resolves a string carrying no timezone against PHP's DEFAULT
 * timezone. Every value we hand it is already UTC — produced by `gmdate()` in
 * the OAuth repositories — so it gets read as local time and converted to UTC
 * a SECOND time. The stored value is then wrong by the site's UTC offset.
 *
 * Normally invisible: WordPress sets `date_default_timezone_set( 'UTC' )` in
 * wp-settings.php, which makes the double conversion a no-op. It stops being a
 * no-op the moment any other plugin changes the default timezone and does not
 * restore it — LearnDash does this in two places, and a customer's plugin did
 * it on the site where this was found.
 *
 * Under Asia/Kolkata (+5:30) every expiry was stored 19800 seconds in the
 * PAST, so a 600-second authorization code was born 5h20m expired and the
 * first code exchange returned `invalid_grant`. Under a NEGATIVE offset the
 * shift runs the other way and EXTENDS token lifetime past its TTL, which
 * fails silently — no error, just credentials that live too long. Both
 * directions are defects; only one of them complains.
 *
 * BerlinDB lets a column name its own validator (`Column::validate()` prefers
 * `$this->validate` when it is callable), so every datetime column in this
 * plugin points here instead. Fixing it per-column rather than at the write
 * sites is what covers `created_at`, which BerlinDB stamps itself before
 * running the same validation pass.
 *
 * ---------------------------------------------------------------------------
 * Contract
 * ---------------------------------------------------------------------------
 * Behaviour matches `Column::validate_datetime()` exactly — the
 * `CURRENT_TIMESTAMP` passthrough, the zero-date fallback for empty input, and
 * the zero-date fallback for unparseable input — differing ONLY in anchoring
 * the parse to UTC.
 *
 * BerlinDB falls back to `(string) $this->default`. A validator is invoked as
 * `call_user_func( $this->validate, $value )` with no column context, so the
 * fallback is the literal zero date here. That is the same value BerlinDB
 * would produce for our columns: none of them declares a `default`, so the
 * default is itself validated from an empty string down to the zero date, and
 * the emitted DDL is `default '0000-00-00 00:00:00'`. A future datetime column
 * that DOES declare a non-zero default must not use this validator without
 * revisiting this paragraph.
 *
 * @since 0.9.16
 */
final class DatetimeColumn {

	/**
	 * Default empty datetime — the value BerlinDB uses with NO_ZERO_DATE off.
	 *
	 * @since 0.9.16
	 */
	public const EMPTY_DATETIME = '0000-00-00 00:00:00';

	/**
	 * Validate a datetime value, resolving it against UTC rather than PHP's
	 * default timezone.
	 *
	 * Referenced from schemas as
	 * `'validate' => array( DatetimeColumn::class, 'validate_utc' )`.
	 *
	 * @since 0.9.16
	 *
	 * @param mixed $value Value to validate.
	 * @return string A valid datetime value, or the zero date.
	 */
	public static function validate_utc( $value = '' ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		// MySQL constant — passed through untouched, as BerlinDB does.
		if ( 'CURRENT_TIMESTAMP' === strtoupper( $value ) ) {
			return 'CURRENT_TIMESTAMP';
		}

		if ( '' === $value || self::EMPTY_DATETIME === $value ) {
			return self::EMPTY_DATETIME;
		}

		$timestamp = strtotime( self::anchor_to_utc( $value ) );

		// Anchoring only ever helps a value that carries no timezone of its
		// own. It can still fail on an input `strtotime()` accepts in its bare
		// form but not with a suffix — a relative expression like "+3 days".
		// Retrying preserves BerlinDB's acceptance rather than narrowing it.
		// Such a value resolves against the default timezone, as it did
		// before; nothing in this plugin writes one, every producer being
		// `gmdate( 'Y-m-d H:i:s', ... )`.
		if ( false === $timestamp ) {
			$timestamp = strtotime( $value );
		}

		if ( false === $timestamp ) {
			return self::EMPTY_DATETIME;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Append a UTC marker to a value that does not already carry a timezone.
	 *
	 * Re-anchoring a value that states its own offset would be wrong — it
	 * would move an instant the caller had already pinned — so a trailing
	 * `Z`, `UTC`, `GMT`, or `±hh:mm` / `±hhmm` offset is left alone.
	 *
	 * @since 0.9.16
	 *
	 * @param string $value Trimmed datetime string.
	 * @return string
	 */
	private static function anchor_to_utc( string $value ): string {
		if ( (bool) preg_match( '/(?:Z|UTC|GMT|[+-]\d{2}:?\d{2})$/i', $value ) ) {
			return $value;
		}

		return $value . ' UTC';
	}
}
