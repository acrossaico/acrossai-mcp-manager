<?php
/**
 * Feature 122 - lists products at or below a stock threshold.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Store;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Product_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * lists products at or below a stock threshold.
 *
 * @since 0.0.34
 */
final class List_Low_Stock extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/list-low-stock';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Low Stock', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List published products that track stock and are at or below a quantity you choose, including those at zero. Read-only. Use it to find what needs reordering before customers do.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'inventory';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'threshold' => array( 'type' => 'integer', 'default' => 5, 'minimum' => 0, 'description' => __( 'Quantity at or below which a product counts as low.', 'acrossai-abilities-manager' ) ),
			'limit' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200, 'description' => __( 'How many products to examine.', 'acrossai-abilities-manager' ) ),
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
			'threshold' => array( 'type' => 'integer' ),
			'products' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
			'count' => array( 'type' => 'integer' ),
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
		return Product_Repository::low_stock(
			(int) ( $input['threshold'] ?? 5 ),
			(int) ( $input['limit'] ?? 50 )
		);
	}
}
