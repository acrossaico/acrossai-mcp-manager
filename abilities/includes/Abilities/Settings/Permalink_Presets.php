<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Settings
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shared lookup for the standard WordPress permalink presets.
 *
 *  - resolve()  — preset name → structure string (custom strings pass through)
 *  - match()    — structure string → preset name (custom when it doesn't match)
 *  - validate() — rejects structures that contain no rewrite tags
 */
final class Permalink_Presets {

	public const PRESETS = array(
		'plain'          => '',
		'day-and-name'   => '/%year%/%monthnum%/%day%/%postname%/',
		'month-and-name' => '/%year%/%monthnum%/%postname%/',
		'numeric'        => '/archives/%post_id%',
		'post-name'      => '/%postname%/',
	);

	/**
	 * Recognised WordPress rewrite tags inside a permalink structure.
	 */
	public const TAGS = array(
		'%year%',
		'%monthnum%',
		'%day%',
		'%hour%',
		'%minute%',
		'%second%',
		'%post_id%',
		'%postname%',
		'%category%',
		'%author%',
	);

	/**
	 * Maps "post-name" → "/%postname%/" and "plain" → "".
	 * Custom strings (anything containing "/" or "%") pass through unchanged.
	 */
	public static function resolve( string $value ): string {
		$value = trim( $value );

		if ( '' === $value || 'plain' === strtolower( $value ) || 'default' === strtolower( $value ) ) {
			return '';
		}

		$normalized = strtolower( $value );
		if ( array_key_exists( $normalized, self::PRESETS ) ) {
			return self::PRESETS[ $normalized ];
		}

		return $value;
	}

	/**
	 * Returns the preset name a structure matches, or "custom" otherwise.
	 */
	public static function match( string $structure ): string {
		$structure = trim( $structure );
		if ( '' === $structure ) {
			return 'plain';
		}
		foreach ( self::PRESETS as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}
			if ( $structure === $value ) {
				return $name;
			}
		}
		return 'custom';
	}

	/**
	 * Returns true on a valid structure (including empty / "plain") and a
	 * WP_Error when the string contains no recognised rewrite tags. This is
	 * loose by design — WordPress accepts almost anything, but if there are
	 * no tags at all the permalinks will collide for every post.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate( string $structure ) {
		$structure = trim( $structure );
		if ( '' === $structure ) {
			return true;
		}

		foreach ( self::TAGS as $tag ) {
			if ( false !== strpos( $structure, $tag ) ) {
				return true;
			}
		}

		return new \WP_Error(
			'invalid_structure',
			/* translators: %s: list of valid rewrite tags */
			sprintf(
				__( 'Permalink structure must contain at least one rewrite tag (%s) or be empty for "plain".', 'acrossai-abilities-manager' ),
				implode( ', ', self::TAGS )
			)
		);
	}
}
