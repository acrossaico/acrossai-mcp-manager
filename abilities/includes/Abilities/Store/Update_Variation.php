<?php
/**
 * Feature 122 - changes one variation of a variable product.
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
 * changes one variation of a variable product.
 *
 * @since 0.0.34
 */
final class Update_Variation extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/update-variation';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Variation', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Change the price, SKU, status, weight or stock of a single variation. Re-syncs the parent product afterwards so its displayed price range follows. WooCommerce own product-update works on products, not variations, so this is the only correct route to one.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'catalog';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id' => array( 'type' => 'integer', 'description' => __( 'Variation id.', 'acrossai-abilities-manager' ) ),
			'regular_price' => array( 'type' => 'string', 'description' => __( 'Regular price.', 'acrossai-abilities-manager' ) ),
			'sale_price' => array( 'type' => 'string', 'description' => __( 'Sale price.', 'acrossai-abilities-manager' ) ),
			'sku' => array( 'type' => 'string', 'description' => __( 'Stock keeping unit.', 'acrossai-abilities-manager' ) ),
			'status' => array( 'type' => 'string', 'description' => __( 'publish or private.', 'acrossai-abilities-manager' ) ),
			'weight' => array( 'type' => 'string', 'description' => __( 'Weight.', 'acrossai-abilities-manager' ) ),
			'manage_stock' => array( 'type' => 'boolean', 'description' => __( 'Whether this variation tracks its own stock rather than the parent.', 'acrossai-abilities-manager' ) ),
			'stock_quantity' => array( 'type' => 'integer', 'description' => __( 'Stock quantity, when this variation manages its own.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'id' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'product' => array( 'type' => 'object', 'additionalProperties' => true ),
			'lookup_state' => array( 'type' => 'object', 'additionalProperties' => true ),
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
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$fields = $input;
		unset( $fields['id'] );

		return Product_Repository::update_variation( (int) $input['id'], $fields );
	}
}
