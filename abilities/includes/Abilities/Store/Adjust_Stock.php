<?php
/**
 * Feature 122 - changes a stock level through WooCommerce own stock handling.
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
 * changes a stock level through WooCommerce own stock handling.
 *
 * @since 0.0.34
 */
final class Adjust_Stock extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/adjust-stock';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Adjust Stock', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Set, increase or decrease a stock level. Routed through WooCommerce own stock handling, which finds the record that actually holds the number - the parent product, for a variation managed there - updates it without losing a concurrent change, recalculates whether the product counts as in stock, refreshes the hidden term the catalogue uses to hide sold-out products, and fires the events inventory integrations listen for. Writing the stock field directly does none of that. Returns the level before and after.', 'acrossai-abilities-manager' );
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
			'id' => array( 'type' => 'integer', 'description' => __( 'Product or variation id.', 'acrossai-abilities-manager' ) ),
			'quantity' => array( 'type' => 'number', 'description' => __( 'The amount to set, add or remove.', 'acrossai-abilities-manager' ) ),
			'operation' => array( 'type' => 'string', 'enum' => array( 'set', 'increase', 'decrease' ), 'default' => 'set', 'description' => __( 'What to do with the quantity.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'id', 'quantity' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'before' => array( 'type' => 'object', 'additionalProperties' => true ),
			'after' => array( 'type' => 'object', 'additionalProperties' => true ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Product_Repository::adjust_stock(
			(int) $input['id'],
			(float) $input['quantity'],
			(string) ( $input['operation'] ?? 'set' )
		);
	}
}
