<?php
/**
 * Feature 124 - creates a discount coupon.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Store;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Config_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * creates a discount coupon.
 *
 * @since 0.0.34
 */
final class Create_Coupon extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/create-coupon';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Create Coupon', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Create a discount coupon: a percentage off, a fixed amount off the cart, or a fixed amount off each product, with optional expiry, usage limits, minimum and maximum spend, and restrictions to particular products or categories. Refuses a code that already exists rather than quietly creating a second one, and refuses a discount type this store does not offer.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'marketing';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'code' => array( 'type' => 'string', 'description' => __( 'The code a customer types. Must not already exist.', 'acrossai-abilities-manager' ) ),
			'discount_type' => array( 'type' => 'string', 'description' => __( 'percent, fixed_cart or fixed_product. Defaults to fixed_cart.', 'acrossai-abilities-manager' ) ),
			'amount' => array( 'type' => 'string', 'description' => __( 'The discount amount. For percent, 10 means ten per cent.', 'acrossai-abilities-manager' ) ),
			'description' => array( 'type' => 'string', 'description' => __( 'Internal description.', 'acrossai-abilities-manager' ) ),
			'date_expires' => array( 'type' => 'string', 'description' => __( 'Expiry date, YYYY-MM-DD.', 'acrossai-abilities-manager' ) ),
			'usage_limit' => array( 'type' => 'integer', 'description' => __( 'Total times the coupon may be used.', 'acrossai-abilities-manager' ) ),
			'usage_limit_per_user' => array( 'type' => 'integer', 'description' => __( 'Times one customer may use it.', 'acrossai-abilities-manager' ) ),
			'minimum_amount' => array( 'type' => 'string', 'description' => __( 'Minimum cart total for the coupon to apply.', 'acrossai-abilities-manager' ) ),
			'maximum_amount' => array( 'type' => 'string', 'description' => __( 'Maximum cart total for the coupon to apply.', 'acrossai-abilities-manager' ) ),
			'individual_use' => array( 'type' => 'boolean', 'description' => __( 'Whether it cannot be combined with other coupons.', 'acrossai-abilities-manager' ) ),
			'free_shipping' => array( 'type' => 'boolean', 'description' => __( 'Whether it grants free shipping.', 'acrossai-abilities-manager' ) ),
			'product_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Restrict to these products.', 'acrossai-abilities-manager' ) ),
			'excluded_product_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Exclude these products.', 'acrossai-abilities-manager' ) ),
			'product_categories' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Restrict to these categories.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'code' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'coupon' => array( 'type' => 'object', 'additionalProperties' => true ),
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
		return Config_Repository::save_coupon( $input, true );
	}
}
