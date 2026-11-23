<?php
/**
 * Feature 124 — coupons, tax, shipping and the store settings that are safe to touch.
 *
 * The line this file holds: tax rates and shipping costs are only correct relative to a real business
 * situation — where it trades, what it sells, which thresholds apply. So everything here REPORTS the
 * configuration and changes it on request, and nothing advises what it ought to be. Getting that
 * wrong does not produce an error, it produces an under-charged tax bill nobody notices for a year.
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
 * Coupons, tax, shipping and settings.
 *
 * @since 0.0.34
 */
final class Config_Repository {

	/**
	 * Settings this suite will write, and nothing else.
	 *
	 * An allow-list, not a passthrough. WooCommerce keeps hundreds of options under this prefix,
	 * including gateway configuration; a generic writer would reach all of them.
	 *
	 * @since 0.0.34
	 * @var   array<string, string>
	 */
	public const WRITABLE_SETTINGS = array(
		'woocommerce_store_address'          => 'text',
		'woocommerce_store_address_2'        => 'text',
		'woocommerce_store_city'             => 'text',
		'woocommerce_store_postcode'         => 'text',
		'woocommerce_default_country'        => 'text',
		'woocommerce_currency'               => 'text',
		'woocommerce_price_thousand_sep'     => 'text',
		'woocommerce_price_decimal_sep'      => 'text',
		'woocommerce_price_num_decimals'     => 'int',
		'woocommerce_weight_unit'            => 'text',
		'woocommerce_dimension_unit'         => 'text',
		'woocommerce_enable_reviews'         => 'yesno',
		'woocommerce_manage_stock'           => 'yesno',
		'woocommerce_notify_low_stock_amount' => 'int',
		'woocommerce_notify_no_stock_amount' => 'int',
		'woocommerce_hide_out_of_stock_items' => 'yesno',
		'woocommerce_enable_guest_checkout'  => 'yesno',
		'woocommerce_calc_taxes'             => 'yesno',
		'woocommerce_prices_include_tax'     => 'yesno',
	);

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/* -------------------------------------------------------------- coupons */

	/**
	 * @since  0.0.34
	 * @param  \WC_Coupon $coupon Coupon.
	 * @return array<string, mixed>
	 */
	private static function shape_coupon( \WC_Coupon $coupon ): array {
		return array(
			'id'                   => $coupon->get_id(),
			'code'                 => $coupon->get_code(),
			'discount_type'        => $coupon->get_discount_type(),
			'amount'               => (string) $coupon->get_amount(),
			'description'          => $coupon->get_description(),
			'date_expires'         => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : '',
			'usage_count'          => $coupon->get_usage_count(),
			'usage_limit'          => $coupon->get_usage_limit(),
			'usage_limit_per_user' => $coupon->get_usage_limit_per_user(),
			'minimum_amount'       => (string) $coupon->get_minimum_amount(),
			'maximum_amount'       => (string) $coupon->get_maximum_amount(),
			'individual_use'       => $coupon->get_individual_use(),
			'free_shipping'        => $coupon->get_free_shipping(),
			'product_ids'          => array_values( array_map( 'intval', (array) $coupon->get_product_ids() ) ),
			'excluded_product_ids' => array_values( array_map( 'intval', (array) $coupon->get_excluded_product_ids() ) ),
			'product_categories'   => array_values( array_map( 'intval', (array) $coupon->get_product_categories() ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @param  int $limit Rows.
	 * @return array<string, mixed>
	 */
	public static function list_coupons( int $limit ): array {
		$posts = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => $limit,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$coupons = array();

		foreach ( (array) $posts as $id ) {
			$coupon = new \WC_Coupon( (int) $id );

			if ( $coupon->get_id() ) {
				$coupons[] = self::shape_coupon( $coupon );
			}
		}

		return array(
			'coupons' => $coupons,
			'count'   => count( $coupons ),
		);
	}

	/**
	 * @since  0.0.34
	 * @param  string $code Coupon code.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_coupon( string $code ) {
		$coupon = new \WC_Coupon( $code );

		if ( ! $coupon->get_id() ) {
			return new WP_Error(
				'unknown_coupon',
				sprintf(
					/* translators: %s: coupon code. */
					__( 'No coupon with the code "%s".', 'acrossai-abilities-manager' ),
					$code
				)
			);
		}

		return array( 'coupon' => self::shape_coupon( $coupon ) );
	}

	/**
	 * Create or change a coupon.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $fields Supplied fields.
	 * @param  bool                 $create Whether this is a creation.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_coupon( array $fields, bool $create ) {
		$code = trim( (string) ( $fields['code'] ?? '' ) );

		if ( '' === $code ) {
			return new WP_Error( 'invalid_input', __( 'A coupon code is required.', 'acrossai-abilities-manager' ) );
		}

		$existing = wc_get_coupon_id_by_code( $code );

		if ( $create && $existing ) {
			return new WP_Error(
				'coupon_exists',
				sprintf(
					/* translators: %s: coupon code. */
					__( 'A coupon with the code "%s" already exists. Use store/update-coupon instead.', 'acrossai-abilities-manager' ),
					$code
				)
			);
		}

		if ( ! $create && ! $existing ) {
			return new WP_Error(
				'unknown_coupon',
				sprintf(
					/* translators: %s: coupon code. */
					__( 'No coupon with the code "%s" to update.', 'acrossai-abilities-manager' ),
					$code
				)
			);
		}

		$coupon = new \WC_Coupon( $existing ? (int) $existing : 0 );
		$coupon->set_code( $code );

		$type = (string) ( $fields['discount_type'] ?? ( $create ? 'fixed_cart' : $coupon->get_discount_type() ) );

		if ( ! array_key_exists( $type, wc_get_coupon_types() ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: supplied type, 2: accepted list. */
					__( '"%1$s" is not a discount type this store offers. Accepted types are: %2$s.', 'acrossai-abilities-manager' ),
					$type,
					implode( ', ', array_keys( wc_get_coupon_types() ) )
				)
			);
		}

		$coupon->set_discount_type( $type );

		$setters = array(
			'amount'               => 'set_amount',
			'description'          => 'set_description',
			'date_expires'         => 'set_date_expires',
			'usage_limit'          => 'set_usage_limit',
			'usage_limit_per_user' => 'set_usage_limit_per_user',
			'minimum_amount'       => 'set_minimum_amount',
			'maximum_amount'       => 'set_maximum_amount',
		);

		foreach ( $setters as $key => $setter ) {
			if ( array_key_exists( $key, $fields ) ) {
				$coupon->{$setter}( $fields[ $key ] );
			}
		}

		foreach ( array( 'individual_use' => 'set_individual_use', 'free_shipping' => 'set_free_shipping' ) as $key => $setter ) {
			if ( array_key_exists( $key, $fields ) ) {
				$coupon->{$setter}( (bool) $fields[ $key ] );
			}
		}

		foreach ( array( 'product_ids' => 'set_product_ids', 'excluded_product_ids' => 'set_excluded_product_ids', 'product_categories' => 'set_product_categories' ) as $key => $setter ) {
			if ( isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) ) {
				$coupon->{$setter}( array_values( array_map( 'intval', $fields[ $key ] ) ) );
			}
		}

		try {
			$id = $coupon->save();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'save_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'The coupon could not be saved: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		$fresh = new \WC_Coupon( (int) $id );

		return array( 'coupon' => self::shape_coupon( $fresh ) );
	}

	/* ------------------------------------------------------------------ tax */

	/**
	 * Tax classes and their rates.
	 *
	 * Reported, never advised. What a rate ought to be is a question about a business, not a
	 * configuration, and a wrong answer here does not error — it under-charges tax for a year.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function tax_rates(): array {
		global $wpdb;

		$classes = array_merge( array( '' ), \WC_Tax::get_tax_class_slugs() );
		$out     = array();

		foreach ( $classes as $class ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rates = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate, tax_rate_name, tax_rate_shipping, tax_rate_priority FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s ORDER BY tax_rate_order",
					$class
				),
				ARRAY_A
			);

			$out[] = array(
				'class' => '' === $class ? 'standard' : $class,
				'rates' => array_map(
					static function ( array $rate ): array {
						return array(
							'id'       => (int) $rate['tax_rate_id'],
							'country'  => (string) $rate['tax_rate_country'],
							'state'    => (string) $rate['tax_rate_state'],
							'rate'     => (string) $rate['tax_rate'],
							'name'     => (string) $rate['tax_rate_name'],
							'shipping' => '1' === (string) $rate['tax_rate_shipping'],
							'priority' => (int) $rate['tax_rate_priority'],
						);
					},
					(array) $rates
				),
			);
		}

		return array
		(
			'taxes_enabled' => 'yes' === get_option( 'woocommerce_calc_taxes' ),
			'prices_include_tax' => 'yes' === get_option( 'woocommerce_prices_include_tax' ),
			'classes'       => $out,
			'note'          => __( 'Reported as configured. What a rate ought to be is a question about the business, not the software, and this suite does not answer it.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Add a tax rate.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $fields Rate definition.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function add_tax_rate( array $fields ) {
		$country = strtoupper( trim( (string) ( $fields['country'] ?? '' ) ) );
		$rate    = (string) ( $fields['rate'] ?? '' );

		if ( '' === $country ) {
			return new WP_Error( 'invalid_input', __( 'A country code is required, for example GB or US.', 'acrossai-abilities-manager' ) );
		}

		if ( '' === $rate || ! is_numeric( $rate ) ) {
			return new WP_Error( 'invalid_input', __( 'A numeric rate is required, for example 20 for twenty per cent.', 'acrossai-abilities-manager' ) );
		}

		$id = \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => $country,
				'tax_rate_state'    => strtoupper( (string) ( $fields['state'] ?? '' ) ),
				'tax_rate'          => $rate,
				'tax_rate_name'     => (string) ( $fields['name'] ?? __( 'Tax', 'acrossai-abilities-manager' ) ),
				'tax_rate_priority' => (int) ( $fields['priority'] ?? 1 ),
				'tax_rate_shipping' => empty( $fields['shipping'] ) ? 0 : 1,
				'tax_rate_class'    => 'standard' === (string) ( $fields['class'] ?? 'standard' ) ? '' : (string) $fields['class'],
			)
		);

		if ( ! $id ) {
			return new WP_Error( 'save_failed', __( 'WooCommerce did not return an id for the new tax rate.', 'acrossai-abilities-manager' ) );
		}

		return array(
			'tax_rate_id' => (int) $id,
			'taxes'       => self::tax_rates(),
			'note'        => 'yes' === get_option( 'woocommerce_calc_taxes' )
				? ''
				: __( 'Tax calculation is switched off for this store, so this rate is stored but not applied. Turn it on with store/update-store-settings if that is intended.', 'acrossai-abilities-manager' ),
		);
	}

	/* ------------------------------------------------------------- shipping */

	/**
	 * Shipping zones and their methods.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function shipping_zones(): array {
		$zones = array();

		foreach ( \WC_Shipping_Zones::get_zones() as $zone ) {
			$methods = array();

			foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {
				if ( ! is_object( $method ) ) {
					continue;
				}

				$methods[] = array(
					'instance_id' => isset( $method->instance_id ) ? (int) $method->instance_id : 0,
					'id'          => isset( $method->id ) ? (string) $method->id : '',
					'title'       => method_exists( $method, 'get_title' ) ? $method->get_title() : '',
					'enabled'     => isset( $method->enabled ) && 'yes' === $method->enabled,
				);
			}

			$zones[] = array(
				'id'        => (int) ( $zone['id'] ?? $zone['zone_id'] ?? 0 ),
				'name'      => (string) ( $zone['zone_name'] ?? '' ),
				'order'     => (int) ( $zone['zone_order'] ?? 0 ),
				'regions'   => array_map(
					static function ( $region ): array {
						return array(
							'code' => is_object( $region ) && isset( $region->code ) ? (string) $region->code : '',
							'type' => is_object( $region ) && isset( $region->type ) ? (string) $region->type : '',
						);
					},
					(array) ( $zone['zone_locations'] ?? array() )
				),
				'methods'   => $methods,
			);
		}

		$rest = \WC_Shipping_Zones::get_zone( 0 );

		return array(
			'zones'          => $zones,
			'count'          => count( $zones ),
			'rest_of_world'  => $rest instanceof \WC_Shipping_Zone
				? array(
					'id'      => 0,
					'name'    => $rest->get_zone_name(),
					'methods' => array_map(
						static function ( $method ): array {
							return array(
								'instance_id' => isset( $method->instance_id ) ? (int) $method->instance_id : 0,
								'id'          => isset( $method->id ) ? (string) $method->id : '',
								'enabled'     => isset( $method->enabled ) && 'yes' === $method->enabled,
							);
						},
						(array) $rest->get_shipping_methods()
					),
				)
				: array(),
			'note'           => __( 'A customer whose address matches no zone is offered the methods on "rest of the world" — which is often empty, and is the usual reason a customer reports being unable to check out.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Shipping classes.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function shipping_classes(): array {
		$terms   = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			)
		);
		$classes = array();

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$classes[] = array(
				'id'          => (int) $term->term_id,
				'name'        => (string) $term->name,
				'slug'        => (string) $term->slug,
				'description' => (string) $term->description,
				'product_count' => (int) $term->count,
			);
		}

		return array(
			'classes' => $classes,
			'count'   => count( $classes ),
		);
	}

	/* ------------------------------------------------------------- settings */

	/**
	 * The settings this suite is willing to report.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$out = array();

		foreach ( array_keys( self::WRITABLE_SETTINGS ) as $key ) {
			$out[ $key ] = get_option( $key );
		}

		return array(
			'settings' => $out,
			'note'     => __( 'A deliberately narrow list. WooCommerce keeps hundreds of options under the same prefix, including payment gateway configuration with its keys and secrets, and none of that is readable or writable here.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Change settings, from the allow-list only.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $fields Supplied settings.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_settings( array $fields ) {
		$changed = array();
		$refused = array();

		foreach ( $fields as $key => $value ) {
			$key = (string) $key;

			if ( ! array_key_exists( $key, self::WRITABLE_SETTINGS ) ) {
				$refused[] = $key;

				continue;
			}

			switch ( self::WRITABLE_SETTINGS[ $key ] ) {
				case 'int':
					$clean = (string) (int) $value;
					break;
				case 'yesno':
					$clean = ( true === $value || 'yes' === $value || 1 === $value || '1' === $value ) ? 'yes' : 'no';
					break;
				default:
					$clean = sanitize_text_field( (string) $value );
			}

			update_option( $key, $clean );

			$changed[ $key ] = $clean;
		}

		if ( array() !== $refused ) {
			return new WP_Error(
				'setting_not_writable',
				sprintf(
					/* translators: 1: refused keys, 2: accepted keys. */
					__( 'These settings are not writable here: %1$s. This suite writes a named list only, because the same option prefix holds payment gateway configuration. Accepted settings are: %2$s.', 'acrossai-abilities-manager' ),
					implode( ', ', $refused ),
					implode( ', ', array_keys( self::WRITABLE_SETTINGS ) )
				)
			);
		}

		if ( array() === $changed ) {
			return new WP_Error( 'invalid_input', __( 'Nothing to change.', 'acrossai-abilities-manager' ) );
		}

		return array(
			'changed'  => $changed,
			'settings' => self::settings()['settings'],
		);
	}
}
