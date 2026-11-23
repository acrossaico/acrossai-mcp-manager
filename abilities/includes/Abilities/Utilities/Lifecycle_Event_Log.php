<?php
/**
 * Feature 055 — option-backed rolling event log for plugin/theme lifecycle
 * events (activated / deactivated / updated).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny option-backed rolling event log for plugin / theme lifecycle
 * events. Persists to `acrossai_abilities_manager_lifecycle_log` as a
 * bounded array. Registers itself on activate/deactivate/update hooks
 * from Bootstrap.
 */
final class Lifecycle_Event_Log {

	/**
	 * WP option name.
	 */
	public const OPTION = 'acrossai_abilities_manager_lifecycle_log';

	/**
	 * Cap on the number of events per (scope, key) pair.
	 */
	public const MAX_EVENTS_PER_KEY = 50;

	/**
	 * Register the hook listeners.
	 */
	public static function register_hooks(): void {
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_deactivated' ), 10, 1 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_upgrader_complete' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'on_theme_switched' ), 10, 3 );
	}

	/**
	 * activated_plugin hook.
	 *
	 * @param string $plugin Plugin basename.
	 */
	public static function on_plugin_activated( $plugin ): void {
		self::record( 'plugin', (string) $plugin, 'activated' );
	}

	/**
	 * deactivated_plugin hook.
	 *
	 * @param string $plugin Plugin basename.
	 */
	public static function on_plugin_deactivated( $plugin ): void {
		self::record( 'plugin', (string) $plugin, 'deactivated' );
	}

	/**
	 * upgrader_process_complete hook — record `updated` events for plugin /
	 * theme / core update runs.
	 *
	 * @param mixed $upgrader Upgrader instance (unused).
	 * @param array<string,mixed> $hook_extra Details about what was upgraded.
	 */
	public static function on_upgrader_complete( $upgrader, $hook_extra ): void {
		unset( $upgrader );
		if ( ! is_array( $hook_extra ) ) {
			return;
		}
		$action = (string) ( $hook_extra['action'] ?? '' );
		$type   = (string) ( $hook_extra['type'] ?? '' );
		if ( 'update' !== $action ) {
			return;
		}
		if ( 'plugin' === $type ) {
			$targets = (array) ( $hook_extra['plugins'] ?? array() );
			foreach ( $targets as $slug ) {
				self::record( 'plugin', (string) $slug, 'updated' );
			}
		} elseif ( 'theme' === $type ) {
			$targets = (array) ( $hook_extra['themes'] ?? array() );
			foreach ( $targets as $slug ) {
				self::record( 'theme', (string) $slug, 'updated' );
			}
		} elseif ( 'core' === $type ) {
			self::record( 'core', 'wordpress', 'updated' );
		}
	}

	/**
	 * switch_theme hook.
	 *
	 * @param string    $new_name Human name of the new theme.
	 * @param \WP_Theme $new      The new theme.
	 * @param \WP_Theme $old      The old theme.
	 */
	public static function on_theme_switched( $new_name, $new, $old ): void {
		unset( $new_name );
		if ( $old instanceof \WP_Theme ) {
			self::record( 'theme', (string) $old->get_stylesheet(), 'deactivated' );
		}
		if ( $new instanceof \WP_Theme ) {
			self::record( 'theme', (string) $new->get_stylesheet(), 'activated' );
		}
	}

	/**
	 * Persist one event.
	 *
	 * @param string $scope 'plugin' | 'theme' | 'core'.
	 * @param string $key   Basename or stylesheet.
	 * @param string $event 'activated' | 'deactivated' | 'updated'.
	 */
	public static function record( string $scope, string $key, string $event ): void {
		if ( '' === $scope || '' === $key || '' === $event ) {
			return;
		}
		$log = self::read();
		if ( ! isset( $log[ $scope ] ) || ! is_array( $log[ $scope ] ) ) {
			$log[ $scope ] = array();
		}
		if ( ! isset( $log[ $scope ][ $key ] ) || ! is_array( $log[ $scope ][ $key ] ) ) {
			$log[ $scope ][ $key ] = array();
		}
		$log[ $scope ][ $key ][] = array(
			'event' => $event,
			'ts'    => time(),
		);
		$len = count( $log[ $scope ][ $key ] );
		if ( $len > self::MAX_EVENTS_PER_KEY ) {
			$log[ $scope ][ $key ] = array_slice( $log[ $scope ][ $key ], $len - self::MAX_EVENTS_PER_KEY );
		}
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Read the log.
	 *
	 * @return array<string,array<string,array<int,array{event:string,ts:int}>>>
	 */
	public static function read(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Return the events for a single (scope, key) pair, plus a small summary
	 * (last activated_at / deactivated_at / updated_at).
	 *
	 * @param string $scope Scope.
	 * @param string $key   Key.
	 * @return array{events: array<int,array{event:string,ts:int}>, last_activated_at: int, last_deactivated_at: int, last_updated_at: int}
	 */
	public static function get_summary( string $scope, string $key ): array {
		$log      = self::read();
		$events   = ( isset( $log[ $scope ][ $key ] ) && is_array( $log[ $scope ][ $key ] ) ) ? $log[ $scope ][ $key ] : array();
		$last_act = 0;
		$last_de  = 0;
		$last_up  = 0;
		foreach ( $events as $ev ) {
			$name = (string) ( $ev['event'] ?? '' );
			$ts   = (int) ( $ev['ts'] ?? 0 );
			if ( 'activated' === $name && $ts > $last_act ) {
				$last_act = $ts;
			} elseif ( 'deactivated' === $name && $ts > $last_de ) {
				$last_de = $ts;
			} elseif ( 'updated' === $name && $ts > $last_up ) {
				$last_up = $ts;
			}
		}
		return array(
			'events'              => $events,
			'last_activated_at'   => $last_act,
			'last_deactivated_at' => $last_de,
			'last_updated_at'     => $last_up,
		);
	}
}
