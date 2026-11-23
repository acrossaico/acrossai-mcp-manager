<?php
/**
 * Feature 107 — resolves which editor Classic Editor will actually use.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\ClassicEditor
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ClassicEditor;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A mirror of `Classic_Editor::get_settings()`, which is private.
 *
 * The plugin resolves the effective editor through a chain of overrides and then caches the answer
 * on a private static. None of it is reachable: `get_settings()`, `is_classic()` and
 * `get_enabled_editors_for_post_type()` are all `private`, and the only public route in is the
 * `classic_editor_plugin_settings` filter, which sets the value rather than reading it. So the
 * chain has to be re-derived here.
 *
 * Mirrors classic-editor.php lines 221-306 (settings), 310-340 (per-post) and 752-795 (per-post-type)
 * of Classic Editor 1.7.0. Two things in the source look like bugs and are reproduced deliberately:
 *
 *   - Single-site normalises the legacy `no-replace` value to block (line 283). The multisite path
 *     does NOT — line 270 assigns the raw option and only the later coercion sees it, so the same
 *     value resolves to classic there. Test_Classic_Editor_Architecture pins both.
 *   - Anything that is not exactly `block` or `no-replace` falls through to classic. Missing, empty
 *     and malformed all mean classic, which is why `describe()` reports the effective value and the
 *     stored value separately — on a site that has never saved the settings there is no row at all,
 *     and reporting "" would imply "not configured" for a site that is actively behaving as classic.
 *
 * @since 0.0.34
 */
final class Editor_Settings_Repository {

	/**
	 * Site option: which editor is the default.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const OPTION_EDITOR = 'classic-editor-replace';

	/**
	 * Site option: whether users may choose for themselves.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const OPTION_ALLOW_USERS = 'classic-editor-allow-users';

	/**
	 * Network option: the network-wide default.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const NETWORK_OPTION_EDITOR = 'classic-editor-replace';

	/**
	 * Network option: whether per-site settings are honoured at all.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const NETWORK_OPTION_ALLOW_SITES = 'classic-editor-allow-sites';

	/**
	 * Per-user preference. Stored with update_user_option(), so the real meta key is
	 * blog-prefixed — read it with get_user_option(), never get_user_meta().
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const USER_OPTION = 'classic-editor-settings';

	/**
	 * Per-post remembered editor.
	 *
	 * Note the values are `classic-editor` / `block-editor` — NOT the `classic` / `block` the
	 * options and the user preference use. Three vocabularies for the same idea; mixing them is the
	 * most likely bug in this suite.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const POST_META = 'classic-editor-remember';

	/**
	 * Accepted values for the editor options and the user preference.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const EDITORS = array( 'classic', 'block' );

	/**
	 * Accepted values for the post meta.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const POST_EDITORS = array( 'classic-editor', 'block-editor' );

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The effective settings, and which layer decided each one.
	 *
	 * @since  0.0.34
	 * @param  int $user_id Whose preference to apply. 0 = current user.
	 * @return array<string, mixed>
	 */
	public static function describe( int $user_id = 0 ): array {
		$stored_editor = get_option( self::OPTION_EDITOR, '' );
		$stored_allow  = get_option( self::OPTION_ALLOW_USERS, '' );

		$multisite = is_multisite();
		$locked    = false;
		$source    = 'default';

		if ( $multisite ) {
			$network_editor = get_network_option( null, self::NETWORK_OPTION_EDITOR, '' );
			$editor         = 'block' === $network_editor ? 'block' : 'classic';
			$allow_users    = false;
			$source         = 'network';

			$locked = 'allow' !== get_network_option( null, self::NETWORK_OPTION_ALLOW_SITES, '' );

			if ( ! $locked ) {
				// Truthiness, not isset: an empty site option leaves the network default standing.
				if ( $stored_editor ) {
					$editor = 'block' === $stored_editor ? 'block' : 'classic';
					$source = 'site';
				}

				if ( $stored_allow ) {
					$allow_users = 'allow' === $stored_allow;
				}
			}
		} else {
			$allow_users = 'allow' === $stored_allow;

			// The legacy normalisation, single-site only.
			$editor = ( 'block' === $stored_editor || 'no-replace' === $stored_editor ) ? 'block' : 'classic';
			$source = '' === (string) $stored_editor ? 'default' : 'site';
		}

		$user_preference = '';

		if ( $allow_users ) {
			$candidate = get_user_option( self::USER_OPTION, $user_id );

			if ( in_array( $candidate, self::EDITORS, true ) ) {
				$user_preference = (string) $candidate;
				$editor          = $user_preference;
				$source          = 'user';
			}
		}

		return array(
			'available'        => Classic_Editor_Guard::is_available(),
			'editor'           => $editor,
			'allow_users'      => (bool) $allow_users,
			'decided_by'       => $source,
			'stored_editor'    => is_scalar( $stored_editor ) ? (string) $stored_editor : '',
			'stored_allow'     => is_scalar( $stored_allow ) ? (string) $stored_allow : '',
			'user_preference'  => $user_preference,
			'multisite'        => $multisite,
			'network_locked'   => $locked,
			'settings_ui_hidden' => $locked,
		);
	}

	/**
	 * Write the site settings through the plugin's own validators.
	 *
	 * `register_setting()`'s sanitize callback only runs through the Settings API, never through a
	 * plain `update_option()`, so the validators have to be called explicitly — otherwise a typo
	 * such as "blok" is stored verbatim and silently resolves to classic.
	 *
	 * Each key is read back after writing and only reported as changed when the stored value
	 * matches what was asked for (BUG-WRITE-REPORTED-WITHOUT-READ-BACK).
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $patch editor and/or allow_users.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update( array $patch ) {
		if ( array() === $patch ) {
			return new WP_Error( 'invalid_input', __( 'Supply editor, allow_users, or both.', 'acrossai-abilities-manager' ) );
		}

		$changed = array();

		if ( array_key_exists( 'editor', $patch ) ) {
			$requested = (string) $patch['editor'];

			if ( ! in_array( $requested, self::EDITORS, true ) ) {
				return new WP_Error(
					'invalid_editor',
					sprintf(
						/* translators: 1: supplied value, 2: comma-separated accepted values */
						__( '"%1$s" is not a valid editor. Accepted: %2$s.', 'acrossai-abilities-manager' ),
						$requested,
						implode( ', ', self::EDITORS )
					)
				);
			}

			$result = self::write( self::OPTION_EDITOR, self::validate_editor( $requested ), $requested );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $result ) {
				$changed[] = 'editor';
			}
		}

		if ( array_key_exists( 'allow_users', $patch ) ) {
			$requested = ! empty( $patch['allow_users'] ) ? 'allow' : 'disallow';
			$result    = self::write( self::OPTION_ALLOW_USERS, self::validate_allow_users( $requested ), $requested );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $result ) {
				$changed[] = 'allow_users';
			}
		}

		return array( 'changed' => $changed );
	}

	/**
	 * Persist one option and confirm it took.
	 *
	 * @since  0.0.34
	 * @param  string $option    Option name.
	 * @param  string $value     Value after the plugin's own validator.
	 * @param  string $requested What the caller asked for, for the error message.
	 * @return bool|WP_Error True when it changed, false when it already held that value.
	 */
	private static function write( string $option, string $value, string $requested ) {
		if ( (string) get_option( $option, '' ) === $value ) {
			return false;
		}

		update_option( $option, $value );

		$stored = (string) get_option( $option, '' );

		if ( $stored !== $value ) {
			return new WP_Error(
				'setting_rejected',
				sprintf(
					/* translators: 1: option name, 2: requested value, 3: value actually stored */
					__( 'Writing "%1$s" did not take: asked for %2$s, the site still holds %3$s.', 'acrossai-abilities-manager' ),
					$option,
					wp_json_encode( $requested ),
					wp_json_encode( $stored )
				)
			);
		}

		return true;
	}

	/**
	 * Classic Editor's own editor-value validator.
	 *
	 * @since  0.0.34
	 * @param  string $value Candidate.
	 * @return string
	 */
	private static function validate_editor( string $value ): string {
		if ( method_exists( 'Classic_Editor', 'validate_option_editor' ) ) {
			return (string) \Classic_Editor::validate_option_editor( $value );
		}

		return 'block' === $value ? 'block' : 'classic';
	}

	/**
	 * Classic Editor's own allow-users validator.
	 *
	 * @since  0.0.34
	 * @param  string $value Candidate.
	 * @return string
	 */
	private static function validate_allow_users( string $value ): string {
		if ( method_exists( 'Classic_Editor', 'validate_option_allow_users' ) ) {
			return (string) \Classic_Editor::validate_option_allow_users( $value );
		}

		return 'allow' === $value ? 'allow' : 'disallow';
	}

	/**
	 * Which editors a post type can use.
	 *
	 * Mirrors `get_enabled_editors_for_post_type()`. The plugin's own filter is re-applied so a site
	 * that filters gets the answer it actually uses rather than the unfiltered default; the plugin
	 * memoises its copy per request, so this may fire the filter a second time.
	 *
	 * @since  0.0.34
	 * @param  string $post_type Post type.
	 * @return array<string, bool>
	 */
	public static function editors_for_post_type( string $post_type ): array {
		$block_editor = false;

		if ( function_exists( 'use_block_editor_for_post_type' ) ) {
			$block_editor = (bool) use_block_editor_for_post_type( $post_type );
		}

		$editors = array(
			'classic_editor' => (bool) post_type_supports( $post_type, 'editor' ),
			'block_editor'   => $block_editor,
		);

		/** This filter is documented in classic-editor.php */
		$filtered = apply_filters( 'classic_editor_enabled_editors_for_post_type', $editors, $post_type );

		return array(
			'classic_editor' => ! empty( $filtered['classic_editor'] ),
			'block_editor'   => ! empty( $filtered['block_editor'] ),
		);
	}

	/**
	 * Which editor a given post will open in, and why.
	 *
	 * Mirrors `is_classic()` plus the enforcement tail of `choose_editor()`. Two deliberate
	 * departures, both because a REST call is not an editor page load:
	 *
	 *   - The `?classic-editor` and `?classic-editor__forget` query arguments are ignored. They
	 *     change the answer for one page load only, and are absent here by definition.
	 *   - With `allow-users` off, the plugin never hooks `choose_editor` at all and decides purely
	 *     from the post type (classic-editor.php:126-141). A remembered value still sits in the
	 *     meta and is simply not consulted — so it is reported, and reported as ignored.
	 *
	 * @since  0.0.34
	 * @param  WP_Post $post    Post.
	 * @param  int     $user_id Whose preference applies. 0 = current user.
	 * @return array<string, mixed>
	 */
	public static function editor_for_post( WP_Post $post, int $user_id = 0 ): array {
		$settings   = self::describe( $user_id );
		$post_type  = (string) $post->post_type;
		$enabled    = self::editors_for_post_type( $post_type );
		$remembered = get_post_meta( (int) $post->ID, self::POST_META, true );
		$remembered = in_array( $remembered, self::POST_EDITORS, true ) ? (string) $remembered : '';
		$has_blocks = function_exists( 'has_blocks' ) ? (bool) has_blocks( $post ) : false;

		if ( ! $settings['allow_users'] ) {
			$editor = 'block' === $settings['editor'] && $enabled['block_editor'] ? 'block' : 'classic';

			return self::post_result(
				$post,
				$editor,
				'site-default',
				$remembered,
				true,
				$enabled,
				$has_blocks,
				$settings
			);
		}

		if ( '' !== $remembered ) {
			$editor = 'classic-editor' === $remembered ? 'classic' : 'block';
			$reason = 'remembered';
		} else {
			$editor = $has_blocks ? 'block' : 'classic';
			$reason = 'content';
		}

		// Post-type support has the last word either way (choose_editor lines 633-638).
		if ( 'block' === $editor && ! $enabled['block_editor'] ) {
			$editor = 'classic';
			$reason = 'post-type-support';
		} elseif ( 'classic' === $editor && ! $enabled['classic_editor'] && $enabled['block_editor'] ) {
			$editor = 'block';
			$reason = 'post-type-support';
		}

		return self::post_result( $post, $editor, $reason, $remembered, false, $enabled, $has_blocks, $settings );
	}

	/**
	 * Shape one per-post answer.
	 *
	 * @since  0.0.34
	 * @param  WP_Post              $post              Post.
	 * @param  string               $editor            Resolved editor.
	 * @param  string               $reason            What decided it.
	 * @param  string               $remembered        Stored per-post value, or ''.
	 * @param  bool                 $remembered_ignored Whether the stored value is consulted at all.
	 * @param  array<string, bool>  $enabled           Post-type support.
	 * @param  bool                 $has_blocks        Whether the body contains block markup.
	 * @param  array<string, mixed> $settings          Effective settings.
	 * @return array<string, mixed>
	 */
	private static function post_result(
		WP_Post $post,
		string $editor,
		string $reason,
		string $remembered,
		bool $remembered_ignored,
		array $enabled,
		bool $has_blocks,
		array $settings
	): array {
		return array(
			'post_id'            => (int) $post->ID,
			'post_type'          => (string) $post->post_type,
			'editor'             => $editor,
			'decided_by'         => $reason,
			'remembered'         => $remembered,
			'remembered_ignored' => $remembered_ignored,
			'classic_available'  => (bool) $enabled['classic_editor'],
			'block_available'    => (bool) $enabled['block_editor'],
			'content_has_blocks' => $has_blocks,
			'allow_users'        => (bool) $settings['allow_users'],
		);
	}
}
