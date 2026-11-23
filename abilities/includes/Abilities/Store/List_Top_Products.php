<?php
/**
 * Feature 123 - best sellers for a period.
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
 * best sellers for a period.
 *
 * @since 0.0.34
 */
final class List_Top_Products extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/list-top-products';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Top Products', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Best selling products for a date range, ranked by revenue or by units sold. Calculated from the order lines themselves, so it reflects what was actually bought rather than a summary table that may be behind. Counts processing and completed orders only.', 'acrossai-abilities-manager' );
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
			'from' => array( 'type' => 'string', 'description' => __( 'Start date, YYYY-MM-DD.', 'acrossai-abilities-manager' ) ),
			'to' => array( 'type' => 'string', 'description' => __( 'End date, YYYY-MM-DD.', 'acrossai-abilities-manager' ) ),
			'rank_by' => array( 'type' => 'string', 'enum' => array( 'revenue', 'quantity' ), 'default' => 'revenue', 'description' => __( 'Rank by money taken or units sold.', 'acrossai-abilities-manager' ) ),
			'limit' => array( 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100, 'description' => __( 'How many products to return.', 'acrossai-abilities-manager' ) ),
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
			'rank_by' => array( 'type' => 'string' ),
			'products' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
			'count' => array( 'type' => 'integer' ),
			'currency' => array( 'type' => 'string' ),
			'source' => array( 'type' => 'string' ),
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
		return Insight_Repository::top_products(
			(string) ( $input['from'] ?? '' ),
			(string) ( $input['to'] ?? '' ),
			(string) ( $input['rank_by'] ?? 'revenue' ),
			(int) ( $input['limit'] ?? 10 )
		);
	}
}
