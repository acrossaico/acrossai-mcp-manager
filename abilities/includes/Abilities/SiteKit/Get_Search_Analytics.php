<?php
/**
 * Feature 120 — Search Console search analytics through Site Kit.
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
 * Ability #7 — site-kit/get-search-analytics.
 */
class Get_Search_Analytics extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'get-search-analytics';
	}

	protected function ability_label(): string {
		return __( 'Get Search Console Analytics', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Read Google Search Console search analytics through Site Kit: clicks, impressions, average CTR and average position, broken down by whichever dimensions you ask for. Use dimensions ["query"] for the searches that found the site, ["page"] for which URLs earn them, ["date"] for a trend, and ["country"] or ["device"] for where and how. Pass url to restrict the whole report to one page. Dates default to Search Console\'s own last-28-days window; note that its data lags roughly two days, so today and yesterday will look empty. This is live Google data read with the current user\'s own connection.', 'acrossai-abilities-manager' );
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
		return Report_Repository::MODULE_SEARCH_CONSOLE;
	}

	protected function input_properties(): array {
		return array(
			'start_date' => array(
				'type'        => 'string',
				'description' => __( 'Start date, YYYY-MM-DD. Defaults with end_date to Search Console\'s last 28 days.', 'acrossai-abilities-manager' ),
			),
			'end_date'   => array(
				'type'        => 'string',
				'description' => __( 'End date, YYYY-MM-DD. Search Console data lags about two days, so a range ending today returns nothing for its last days.', 'acrossai-abilities-manager' ),
			),
			'dimensions' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'How to break the totals down: query, page, country, device, date. Omit for site-wide totals over the period.', 'acrossai-abilities-manager' ),
			),
			'url'        => array(
				'type'        => 'string',
				'description' => __( 'Restrict the report to one page URL.', 'acrossai-abilities-manager' ),
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
			'rows'    => array( 'type' => 'array' ),
			'request' => array( 'type' => 'object' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Report_Repository::search_analytics( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$count = count( $result['rows'] );

		$result['message'] = 0 === $count
			// An empty result is almost never an error here, and saying so saves a
			// round of debugging a connection that is working fine.
			? __( 'Search Console returned no rows for that period. That usually means the range is too recent — its data lags about two days — or the page filter matched nothing it has data for.', 'acrossai-abilities-manager' )
			: sprintf(
				/* translators: %d: number of rows */
				__( 'Search Console returned %d rows.', 'acrossai-abilities-manager' ),
				$count
			);

		return $result;
	}
}
