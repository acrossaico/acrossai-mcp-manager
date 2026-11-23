<?php
/**
 * Feature 122 - changes the product fields WooCommerce own update does not reach.
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
 * changes the product fields WooCommerce own update does not reach.
 *
 * @since 0.0.34
 */
final class Update_Product_Details extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/update-product-details';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Product Details', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Change the parts of a product WooCommerce own product-update cannot touch: weight and dimensions, tax status and class, shipping class, the featured flag, catalogue visibility, menu order, purchase note, and cross-sells and upsells. For name, SKU, price, description, status and stock quantity use woocommerce/product-update, which already handles those correctly.', 'acrossai-abilities-manager' );
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
			'id' => array( 'type' => 'integer', 'description' => __( 'Product id.', 'acrossai-abilities-manager' ) ),
			'weight' => array( 'type' => 'string', 'description' => __( 'Weight in the store unit.', 'acrossai-abilities-manager' ) ),
			'length' => array( 'type' => 'string', 'description' => __( 'Length.', 'acrossai-abilities-manager' ) ),
			'width' => array( 'type' => 'string', 'description' => __( 'Width.', 'acrossai-abilities-manager' ) ),
			'height' => array( 'type' => 'string', 'description' => __( 'Height.', 'acrossai-abilities-manager' ) ),
			'tax_status' => array( 'type' => 'string', 'description' => __( 'taxable, shipping or none.', 'acrossai-abilities-manager' ) ),
			'tax_class' => array( 'type' => 'string', 'description' => __( 'Tax class slug. Empty string for standard.', 'acrossai-abilities-manager' ) ),
			'shipping_class_id' => array( 'type' => 'integer', 'description' => __( 'Shipping class term id.', 'acrossai-abilities-manager' ) ),
			'featured' => array( 'type' => 'boolean', 'description' => __( 'Whether the product is featured.', 'acrossai-abilities-manager' ) ),
			'catalog_visibility' => array( 'type' => 'string', 'description' => __( 'visible, catalog, search or hidden.', 'acrossai-abilities-manager' ) ),
			'menu_order' => array( 'type' => 'integer', 'description' => __( 'Ordering within the catalogue.', 'acrossai-abilities-manager' ) ),
			'purchase_note' => array( 'type' => 'string', 'description' => __( 'Note shown to the customer after purchase.', 'acrossai-abilities-manager' ) ),
			'cross_sell_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Product ids to cross-sell.', 'acrossai-abilities-manager' ) ),
			'upsell_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Product ids to upsell.', 'acrossai-abilities-manager' ) ),
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

		return Product_Repository::update_details( (int) $input['id'], $fields );
	}
}
