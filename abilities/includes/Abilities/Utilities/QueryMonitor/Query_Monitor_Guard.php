<?php
/**
 * Feature 129 — shared guard and collector reader for the Query Monitor ability suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\QueryMonitor
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\QueryMonitor;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only helpers that keep every Query Monitor ability honest when the
 * plugin is missing, off, or restructures.
 *
 * Query Monitor ships no abilities of its own and its collector data shapes are
 * not a public contract, so each reader is wrapped and degrades to null rather
 * than fatally dereferencing a class that moved. Every execute() calls
 * {@see self::is_active()} first: the bootstrap gates registration, but the
 * plugin can be deactivated after the abilities were registered in the same
 * request.
 */
final class Query_Monitor_Guard {

	/**
	 * The query-monitor plugin file, as registered with WordPress.
	 */
	public const PLUGIN_FILE = 'query-monitor/query-monitor.php';

	/**
	 * Private constructor — this class is static-only.
	 */
	private function __construct() {}

	/**
	 * Whether Query Monitor is loaded this request.
	 *
	 * Detects by its collector registry class, which is present whenever QM has
	 * booted — never by the plugin-active option, which can disagree with what is
	 * actually loaded.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return class_exists( '\QM_Collectors' );
	}

	/**
	 * The "Query Monitor is not active" response shared by every ability.
	 *
	 * @return array{active:bool,message:string}
	 */
	public static function not_active(): array {
		return array(
			'active'  => false,
			'message' => __( 'Query Monitor is not active on this site. Install and activate the Query Monitor plugin to use this ability.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Read one collector's processed data object, or null when unavailable.
	 *
	 * Calls the collector's own process() so derived fields (totals, duplicates,
	 * the slowest queries) are populated; a bare get_data() returns the raw log.
	 *
	 * @param string $collector_id Query Monitor collector id, e.g. 'db_queries'.
	 * @return object|null
	 */
	public static function collector_data( string $collector_id ) {
		if ( ! self::is_active() ) {
			return null;
		}

		try {
			$collector = \QM_Collectors::get( $collector_id );
			if ( ! is_object( $collector ) || ! method_exists( $collector, 'get_data' ) ) {
				return null;
			}

			if ( method_exists( $collector, 'process' ) ) {
				$collector->process();
			}

			$data = $collector->get_data();

			return is_object( $data ) ? $data : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}
}
