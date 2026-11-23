<?php
/**
 * Feature 120 — Site Kit's Key Metrics, the row of tiles at the top of its dashboard.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only accessor for Key Metrics.
 *
 * PER USER, not per site. Key_Metrics_Settings extends User_Setting, so the selection
 * lives in user meta and every administrator has their own. Reading it reports what
 * the CALLER sees when they open Site Kit, and writing it changes only their own
 * dashboard — nobody else's view moves, and nothing about the site changes.
 *
 * Which tile slugs exist is decided in Site Kit's JavaScript, not its PHP, and it does
 * not validate what it stores: Sanitize::sanitize_string_list accepts any string, so
 * an unknown slug saves cleanly and then renders nothing. KNOWN_SLUGS below exists so
 * a caller has somewhere to look, and is checked against rather than enforced — see
 * update() for why refusing an unrecognised slug would be worse than warning about it.
 */
final class Key_Metrics_Repository {

	/**
	 * Tile slugs shipped by Site Kit 1.188.0, read from its built assets.
	 *
	 * A REFERENCE, not a contract. Site Kit adds tiles in ordinary releases and defines
	 * them only in JavaScript, so this list dates. It is used to warn, never to refuse.
	 */
	public const KNOWN_SLUGS = array(
		'kmAnalyticsAdSenseTopEarningContent',
		'kmAnalyticsEngagedTrafficSource',
		'kmAnalyticsLeastEngagingPages',
		'kmAnalyticsMostEngagingPages',
		'kmAnalyticsNewVisitors',
		'kmAnalyticsPagesPerVisit',
		'kmAnalyticsPopularAuthors',
		'kmAnalyticsPopularContent',
		'kmAnalyticsPopularProducts',
		'kmAnalyticsReturningVisitors',
		'kmAnalyticsSalesByCountries',
		'kmAnalyticsSalesByVisitorType',
		'kmAnalyticsSalesEngagementRate',
		'kmAnalyticsSalesRate',
		'kmAnalyticsTopAuthorsDrivingSales',
		'kmAnalyticsTopCategories',
		'kmAnalyticsTopCities',
		'kmAnalyticsTopCitiesDrivingAddToCart',
		'kmAnalyticsTopCitiesDrivingLeads',
		'kmAnalyticsTopCitiesDrivingPurchases',
		'kmAnalyticsTopConvertingTrafficSource',
		'kmAnalyticsTopCountries',
		'kmAnalyticsTopDeviceDrivingPurchases',
		'kmAnalyticsTopPagesDrivingLeads',
		'kmAnalyticsTopPagesDrivingSales',
		'kmAnalyticsTopRecentTrendingPages',
		'kmAnalyticsTopReturningVisitorPages',
		'kmAnalyticsTopTrafficChannelsDrivingSalesRate',
		'kmAnalyticsTopTrafficSource',
		'kmAnalyticsTopTrafficSourceDrivingAddToCart',
		'kmAnalyticsTopTrafficSourceDrivingLeads',
		'kmAnalyticsTopTrafficSourceDrivingPurchases',
		'kmAnalyticsTotalSales',
		'kmAnalyticsVisitLength',
		'kmAnalyticsVisitsPerVisitor',
		'kmSearchConsolePopularKeywords',
	);

	/**
	 * Site Kit's own dashboard caps the selection at this many tiles.
	 */
	public const MAX_SLUGS = 8;

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The current user's Key Metrics selection.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get() {
		$settings = self::settings();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		try {
			$stored = $settings->get();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_request_failed',
				sprintf(
					/* translators: %s: error message from Site Kit */
					__( 'Site Kit could not read the Key Metrics selection: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		$stored = is_array( $stored ) ? $stored : array();
		$slugs  = isset( $stored['widgetSlugs'] ) && is_array( $stored['widgetSlugs'] )
			? array_values( array_map( 'strval', $stored['widgetSlugs'] ) )
			: array();

		return array(
			'widget_slugs'       => $slugs,
			'widget_area_hidden' => ! empty( $stored['isWidgetHidden'] ),
			'unrecognised_slugs' => array_values( array_diff( $slugs, self::KNOWN_SLUGS ) ),
			'setup_completed_by' => self::setup_completed_by(),
			'current_user_id'    => get_current_user_id(),
			'max_slugs'          => self::MAX_SLUGS,
			'known_slugs'        => self::KNOWN_SLUGS,
		);
	}

	/**
	 * Replace the current user's Key Metrics selection.
	 *
	 * @param string[]|null $slugs  Tile slugs, or null to leave the selection alone.
	 * @param bool|null     $hidden Whether to hide the row, or null to leave it alone.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( ?array $slugs, ?bool $hidden ) {
		if ( null === $slugs && null === $hidden ) {
			return new WP_Error(
				'invalid_input',
				__( 'Pass widget_slugs, hide_widget_area, or both. Nothing was changed.', 'acrossai-abilities-manager' )
			);
		}

		// Input is validated BEFORE Site Kit is touched. A caller who sent nine tiles sent
		// nine tiles whether or not Site Kit is connected, and telling them so is more
		// use than a connection error that sends them looking in the wrong place.
		$partial      = array();
		$unrecognised = array();

		if ( null !== $slugs ) {
			$clean = array();
			foreach ( $slugs as $slug ) {
				$slug = trim( (string) $slug );
				if ( '' === $slug || in_array( $slug, $clean, true ) ) {
					continue;
				}
				$clean[] = $slug;
			}

			if ( count( $clean ) > self::MAX_SLUGS ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: 1: number submitted, 2: maximum allowed */
						__( 'Site Kit shows at most %2$d Key Metrics tiles and %1$d were given. Nothing was changed.', 'acrossai-abilities-manager' ),
						count( $clean ),
						self::MAX_SLUGS
					)
				);
			}

			// Warned about, not refused. The slug list lives in Site Kit's JavaScript and
			// grows with ordinary releases, so refusing an unknown one would break this
			// ability on exactly the tiles a newer Site Kit just added.
			$unrecognised           = array_values( array_diff( $clean, self::KNOWN_SLUGS ) );
			$partial['widgetSlugs'] = $clean;
		}

		if ( null !== $hidden ) {
			$partial['isWidgetHidden'] = $hidden;
		}

		$before = self::get();
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		$settings = self::settings();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		try {
			$settings->merge( $partial );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_request_failed',
				sprintf(
					/* translators: %s: error message from Site Kit */
					__( 'Site Kit refused the Key Metrics write: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		$after = self::get();
		if ( is_wp_error( $after ) ) {
			return $after;
		}

		return array(
			'widget_slugs'        => $after['widget_slugs'],
			'widget_area_hidden'  => $after['widget_area_hidden'],
			'previous_slugs'      => $before['widget_slugs'],
			'changed'             => $before['widget_slugs'] !== $after['widget_slugs']
				|| $before['widget_area_hidden'] !== $after['widget_area_hidden'],
			'unrecognised_slugs'  => $unrecognised,
			'current_user_id'     => get_current_user_id(),
		);
	}

	/**
	 * Who completed Key Metrics setup for this site, if anyone.
	 *
	 * Site-wide, unlike the selection itself.
	 *
	 * @return array{user_id:int|null,display_name:string|null}
	 */
	private static function setup_completed_by(): array {
		$empty = array( 'user_id' => null, 'display_name' => null );

		$setting = Site_Kit_Context::key_metrics_setup_completed_by();
		if ( null === $setting ) {
			return $empty;
		}

		try {
			$user_id = (int) $setting->get();
		} catch ( \Throwable $e ) {
			return $empty;
		}

		if ( $user_id < 1 ) {
			return $empty;
		}

		$user = get_userdata( $user_id );

		return array(
			'user_id'      => $user_id,
			'display_name' => $user ? (string) $user->display_name : null,
		);
	}

	/**
	 * The per-user settings store.
	 *
	 * @return object|WP_Error
	 */
	private static function settings() {
		$settings = Site_Kit_Context::key_metrics_settings();

		if ( null === $settings ) {
			return new WP_Error(
				'site_kit_missing',
				__( 'This Site Kit version does not provide Key Metrics, or Site Kit did not finish loading this request.', 'acrossai-abilities-manager' )
			);
		}

		return $settings;
	}
}
