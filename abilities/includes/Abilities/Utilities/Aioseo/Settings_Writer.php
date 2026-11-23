<?php
/**
 * Feature 132 — the single AIOSEO settings write path.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every settings write in this suite goes through here, and it exists because of one trap.
 *
 * **`sanitizeAndSave()` is not a partial update.** Both of AIOSEO's option stores finish their save
 * with `arrayIntersectRecursive( $dbOptions, $replacement, 'value' )`, under a comment saying "we
 * must also intersect both arrays to delete any individual keys that were unset"
 * (`Options.php:699`, `DynamicOptions.php:153`). So any key absent from the array you pass is
 * **deleted**. Handing it `array( 'sitemap' => array( 'general' => array( 'enable' => true ) ) )`
 * does not switch the sitemap on — it switches the sitemap on and erases every other AIOSEO setting
 * on the site, reporting success.
 *
 * AIOSEO's own REST route gets away with passing "the options" because its Vue app always sends the
 * entire tree back. An ability cannot: a caller names three fields. So the recipe here is fixed —
 * read the whole tree, merge the caller's fields into it, pass the whole thing back. There is no
 * other correct order, and no shortcut that is merely less efficient rather than destructive.
 *
 * The write itself must still be `sanitizeAndSave()` and never `update_option( 'aioseo_options' )`:
 * that method is where AIOSEO casts each value to its declared type, rewrites localized strings,
 * schedules a sitemap regeneration when sitemap options changed, flushes rewrite rules when
 * `removeCategoryBase` changed, reformats the schema phone number, and reschedules log retention. A
 * raw option write stores settings that never take effect.
 *
 * @since 0.0.44
 */
final class Settings_Writer {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Write fields into one site-wide settings panel.
	 *
	 * @since  0.0.44
	 * @param  string               $panel  Panel slug, as declared by {@see Settings_Registry::panels()}.
	 * @param  array<string, mixed> $fields Field id => value, ids dotted within the panel.
	 * @return array<string, mixed>|WP_Error Written field report.
	 */
	public static function write( string $panel, array $fields ) {
		$resolved = Settings_Registry::resolve( $panel );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$allowed = Aioseo_Guard::assert_capability( (string) $resolved['capability'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$validated = Settings_Registry::validate( $panel, $fields );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$refused = self::assert_group_allowed( (string) $resolved['path'][0] );
		if ( is_wp_error( $refused ) ) {
			return $refused;
		}

		$before = Settings_Registry::values( $panel );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		$tree = Settings_Registry::all_values();
		if ( array() === $tree ) {
			return new WP_Error(
				'aioseo_settings_unreadable',
				__( 'AIOSEO returned no settings to merge into, so the write was abandoned rather than risk replacing the whole tree with just these fields.', 'acrossai-abilities-manager' )
			);
		}

		$patch = self::nest( (array) $resolved['path'], Settings_Registry::expand( $validated ) );
		$merged = self::merge( $tree, $patch );

		aioseo()->options->sanitizeAndSave( $merged );

		$after = Settings_Registry::values( $panel );
		if ( is_wp_error( $after ) ) {
			return $after;
		}

		return self::report( $panel, array_keys( $validated ), is_array( $before ) ? $before : array(), is_array( $after ) ? $after : array() );
	}

	/**
	 * Write fields onto one object's search-appearance settings.
	 *
	 * The dynamic store, which is a different option row with its own defaults and its own
	 * `sanitizeAndSave()` — and the same full-tree requirement.
	 *
	 * @since  0.0.44
	 * @param  string               $scope  'post-type', 'taxonomy' or 'archive'.
	 * @param  string               $object Object name, e.g. 'post' or 'category'.
	 * @param  array<string, mixed> $fields Field id => value, ids dotted within the object.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function write_object( string $scope, string $object, array $fields ) {
		$allowed = Aioseo_Guard::assert_capability( 'aioseo_search_appearance_settings' );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$validated = Object_Settings_Registry::validate( $scope, $object, $fields );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$path = Object_Settings_Registry::path( $scope, $object );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$before = Object_Settings_Registry::values( $scope, $object );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		$tree = Object_Settings_Registry::all_values();
		if ( array() === $tree ) {
			return new WP_Error(
				'aioseo_settings_unreadable',
				__( 'AIOSEO returned no per-object settings to merge into, so the write was abandoned rather than risk replacing them with just these fields.', 'acrossai-abilities-manager' )
			);
		}

		$patch  = self::nest( $path, Settings_Registry::expand( $validated ) );
		$merged = self::merge( $tree, $patch );

		aioseo()->dynamicOptions->sanitizeAndSave( $merged );

		$after = Object_Settings_Registry::values( $scope, $object );
		if ( is_wp_error( $after ) ) {
			return $after;
		}

		return self::report(
			$scope . ':' . $object,
			array_keys( $validated ),
			is_array( $before ) ? $before : array(),
			is_array( $after ) ? $after : array()
		);
	}

	/**
	 * Refuse a write to an option group AIOSEO itself would strip for this user.
	 *
	 * AIOSEO's save route calls `aioseo()->access->getNotAllowedOptions()` and unsets those groups
	 * before saving, so a caller who lacks the rights has their write silently dropped. Silently is
	 * the problem: this reports it instead. For an administrator the list is empty, so this is a
	 * no-op on the common path.
	 *
	 * @since  0.0.44
	 * @param  string $group Top-level option group.
	 * @return true|WP_Error
	 */
	private static function assert_group_allowed( string $group ) {
		if ( ! isset( aioseo()->access ) || ! is_object( aioseo()->access ) ) {
			return true;
		}

		$not_allowed = aioseo()->access->getNotAllowedOptions();

		if ( is_array( $not_allowed ) && in_array( $group, $not_allowed, true ) ) {
			return new WP_Error(
				'aioseo_group_not_allowed',
				sprintf(
					/* translators: %s: AIOSEO option group name */
					__( 'All in One SEO does not allow your role to manage its "%s" option group, and would have discarded this write without saying so.', 'acrossai-abilities-manager' ),
					$group
				)
			);
		}

		return true;
	}

	/**
	 * Build a nested array placing a subtree at a path.
	 *
	 * @since  0.0.44
	 * @param  string[]             $path    Path segments.
	 * @param  array<string, mixed> $subtree Subtree to place.
	 * @return array<string, mixed>
	 */
	private static function nest( array $path, array $subtree ): array {
		foreach ( array_reverse( $path ) as $segment ) {
			$subtree = array( $segment => $subtree );
		}

		return $subtree;
	}

	/**
	 * Recursively merge a patch into a tree, patch winning at the leaves.
	 *
	 * Not `array_merge_recursive`, which appends to arrays instead of replacing them — a list-valued
	 * setting such as `sitemap.general.postTypes.included` would accumulate every post type ever
	 * written rather than taking the caller's list. Not `array_replace_recursive` either: that
	 * descends into list values and merges them index by index, so replacing a three-entry list with a
	 * one-entry list would leave the last two entries in place.
	 *
	 * A list on the patch side therefore replaces the target wholesale, and only string-keyed maps are
	 * descended into.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $tree  Target.
	 * @param  array<string, mixed> $patch Patch.
	 * @return array<string, mixed>
	 */
	private static function merge( array $tree, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if (
				is_array( $value )
				&& isset( $tree[ $key ] )
				&& is_array( $tree[ $key ] )
				&& ! self::is_list( $value )
			) {
				$tree[ $key ] = self::merge( $tree[ $key ], $value );

				continue;
			}

			$tree[ $key ] = $value;
		}

		return $tree;
	}

	/**
	 * Whether an array is a list rather than a map.
	 *
	 * `array_is_list()` is PHP 8.1+, which this plugin requires, but AIOSEO option values arrive from
	 * JSON and can be a map with numeric-string keys. Testing the keys directly is what matters.
	 *
	 * @since  0.0.44
	 * @param  array<mixed> $value Value.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Build the written-field report.
	 *
	 * Reports what AIOSEO stored, read back after the save, rather than what the caller asked for.
	 * Each value passes through `sanitizeField()`, so "what I sent" and "what is now stored" are
	 * routinely different — a number cast from a string, a string with its HTML stripped — and the
	 * caller needs the second. A field whose stored value did not move is listed as `unchanged`, which
	 * is how a silently-rejected value becomes visible.
	 *
	 * @since  0.0.44
	 * @param  string               $scope  Panel slug or scope:object, for the message.
	 * @param  string[]             $ids    Field ids the caller wrote.
	 * @param  array<string, mixed> $before Values before.
	 * @param  array<string, mixed> $after  Values after.
	 * @return array<string, mixed>
	 */
	private static function report( string $scope, array $ids, array $before, array $after ): array {
		$updated   = array();
		$unchanged = array();

		foreach ( $ids as $id ) {
			$was = $before[ $id ] ?? null;
			$now = $after[ $id ] ?? null;

			if ( $was === $now ) {
				$unchanged[ $id ] = $now;

				continue;
			}

			$updated[ $id ] = array(
				'from' => $was,
				'to'   => $now,
			);
		}

		$message = array() === $updated
			? sprintf(
				/* translators: %s: panel slug or scope:object */
				__( 'Nothing changed in "%s" — every field already held the value given, or AIOSEO\'s sanitizer reduced it to the stored one. The unchanged list shows what is stored now.', 'acrossai-abilities-manager' ),
				$scope
			)
			: sprintf(
				/* translators: 1: number of fields, 2: panel slug or scope:object */
				_n( '%1$d field written to "%2$s".', '%1$d fields written to "%2$s".', count( $updated ), 'acrossai-abilities-manager' ),
				count( $updated ),
				$scope
			);

		return array(
			'scope'     => $scope,
			'updated'   => $updated,
			'unchanged' => $unchanged,
			'message'   => $message,
		);
	}
}
