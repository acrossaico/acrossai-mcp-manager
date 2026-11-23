<?php
/**
 * Feature 123 — sales figures, and where they came from.
 *
 * Every number here carries its source. WooCommerce Analytics keeps its own summary tables, and they
 * can be empty, mid-import or behind — a figure read from them while an import is pending is
 * confidently wrong in a way nothing on the screen suggests. So these read the orders themselves and
 * say so, rather than returning a bare number whose provenance the caller cannot see.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only sales insight.
 *
 * @since 0.0.34
 */
final class Insight_Repository {

	/**
	 * Statuses that count as money taken.
	 *
	 * `processing` and `completed` only. Pending and failed orders are not revenue, and counting
	 * `on-hold` would inflate every figure on a store that uses bank transfer.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const PAID_STATUSES = array( 'wc-processing', 'wc-completed' );

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Orders in a window.
	 *
	 * @since  0.0.34
	 * @param  string $from Start date, Y-m-d.
	 * @param  string $to   End date, Y-m-d.
	 * @return \WC_Order[]
	 */
	private static function orders( string $from, string $to ): array {
		$args = array(
			'limit'  => -1,
			'status' => self::PAID_STATUSES,
			'return' => 'objects',
		);

		if ( '' !== $from || '' !== $to ) {
			$args['date_created'] = ( '' !== $from ? $from : '' ) . '...' . ( '' !== $to ? $to : gmdate( 'Y-m-d' ) );
		}

		$orders = wc_get_orders( $args );
		$out    = array();

		foreach ( (array) $orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$out[] = $order;
			}
		}

		return $out;
	}

	/**
	 * Revenue and order counts for a window.
	 *
	 * @since  0.0.34
	 * @param  string $from Start date.
	 * @param  string $to   End date.
	 * @return array<string, mixed>
	 */
	public static function sales_summary( string $from, string $to ): array {
		$orders = self::orders( $from, $to );

		$gross    = 0.0;
		$refunded = 0.0;
		$tax      = 0.0;
		$shipping = 0.0;
		$customers = array();

		foreach ( $orders as $order ) {
			$gross    += (float) $order->get_total();
			$refunded += (float) $order->get_total_refunded();
			$tax      += (float) $order->get_total_tax();
			$shipping += (float) $order->get_shipping_total();

			$customers[ (int) $order->get_customer_id() ] = true;
		}

		$count = count( $orders );

		return array(
			'from'             => $from,
			'to'               => '' !== $to ? $to : gmdate( 'Y-m-d' ),
			'orders'           => $count,
			'gross_revenue'    => wc_format_decimal( $gross, 2 ),
			'refunded'         => wc_format_decimal( $refunded, 2 ),
			'net_revenue'      => wc_format_decimal( $gross - $refunded, 2 ),
			'tax'              => wc_format_decimal( $tax, 2 ),
			'shipping'         => wc_format_decimal( $shipping, 2 ),
			'average_order'    => $count > 0 ? wc_format_decimal( $gross / $count, 2 ) : '0',
			'distinct_customers' => count( $customers ),
			'currency'         => get_woocommerce_currency(),
			'counted_statuses' => self::PAID_STATUSES,
			'source'           => 'orders',
			'note'             => __( 'Calculated from the orders themselves rather than the analytics summary tables, which can be empty or mid-import and would give a confidently wrong answer. Counts processing and completed orders only.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Best sellers for a window.
	 *
	 * @since  0.0.34
	 * @param  string $from    Start date.
	 * @param  string $to      End date.
	 * @param  string $rank_by revenue or quantity.
	 * @param  int    $limit   Rows.
	 * @return array<string, mixed>
	 */
	public static function top_products( string $from, string $to, string $rank_by, int $limit ): array {
		$tally = array();

		foreach ( self::orders( $from, $to ) as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$id = (int) $item->get_product_id();

				if ( ! isset( $tally[ $id ] ) ) {
					$tally[ $id ] = array(
						'product_id' => $id,
						'name'       => $item->get_name(),
						'quantity'   => 0,
						'revenue'    => 0.0,
					);
				}

				$tally[ $id ]['quantity'] += (int) $item->get_quantity();
				$tally[ $id ]['revenue']  += (float) $item->get_total();
			}
		}

		$key = 'quantity' === $rank_by ? 'quantity' : 'revenue';

		uasort(
			$tally,
			static function ( array $a, array $b ) use ( $key ): int {
				return $b[ $key ] <=> $a[ $key ];
			}
		);

		$rows = array();

		foreach ( array_slice( $tally, 0, $limit, true ) as $row ) {
			$row['revenue'] = wc_format_decimal( $row['revenue'], 2 );
			$rows[]         = $row;
		}

		return array
		(
			'from'     => $from,
			'to'       => '' !== $to ? $to : gmdate( 'Y-m-d' ),
			'rank_by'  => $key,
			'products' => $rows,
			'count'    => count( $rows ),
			'currency' => get_woocommerce_currency(),
			'source'   => 'orders',
		);
	}
}
