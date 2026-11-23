<?php
/**
 * Slash-safe, verified persistence for wp_global_styles post_content.
 *
 * Two defects made every writer in this subsystem unsafe, and both are fixed here rather than in
 * each caller, because both are properties of *how the JSON reaches the database*, not of what any
 * one ability wants to store.
 *
 * ## 1. The user record needs flags WordPress looks for
 *
 * `WP_Theme_JSON_Resolver::get_user_data()` uses a wp_global_styles record only when its
 * post_content decodes to an array **and** carries `"isGlobalStylesUserThemeJSON": true`. Without
 * that key core discards the whole record and silently falls back to the theme's theme.json — no
 * notice, no log, no error. Writing `settings`/`styles` alone therefore produced a record that
 * stored correctly, read back correctly, and had no effect on the site. Core seeds the post it
 * creates itself with `{ "version": 3, "isGlobalStylesUserThemeJSON": true }`; {@see with_user_flags()}
 * reproduces that, and every DB write goes through it, so callers never have to pass the flags.
 *
 * ## 2. post_content must be slashed on the way in
 *
 * `wp_insert_post()` / `wp_update_post()` run `wp_unslash()` on every field. JSON handed to them
 * unslashed therefore loses the backslashes inside its own string literals: a font stack such as
 * `"\"EB Garamond\", Georgia, serif"` arrives as `""EB Garamond", Georgia, serif"` and the stored
 * post_content is no longer valid JSON. The record then decodes to null, which the old read path
 * reported as an empty record — so the next merge-write started from empty and dropped every
 * section saved before it. One quoted font stack wiped colors, layout and spacing.
 *
 * Core's own `WP_REST_Global_Styles_Controller::update_item()` calls
 * `wp_update_post( wp_slash( (array) $prepared_post ), true, false )` for exactly this reason. This
 * class mirrors that, which is what "use the core save path" means in practice.
 *
 * ## 3. Never report a write that did not survive
 *
 * Slashing is necessary but not sufficient: a `content_save_pre` filter, kses, or a storage-layer
 * fault can still mangle the JSON after it leaves here. Every write is therefore read back and
 * decoded before it is called a success, and a write that does not decode is rolled back to the
 * previous content instead of being left in place behind a `success: true`.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Global_Styles
 * @since      0.0.41
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles;

defined( 'ABSPATH' ) || exit;

/**
 * Writes theme.json-shaped arrays into wp_global_styles posts safely.
 */
final class Global_Styles_Writer {

	/**
	 * The key `WP_Theme_JSON_Resolver::get_user_data()` requires before it will use a record.
	 *
	 * @var string
	 */
	public const USER_FLAG = 'isGlobalStylesUserThemeJSON';

	/**
	 * Schema version used when WP_Theme_JSON is unavailable (it is only absent under the unit-test
	 * bootstrap and on pre-5.8 WordPress, neither of which can serve Global Styles anyway).
	 *
	 * @var int
	 */
	public const FALLBACK_SCHEMA = 3;

	/**
	 * The schema version core is currently writing.
	 *
	 * Read from `WP_Theme_JSON::LATEST_SCHEMA` rather than hardcoded: a record stamped with an older
	 * version is migrated by core on every read, which is wasted work and, for any key core moved
	 * between versions, a silent rewrite of what was stored.
	 *
	 * @since  0.0.41
	 * @return int
	 */
	public static function latest_schema(): int {
		if ( class_exists( '\WP_Theme_JSON' ) && defined( '\WP_Theme_JSON::LATEST_SCHEMA' ) ) {
			return (int) constant( '\WP_Theme_JSON::LATEST_SCHEMA' );
		}

		return self::FALLBACK_SCHEMA;
	}

	/**
	 * Stamp the two keys core requires onto a user-origin record.
	 *
	 * Always overwrites, never merges: a caller-supplied `version` of 2 on a site whose core writes
	 * 3 is not a preference to honour, and a caller-supplied `isGlobalStylesUserThemeJSON: false`
	 * is a record that does nothing. Both keys lead the array so the stored JSON opens the way
	 * core's own seed does.
	 *
	 * @since  0.0.41
	 * @param  array<string, mixed> $data theme.json-shaped data.
	 * @return array<string, mixed>
	 */
	public static function with_user_flags( array $data ): array {
		unset( $data['version'], $data[ self::USER_FLAG ] );

		return array_merge(
			array(
				'version'       => self::latest_schema(),
				self::USER_FLAG => true,
			),
			$data
		);
	}

	/**
	 * Whether a decoded record carries the flag that makes WordPress use it.
	 *
	 * Strict `true`, matching core: `get_user_data()` tests
	 * `! empty( $decoded_data['isGlobalStylesUserThemeJSON'] )`, but a record storing the string
	 * "false" would pass that and is plainly not a record anyone meant to be active, so this is the
	 * stricter of the two readings and the only one that survives a round-trip through JSON.
	 *
	 * @since  0.0.41
	 * @param  array<string, mixed> $data Decoded record.
	 * @return bool
	 */
	public static function has_user_flags( array $data ): bool {
		return isset( $data[ self::USER_FLAG ] ) && true === $data[ self::USER_FLAG ];
	}

	/**
	 * Decode stored post_content, distinguishing "empty" from "corrupt".
	 *
	 * The distinction is the whole point: the old code collapsed both to `array()`, which is what
	 * turned one bad write into silent loss of every earlier section.
	 *
	 * @since  0.0.41
	 * @param  string $content Raw post_content.
	 * @return array<string, mixed>|\WP_Error Empty array for empty content; WP_Error when content
	 *                                        exists but is not a JSON object.
	 */
	public static function decode( string $content ) {
		$content = trim( $content );
		if ( '' === $content ) {
			return array();
		}

		$decoded = json_decode( $content, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'corrupt_record',
				sprintf(
					/* translators: %s: JSON parse error message. */
					__( 'The stored Global Styles record is not valid JSON (%s), so it cannot be merged into. Re-save the whole record with merge=false, or pass repair=true, to replace it.', 'acrossai-abilities-manager' ),
					json_last_error_msg()
				)
			);
		}

		return $decoded;
	}

	/**
	 * Insert a new wp_global_styles post carrying $data.
	 *
	 * @since  0.0.41
	 * @param  array<string, mixed> $postarr Post fields; post_content is supplied by this method.
	 * @param  array<string, mixed> $data    theme.json-shaped data to store.
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function insert( array $postarr, array $data ) {
		return self::insert_post( $postarr, self::with_user_flags( $data ), true );
	}

	/**
	 * Insert without the user-record flags.
	 *
	 * For rows that live in wp_global_styles but are not the per-theme user record — block style
	 * variations. They need the slashing and the read-back verification; stamping them with
	 * isGlobalStylesUserThemeJSON would be wrong, because core reads them by a different path.
	 *
	 * @since  0.0.41
	 * @param  array<string, mixed> $postarr Post fields; post_content is supplied by this method.
	 * @param  array<string, mixed> $data    theme.json-shaped data to store.
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function insert_raw( array $postarr, array $data ) {
		return self::insert_post( $postarr, $data, false );
	}

	/**
	 * Shared insert path.
	 *
	 * @since  0.0.41
	 * @param  array<string, mixed> $postarr     Post fields.
	 * @param  array<string, mixed> $data        Data to store, already flagged or deliberately not.
	 * @param  bool                 $expect_flag Whether the read-back must find the user flag.
	 * @return int|\WP_Error
	 */
	private static function insert_post( array $postarr, array $data, bool $expect_flag ) {
		$json = Global_Styles_Db::encode_json( $data );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$postarr['post_content'] = wp_slash( $json );

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$verified = self::verify( (int) $post_id, $data, $expect_flag );
		if ( is_wp_error( $verified ) ) {
			// An unusable record is worse than no record: the detector would find it, every
			// subsequent read would report a corrupt DB copy, and the theme.json copy the site is
			// actually serving would look overridden. Remove it and report the failure.
			wp_delete_post( (int) $post_id, true );

			return $verified;
		}

		return (int) $post_id;
	}

	/**
	 * Replace an existing record's content with $data.
	 *
	 * @since  0.0.41
	 * @param  int                  $post_id      Target post.
	 * @param  array<string, mixed> $data         theme.json-shaped data to store.
	 * @param  array<string, mixed> $extra_fields Additional post fields to update alongside content.
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function update( int $post_id, array $data, array $extra_fields = array() ) {
		return self::update_post( $post_id, self::with_user_flags( $data ), $extra_fields, true );
	}

	/**
	 * Replace content without stamping the user-record flags. See {@see self::insert_raw()}.
	 *
	 * @since  0.0.41
	 * @param  int                  $post_id      Target post.
	 * @param  array<string, mixed> $data         theme.json-shaped data to store.
	 * @param  array<string, mixed> $extra_fields Additional post fields to update alongside content.
	 * @return int|\WP_Error Post ID on success.
	 */
	public static function update_raw( int $post_id, array $data, array $extra_fields = array() ) {
		return self::update_post( $post_id, $data, $extra_fields, false );
	}

	/**
	 * Shared update path.
	 *
	 * @since  0.0.41
	 * @param  int                  $post_id      Target post.
	 * @param  array<string, mixed> $data         Data to store, already flagged or deliberately not.
	 * @param  array<string, mixed> $extra_fields Additional post fields.
	 * @param  bool                 $expect_flag  Whether the read-back must find the user flag.
	 * @return int|\WP_Error
	 */
	private static function update_post( int $post_id, array $data, array $extra_fields, bool $expect_flag ) {
		$before = get_post( $post_id );
		if ( ! $before ) {
			return new \WP_Error( 'db_post_missing', __( 'The Global Styles post no longer exists.', 'acrossai-abilities-manager' ) );
		}
		$previous = (string) $before->post_content;

		$json = Global_Styles_Db::encode_json( $data );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$result = wp_update_post(
			array_merge(
				$extra_fields,
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $json ),
				)
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$verified = self::verify( $post_id, $data, $expect_flag );
		if ( is_wp_error( $verified ) ) {
			return self::restore( $post_id, $previous, $verified );
		}

		return (int) $result;
	}

	/**
	 * Read a record back and prove it decodes to what was written.
	 *
	 * @since  0.0.41
	 * @param  int                  $post_id     Post to read back.
	 * @param  array<string, mixed> $expected    Data that was written.
	 * @param  bool                 $expect_flag Whether the user-record flag must be present.
	 * @return true|\WP_Error
	 */
	private static function verify( int $post_id, array $expected, bool $expect_flag = true ) {
		// The object cache holds the row this request just wrote; for the verification to mean
		// anything it has to come from storage.
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'write_verification_failed', __( 'The Global Styles record could not be read back after saving.', 'acrossai-abilities-manager' ) );
		}

		$stored = self::decode( (string) $post->post_content );
		if ( is_wp_error( $stored ) ) {
			return new \WP_Error(
				'write_verification_failed',
				__( 'The saved Global Styles record does not decode as JSON, so WordPress would ignore it. Nothing was kept.', 'acrossai-abilities-manager' )
			);
		}

		if ( $expect_flag && ! self::has_user_flags( $stored ) ) {
			return new \WP_Error(
				'write_verification_failed',
				__( 'The saved Global Styles record lost its isGlobalStylesUserThemeJSON flag, so WordPress would ignore it. Nothing was kept.', 'acrossai-abilities-manager' )
			);
		}

		$missing = array();
		foreach ( array_keys( $expected ) as $key ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				$missing[] = (string) $key;
			}
		}

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'write_verification_failed',
				sprintf(
					/* translators: %s: comma-separated list of JSON keys. */
					__( 'The saved Global Styles record is missing keys that were written: %s. Nothing was kept.', 'acrossai-abilities-manager' ),
					implode( ', ', $missing )
				)
			);
		}

		return true;
	}

	/**
	 * Put the previous content back after a failed write.
	 *
	 * The restore is itself verified, because whatever mangled the new content may mangle this too
	 * (a `content_save_pre` filter, for instance). When it does, the caller is told the record is
	 * now unusable rather than being left to assume the rollback worked.
	 *
	 * @since  0.0.41
	 * @param  int       $post_id  Post to restore.
	 * @param  string    $previous Content to restore.
	 * @param  \WP_Error $failure  The write failure being rolled back.
	 * @return \WP_Error
	 */
	private static function restore( int $post_id, string $previous, \WP_Error $failure ): \WP_Error {
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $previous ),
			)
		);

		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $post_id );
		}

		$post   = get_post( $post_id );
		$actual = $post ? (string) $post->post_content : '';

		if ( $actual === $previous ) {
			return new \WP_Error(
				$failure->get_error_code(),
				$failure->get_error_message() . ' ' . __( 'The previous record was restored unchanged.', 'acrossai-abilities-manager' ),
				array( 'restored' => true )
			);
		}

		return new \WP_Error(
			$failure->get_error_code(),
			$failure->get_error_message() . ' ' . __( 'The previous record could NOT be restored — something on this site is altering post content as it is saved. The record should be treated as unusable until that is resolved.', 'acrossai-abilities-manager' ),
			array(
				'restored'         => false,
				'previous_content' => $previous,
			)
		);
	}
}
