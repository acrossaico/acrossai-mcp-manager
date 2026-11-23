<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for the Cron ability suite.
 *
 * Custom schedules created via cron-create-schedule live in the option
 * "acrossai_custom_cron_schedules" so they survive the request. Main.php hooks
 * register_filter() on plugins_loaded so the cron_schedules filter always sees
 * the persisted set on subsequent loads.
 */
final class Cron_Helpers {

	public const OPTION = 'acrossai_custom_cron_schedules';

	/**
	 * Hooked into cron_schedules — merges persisted custom schedules into the
	 * runtime schedule list.
	 *
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public static function filter_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		foreach ( self::get_custom() as $name => $def ) {
			if ( ! isset( $schedules[ $name ] ) ) {
				$schedules[ $name ] = $def;
			}
		}
		return $schedules;
	}

	/**
	 * Register filter.
	 *
	 * @return void
	 */
	public static function register_filter(): void {
		add_filter( 'cron_schedules', array( self::class, 'filter_schedules' ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_custom(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Add custom.
	 *
	 * @param string $name
	 * @param int $interval
	 * @param string $display
	 * @return bool
	 */
	public static function add_custom( string $name, int $interval, string $display ): bool {
		$custom          = self::get_custom();
		$custom[ $name ] = array(
			'interval' => $interval,
			'display'  => $display,
		);
		return (bool) update_option( self::OPTION, $custom );
	}

	/**
	 * Remove custom.
	 *
	 * @param string $name
	 * @return bool
	 */
	public static function remove_custom( string $name ): bool {
		$custom = self::get_custom();
		if ( ! isset( $custom[ $name ] ) ) {
			return false;
		}
		unset( $custom[ $name ] );
		return (bool) update_option( self::OPTION, $custom );
	}

	/**
	 * Returns _get_cron_array() flattened to one row per event for easier iteration.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function flatten_events(): array {
		if ( ! function_exists( '_get_cron_array' ) ) {
			require_once ABSPATH . WPINC . '/cron.php';
		}
		$cron = _get_cron_array();
		$out  = array();
		if ( ! is_array( $cron ) ) {
			return $out;
		}
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				foreach ( $events as $event_key => $event ) {
					$out[] = array(
						'timestamp' => (int) $timestamp,
						'datetime'  => gmdate( 'c', (int) $timestamp ),
						'hook'      => (string) $hook,
						'schedule'  => isset( $event['schedule'] ) ? (string) $event['schedule'] : '',
						'interval'  => isset( $event['interval'] ) ? (int) $event['interval'] : 0,
						'args'      => isset( $event['args'] ) ? (array) $event['args'] : array(),
						'key'       => (string) $event_key,
					);
				}
			}
		}
		return $out;
	}
}
