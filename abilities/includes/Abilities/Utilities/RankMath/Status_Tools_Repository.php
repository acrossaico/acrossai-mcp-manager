<?php
/**
 * Feature 069 — Rank Math settings import/export, backups, importer detection and
 * cached SEO analysis.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only accessor for the Status & Tools surface.
 *
 * Rank Math's Import_Export_Settings::export() and ::import() wrappers are
 * unusable from an ability — they read $_POST and $_FILES respectively — but the
 * underlying get_export_data() and do_import_data() are public statics, so those are
 * called directly (research F6).
 */
final class Status_Tools_Repository {

	/**
	 * Settings panels that can be exported or imported.
	 */
	public const PANELS = array( 'general', 'titles', 'sitemap', 'role-manager', 'redirections' );

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Export Rank Math settings.
	 *
	 * @param string[] $panels Panels to include; empty means all.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function export_settings( array $panels ) {
		if ( ! class_exists( '\RankMath\Status\Import_Export_Settings' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math status module is not available.', 'acrossai-abilities-manager' ) );
		}

		$panels = array_values( array_filter( array_map( 'sanitize_key', $panels ) ) );
		foreach ( $panels as $panel ) {
			if ( ! in_array( $panel, self::PANELS, true ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: 1: submitted panel, 2: comma-separated valid panels */
						__( 'Unknown export panel "%1$s". Valid panels: %2$s.', 'acrossai-abilities-manager' ),
						$panel,
						implode( ', ', self::PANELS )
					)
				);
			}
		}

		$data = \RankMath\Status\Import_Export_Settings::get_export_data( $panels );
		$data = is_array( $data ) ? $data : array();

		return array(
			'panels' => array() === $panels ? array_keys( $data ) : $panels,
			'data'   => $data,
			'keys'   => array_keys( $data ),
		);
	}

	/**
	 * Import Rank Math settings.
	 *
	 * do_import_data() snapshots a backup first, then writes each panel with
	 * update_option() and NO merge — so this replaces whole option blobs. The
	 * created backup key is returned so the caller has an undo path.
	 *
	 * @param array<string,mixed> $data Payload from export_settings().
	 * @return array<string,mixed>|WP_Error
	 */
	public static function import_settings( array $data ) {
		if ( ! class_exists( '\RankMath\Status\Import_Export_Settings' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math status module is not available.', 'acrossai-abilities-manager' ) );
		}
		if ( array() === $data ) {
			return new WP_Error( 'invalid_input', __( 'The data object is empty. Nothing to import.', 'acrossai-abilities-manager' ) );
		}

		$known = array_values( array_intersect( array_keys( $data ), self::PANELS ) );
		if ( array() === $known ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: comma-separated valid panel names */
					__( 'The data object contains no recognised settings panels. Expected one or more of: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', self::PANELS )
				)
			);
		}

		$backups_before = self::list_backups();
		$imported       = (bool) \RankMath\Status\Import_Export_Settings::do_import_data( $data );
		$backups_after  = self::list_backups();

		// The backup do_import_data() just created is whatever key is new.
		$new_keys   = array_values( array_diff( array_column( $backups_after, 'key' ), array_column( $backups_before, 'key' ) ) );
		$backup_key = $new_keys[0] ?? '';

		if ( ! $imported ) {
			return new WP_Error( 'invalid_input', __( 'Rank Math rejected the import payload.', 'acrossai-abilities-manager' ) );
		}

		return array(
			'panels_imported' => $known,
			'backup_key'      => (string) $backup_key,
		);
	}

	/**
	 * Existing settings backups.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_backups(): array {
		if ( ! class_exists( '\RankMath\Status\Backup' ) ) {
			return array();
		}
		$backups = \RankMath\Status\Backup::get_backups();
		if ( ! is_array( $backups ) ) {
			return array();
		}

		$out = array();
		foreach ( $backups as $key => $value ) {
			$out[] = array(
				'key'   => (string) $key,
				'date'  => is_string( $value ) ? $value : '',
				'label' => is_string( $value ) ? $value : (string) $key,
			);
		}
		return $out;
	}

	/**
	 * Create a settings backup.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function create_backup() {
		if ( ! class_exists( '\RankMath\Status\Backup' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'Rank Math settings backups are not available.', 'acrossai-abilities-manager' ) );
		}

		$before = array_column( self::list_backups(), 'key' );
		\RankMath\Status\Backup::create_backup();
		$after = self::list_backups();

		$new = array_values( array_diff( array_column( $after, 'key' ), $before ) );

		return array(
			'key'   => (string) ( $new[0] ?? '' ),
			'total' => count( $after ),
		);
	}

	/**
	 * Restore or delete a backup.
	 *
	 * @param string $action 'restore' or 'delete'.
	 * @param string $key    Backup key.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function manage_backup( string $action, string $key ) {
		if ( ! class_exists( '\RankMath\Status\Backup' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'Rank Math settings backups are not available.', 'acrossai-abilities-manager' ) );
		}
		if ( ! in_array( $action, array( 'restore', 'delete' ), true ) ) {
			return new WP_Error( 'invalid_input', __( 'action must be restore or delete.', 'acrossai-abilities-manager' ) );
		}
		if ( '' === $key ) {
			return new WP_Error( 'invalid_input', __( 'key is required.', 'acrossai-abilities-manager' ) );
		}

		$existing = array_column( self::list_backups(), 'key' );
		if ( ! in_array( $key, $existing, true ) ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: backup key */
					__( 'No Rank Math settings backup with key "%s". List them with rank-math/list-backups.', 'acrossai-abilities-manager' ),
					$key
				)
			);
		}

		if ( 'restore' === $action ) {
			\RankMath\Status\Backup::restore_backup( $key );
		} else {
			\RankMath\Status\Backup::delete_backup( $key );
		}

		return array(
			'action'    => $action,
			'key'       => $key,
			'remaining' => count( self::list_backups() ),
		);
	}

	/**
	 * Detect importable data from other SEO plugins.
	 *
	 * Only detection is exposed. Running an import is chunked and long-running, and
	 * belongs to a dedicated migration flow rather than a single ability call.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function detect_seo_plugins() {
		if ( ! class_exists( '\RankMath\Admin\Importers\Detector' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math importer detector is not available.', 'acrossai-abilities-manager' ) );
		}

		$detector = new \RankMath\Admin\Importers\Detector();
		if ( ! method_exists( $detector, 'detect' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'This Rank Math build does not expose importer detection.', 'acrossai-abilities-manager' ) );
		}

		$detected = $detector->detect();
		$detected = is_array( $detected ) ? $detected : array();

		$out = array();
		foreach ( $detected as $slug => $plugin ) {
			$plugin = is_array( $plugin ) ? $plugin : array();
			$out[]  = array(
				'slug'    => (string) $slug,
				'name'    => isset( $plugin['name'] ) ? (string) $plugin['name'] : (string) $slug,
				'choices' => isset( $plugin['choices'] ) && is_array( $plugin['choices'] ) ? array_keys( $plugin['choices'] ) : array(),
				'file'    => isset( $plugin['file'] ) ? (string) $plugin['file'] : '',
			);
		}

		return array(
			'detected' => $out,
			'count'    => count( $out ),
		);
	}

	/**
	 * Stored SEO Analyzer results, without re-running the analysis.
	 *
	 * Rank Math core already ships rank-math/audit-site-seo for running it, which
	 * makes remote API calls. This is the cheap read of whatever the last run left
	 * behind.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function seo_analysis_results() {
		if ( ! class_exists( '\RankMath\SEO_Analysis\SEO_Analyzer' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math SEO Analyzer is not available.', 'acrossai-abilities-manager' ) );
		}

		$analyzer = new \RankMath\SEO_Analysis\SEO_Analyzer();
		if ( method_exists( $analyzer, 'get_results_from_storage' ) ) {
			$analyzer->get_results_from_storage();
		}

		$results = isset( $analyzer->results ) && is_array( $analyzer->results ) ? $analyzer->results : array();
		$date    = method_exists( $analyzer, 'get_last_checked_date' ) ? $analyzer->get_last_checked_date() : '';

		return array(
			'has_results'  => array() !== $results,
			'last_checked' => is_string( $date ) && '' !== $date ? $date : null,
			'result_count' => count( $results ),
			'results'      => $results,
		);
	}
}
