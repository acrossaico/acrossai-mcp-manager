<?php
/**
 * Canonical form for the plugin's own identifier keys.
 *
 * `sanitize_key()` with a length guard, in one place. Three retained classes needed it and were all
 * reaching into `AcrossAI_Ability_Library_Config` — the class Feature 102 removed — for a two-line
 * static helper that had nothing to do with the category configuration it lived next to. Extracting
 * it is what let that class go (Constitution §VI).
 *
 * The length guard matters as much as the character filter: these keys are used as array keys in
 * stored options and as ability-name fragments, and an unbounded key from a third-party definition
 * would be stored verbatim.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizes identifier keys to a bounded, lowercase, key-safe form.
 */
class AcrossAI_Key_Sanitizer {

	/**
	 * Longest key retained.
	 *
	 * Carried over unchanged from the class this was extracted from, so no stored key changes
	 * shape across the upgrade.
	 *
	 * @since 0.0.34
	 * @var   int
	 */
	public const MAX_KEY_LENGTH = 100;

	/**
	 * Sanitize a raw key string: `sanitize_key()` plus the max-length guard.
	 *
	 * @since  0.0.34
	 * @param  string $key Raw key string.
	 * @return string Sanitised key, at most self::MAX_KEY_LENGTH characters.
	 */
	public static function key( string $key ): string {
		return substr( sanitize_key( $key ), 0, self::MAX_KEY_LENGTH );
	}
}
