<?php
/**
 * Feature 120 — Analytics 4 reports through Site Kit.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteKit;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit\Report_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #8 — site-kit/get-analytics-report.
 */
class Get_Analytics_Report extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'get-analytics-report';
	}

	protected function ability_label(): string {
		return __( 'Get Analytics 4 Report', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Run a Google Analytics 4 report through Site Kit against the property this site is connected to. metrics is required and takes GA4 metric names such as sessions, totalUsers, screenPageViews, engagementRate, averageSessionDuration or conversions. dimensions takes GA4 dimension names such as pagePath, pageTitle, sessionDefaultChannelGroup, country, deviceCategory or date. Pass url to restrict the report to one page. Names must be GA4\'s own — the older Universal Analytics names such as ga:sessions are rejected by Google, not by this ability. This is live Google data read with the current user\'s own connection.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-data';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	protected function requires_authentication(): bool {
		return true;
	}

	protected function required_module(): string {
		return Report_Repository::MODULE_ANALYTICS;
	}

	protected function input_properties(): array {
		return array(
			'metrics'    => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'GA4 metric names, e.g. ["sessions", "totalUsers", "screenPageViews"]. At least one is required.', 'acrossai-abilities-manager' ),
			),
			'dimensions' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'GA4 dimension names, e.g. ["pagePath"] for top pages, ["sessionDefaultChannelGroup"] for traffic sources, ["date"] for a trend. Omit for totals.', 'acrossai-abilities-manager' ),
			),
			'start_date' => array(
				'type'        => 'string',
				'description' => __( 'Start date, YYYY-MM-DD. Defaults with end_date to Site Kit\'s standard recent window.', 'acrossai-abilities-manager' ),
			),
			'end_date'   => array(
				'type'        => 'string',
				'description' => __( 'End date, YYYY-MM-DD.', 'acrossai-abilities-manager' ),
			),
			'url'        => array(
				'type'        => 'string',
				'description' => __( 'Restrict the report to one page URL.', 'acrossai-abilities-manager' ),
			),
			'order_by'   => array(
				'type'        => 'array',
				'description' => __( 'Ordering, in Google\'s own orderBys shape, e.g. [{"metric":{"metricName":"sessions"},"desc":true}].', 'acrossai-abilities-manager' ),
			),
			'limit'      => array(
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 1000,
				'description' => __( 'Maximum rows to return.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'report'  => array( 'type' => array( 'object', 'array' ) ),
			'request' => array( 'type' => 'object' ),
		);
	}

	protected function required_input(): array {
		return array( 'metrics' );
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Report_Repository::analytics_report( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rows = is_array( $result['report'] ) && isset( $result['report']['rows'] ) && is_array( $result['report']['rows'] )
			? count( $result['report']['rows'] )
			: null;

		$result['message'] = null === $rows
			? __( 'Analytics returned a report.', 'acrossai-abilities-manager' )
			: sprintf(
				/* translators: %d: number of rows */
				__( 'Analytics returned %d rows.', 'acrossai-abilities-manager' ),
				$rows
			);

		return $result;
	}
}
