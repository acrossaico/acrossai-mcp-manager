<?php
/**
 * Feature 106 — Yoast status, indexation and import tooling.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over Yoast's tools.
 */
final class Tools_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Version, edition and the state of the things most often behind "why is Yoast not doing X".
	 *
	 * `indexables_built` is reported rather than gated on: Yoast stops building them outside
	 * production, and knowing that is the answer to most of those questions.
	 *
	 * @since  0.0.34
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		return array(
			'version'            => defined( 'WPSEO_VERSION' ) ? (string) \WPSEO_VERSION : '',
			'is_premium'         => defined( 'WPSEO_PREMIUM_FILE' ),
			'xml_sitemaps'       => (bool) \WPSEO_Options::get( 'enable_xml_sitemap', false ),
			'indexables_built'   => Yoast_Guard::has_indexables(),
			'indexation_done'    => (bool) \WPSEO_Options::get( 'indexables_indexing_completed', false ),
			'environment'        => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
			'content_analysis'   => (bool) \WPSEO_Options::get( 'keyword_analysis_active', true ),
			'readability'        => (bool) \WPSEO_Options::get( 'content_analysis_active', true ),
		);
	}

	/**
	 * Plugins Yoast considers conflicting, as ROWS.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string,mixed>>
	 */
	public static function conflicting_plugins(): array {
		if ( ! class_exists( '\Yoast\WP\SEO\Config\Conflicting_Plugins' ) ) {
			return array();
		}

		$active = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( self::known_conflicts() as $group => $plugins ) {
			foreach ( $plugins as $basename ) {
				if ( is_plugin_active( $basename ) ) {
					$active[] = array(
						'plugin' => $basename,
						'group'  => $group,
					);
				}
			}
		}

		return $active;
	}

	/**
	 * Yoast's own conflict list, flattened.
	 *
	 * @since  0.0.34
	 * @return array<string, array<int,string>>
	 */
	private static function known_conflicts(): array {
		$class = '\Yoast\WP\SEO\Config\Conflicting_Plugins';
		$out   = array();

		foreach ( array( 'OPEN_GRAPH_PLUGINS', 'XML_SITEMAPS_PLUGINS', 'CLOAKING_PLUGINS', 'SEO_PLUGINS' ) as $constant ) {
			if ( defined( $class . '::' . $constant ) ) {
				$out[ strtolower( $constant ) ] = (array) constant( $class . '::' . $constant );
			}
		}

		return $out;
	}

	/**
	 * Indexation progress.
	 *
	 * @since  0.0.34
	 * @return array<string,mixed>
	 */
	public static function indexing_status(): array {
		return array(
			'indexables_built' => Yoast_Guard::has_indexables(),
			'completed'        => (bool) \WPSEO_Options::get( 'indexables_indexing_completed', false ),
			'started'          => (int) \WPSEO_Options::get( 'indexing_started', 0 ),
			'reason'           => (string) \WPSEO_Options::get( 'indexing_reason', '' ),
			'first_time'       => (bool) \WPSEO_Options::get( 'indexing_first_time', true ),
		);
	}

	/**
	 * Mark the indexation as needing a rerun.
	 *
	 * Yoast rebuilds on its own schedule once the flags are cleared; there is no synchronous
	 * "reindex now" that is safe to call from a request, because a full rebuild on a large site far
	 * outlives one.
	 *
	 * @since  0.0.34
	 * @param  string $reason Why the reset was requested.
	 * @return void
	 */
	public static function reset_indexing( string $reason ): void {
		\WPSEO_Options::set( 'indexables_indexing_completed', false );
		\WPSEO_Options::set( 'indexing_reason', $reason );
		\WPSEO_Options::set( 'indexing_started', 0 );
	}
}
