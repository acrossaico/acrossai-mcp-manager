<?php
/**
 * Feature 122 - reads one product in full, and whether the shop agrees with it.
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
 * reads one product in full, and whether the shop agrees with it.
 *
 * @since 0.0.34
 */
final class Get_Product extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/get-product';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Product', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read one product completely: type, prices and sale window, stock, weight and dimensions, tax and shipping class, categories and tags, images, attributes, variations and merchandising flags. Also reports whether the product lookup table - which is what SKU search, price sorting and the on-sale query actually read - still agrees with the product. A mismatch there means something wrote this product outside WooCommerce, and the shop is answering those queries with older values while the product page looks correct. WooCommerce own products-query returns a much smaller flat shape; use this when you need the whole picture.', 'acrossai-abilities-manager' );
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
			'id' => array( 'type' => 'integer', 'description' => __( 'Product or variation id.', 'acrossai-abilities-manager' ) ),
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
		$product = Product_Repository::load( (int) $input['id'] );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		return array(
			'product'      => Product_Repository::shape( $product ),
			'lookup_state' => Product_Repository::lookup_state( $product ),
		);
	}
}
