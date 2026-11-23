<?php
/**
 * Feature 120 — AdSense earnings through Site Kit.
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
 * Ability #10 — site-kit/get-adsense-report.
 */
class Get_Adsense_Report extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'get-adsense-report';
	}

	protected function ability_label(): string {
		return __( 'Get AdSense Report', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Read a Google AdSense report through Site Kit for the account this site is connected to: earnings, impressions, clicks, page RPM and CTR, optionally broken down by date, page or ad unit. metrics takes AdSense metric names such as ESTIMATED_EARNINGS, IMPRESSIONS, CLICKS or PAGE_VIEWS_RPM, and dimensions names such as DATE or PAGE_URL; omit both for the account defaults. Earnings are estimates until Google finalises them at month end, so recent figures move. Only useful on a site running AdSense with the module connected.', 'acrossai-abilities-manager' );
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
		return Report_Repository::MODULE_ADSENSE;
	}

	protected function input_properties(): array {
		return array(
			'metrics'    => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'AdSense metric names, e.g. ["ESTIMATED_EARNINGS", "IMPRESSIONS"]. Omit for the account defaults.', 'acrossai-abilities-manager' ),
			),
			'dimensions' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'AdSense dimension names, e.g. ["DATE"] for a trend or ["PAGE_URL"] for earnings per page.', 'acrossai-abilities-manager' ),
			),
			'start_date' => array(
				'type'        => 'string',
				'description' => __( 'Start date, YYYY-MM-DD.', 'acrossai-abilities-manager' ),
			),
			'end_date'   => array(
				'type'        => 'string',
				'description' => __( 'End date, YYYY-MM-DD.', 'acrossai-abilities-manager' ),
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
		$result = Report_Repository::adsense_report( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = __( 'AdSense returned a report. Recent earnings are estimates until Google finalises them.', 'acrossai-abilities-manager' );

		return $result;
	}
}
