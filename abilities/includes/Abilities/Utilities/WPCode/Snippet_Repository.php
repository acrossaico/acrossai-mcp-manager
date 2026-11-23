<?php
/**
 * Feature 112 — the only place this plugin touches WPCode snippet state.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;
use WPCode_Snippet;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Everything goes through WPCode's own snippet object. Nothing here writes the CPT or its meta.
 *
 * That is not a style preference. `WPCode_Snippet::save()` ends with `rebuild_cache()`
 * (class-wpcode-snippet.php:638-650), which rewrites the `wpcode_snippets` option — and THAT option,
 * not the CPT, is what the loader reads (auto-insert/class-wpcode-auto-insert-type.php:295 calls
 * `get_cached_snippets()`). A snippet written straight to the post table looks perfect in the
 * database, reads back correctly, and never runs, because the cache still holds the old set.
 *
 * @since 0.0.34
 */
final class Snippet_Repository {

	/**
	 * The option WPCode's loader actually reads.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const CACHE_OPTION = 'wpcode_snippets';

	/**
	 * Post type.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const POST_TYPE = 'wpcode';

	/**
	 * Auto-insert locations, from includes/auto-insert/.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const LOCATIONS = array(
		'site_wide_header',
		'site_wide_body',
		'site_wide_footer',
		'everywhere',
		'admin_only',
		'before_post',
		'after_post',
		'before_content',
		'after_content',
		'before_paragraph',
		'after_paragraph',
	);

	/**
	 * Legacy option keys behind the global header/body/footer scripts.
	 *
	 * `global-output.php:21-45` hooks wp_head / wp_body_open / wp_footer and reads these, not the
	 * `wpcode_global_*` names the functions are called. Writing the function-shaped name would store
	 * a value nothing ever outputs.
	 *
	 * @since 0.0.34
	 * @var   array<string, string>
	 */
	public const GLOBAL_KEYS = array(
		'header' => 'ihaf_insert_header',
		'body'   => 'ihaf_insert_body',
		'footer' => 'ihaf_insert_footer',
	);

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Load one snippet.
	 *
	 * @since  0.0.34
	 * @param  int $id Snippet id.
	 * @return WPCode_Snippet|WP_Error
	 */
	public static function find( int $id ) {
		if ( $id <= 0 ) {
			return new WP_Error(
				'invalid_input',
				__( 'A snippet id must be a positive integer.', 'acrossai-abilities-manager' )
			);
		}

		$snippet = new WPCode_Snippet( $id );

		/*
		 * WPCode_Snippet unsets its own id when the post is not a `wpcode` row
		 * (class-wpcode-snippet.php:262-265), so a wrong-post-type id is indistinguishable from a
		 * missing one at the constructor. get_id() returning 0 covers both.
		 */
		if ( 0 === (int) $snippet->get_id() ) {
			return new WP_Error(
				'unknown_snippet',
				sprintf(
					/* translators: %d: snippet id. */
					__( 'No WPCode snippet with id %d. Use list-snippets to see what exists.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		return $snippet;
	}

	/**
	 * The set the loader will actually run, straight from the cache option.
	 *
	 * @since  0.0.34
	 * @return array<int, int> Snippet ids present in the cache.
	 */
	public static function cached_ids(): array {
		return self::collect_ids( get_option( self::CACHE_OPTION, array() ) );
	}

	/**
	 * Walk the cache and pull out every snippet id.
	 *
	 * The option is keyed by LOCATION, not by snippet: `[ 'site_wide_header' => [ 0 => [ 'id' =>
	 * 143, ... ] ] ]`. Reading only the top level finds location buckets and no ids at all, which
	 * reports every active snippet as missing from the cache — measured on a live install. The walk
	 * is recursive and shape-tolerant rather than assuming exactly two levels, because the depth is
	 * WPCode's business and an empty cache, a flat cache and a nested one all have to answer
	 * correctly.
	 *
	 * @since  0.0.34
	 * @param  mixed $node Cache fragment.
	 * @return array<int, int>
	 */
	private static function collect_ids( $node ): array {
		if ( ! is_array( $node ) ) {
			return array();
		}

		if ( isset( $node['id'] ) && is_scalar( $node['id'] ) ) {
			return array( (int) $node['id'] );
		}

		$ids = array();

		foreach ( $node as $child ) {
			foreach ( self::collect_ids( $child ) as $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Whether the loader currently holds this snippet.
	 *
	 * The honest proof a write landed: the CPT row is not what decides whether a snippet runs.
	 *
	 * @since  0.0.34
	 * @param  int $id Snippet id.
	 * @return bool
	 */
	public static function in_cache( int $id ): bool {
		return in_array( $id, self::cached_ids(), true );
	}

	/**
	 * Shape one snippet for output. Rows, never maps.
	 *
	 * @since  0.0.34
	 * @param  WPCode_Snippet $snippet Snippet.
	 * @return array<string, mixed>
	 */
	public static function shape( WPCode_Snippet $snippet ): array {
		$id = (int) $snippet->get_id();

		return array(
			'id'            => $id,
			'title'         => (string) $snippet->get_title(),
			'code_type'     => (string) $snippet->get_code_type(),
			'code'          => (string) $snippet->get_code(),
			'active'        => (bool) $snippet->is_active(),
			'location'      => (string) $snippet->get_location(),
			'auto_insert'   => (bool) $snippet->get_auto_insert(),
			'priority'      => (int) $snippet->get_priority(),
			'note'          => (string) $snippet->get_note(),
			'tags'          => self::tag_names( $snippet ),
			'in_cache'         => self::in_cache( $id ),
			'last_error'       => self::last_error( $snippet ),
			'custom_shortcode' => (string) $snippet->get_custom_shortcode(),
			'conditional_logic' => (array) $snippet->get_conditional_rules(),
		);
	}

	/**
	 * The stored error for a snippet, as a human string.
	 *
	 * Through WPCode's own getter rather than the meta key: it returns `false` for "no error" and an
	 * array otherwise (class-wpcode-snippet.php), so the empty case has one definition here.
	 *
	 * @since  0.0.34
	 * @param  WPCode_Snippet $snippet Snippet.
	 * @return string
	 */
	public static function last_error( WPCode_Snippet $snippet ): string {
		$error = $snippet->get_last_error();

		if ( ! is_array( $error ) ) {
			return '';
		}

		return isset( $error['message'] ) ? (string) $error['message'] : wp_json_encode( $error );
	}

	/**
	 * Tag names, whatever shape WPCode hands back.
	 *
	 * `get_tags()` lazily populates from the taxonomy, so it can be term objects or plain strings
	 * depending on how the snippet was loaded. Casting a WP_Term to string is a fatal, so this
	 * normalises rather than assuming.
	 *
	 * @since  0.0.34
	 * @param  WPCode_Snippet $snippet Snippet.
	 * @return array<int, string>
	 */
	private static function tag_names( WPCode_Snippet $snippet ): array {
		$out = array();

		foreach ( (array) $snippet->get_tags() as $tag ) {
			if ( is_object( $tag ) && isset( $tag->name ) ) {
				$out[] = (string) $tag->name;
				continue;
			}

			if ( is_scalar( $tag ) ) {
				$out[] = (string) $tag;
			}
		}

		return array_values( $out );
	}

	/**
	 * Persist a snippet and prove it landed.
	 *
	 * Two read-backs, because WPCode can refuse each half silently:
	 *
	 * 1. `save()` returns the id, but says nothing about whether the cache was rebuilt. The cache is
	 *    what decides execution, so it is checked directly.
	 * 2. `save()` calls `run_activation_checks()` (class-wpcode-snippet.php:514) which, for php and
	 *    universal snippets that fail, sets `$this->active = false` and SAVES ANYWAY. The call
	 *    returns normally and the snippet is simply off. Reporting that as success would be the
	 *    plainest possible case of BUG-WRITE-REPORTED-WITHOUT-READ-BACK.
	 *
	 * @since  0.0.34
	 * @param  WPCode_Snippet $snippet       Snippet to save.
	 * @param  bool           $expect_active Whether the caller asked for it to end up active.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function persist( WPCode_Snippet $snippet, bool $expect_active = false ) {
		$id = $snippet->save();

		if ( ! $id ) {
			return new WP_Error(
				'save_failed',
				__( 'WPCode refused to save the snippet and gave no reason.', 'acrossai-abilities-manager' )
			);
		}

		$saved = self::find( (int) $id );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$active = (bool) $saved->is_active();

		if ( $expect_active && ! $active ) {
			return new WP_Error(
				'activation_refused',
				sprintf(
					/* translators: 1: snippet id, 2: the error WPCode recorded. */
					__( 'WPCode test-ran snippet %1$d before activating it, the code errored, and WPCode left it switched off. The snippet was saved but is NOT running. Error: %2$s', 'acrossai-abilities-manager' ),
					(int) $id,
					self::activation_error( $saved )
				)
			);
		}

		return self::shape( $saved );
	}

	/**
	 * The reason an activation was refused, from whichever place WPCode left it.
	 *
	 * The stored `_wpcode_last_error` meta is the obvious source but is often empty here: a syntax
	 * error caught by `run_activation_checks()` lives only in WPCode's in-memory error handler for
	 * the rest of the request, and is never persisted. Measured on a deliberately broken snippet,
	 * the meta was empty and the handler held the message. Reporting "(none recorded)" when WPCode
	 * knows the reason leaves the caller unable to fix the code.
	 *
	 * @since  0.0.34
	 * @param  WPCode_Snippet $snippet Snippet.
	 * @return string
	 */
	private static function activation_error( WPCode_Snippet $snippet ): string {
		$stored = self::last_error( $snippet );

		if ( '' !== $stored ) {
			return $stored;
		}

		if ( function_exists( 'wpcode' ) && isset( wpcode()->error ) ) {
			$live = (string) wpcode()->error->get_last_error_message();

			if ( '' !== $live ) {
				return $live;
			}
		}

		return __( '(WPCode recorded no message; the code most likely has a syntax error)', 'acrossai-abilities-manager' );
	}

	/**
	 * Apply caller-supplied fields to a snippet object.
	 *
	 * Only the keys this suite exposes. `load_from_array()` assigns any property it recognises
	 * (class-wpcode-snippet.php:287), so handing it raw input would let a caller set internals such
	 * as `compiled_code` or `post_data`.
	 *
	 * Free-text values are slashed on the way in. `save()` hands `code` straight to
	 * wp_update_post()/wp_insert_post() without slashing (class-wpcode-snippet.php:498-527) and core
	 * then unslashes, so an unslashed payload loses one level of backslashes: `\WP_Query` becomes
	 * `WP_Query`, `"\n"` becomes `"n"`, `/\d+/` becomes `/d+/`. WPCode's admin form is unaffected
	 * because $_POST arrives pre-slashed; an ability receives unslashed JSON. WPCode hit this itself
	 * and fixed it in duplicate() only, commented "Let's make sure the slashes don't get removed
	 * from the code".
	 *
	 * @since  0.0.34
	 * @param  WPCode_Snippet       $snippet Snippet to mutate.
	 * @param  array<string, mixed> $input   Caller input.
	 * @return void
	 */
	public static function apply( WPCode_Snippet $snippet, array $input ): void {
		$map = array(
			'title'            => 'title',
			'code'             => 'code',
			'code_type'        => 'code_type',
			'note'             => 'note',
			'priority'         => 'priority',
			'location'         => 'location',
			'auto_insert'      => 'auto_insert',
			'custom_shortcode' => 'custom_shortcode',
		);

		$data = array();

		foreach ( $map as $key => $property ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];

			if ( 'priority' === $key ) {
				$value = (int) $value;
			} elseif ( 'auto_insert' === $key ) {
				$value = self::auto_insert_flag( $value );
			} elseif ( in_array( $key, self::FREE_TEXT, true ) ) {
				$value = Slash_Input::slash( (string) $value, $input );
			} else {
				$value = (string) $value;
			}

			$data[ $property ] = $value;
		}

		if ( array_key_exists( 'tags', $input ) && is_array( $input['tags'] ) ) {
			$data['tags'] = array_values( array_map( 'strval', $input['tags'] ) );
		}

		if ( ! empty( $data ) ) {
			self::assign( $snippet, $data );
		}
	}

	/**
	 * WPCode compares auto_insert with `1 === $this->auto_insert`, so it must be an integer.
	 *
	 * Measured: passing boolean true fails that strict comparison, `save()` takes the else branch
	 * and calls wp_set_post_terms( $id, array(), 'wpcode_location' ) — which CLEARS the location
	 * instead of setting it. The write reports success, auto_insert reads back as true, and the
	 * location comes back empty, so the snippet is placed nowhere and nothing says why.
	 *
	 * @since  0.0.34
	 * @param  mixed $value Caller-supplied flag.
	 * @return int 1 or 0, never a boolean.
	 */
	private static function auto_insert_flag( $value ): int {
		return $value ? 1 : 0;
	}

	/**
	 * Hand a whitelisted field set to WPCode's own loader.
	 *
	 * Direct property assignment is not an option: `note`, `priority`, `use_rules` and `rules` are
	 * PRIVATE on WPCode_Snippet, so `$snippet->note = ...` is a fatal at call time. `load_from_array()`
	 * reaches them because it runs inside the class and reads `get_object_vars( $this )`.
	 *
	 * The whitelist matters just as much: that same method assigns ANY property it recognises, so
	 * handing it raw caller input would expose internals such as `post_data`, `compiled_code` and
	 * `id`. Building the array here keeps WPCode's supported route without widening what a caller
	 * can reach.
	 *
	 * @since  0.0.34
	 * @param  WPCode_Snippet       $snippet Snippet to mutate.
	 * @param  array<string, mixed> $data    Whitelisted property => value.
	 * @return void
	 */
	private static function assign( WPCode_Snippet $snippet, array $data ): void {
		$snippet->load_from_array( $data );
	}

	/**
	 * Place a snippet, and say whether it will run there.
	 *
	 * @since  0.0.34
	 * @param  WPCode_Snippet $snippet     Snippet.
	 * @param  string         $location    One of self::LOCATIONS.
	 * @param  bool           $auto_insert Whether WPCode should insert it automatically.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function place( WPCode_Snippet $snippet, string $location, bool $auto_insert ) {
		if ( ! in_array( $location, self::LOCATIONS, true ) ) {
			return new WP_Error(
				'unknown_location',
				sprintf(
					/* translators: 1: supplied location, 2: accepted list. */
					__( '"%1$s" is not a WPCode auto-insert location. Accepted values are: %2$s.', 'acrossai-abilities-manager' ),
					$location,
					implode( ', ', self::LOCATIONS )
				)
			);
		}

		$snippet->location    = $location;
		$snippet->auto_insert = self::auto_insert_flag( $auto_insert );

		return self::persist( $snippet, (bool) $snippet->is_active() );
	}

	/**
	 * Conditional-logic rule types WPCode Lite can actually evaluate.
	 *
	 * The rest — device, schedule, WooCommerce, EDD, MemberPress, location, snippet — live in
	 * includes/lite/conditional-logic/ as Pro upsells. Saving one on Lite stores a rule that never
	 * matches, so a snippet would silently stop appearing with no error anywhere.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const LITE_RULE_TYPES = array( 'page', 'user' );

	/**
	 * Free-text fields that must survive backslashes intact.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const FREE_TEXT = array( 'title', 'code', 'note', 'custom_shortcode' );

	/**
	 * Delete a snippet and make sure the loader stops running it.
	 *
	 * `wp_delete_post()` removes the row but does not rebuild WPCode's cache, so without the rebuild
	 * a deleted snippet keeps executing from the cached copy until something else saves a snippet.
	 *
	 * @since  0.0.34
	 * @param  int $id Snippet id.
	 * @return true|WP_Error
	 */
	public static function delete( int $id ) {
		$snippet = self::find( $id );

		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		if ( ! wp_delete_post( $id, true ) ) {
			return new WP_Error(
				'delete_failed',
				sprintf(
					/* translators: %d: snippet id. */
					__( 'WordPress refused to delete snippet %d.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		self::rebuild_cache();

		if ( self::in_cache( $id ) ) {
			return new WP_Error(
				'delete_incomplete',
				sprintf(
					/* translators: %d: snippet id. */
					__( 'Snippet %d was deleted but is still in WPCode\'s active snippet cache, so it may still run. Re-save any snippet to force a rebuild.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		return true;
	}

	/**
	 * Rebuild the option the loader reads.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function rebuild_cache(): void {
		if ( function_exists( 'wpcode' ) && isset( wpcode()->cache ) ) {
			wpcode()->cache->cache_all_loaded_snippets();
		}
	}

	/**
	 * Set conditional logic, refusing rule types this edition cannot evaluate.
	 *
	 * `save()` persists `use_rules` and `rules` to meta itself (class-wpcode-snippet.php:556-561),
	 * so the object is the whole interface. The validation is the point: WPCode Lite only ships the
	 * `page` and `user` evaluators, and a Pro-only rule saved on Lite simply never matches — the
	 * snippet quietly stops appearing and nothing reports why.
	 *
	 * @since  0.0.34
	 * @param  WPCode_Snippet            $snippet Snippet.
	 * @param  bool                      $enabled Whether the rules apply at all.
	 * @param  array<int, array<mixed>>  $groups  WPCode's rule-group structure.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_rules( WPCode_Snippet $snippet, bool $enabled, array $groups ) {
		foreach ( $groups as $group ) {
			foreach ( (array) $group as $rule ) {
				$type = is_array( $rule ) ? (string) ( $rule['type'] ?? '' ) : '';

				if ( '' === $type || in_array( $type, self::LITE_RULE_TYPES, true ) ) {
					continue;
				}

				return new WP_Error(
					'requires_pro',
					sprintf(
						/* translators: 1: rule type, 2: accepted list. */
						__( 'Conditional-logic rule type "%1$s" needs WPCode Pro. This site runs Lite, which evaluates only: %2$s. Saving an unsupported rule would leave the snippet silently never matching.', 'acrossai-abilities-manager' ),
						$type,
						implode( ', ', self::LITE_RULE_TYPES )
					)
				);
			}
		}

		// use_rules and rules are both private; go through WPCode's own loader.
		self::assign(
			$snippet,
			array(
				'use_rules' => $enabled,
				'rules'     => $groups,
			)
		);

		return self::persist( $snippet, (bool) $snippet->is_active() );
	}

	/**
	 * Write the global header/body/footer scripts.
	 *
	 * Deliberately NOT slashed, which is the opposite of the snippet-code path a few lines up, and
	 * the difference is a WordPress one rather than a WPCode one. `wp_insert_post()`,
	 * `wp_update_post()` and `update_post_meta()` all expect SLASHED input and unslash it
	 * internally, so snippet code must be slashed or it loses a level. `update_option()` does no
	 * such unslashing, so slashing here ADDS a level instead: measured, sending `\d+` stored
	 * `\\d+` (hex 5C5C). Applying one rule to both paths corrupts one of them whichever rule you
	 * pick.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $values Slot => markup, any subset of self::GLOBAL_KEYS.
	 * @return array<string, string>|WP_Error
	 */
	public static function update_global_scripts( array $values ) {
		$touched = array();

		foreach ( self::GLOBAL_KEYS as $slot => $option ) {
			if ( ! array_key_exists( $slot, $values ) ) {
				continue;
			}

			$new = (string) $values[ $slot ];

			update_option( $option, $new );

			/*
			 * Strict equality against what the caller actually sent. update_option() returns false
			 * for an unchanged value as readily as for a failure, so its return tells us nothing;
			 * and a looser comparison here would have accepted the double-slashed value that made
			 * this method wrong in the first place.
			 */
			$stored = (string) get_option( $option, '' );

			if ( $new !== $stored ) {
				return new WP_Error(
					'global_script_rejected',
					sprintf(
						/* translators: 1: slot name, 2: what was sent, 3: what was stored. */
						__( 'The %1$s script did not store as sent. Sent: %2$s. Stored: %3$s. Something on this site is filtering the option.', 'acrossai-abilities-manager' ),
						$slot,
						$new,
						$stored
					)
				);
			}

			$touched[] = $slot;
		}

		if ( empty( $touched ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Supply at least one of header, body or footer.', 'acrossai-abilities-manager' )
			);
		}

		return self::global_scripts();
	}

	/**
	 * Read the global header/body/footer scripts.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function global_scripts(): array {
		$out = array();

		foreach ( self::GLOBAL_KEYS as $slot => $option ) {
			$out[ $slot ] = (string) get_option( $option, '' );
		}

		return $out;
	}
}
