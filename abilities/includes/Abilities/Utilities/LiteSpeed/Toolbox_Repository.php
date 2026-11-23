<?php
/**
 * Feature 104 — presets, export, object-cache probing and the environment report.
 *
 * `Preset::apply()` and `restore()` both return void, so success is established by re-reading
 * LiteSpeed's own preset log rather than by trusting the call. `apply()` takes an automatic backup
 * first, which is what makes it recoverable and why it is confirm-gated rather than annotated
 * destructive.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over LiteSpeed's toolbox.
 */
final class Toolbox_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The presets LiteSpeed ships, weakest to strongest.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function presets(): array {
		return array(
			'essentials' => __( 'Cache only, with the safest defaults. The starting point on an unfamiliar site.', 'acrossai-abilities-manager' ),
			'basic'      => __( 'Essentials plus browser cache and basic optimisation.', 'acrossai-abilities-manager' ),
			'advanced'   => __( 'Basic plus CSS/JS minification and combination. The usual recommendation.', 'acrossai-abilities-manager' ),
			'aggressive' => __( 'Advanced plus deferred JS and lazy loading. Test the front end afterwards.', 'acrossai-abilities-manager' ),
			'extreme'    => __( 'Everything on, including settings that break some themes. Expect to test and roll back.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Backups LiteSpeed took before a preset was applied, as ROWS.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function backups(): array {
		$rows = array();

		foreach ( (array) \LiteSpeed\Preset::get_backups() as $backup ) {
			$name = (string) $backup;

			if ( 1 !== preg_match( '/^backup-(\d+)/', $name, $matches ) ) {
				continue;
			}

			$rows[] = array(
				'timestamp' => (int) $matches[1],
				'name'      => $name,
				'taken'     => gmdate( 'c', (int) $matches[1] ),
			);
		}

		return $rows;
	}

	/**
	 * Apply a preset.
	 *
	 * @since  0.0.34
	 * @param  string $preset One of presets().
	 * @return true|WP_Error
	 */
	public static function apply_preset( string $preset ) {
		if ( ! isset( self::presets()[ $preset ] ) ) {
			return new WP_Error(
				'unknown_preset',
				sprintf(
					/* translators: 1: requested preset, 2: comma-separated known presets */
					__( '"%1$s" is not a preset. Known presets: %2$s.', 'acrossai-abilities-manager' ),
					$preset,
					implode( ', ', array_keys( self::presets() ) )
				)
			);
		}

		\LiteSpeed\Core::cls( 'Preset' )->apply( $preset );

		return true;
	}

	/**
	 * Restore a backup taken before a preset was applied.
	 *
	 * @since  0.0.34
	 * @param  int $timestamp Backup timestamp, as reported by backups().
	 * @return true|WP_Error
	 */
	public static function restore_backup( int $timestamp ) {
		$known = array_column( self::backups(), 'timestamp' );

		if ( ! in_array( $timestamp, $known, true ) ) {
			return new WP_Error(
				'unknown_backup',
				sprintf(
					/* translators: %d: requested timestamp */
					__( 'No preset backup with timestamp %d. Call litespeed/list-preset-backups first.', 'acrossai-abilities-manager' ),
					$timestamp
				)
			);
		}

		\LiteSpeed\Core::cls( 'Preset' )->restore( (string) $timestamp );

		return true;
	}

	/**
	 * The whole configuration as a portable payload.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public static function export(): string {
		return (string) \LiteSpeed\Core::cls( 'Import' )->export( true );
	}

	/**
	 * LiteSpeed's own environment report.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public static function environment_report(): string {
		return (string) \LiteSpeed\Core::cls( 'Report' )->generate_environment_report();
	}

	/**
	 * Whether the configured object-cache backend is reachable.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function test_object_cache(): bool {
		return (bool) \LiteSpeed\Core::cls( 'Object_Cache' )->test_connection();
	}

	/**
	 * Flush the object cache through LiteSpeed's own handler.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function flush_object_cache(): void {
		\LiteSpeed\Purge::purge_all_object();
	}

	/**
	 * Whether LiteSpeed's object-cache drop-in is installed.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function object_cache_dropin_installed(): bool {
		return defined( 'LSCWP_OBJECT_CACHE' ) && constant( 'LSCWP_OBJECT_CACHE' );
	}
}
