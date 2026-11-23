<?php
/**
 * Feature 069 — Rank Math status / diagnostic panel reads.
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
 * Static-only reader for Rank Math's diagnostic panels.
 *
 * The first four panels mirror Rank Math's own dispatch hash at
 * includes/modules/status/class-rest.php:141-147. Note the class for
 * import_export is \RankMath\Admin\Import_Export — NOT
 * \RankMath\Status\Import_Export_Settings, which is the separate class holding
 * get_export_data() / do_import_data().
 *
 * The 'google' panel is ours: Rank Math surfaces connection state through
 * scattered wp_ajax_ handlers with no single read, but the underlying checks are
 * all public statics.
 */
final class Status_Repository {

	/**
	 * Valid panel names. Also the input enum for ability #19 — the two must not
	 * drift, which Test_Rank_Math_Get_Status asserts.
	 */
	public const PANELS = array( 'status', 'tools', 'import_export', 'version_control', 'google' );

	/**
	 * Rank Math class per panel, mirroring its own dispatch hash.
	 *
	 * @see seo-by-rank-math/includes/modules/status/class-rest.php:141-147
	 */
	private const PANEL_CLASSES = array(
		'status'          => '\RankMath\Status\System_Status',
		'tools'           => '\RankMath\Tools\Database_Tools',
		'import_export'   => '\RankMath\Admin\Import_Export',
		'version_control' => '\RankMath\Version_Control',
	);

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Read one panel.
	 *
	 * @param string $panel         One of self::PANELS.
	 * @param bool   $include_sites Only for 'google' — also list Search Console properties (live API call).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function panel( string $panel, bool $include_sites = false ) {
		if ( 'google' === $panel ) {
			return self::google( $include_sites );
		}

		if ( ! isset( self::PANEL_CLASSES[ $panel ] ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: panel name */
					__( 'Unknown Rank Math status panel "%s".', 'acrossai-abilities-manager' ),
					$panel
				)
			);
		}

		$class = self::PANEL_CLASSES[ $panel ];
		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_json_data' ) ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: panel name */
					__( 'This Rank Math build does not provide the "%s" panel.', 'acrossai-abilities-manager' ),
					$panel
				)
			);
		}

		$data = $class::get_json_data();

		/**
		 * Rank Math applies its own filter to each panel's data. Re-applying it
		 * keeps ability output consistent with what the Rank Math admin screen
		 * shows, so a client and a human see the same thing.
		 */
		$data = apply_filters( "rank_math/status/{$panel}/json_data", $data );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Google Search Console / GA4 connection state.
	 *
	 * Every check is a public static, so this needs no wp_ajax_ plumbing. Listing
	 * properties is opt-in because Console::get_sites() performs a live request
	 * to googleapis.com.
	 *
	 * @param bool $include_sites Whether to list Search Console properties.
	 * @return array<string,mixed>
	 */
	private static function google( bool $include_sites ): array {
		$has_auth    = class_exists( '\RankMath\Google\Authentication' );
		$has_console = class_exists( '\RankMath\Google\Console' );

		$authorized = $has_auth && \RankMath\Google\Authentication::is_authorized();

		$data = array(
			'analytics_module_active' => class_exists( '\RankMath\Helper' ) && \RankMath\Helper::is_module_active( 'analytics' ),
			'authorized'              => $authorized,
			// Guarded on $authorized deliberately. Rank Math's is_token_expired()
			// reads $tokens['expire'] with no isset() check
			// (analytics/google/class-authentication.php:99), so calling it on a
			// site that never connected emits a PHP warning. A readonly ability
			// must not produce warnings, and an unauthorised site has no token to
			// expire, so false is also the correct answer.
			'token_expired'           => $authorized && \RankMath\Google\Authentication::is_token_expired(),
			'console_connected'       => $has_console && \RankMath\Google\Console::is_console_connected(),
			'url_inspection_enabled'  => class_exists( '\RankMath\Analytics\URL_Inspection' ) && \RankMath\Analytics\URL_Inspection::is_enabled(),
			'sites_included'          => $include_sites,
		);

		if ( $include_sites && $data['console_connected'] ) {
			$sites         = \RankMath\Google\Console::get_sites();
			$data['sites'] = is_array( $sites ) ? $sites : array();
		}

		return $data;
	}
}
