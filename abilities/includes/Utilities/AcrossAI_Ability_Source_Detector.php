<?php
/**
 * Detects the source of a registered ability.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Maps a registered ability array to one of four source enum values.
 *
 * Source values: 'core' | 'theme' | 'db' | 'plugin'
 *
 * MUST be called from REST controller handlers before save_override() (RF-04).
 * Do NOT call from inside the Query/Row persistence layer.
 *
 * @since 0.1.0
 */
class AcrossAI_Ability_Source_Detector {

	/**
	 * Detect the source of an ability from its registry data.
	 *
	 * @since  0.1.0
	 * @param  array $ability Raw ability array from wp_get_ability().
	 * @return string One of 'core', 'theme', 'db', or 'plugin'.
	 */
	public static function detect( array $ability ): string {
		$provider = isset( $ability['provider'] ) ? (string) $ability['provider'] : '';

		// Core abilities.
		if ( in_array( $provider, array( 'wordpress-core', 'core' ), true ) ) {
			return 'core';
		}

		// Theme abilities — registered by the currently active theme.
		if ( '' !== $provider && function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			if ( $theme instanceof \WP_Theme && $provider === $theme->get_stylesheet() ) {
				return 'theme';
			}
		}

		// Custom/DB abilities — no registered provider.
		if ( '' === $provider ) {
			return 'db';
		}

		// All other registered abilities (plugins).
		return 'plugin';
	}
}
