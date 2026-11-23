<?php
/**
 * Feature 123 — orders: the full read WooCommerce's own query does not give, notes, and refunds.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Order reads, notes and refunds.
 *
 * @since 0.0.34
 */
final class Order_Repository {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * @since  0.0.34
	 * @param  int $id Order id.
	 * @return \WC_Order|WP_Error
	 */
	public static function load( int $id ) {
		$order = $id > 0 ? wc_get_order( $id ) : null;

		if ( ! $order instanceof \WC_Order ) {
			return new WP_Error(
				'unknown_order',
				sprintf(
					/* translators: %d: order id. */
					__( 'No order with id %d. Call woocommerce/orders-query to find one.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		return $order;
	}

	/**
	 * An order in full.
	 *
	 * Personal data is withheld unless asked for. An order carries a real person's name, address,
	 * email, phone and IP, and most questions about an order — what was bought, what it cost, has it
	 * shipped — need none of that. The same position Feature 110 settled for attendees.
	 *
	 * The customer IP and user agent are never returned under any flag: they identify a person's
	 * device and location and answer no question a store operator actually has.
	 *
	 * @since  0.0.34
	 * @param  \WC_Order $order             Order.
	 * @param  bool      $with_personal_data Whether to include identifying fields.
	 * @return array<string, mixed>
	 */
	public static function shape( \WC_Order $order, bool $with_personal_data = false ): array {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$items[] = array(
				'id'           => $item->get_id(),
				'name'         => $item->get_name(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => $item->get_quantity(),
				'subtotal'     => (string) $item->get_subtotal(),
				'total'        => (string) $item->get_total(),
				'tax'          => (string) $item->get_total_tax(),
			);
		}

		$refunds = array();

		foreach ( $order->get_refunds() as $refund ) {
			$refunds[] = array(
				'id'     => $refund->get_id(),
				'amount' => (string) $refund->get_amount(),
				'reason' => (string) $refund->get_reason(),
				'date'   => $refund->get_date_created() ? $refund->get_date_created()->date( 'c' ) : '',
			);
		}

		$shape = array(
			'id'              => $order->get_id(),
			'number'          => $order->get_order_number(),
			'status'          => $order->get_status(),
			'currency'        => $order->get_currency(),
			'total'           => (string) $order->get_total(),
			'subtotal'        => (string) $order->get_subtotal(),
			'total_tax'       => (string) $order->get_total_tax(),
			'shipping_total'  => (string) $order->get_shipping_total(),
			'discount_total'  => (string) $order->get_discount_total(),
			'total_refunded'  => (string) $order->get_total_refunded(),
			'payment_method'  => $order->get_payment_method_title(),
			'date_created'    => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
			'date_paid'       => $order->get_date_paid() ? $order->get_date_paid()->date( 'c' ) : '',
			'customer_id'     => $order->get_customer_id(),
			'item_count'      => count( $items ),
			'items'           => $items,
			'refunds'         => $refunds,
			'coupon_codes'    => array_values( array_map( 'strval', (array) $order->get_coupon_codes() ) ),
			'personal_data_included' => $with_personal_data,
		);

		if ( ! $with_personal_data ) {
			$shape['withheld'] = array( 'billing name and address', 'shipping name and address', 'email', 'phone' );

			return $shape;
		}

		$shape['billing']  = $order->get_address( 'billing' );
		$shape['shipping'] = $order->get_address( 'shipping' );
		$shape['withheld'] = array( 'customer IP address', 'customer user agent' );

		return $shape;
	}

	/**
	 * Notes on an order.
	 *
	 * WooCommerce can ADD a note and has no way to read one back — a genuine asymmetry, and the
	 * reason this exists. Customer-facing notes were sent to a person, so they are marked.
	 *
	 * @since  0.0.34
	 * @param  int $id    Order id.
	 * @param  int $limit Rows.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function notes( int $id, int $limit ) {
		$order = self::load( $id );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$raw = wc_get_order_notes(
			array(
				'order_id' => $id,
				'limit'    => $limit,
			)
		);

		$notes = array();

		foreach ( (array) $raw as $note ) {
			$notes[] = array(
				'id'            => (int) $note->id,
				'content'       => (string) $note->content,
				'added_by'      => (string) $note->added_by,
				'date_created'  => isset( $note->date_created ) && $note->date_created ? $note->date_created->date( 'c' ) : '',
				'customer_note' => (bool) $note->customer_note,
			);
		}

		return array(
			'order_id' => $id,
			'notes'    => $notes,
			'count'    => count( $notes ),
		);
	}

	/**
	 * Refund an order, in whole or in part.
	 *
	 * Through `wc_create_refund()`, which records the refund against the order, restores stock when
	 * asked and fires the events reporting and accounting integrations listen for. It does NOT send
	 * money back through the payment gateway — that is a gateway action requiring its credentials,
	 * and is deliberately out of scope. The response says so rather than letting a caller assume the
	 * customer has been paid.
	 *
	 * @since  0.0.34
	 * @param  int                  $id     Order id.
	 * @param  array<string, mixed> $fields amount / reason / restock_items.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function refund( int $id, array $fields ) {
		$order = self::load( $id );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$remaining = (float) $order->get_total() - (float) $order->get_total_refunded();
		$amount    = isset( $fields['amount'] ) && '' !== (string) $fields['amount']
			? (float) $fields['amount']
			: $remaining;

		if ( $amount <= 0 ) {
			return new WP_Error(
				'invalid_input',
				__( 'The refund amount must be more than zero.', 'acrossai-abilities-manager' )
			);
		}

		if ( $amount > $remaining + 0.0001 ) {
			return new WP_Error(
				'refund_exceeds_total',
				sprintf(
					/* translators: 1: requested amount, 2: amount still refundable. */
					__( 'Cannot refund %1$s: only %2$s of this order has not already been refunded.', 'acrossai-abilities-manager' ),
					wc_format_decimal( $amount, 2 ),
					wc_format_decimal( $remaining, 2 )
				)
			);
		}

		$refund = wc_create_refund(
			array(
				'order_id'       => $id,
				'amount'         => wc_format_decimal( $amount, wc_get_price_decimals() ),
				'reason'         => (string) ( $fields['reason'] ?? '' ),
				'restock_items'  => ! empty( $fields['restock_items'] ),
			)
		);

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		$fresh = self::load( $id );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		return array(
			'refund_id'      => $refund->get_id(),
			'amount'         => (string) $refund->get_amount(),
			'restocked'      => ! empty( $fields['restock_items'] ),
			'order'          => self::shape( $fresh ),
			'note'           => __( 'The refund is recorded against the order in WooCommerce. It does NOT return money through the payment provider — that has to be done in the provider\'s own dashboard, or by a gateway that supports it.', 'acrossai-abilities-manager' ),
		);
	}
}
