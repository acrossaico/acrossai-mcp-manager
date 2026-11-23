<?php
/**
 * Feature 123 - revenue and order counts for a period.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Store;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Customer_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Insight_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Order_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * revenue and order counts for a period.
 *
 * @since 0.0.34
 */
final class Get_Sales_Summary extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/get-sales-summary';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Sales Summary', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Revenue, order count, refunds, tax, shipping and average order value for a date range, with how many distinct customers bought. Calculated from the orders themselves rather than the analytics summary tables, which can be empty or part-way through an import and would otherwise give a confidently wrong answer; the response says which source was used. Counts processing and completed orders only - pending and failed orders are not revenue.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'insight';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'from' => array( 'type' => 'string', 'description' => __( 'Start date, YYYY-MM-DD. Omit for all time.', 'acrossai-abilities-manager' ) ),
			'to' => array( 'type' => 'string', 'description' => __( 'End date, YYYY-MM-DD. Defaults to today.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'from' => array( 'type' => 'string' ),
			'to' => array( 'type' => 'string' ),
			'orders' => array( 'type' => 'integer' ),
			'gross_revenue' => array( 'type' => 'string' ),
			'refunded' => array( 'type' => 'string' ),
			'net_revenue' => array( 'type' => 'string' ),
			'tax' => array( 'type' => 'string' ),
			'shipping' => array( 'type' => 'string' ),
			'average_order' => array( 'type' => 'string' ),
			'distinct_customers' => array( 'type' => 'integer' ),
			'currency' => array( 'type' => 'string' ),
			'counted_statuses' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'source' => array( 'type' => 'string' ),
			'note' => array( 'type' => 'string' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Insight_Repository::sales_summary(
			(string) ( $input['from'] ?? '' ),
			(string) ( $input['to'] ?? '' )
		);
	}
}
