<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence layer for the "extra allowed upload MIME types" configured by
 * admins via the Core settings tab or the media-mimes-update ability.
 *
 * The stored value is `array<string,string>` where the key is a bare
 * lowercase extension (e.g. `svg`) and the value is a MIME type
 * (e.g. `image/svg+xml`). The stored map is merged into WordPress's
 * `upload_mimes` allowlist without overwriting anything the site already
 * accepted — this class can only ADD types, never disable core defaults.
 */
final class Mime_Types_Store {

	public const OPTION = 'acrossai_abilities_manager_extra_mimes';

	/**
	 * Load the persisted extras. Returns an empty array when the option is
	 * absent or malformed.
	 *
	 * @return array<string,string>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $ext => $mime ) {
			$ext_key = self::sanitize_ext( (string) $ext );
			$mime_v  = self::sanitize_mime( (string) $mime );
			if ( '' !== $ext_key && null !== $mime_v ) {
				$out[ $ext_key ] = $mime_v;
			}
		}
		return $out;
	}

	/**
	 * Validate a caller-supplied map WITHOUT persisting. Pure — no side
	 * effects, no `update_option` call. Callers that need persistence
	 * should use `set()`; callers running INSIDE a Settings API
	 * `sanitize_callback` MUST use `validate()` to avoid re-entering
	 * WordPress's own `update_option` → `sanitize_option_{$option}` filter
	 * loop (which fatal-OOMs the site).
	 *
	 * Return shape:
	 *   [ 'stored' => <validated map>, 'skipped' => [ [ext, mime, reason], ... ] ]
	 *
	 * @param array<string,string> $input Ext => MIME map.
	 * @return array{stored: array<string,string>, skipped: array<int, array{ext: string, mime: string, reason: string}>}
	 */
	public static function validate( array $input ): array {
		$stored  = array();
		$skipped = array();

		foreach ( $input as $ext => $mime ) {
			$raw_ext  = (string) $ext;
			$raw_mime = (string) $mime;
			$ext_key  = self::sanitize_ext( $raw_ext );
			$mime_v   = self::sanitize_mime( $raw_mime );

			if ( '' === $ext_key ) {
				$skipped[] = array(
					'ext'    => $raw_ext,
					'mime'   => $raw_mime,
					'reason' => __( 'Extension must be lowercase letters and digits only.', 'acrossai-abilities-manager' ),
				);
				continue;
			}
			if ( null === $mime_v ) {
				$skipped[] = array(
					'ext'    => $raw_ext,
					'mime'   => $raw_mime,
					'reason' => __( 'MIME type must match "type/subtype" (lowercase, RFC-compatible).', 'acrossai-abilities-manager' ),
				);
				continue;
			}
			$stored[ $ext_key ] = $mime_v;
		}

		return array(
			'stored'  => $stored,
			'skipped' => $skipped,
		);
	}

	/**
	 * Validate + persist a caller-supplied map. Existing entries not present
	 * in the input are DROPPED — this is a full replacement, not a merge.
	 * Callers that want to merge should read `get()` first.
	 *
	 * DO NOT call this from inside a Settings API `sanitize_callback` —
	 * the internal `update_option` would recursively fire
	 * `sanitize_option_{$option}`, causing infinite recursion and OOM.
	 * Use `validate()` in that context and let WordPress persist.
	 *
	 * Return shape:
	 *   [ 'stored' => <persisted map>, 'skipped' => [ [ext, mime, reason], ... ] ]
	 *
	 * @param array<string,string> $input Ext => MIME map.
	 * @return array{stored: array<string,string>, skipped: array<int, array{ext: string, mime: string, reason: string}>}
	 */
	public static function set( array $input ): array {
		$result = self::validate( $input );
		update_option( self::OPTION, $result['stored'], false );
		return $result;
	}

	/**
	 * Merge the caller-supplied additions on top of the currently-stored
	 * extras (add-only — never removes an existing entry via this API).
	 * Duplicates (ext already present with the same MIME) are reported as
	 * unchanged, not "added".
	 *
	 * @param array<string,string> $additions Ext => MIME map to add/merge.
	 * @return array{stored: array<string,string>, added: array<int, array{ext: string, mime: string}>, skipped: array<int, array{ext: string, mime: string, reason: string}>}
	 */
	public static function merge( array $additions ): array {
		$current = self::get();
		$added   = array();
		$skipped = array();

		foreach ( $additions as $ext => $mime ) {
			$raw_ext  = (string) $ext;
			$raw_mime = (string) $mime;
			$ext_key  = self::sanitize_ext( $raw_ext );
			$mime_v   = self::sanitize_mime( $raw_mime );

			if ( '' === $ext_key ) {
				$skipped[] = array(
					'ext'    => $raw_ext,
					'mime'   => $raw_mime,
					'reason' => __( 'Extension must be lowercase letters and digits only.', 'acrossai-abilities-manager' ),
				);
				continue;
			}
			if ( null === $mime_v ) {
				$skipped[] = array(
					'ext'    => $raw_ext,
					'mime'   => $raw_mime,
					'reason' => __( 'MIME type must match "type/subtype" (lowercase, RFC-compatible).', 'acrossai-abilities-manager' ),
				);
				continue;
			}

			if ( isset( $current[ $ext_key ] ) && $current[ $ext_key ] === $mime_v ) {
				continue;
			}

			$current[ $ext_key ] = $mime_v;
			$added[]             = array(
				'ext'  => $ext_key,
				'mime' => $mime_v,
			);
		}

		update_option( self::OPTION, $current, false );

		return array(
			'stored'  => $current,
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	/**
	 * Delete a list of extensions from the persisted store.
	 *
	 * @param string[] $exts Extensions to remove (as bare lowercase strings).
	 * @return array{stored: array<string,string>, removed: array<int, string>, not_found: array<int, string>}
	 */
	public static function remove( array $exts ): array {
		$current   = self::get();
		$removed   = array();
		$not_found = array();

		foreach ( $exts as $raw ) {
			$ext = self::sanitize_ext( (string) $raw );
			if ( '' === $ext ) {
				$not_found[] = (string) $raw;
				continue;
			}
			if ( ! array_key_exists( $ext, $current ) ) {
				$not_found[] = $ext;
				continue;
			}
			unset( $current[ $ext ] );
			$removed[] = $ext;
		}

		update_option( self::OPTION, $current, false );

		return array(
			'stored'    => $current,
			'removed'   => $removed,
			'not_found' => $not_found,
		);
	}

	/**
	 * Merge the stored extras into an existing `upload_mimes` map. Never
	 * overwrites an existing entry — WordPress core defaults and other
	 * plugins' filters always win. Hooked on `upload_mimes` only during
	 * `upload-media` ability execution (never globally) — the extras
	 * intentionally do NOT apply to regular Media Library uploads via
	 * wp-admin.
	 *
	 * @param array<string,string>|mixed $mimes
	 * @return array<string,string>
	 */
	public static function filter_upload_mimes( $mimes ): array {
		$mimes = is_array( $mimes ) ? $mimes : array();
		foreach ( self::get() as $ext => $mime ) {
			if ( ! isset( $mimes[ $ext ] ) ) {
				$mimes[ $ext ] = $mime;
			}
		}
		return $mimes;
	}

	/**
	 * Classify a single row of `get_allowed_mime_types()` by comparing it
	 * against WordPress's core defaults and the persisted extras. Used by
	 * the Core tab's read-only table and the media-mimes-list ability.
	 *
	 * @param string               $ext_key The `upload_mimes` key (may be a compound like `jpg|jpeg|jpe`).
	 * @param string               $mime    The MIME string.
	 * @param array<string,string> $core    Core defaults (from `wp_get_mime_types()`).
	 * @param array<string,string> $extras  Persisted extras (from `self::get()`).
	 * @return 'core'|'this-plugin'|'other-filter'
	 */
	public static function attribute( string $ext_key, string $mime, array $core, array $extras ): string {
		if ( isset( $core[ $ext_key ] ) && $core[ $ext_key ] === $mime ) {
			return 'core';
		}
		foreach ( explode( '|', $ext_key ) as $part ) {
			$part = self::sanitize_ext( $part );
			if ( '' !== $part && isset( $extras[ $part ] ) && $extras[ $part ] === $mime ) {
				return 'this-plugin';
			}
		}
		return 'other-filter';
	}

	/**
	 * Expand a compound `upload_mimes` key (e.g. `jpg|jpeg|jpe`) into its
	 * individual extensions. Kept public so callers building UI tables can
	 * decide whether to render the compound as-is or split it.
	 *
	 * @return string[]
	 */
	public static function expand_ext_key( string $ext_key ): array {
		$out = array();
		foreach ( explode( '|', $ext_key ) as $part ) {
			$part = self::sanitize_ext( $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * Sanitize ext.
	 *
	 * @param string $ext
	 * @return string
	 */
	private static function sanitize_ext( string $ext ): string {
		$ext = strtolower( trim( $ext ) );
		$ext = ltrim( $ext, '.' );
		if ( '' === $ext || ! preg_match( '/^[a-z0-9]+$/', $ext ) ) {
			return '';
		}
		return $ext;
	}

	/**
	 * Sanitize mime.
	 *
	 * @param string $mime
	 * @return ?string
	 */
	private static function sanitize_mime( string $mime ): ?string {
		$mime = strtolower( trim( $mime ) );
		if ( ! preg_match( '#^[a-z0-9]+/[a-z0-9.+-]+$#', $mime ) ) {
			return null;
		}
		return $mime;
	}
}
