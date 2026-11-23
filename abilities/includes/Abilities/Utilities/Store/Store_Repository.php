<?php
/**
 * Feature 121 — the only place store state is read.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Store diagnostics: where the data lives, and whether the derived copies of it are current.
 *
 * @since 0.0.34
 */
final class Store_Repository {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether orders are kept outside the posts table.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function hpos_enabled(): bool {
		$util = '\Automattic\WooCommerce\Utilities\OrderUtil';

		if ( ! class_exists( $util ) || ! method_exists( $util, 'custom_orders_table_usage_is_enabled' ) ) {
			return false;
		}

		try {
			return (bool) $util::custom_orders_table_usage_is_enabled();
		} catch ( \Throwable $e ) {
			unset( $e );

			return false;
		}
	}

	/**
	 * How the product lookup table compares with the catalogue.
	 *
	 * The lookup table is what SKU search, price sorting and the on-sale query actually read. A row
	 * count short of the catalogue means something wrote products without going through WooCommerce
	 * — and those products are then invisible to exactly those queries while looking perfectly fine
	 * in the admin list.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function lookup_health(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_product_meta_lookup';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

		if ( ! $exists ) {
			return array(
				'table_present' => false,
				'products'      => 0,
				'lookup_rows'   => 0,
				'drift'         => 0,
				'healthy'       => false,
			);
		}

		$products = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( 'product', 'product_variation' ) AND post_status NOT IN ( 'trash', 'auto-draft' )"
		);
		$rows     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return array(
			'table_present' => true,
			'products'      => $products,
			'lookup_rows'   => $rows,
			'drift'         => $products - $rows,
			'healthy'       => $products === $rows,
		);
	}

	/**
	 * Age of the transients that decide the on-sale and featured lists.
	 *
	 * Reported because both hold for THIRTY DAYS. A sale written outside WooCommerce's own save path
	 * can therefore be wrong for a month with nothing on the site suggesting anything is amiss.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function catalogue_transients(): array {
		$out = array();

		foreach ( array( 'wc_products_onsale', 'wc_featured_products' ) as $key ) {
			$value = get_transient( $key );

			$out[ $key ] = array(
				'cached'  => false !== $value,
				'entries' => is_array( $value ) ? count( $value ) : 0,
			);
		}

		return $out;
	}

	/**
	 * Payment gateways, by name and state only.
	 *
	 * Never their settings. A gateway's configuration holds API keys, secrets and webhook tokens in
	 * the same array as its title, so anything that returns the settings wholesale hands those out —
	 * the Feature 106 lesson about stored credentials. Knowing a gateway is enabled is the useful
	 * part and carries no secret.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function gateways(): array {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}

		try {
			$wc = WC();

			if ( ! is_object( $wc ) || ! method_exists( $wc, 'payment_gateways' ) ) {
				return array();
			}

			$gateways = $wc->payment_gateways();

			if ( ! is_object( $gateways ) || ! method_exists( $gateways, 'payment_gateways' ) ) {
				return array();
			}

			$out = array();

			foreach ( (array) $gateways->payment_gateways() as $gateway ) {
				if ( ! is_object( $gateway ) ) {
					continue;
				}

				$out[] = array(
					'id'      => isset( $gateway->id ) ? (string) $gateway->id : '',
					'title'   => isset( $gateway->title ) ? (string) $gateway->title : '',
					'enabled' => isset( $gateway->enabled ) && 'yes' === $gateway->enabled,
				);
			}

			return $out;
		} catch ( \Throwable $e ) {
			unset( $e );

			return array();
		}
	}

	/**
	 * Everything `store/get-store-status` answers.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		global $wpdb;

		$hpos   = self::hpos_enabled();
		$lookup = self::lookup_health();
		$notes  = array();

		if ( ! $lookup['healthy'] && $lookup['table_present'] && 0 !== $lookup['drift'] ) {
			$notes[] = sprintf(
				/* translators: %d: number of products missing a lookup row. */
				__( '%d products have no row in the product lookup table. Those products are invisible to SKU search, price sorting and the on-sale query, while looking correct in the admin list. Something wrote them without going through WooCommerce.', 'acrossai-abilities-manager' ),
				(int) $lookup['drift']
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$placeholders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order_placehold'" );

		if ( $placeholders > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of placeholder rows. */
				__( '%d order placeholder rows are present. These are stubs left in the posts table by the order tables; they are not orders and must not be written.', 'acrossai-abilities-manager' ),
				$placeholders
			);
		}

		if ( $hpos ) {
			$notes[] = __( 'Orders are kept in the dedicated order tables, not the posts table. Anything that writes shop_order posts is changing a record nothing reads, and WooCommerce eventually deletes it.', 'acrossai-abilities-manager' );
		}

		return array(
			'woocommerce_version'    => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
			'hpos_enabled'           => $hpos,
			'order_sync_enabled'     => 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ),
			'order_placeholder_rows' => $placeholders,
			'product_lookup'         => $lookup,
			'catalogue_transients'   => self::catalogue_transients(),
			'payment_gateways'       => self::gateways(),
			'notes'                  => $notes,
		);
	}
}
