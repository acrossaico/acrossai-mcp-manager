<?php
/**
 * Slash_Input helper — per-call opt-out for wp_slash() on write abilities.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Bridge between an ability's `apply_wp_slash` input flag and the wp_slash()
 * call it wraps around WordPress core write functions.
 *
 * Merge schema_fragment() into an ability's input_schema `properties` array,
 * then call self::slash() at every wp_slash() site the ability previously
 * called directly. Default behaviour (missing key OR true) preserves the
 * #131 fix; passing `apply_wp_slash: false` on the ability call opts out.
 */
class Slash_Input {

	/**
	 * Schema fragment to merge into an ability's `input_schema['properties']`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema_fragment(): array {
		return array(
			'apply_wp_slash' => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Apply wp_slash() to caller-supplied strings before writing. Default true; keeps literal backslashes intact (e.g. PHP namespaces \\Foo\\Bar, regex \\d+, Windows paths C:\\Users). Set false only when the caller has already slashed the payload — otherwise WordPress core will silently strip backslashes.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Meta annotation advertising the flag on the ability's `meta` block. Lets
	 * discovery tooling and AI clients see that this ability supports the
	 * apply_wp_slash opt-out without fetching the full input_schema.
	 *
	 * Merge into an ability's top-level `meta` array as
	 *   'input_flags' => Slash_Input::meta_flags(),
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function meta_flags(): array {
		return array(
			'apply_wp_slash' => array(
				'default'     => true,
				'summary'     => __( 'Toggle wp_slash() on caller-supplied strings before the core write. Default true preserves backslashes; false skips escaping.', 'acrossai-abilities-manager' ),
				'schema_path' => 'input_schema.properties.apply_wp_slash',
			),
		);
	}

	/**
	 * Wrap $value with wp_slash() unless the ability input explicitly opts out.
	 *
	 * Missing key OR true → slash. Only literal boolean false skips.
	 *
	 * @param mixed              $value Value about to be handed to a WP core write function.
	 * @param array<mixed,mixed> $input Ability input payload.
	 * @return mixed Slashed value, or the original value if opted out.
	 */
	public static function slash( $value, array $input ) {
		if ( false === ( $input['apply_wp_slash'] ?? true ) ) {
			return $value;
		}
		return wp_slash( $value );
	}
}
