<?php
/**
 * Feature 122 - puts a product on sale, or takes it off.
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
 * puts a product on sale, or takes it off.
 *
 * @since 0.0.34
 */
final class Schedule_Sale extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/schedule-sale';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Schedule a Sale', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Put a product on sale, optionally between two dates, or end a sale with clear. Refuses a sale price that is not below the regular price, because WooCommerce discards one that is not lower and the call would otherwise report success having done nothing. Going through the proper save path matters here more than anywhere: the displayed price is worked out during the save, and the cached on-sale list is held for thirty days, so a sale written any other way can leave a shop off-sale for a month with nothing suggesting a problem.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'pricing';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id' => array( 'type' => 'integer', 'description' => __( 'Product id.', 'acrossai-abilities-manager' ) ),
			'sale_price' => array( 'type' => 'string', 'description' => __( 'Sale price. Must be below the regular price.', 'acrossai-abilities-manager' ) ),
			'from' => array( 'type' => 'string', 'description' => __( 'Start date, YYYY-MM-DD. Optional.', 'acrossai-abilities-manager' ) ),
			'to' => array( 'type' => 'string', 'description' => __( 'End date, YYYY-MM-DD. Optional.', 'acrossai-abilities-manager' ) ),
			'clear' => array( 'type' => 'boolean', 'default' => false, 'description' => __( 'End the sale and remove any schedule.', 'acrossai-abilities-manager' ) ),
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

		return Product_Repository::schedule_sale( (int) $input['id'], $fields );
	}
}
