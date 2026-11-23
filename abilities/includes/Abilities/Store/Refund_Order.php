<?php
/**
 * Feature 123 - records a refund against an order.
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
 * records a refund against an order.
 *
 * @since 0.0.34
 */
final class Refund_Order extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/refund-order';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Refund an Order', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Record a refund against an order, in whole or in part, optionally putting the items back into stock. Refuses to refund more than is left un-refunded. Important: this records the refund in WooCommerce and does NOT send money back through the payment provider - that has to be done in the provider own dashboard. The response says so, so nobody assumes the customer has been paid.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'orders';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id' => array( 'type' => 'integer', 'description' => __( 'Order id.', 'acrossai-abilities-manager' ) ),
			'amount' => array( 'type' => 'string', 'description' => __( 'Amount to refund. Omit to refund everything not already refunded.', 'acrossai-abilities-manager' ) ),
			'reason' => array( 'type' => 'string', 'description' => __( 'Reason, recorded on the refund.', 'acrossai-abilities-manager' ) ),
			'restock_items' => array( 'type' => 'boolean', 'default' => false, 'description' => __( 'Put the refunded items back into stock.', 'acrossai-abilities-manager' ) ),
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
			'refund_id' => array( 'type' => 'integer' ),
			'amount' => array( 'type' => 'string' ),
			'restocked' => array( 'type' => 'boolean' ),
			'order' => array( 'type' => 'object', 'additionalProperties' => true ),
			'note' => array( 'type' => 'string' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'A refund is recorded permanently against the order and, if restocking is asked for, changes stock levels. It cannot be undone from here. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$fields = $input;
		unset( $fields['id'] );

		return Order_Repository::refund( (int) $input['id'], $fields );
	}
}
