<?php
/**
 * Shared input-sanitization utility for all AcrossAI modules.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Static sanitization helpers used at REST API boundaries.
 *
 * @since 0.1.0
 */
class AcrossAI_Sanitizer {

	/**
	 * Sanitize an ability slug parameter.
	 *
	 * Applies sanitize_text_field() and strips any characters that are not
	 * valid in an ability slug (alphanumeric, hyphens, forward-slashes).
	 *
	 * @since  0.1.0
	 * @param  string $slug Raw slug value from request.
	 * @return string
	 */
	public static function sanitize_ability_slug( string $slug ): string {
		$slug = sanitize_text_field( $slug );
		// Allow alphanumeric, hyphens, underscores, forward-slashes (namespaced slugs).
		$slug = preg_replace( '/[^a-zA-Z0-9\-_\/]/', '', $slug );
		// SEC-001: collapse consecutive slashes that could arise after rawurldecode (e.g. %2F%2F).
		$slug = preg_replace( '/\/+/', '/', $slug );
		// Enforce maximum length to prevent oversized slugs reaching the DB (LOW-04).
		return substr( $slug, 0, 255 );
	}

	/**
	 * Validate an ability slug — reject slugs exceeding 255 characters.
	 *
	 * Used as `validate_callback` on `{slug}` URL parameters. Returns a WP_Error
	 * with HTTP 400 when the raw slug exceeds 255 characters (LOW-04 / ST-01).
	 * Sanitization is handled separately via `sanitize_ability_slug()`.
	 *
	 * @since  0.1.0
	 * @param  string $slug Raw slug value from URL.
	 * @return true|\WP_Error
	 */
	public static function validate_ability_slug( string $slug ) {
		if ( mb_strlen( $slug ) > 255 ) {
			return new \WP_Error(
				'rest_invalid_param',
				__( 'Ability slug must not exceed 255 characters.', 'acrossai-abilities-manager' ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Sanitize a tri-state value (true / false / null).
	 *
	 * Uses strict comparisons throughout — loose equality MUST NOT be used because
	 * PHP loose equality treats `false == null` as true, which conflates "No" with
	 * "Inherit" and corrupts governance semantics.
	 *
	 * Mapping (strict):
	 *   true / 1 / "1"                  → PHP true
	 *   false / 0 / "0"                 → PHP false
	 *   null / "null" / "inherit"       → PHP null (Inherit)
	 *   anything else                   → PHP null + error_log notice
	 *
	 * @since  0.1.0
	 * @param  mixed $value Raw value (from REST body, form input, etc.).
	 * @return bool|null
	 */
	public static function sanitize_tri_state( $value ): ?bool {
		// --- Null / Inherit ---
		if ( null === $value ) {
			return null;
		}
		if ( 'null' === $value || 'inherit' === $value ) {
			return null;
		}

		// --- True ---
		if ( true === $value || 1 === $value || '1' === $value ) {
			return true;
		}

		// --- False ---
		if ( false === $value || 0 === $value || '0' === $value ) {
			return false;
		}

		// Unrecognised value — return null (safe default) and log.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[AcrossAI] sanitize_tri_state: unexpected value type ' . gettype( $value ) . ', coercing to null.' );
		}
		return null;
	}

	/**
	 * Sanitize an MCP type value.
	 *
	 * @since  0.1.0
	 * @param  mixed $value Raw value.
	 * @return string|null  One of 'tool', 'resource', 'prompt', or null.
	 */
	public static function sanitize_mcp_type( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$allowed = array( 'tool', 'resource', 'prompt' );
		$value   = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : null;
	}

	/**
	 * Cast a tinyint database value to PHP bool or null.
	 *
	 * MySQL returns tinyint columns as strings ('1', '0') or PHP null for SQL NULL.
	 * Uses strict comparisons — do NOT use (bool) or loose == because (bool) '0'
	 * is false but that only works by accident; loose == loses the null vs false
	 * distinction when comparing cast results downstream.
	 *
	 * DB value → PHP value:
	 *   1 / '1'       → true   (explicit Yes override)
	 *   0 / '0'       → false  (explicit No override)
	 *   null / ''     → null   (Inherit — no override for this field)
	 *
	 * Used by AcrossAI_Abilities_Row and any future Row classes.
	 * Do NOT duplicate this method on individual Row classes (RF-02).
	 *
	 * @since  0.1.0
	 * @param  mixed $value DB value (int 1/0, string '1'/'0', or null).
	 * @return bool|null
	 */
	public static function cast_tri_state( $value ): ?bool {
		// SQL NULL / empty string → Inherit.
		if ( null === $value || '' === $value ) {
			return null;
		}
		// Strict int checks (PHP may receive int from some DB drivers).
		if ( 1 === $value || '1' === $value ) {
			return true;
		}
		if ( 0 === $value || '0' === $value ) {
			return false;
		}
		// Fallback: treat any other truthy int/string as true (e.g. '2'), null otherwise.
		return null;
	}
}
