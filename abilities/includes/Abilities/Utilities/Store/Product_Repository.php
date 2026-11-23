<?php
/**
 * Feature 122 — the only place catalogue data is read or written.
 *
 * Everything goes through WooCommerce's own CRUD classes, never `$wpdb` and never `update_post_meta`.
 * Measured on 11.1 and the reason this class exists: writing `_regular_price` directly leaves `_price`
 * and `wc_product_meta_lookup` on the OLD price, so the shop keeps charging it — and saving the
 * product correctly afterwards does NOT repair it, because WooCommerce sees the meta already changed,
 * registers no change, and never re-derives anything.
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
 * Catalogue reads and writes.
 *
 * @since 0.0.34
 */
final class Product_Repository {

	/**
	 * Most products a single bulk price change may touch.
	 *
	 * @since 0.0.34
	 * @var   int
	 */
	public const MAX_BULK = 200;

	/**
	 * Most variations one call may generate.
	 *
	 * A 3x4x5 attribute set is 60 products. Generating them is slow, and a request that times out
	 * half way leaves a partly-built variable product that looks finished.
	 *
	 * @since 0.0.34
	 * @var   int
	 */
	public const MAX_VARIATIONS = 50;

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Load a product, or say why not.
	 *
	 * @since  0.0.34
	 * @param  int $id Product or variation id.
	 * @return \WC_Product|WP_Error
	 */
	public static function load( int $id ) {
		$product = $id > 0 ? wc_get_product( $id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return new WP_Error(
				'unknown_product',
				sprintf(
					/* translators: %d: product id. */
					__( 'No product with id %d. Call woocommerce/products-query to find one.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		return $product;
	}

	/**
	 * Whether this product's lookup row matches what the product actually says.
	 *
	 * The lookup table is what SKU search, price sorting and the on-sale query read. A mismatch means
	 * something wrote the product outside WooCommerce, and the shop is answering those queries with
	 * the older values while the product page looks correct.
	 *
	 * @since  0.0.34
	 * @param  \WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	public static function lookup_state( \WC_Product $product ): array {
		global $wpdb;

		$id = $product->get_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT sku, min_price, stock_quantity, stock_status, onsale FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d", $id ),
			ARRAY_A
		);

		if ( null === $row ) {
			return array(
				'row_present' => false,
				'matches'     => false,
				'note'        => __( 'This product has no row in the lookup table, so it is invisible to SKU search, price sorting and the on-sale query while looking correct everywhere else.', 'acrossai-abilities-manager' ),
			);
		}

		$price = $product->get_price();

		$lookup_agrees = (string) $row['sku'] === (string) $product->get_sku()
			&& ( '' === (string) $price || abs( (float) $row['min_price'] - (float) $price ) < 0.0001 );

		/*
		 * The lookup row mirrors _price, so comparing the two catches only half the corruption.
		 * Measured: after a raw _regular_price write of 9.99 the product reported regular_price
		 * 9.99, _price 100.00 and min_price 100.0000 -- lookup and _price agreed with each other
		 * and this verdict read "matches: true" on a product the shop was charging the old price
		 * for. _price must also be consistent with what it is derived FROM: the sale price while a
		 * sale is running, the regular price otherwise.
		 */
		$expected = $product->is_on_sale() ? (string) $product->get_sale_price() : (string) $product->get_regular_price();

		$price_derived = '' === (string) $price
			|| '' === $expected
			|| abs( (float) $price - (float) $expected ) < 0.0001;

		$matches = $lookup_agrees && $price_derived;

		if ( $matches ) {
			$note = '';
		} elseif ( ! $price_derived ) {
			$note = sprintf(
				/* translators: 1: the price the shop charges, 2: the price the product claims. */
				__( 'The price the shop charges (%1$s) is not the one this product says it has (%2$s). Something wrote the price field directly instead of saving the product, so WooCommerce never re-derived it - and saving the product now will not repair it, because WooCommerce sees no change to make. Set the price through woocommerce/product-update or store/schedule-sale to rebuild it.', 'acrossai-abilities-manager' ),
				$price,
				$expected
			);
		} else {
			$note = __( 'The lookup table disagrees with the product. Something wrote this product outside WooCommerce; search, sorting and the on-sale list are answering with the older values.', 'acrossai-abilities-manager' );
		}

		return array(
			'row_present'   => true,
			'matches'       => $matches,
			'lookup_agrees' => $lookup_agrees,
			'price_derived' => $price_derived,
			'lookup'        => $row,
			'note'          => $note,
		);
	}

	/**
	 * A product, in full.
	 *
	 * @since  0.0.34
	 * @param  \WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	public static function shape( \WC_Product $product ): array {
		$attributes = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			$attributes[] = array(
				'name'         => $attribute->get_name(),
				'options'      => array_values( array_map( 'strval', (array) $attribute->get_options() ) ),
				'visible'      => $attribute->get_visible(),
				'for_variations' => $attribute->get_variation(),
				'taxonomy'     => $attribute->is_taxonomy(),
			);
		}

		return array(
			'id'                 => $product->get_id(),
			'parent_id'          => $product->get_parent_id(),
			'type'               => $product->get_type(),
			'name'               => $product->get_name(),
			'slug'               => $product->get_slug(),
			'sku'                => $product->get_sku(),
			'status'             => $product->get_status(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'featured'           => $product->get_featured(),
			'price'              => (string) $product->get_price(),
			'regular_price'      => (string) $product->get_regular_price(),
			'sale_price'         => (string) $product->get_sale_price(),
			'on_sale'            => $product->is_on_sale(),
			'date_on_sale_from'  => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date( 'Y-m-d' ) : '',
			'date_on_sale_to'    => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date( 'Y-m-d' ) : '',
			'manage_stock'       => $product->get_manage_stock(),
			'stock_quantity'     => $product->get_stock_quantity(),
			'stock_status'       => $product->get_stock_status(),
			'weight'             => (string) $product->get_weight(),
			'dimensions'         => array(
				'length' => (string) $product->get_length(),
				'width'  => (string) $product->get_width(),
				'height' => (string) $product->get_height(),
			),
			'shipping_class_id'  => $product->get_shipping_class_id(),
			'tax_status'         => $product->get_tax_status(),
			'tax_class'          => $product->get_tax_class(),
			'menu_order'         => $product->get_menu_order(),
			'category_ids'       => array_values( array_map( 'intval', (array) $product->get_category_ids() ) ),
			'tag_ids'            => array_values( array_map( 'intval', (array) $product->get_tag_ids() ) ),
			'image_id'           => (int) $product->get_image_id(),
			'gallery_image_ids'  => array_values( array_map( 'intval', (array) $product->get_gallery_image_ids() ) ),
			'cross_sell_ids'     => array_values( array_map( 'intval', (array) $product->get_cross_sell_ids() ) ),
			'upsell_ids'         => array_values( array_map( 'intval', (array) $product->get_upsell_ids() ) ),
			'attributes'         => $attributes,
			'variation_ids'      => $product instanceof \WC_Product_Variable
				? array_values( array_map( 'intval', (array) $product->get_children() ) )
				: array(),
			'permalink'          => (string) $product->get_permalink(),
		);
	}

	/**
	 * Save, then read the product back from the database.
	 *
	 * Never trusts the in-memory object. WooCommerce derives `_price` from the regular and sale
	 * prices during save, syncs taxonomy terms and rewrites the lookup row, so the only honest
	 * account of what happened is a fresh read.
	 *
	 * @since  0.0.34
	 * @param  \WC_Product $product Product to save.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function save_and_reread( \WC_Product $product ) {
		try {
			$id = $product->save();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'save_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'WooCommerce refused the change: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		if ( ! $id ) {
			return new WP_Error(
				'save_failed',
				__( 'WooCommerce reported no id after saving, so the change cannot be confirmed.', 'acrossai-abilities-manager' )
			);
		}

		$fresh = self::load( (int) $id );

		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		return array(
			'product'      => self::shape( $fresh ),
			'lookup_state' => self::lookup_state( $fresh ),
		);
	}

	/* ------------------------------------------------------------- details */

	/**
	 * Fields WooCommerce's own product-update does not reach.
	 *
	 * @since  0.0.34
	 * @param  int                  $id     Product id.
	 * @param  array<string, mixed> $fields Supplied fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_details( int $id, array $fields ) {
		$product = self::load( $id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$setters = array(
			'weight'             => 'set_weight',
			'length'             => 'set_length',
			'width'              => 'set_width',
			'height'             => 'set_height',
			'tax_status'         => 'set_tax_status',
			'tax_class'          => 'set_tax_class',
			'menu_order'         => 'set_menu_order',
			'catalog_visibility' => 'set_catalog_visibility',
			'purchase_note'      => 'set_purchase_note',
		);

		$changed = false;

		foreach ( $setters as $key => $setter ) {
			if ( array_key_exists( $key, $fields ) && method_exists( $product, $setter ) ) {
				$product->{$setter}( $fields[ $key ] );
				$changed = true;
			}
		}

		if ( array_key_exists( 'featured', $fields ) ) {
			$product->set_featured( (bool) $fields['featured'] );
			$changed = true;
		}

		if ( array_key_exists( 'shipping_class_id', $fields ) ) {
			$product->set_shipping_class_id( (int) $fields['shipping_class_id'] );
			$changed = true;
		}

		foreach ( array( 'cross_sell_ids' => 'set_cross_sell_ids', 'upsell_ids' => 'set_upsell_ids' ) as $key => $setter ) {
			if ( isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) ) {
				$product->{$setter}( array_values( array_map( 'intval', $fields[ $key ] ) ) );
				$changed = true;
			}
		}

		if ( ! $changed ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: accepted field list. */
					__( 'Nothing to change. Accepted fields are: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', array_merge( array_keys( $setters ), array( 'featured', 'shipping_class_id', 'cross_sell_ids', 'upsell_ids' ) ) )
				)
			);
		}

		return self::save_and_reread( $product );
	}

	/* ------------------------------------------------------------ taxonomy */

	/**
	 * Categories and tags.
	 *
	 * Through `set_category_ids()` and `set_tag_ids()` rather than `wp_set_object_terms()`: the data
	 * store applies the default category when the list empties, syncs the `product_visibility` terms
	 * the catalogue actually filters on, and rebuilds the lookup row. A raw term write does none of
	 * that.
	 *
	 * @since  0.0.34
	 * @param  int                  $id     Product id.
	 * @param  array<string, mixed> $fields category_ids / tag_ids / mode.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_taxonomy( int $id, array $fields ) {
		$product = self::load( $id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$append  = 'append' === ( $fields['mode'] ?? 'replace' );
		$changed = false;

		foreach ( array( 'category_ids' => array( 'get_category_ids', 'set_category_ids' ), 'tag_ids' => array( 'get_tag_ids', 'set_tag_ids' ) ) as $key => $pair ) {
			if ( ! isset( $fields[ $key ] ) || ! is_array( $fields[ $key ] ) ) {
				continue;
			}

			$ids = array_values( array_unique( array_map( 'intval', $fields[ $key ] ) ) );

			if ( $append ) {
				$ids = array_values( array_unique( array_merge( (array) $product->{$pair[0]}(), $ids ) ) );
			}

			$product->{$pair[1]}( $ids );
			$changed = true;
		}

		if ( ! $changed ) {
			return new WP_Error(
				'invalid_input',
				__( 'Supply category_ids, tag_ids, or both.', 'acrossai-abilities-manager' )
			);
		}

		return self::save_and_reread( $product );
	}

	/* -------------------------------------------------------------- images */

	/**
	 * @since  0.0.34
	 * @param  int                  $id     Product id.
	 * @param  array<string, mixed> $fields image_id / gallery_image_ids.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_images( int $id, array $fields ) {
		$product = self::load( $id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$changed = false;

		if ( array_key_exists( 'image_id', $fields ) ) {
			$image_id = (int) $fields['image_id'];

			if ( $image_id > 0 && 'attachment' !== get_post_type( $image_id ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %d: supplied id. */
						__( '%d is not an attachment. Upload the image through the media abilities first, then pass the attachment id.', 'acrossai-abilities-manager' ),
						$image_id
					)
				);
			}

			$product->set_image_id( $image_id );
			$changed = true;
		}

		if ( isset( $fields['gallery_image_ids'] ) && is_array( $fields['gallery_image_ids'] ) ) {
			$ids = array_values( array_map( 'intval', $fields['gallery_image_ids'] ) );

			foreach ( $ids as $attachment ) {
				if ( 'attachment' !== get_post_type( $attachment ) ) {
					return new WP_Error(
						'invalid_input',
						sprintf(
							/* translators: %d: supplied id. */
							__( 'Gallery entry %d is not an attachment.', 'acrossai-abilities-manager' ),
							$attachment
						)
					);
				}
			}

			$product->set_gallery_image_ids( $ids );
			$changed = true;
		}

		if ( ! $changed ) {
			return new WP_Error(
				'invalid_input',
				__( 'Supply image_id, gallery_image_ids, or both.', 'acrossai-abilities-manager' )
			);
		}

		return self::save_and_reread( $product );
	}

	/* -------------------------------------------------------------- pricing */

	/**
	 * Set or clear a sale, with an optional window.
	 *
	 * Through the CRUD so `_price` is re-derived and `wc_products_onsale` is flushed. That transient
	 * holds for THIRTY DAYS, so a sale written any other way can leave a shop off-sale for a month
	 * with nothing anywhere suggesting a problem.
	 *
	 * @since  0.0.34
	 * @param  int                  $id     Product id.
	 * @param  array<string, mixed> $fields sale_price / from / to / clear.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function schedule_sale( int $id, array $fields ) {
		$product = self::load( $id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		if ( ! empty( $fields['clear'] ) ) {
			$product->set_sale_price( '' );
			$product->set_date_on_sale_from( '' );
			$product->set_date_on_sale_to( '' );

			return self::save_and_reread( $product );
		}

		if ( ! isset( $fields['sale_price'] ) || '' === (string) $fields['sale_price'] ) {
			return new WP_Error(
				'invalid_input',
				__( 'Supply sale_price, or clear: true to end the sale.', 'acrossai-abilities-manager' )
			);
		}

		$sale    = (float) $fields['sale_price'];
		$regular = (float) $product->get_regular_price();

		if ( $regular > 0 && $sale >= $regular ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: sale price, 2: regular price. */
					__( 'A sale price of %1$s is not below the regular price of %2$s. WooCommerce discards a sale price that is not lower, so this would silently do nothing.', 'acrossai-abilities-manager' ),
					(string) $fields['sale_price'],
					(string) $product->get_regular_price()
				)
			);
		}

		$product->set_sale_price( (string) $fields['sale_price'] );

		foreach ( array( 'from' => 'set_date_on_sale_from', 'to' => 'set_date_on_sale_to' ) as $key => $setter ) {
			if ( array_key_exists( $key, $fields ) ) {
				$product->{$setter}( '' === (string) $fields[ $key ] ? '' : (string) $fields[ $key ] );
			}
		}

		return self::save_and_reread( $product );
	}

	/**
	 * Change prices across a filtered set.
	 *
	 * The most dangerous ability in the suite, so: dry run by default, an explicit filter required,
	 * a hard cap, and a per-product before/after in the response. Nothing about "all products".
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Filter, change and flags.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function bulk_update_prices( array $input ) {
		$ids      = isset( $input['product_ids'] ) ? array_map( 'intval', (array) $input['product_ids'] ) : array();
		$category = isset( $input['category_id'] ) ? (int) $input['category_id'] : 0;

		if ( array() === $ids && $category <= 0 ) {
			return new WP_Error(
				'invalid_input',
				__( 'Supply product_ids or category_id. There is deliberately no way to change every price on the store in one call.', 'acrossai-abilities-manager' )
			);
		}

		if ( array() === $ids ) {
			$found = wc_get_products(
				array(
					'limit'    => self::MAX_BULK + 1,
					'category' => array( (string) get_term_field( 'slug', $category, 'product_cat' ) ),
					'return'   => 'ids',
					'status'   => array( 'publish', 'private', 'draft' ),
				)
			);
			$ids   = array_map( 'intval', (array) $found );
		}

		if ( count( $ids ) > self::MAX_BULK ) {
			return new WP_Error(
				'too_many',
				sprintf(
					/* translators: 1: number matched, 2: cap. */
					__( '%1$d products matched, which is more than the %2$d this ability will change in one call. Narrow the filter and run it again.', 'acrossai-abilities-manager' ),
					count( $ids ),
					self::MAX_BULK
				)
			);
		}

		$mode   = (string) ( $input['mode'] ?? '' );
		$amount = (float) ( $input['amount'] ?? 0 );

		if ( ! in_array( $mode, array( 'set', 'increase_by_percent', 'decrease_by_percent' ), true ) ) {
			return new WP_Error( 'invalid_input', __( 'mode must be set, increase_by_percent or decrease_by_percent.', 'acrossai-abilities-manager' ) );
		}

		$dry     = ! isset( $input['apply'] ) || ! $input['apply'];
		$changes = array();

		foreach ( $ids as $id ) {
			$product = self::load( $id );

			if ( is_wp_error( $product ) ) {
				continue;
			}

			$current = (float) $product->get_regular_price();

			if ( 'set' === $mode ) {
				$new = $amount;
			} elseif ( 'increase_by_percent' === $mode ) {
				$new = $current * ( 1 + ( $amount / 100 ) );
			} else {
				$new = $current * ( 1 - ( $amount / 100 ) );
			}

			$new = round( max( 0, $new ), (int) wc_get_price_decimals() );

			$row = array(
				'id'     => $id,
				'sku'    => $product->get_sku(),
				'name'   => $product->get_name(),
				'before' => (string) $product->get_regular_price(),
				'after'  => (string) $new,
			);

			if ( ! $dry ) {
				$product->set_regular_price( (string) $new );
				$saved = self::save_and_reread( $product );

				if ( is_wp_error( $saved ) ) {
					$row['error'] = $saved->get_error_message();
				} else {
					$row['price_now'] = $saved['product']['price'];
				}
			}

			$changes[] = $row;
		}

		return array(
			'dry_run'  => $dry,
			'mode'     => $mode,
			'amount'   => $amount,
			'count'    => count( $changes ),
			'changes'  => $changes,
			'note'     => $dry
				? __( 'Nothing was changed. This is what would happen; pass apply: true together with confirm: true to make it so.', 'acrossai-abilities-manager' )
				: '',
		);
	}

	/* ----------------------------------------------------------- attributes */

	/**
	 * Replace a product's attributes.
	 *
	 * @since  0.0.34
	 * @param  int                              $id         Product id.
	 * @param  array<int, array<string, mixed>> $attributes Attribute definitions.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_attributes( int $id, array $attributes ) {
		$product = self::load( $id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$built = array();

		foreach ( $attributes as $index => $definition ) {
			if ( ! is_array( $definition ) || empty( $definition['name'] ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %d: position in the list. */
						__( 'Attribute %d has no name.', 'acrossai-abilities-manager' ),
						(int) $index + 1
					)
				);
			}

			$options = isset( $definition['options'] ) ? array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $definition['options'] ) ) ) ) : array();

			if ( array() === $options ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %s: attribute name. */
						__( 'Attribute "%s" has no options, so it would appear on the product with nothing to choose.', 'acrossai-abilities-manager' ),
						(string) $definition['name']
					)
				);
			}

			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( (string) $definition['name'] );
			$attribute->set_options( $options );
			$attribute->set_position( (int) $index );
			$attribute->set_visible( ! isset( $definition['visible'] ) || (bool) $definition['visible'] );
			$attribute->set_variation( ! empty( $definition['for_variations'] ) );

			$built[] = $attribute;
		}

		$product->set_attributes( $built );

		return self::save_and_reread( $product );
	}

	/* ----------------------------------------------------------- variations */

	/**
	 * Create a variable product with variation-enabled attributes.
	 *
	 * WooCommerce's own `product-create` cannot do this: its type list is
	 * physical|virtual|digital|affiliate|grouped, with no variable at all.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input name / sku / status / attributes.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_variable( array $input ) {
		$name = trim( (string) ( $input['name'] ?? '' ) );

		if ( '' === $name ) {
			return new WP_Error( 'invalid_input', __( 'A product name is required.', 'acrossai-abilities-manager' ) );
		}

		$product = new \WC_Product_Variable();
		$product->set_name( $name );
		$product->set_status( (string) ( $input['status'] ?? 'draft' ) );

		if ( ! empty( $input['sku'] ) ) {
			$product->set_sku( (string) $input['sku'] );
		}

		$saved = self::save_and_reread( $product );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) ) {
			$with_attributes = self::set_attributes( (int) $saved['product']['id'], $input['attributes'] );

			if ( is_wp_error( $with_attributes ) ) {
				return $with_attributes;
			}

			return $with_attributes;
		}

		return $saved;
	}

	/**
	 * Build variations from the product's variation-enabled attributes.
	 *
	 * Dry run by default and hard-capped. A 3x4x5 attribute set is 60 products; a request that times
	 * out half way leaves a partly-built variable product that looks finished.
	 *
	 * @since  0.0.34
	 * @param  int  $id    Parent product id.
	 * @param  bool $apply Whether to create them.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function generate_variations( int $id, bool $apply ) {
		$product = self::load( $id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		if ( ! $product instanceof \WC_Product_Variable ) {
			return new WP_Error(
				'not_variable',
				sprintf(
					/* translators: %s: product type. */
					__( 'This is a %s product. Only a variable product has variations; use store/create-variable-product to make one.', 'acrossai-abilities-manager' ),
					$product->get_type()
				)
			);
		}

		$axes = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_variation() ) {
				continue;
			}

			$options = $attribute->is_taxonomy()
				? wp_list_pluck( (array) $attribute->get_terms(), 'slug' )
				: array_map( 'strval', (array) $attribute->get_options() );

			if ( array() !== $options ) {
				$axes[ $attribute->get_name() ] = array_values( $options );
			}
		}

		if ( array() === $axes ) {
			return new WP_Error(
				'no_variation_attributes',
				__( 'This product has no attributes marked for variations. Set them with store/set-product-attributes, using for_variations: true.', 'acrossai-abilities-manager' )
			);
		}

		$combinations = array( array() );

		foreach ( $axes as $name => $options ) {
			$next = array();

			foreach ( $combinations as $combination ) {
				foreach ( $options as $option ) {
					$next[] = array_merge( $combination, array( $name => $option ) );
				}
			}

			$combinations = $next;
		}

		$existing = array();

		foreach ( (array) $product->get_children() as $child ) {
			$variation = wc_get_product( $child );

			if ( $variation instanceof \WC_Product_Variation ) {
				$existing[] = wp_json_encode( $variation->get_attributes() );
			}
		}

		$wanted = array();

		foreach ( $combinations as $combination ) {
			$key = array();

			foreach ( $combination as $name => $option ) {
				$key[ sanitize_title( $name ) ] = $option;
			}

			if ( ! in_array( wp_json_encode( $key ), $existing, true ) ) {
				$wanted[] = $key;
			}
		}

		if ( count( $wanted ) > self::MAX_VARIATIONS ) {
			return new WP_Error(
				'too_many',
				sprintf(
					/* translators: 1: number needed, 2: cap. */
					__( 'That attribute set needs %1$d variations, which is more than the %2$d this ability creates in one call. Reduce the options, or create them in batches.', 'acrossai-abilities-manager' ),
					count( $wanted ),
					self::MAX_VARIATIONS
				)
			);
		}

		$created = array();

		if ( $apply ) {
			foreach ( $wanted as $attributes ) {
				$variation = new \WC_Product_Variation();
				$variation->set_parent_id( $product->get_id() );
				$variation->set_attributes( $attributes );
				$variation->set_status( 'publish' );

				$created[] = array(
					'id'         => (int) $variation->save(),
					'attributes' => $attributes,
				);
			}

			\WC_Product_Variable::sync( $product->get_id() );
		}

		return array(
			'dry_run'          => ! $apply,
			'existing_count'   => count( $existing ),
			'would_create'     => $wanted,
			'would_create_count' => count( $wanted ),
			'created'          => $created,
			'note'             => $apply ? '' : __( 'Nothing was created. Pass apply: true to build these.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Change one variation.
	 *
	 * @since  0.0.34
	 * @param  int                  $id     Variation id.
	 * @param  array<string, mixed> $fields Supplied fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_variation( int $id, array $fields ) {
		$variation = self::load( $id );

		if ( is_wp_error( $variation ) ) {
			return $variation;
		}

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return new WP_Error(
				'not_a_variation',
				__( 'That id is a product, not a variation. Use woocommerce/product-update for a product.', 'acrossai-abilities-manager' )
			);
		}

		$setters = array(
			'regular_price' => 'set_regular_price',
			'sale_price'    => 'set_sale_price',
			'sku'           => 'set_sku',
			'status'        => 'set_status',
			'weight'        => 'set_weight',
		);

		$changed = false;

		foreach ( $setters as $key => $setter ) {
			if ( array_key_exists( $key, $fields ) ) {
				$variation->{$setter}( (string) $fields[ $key ] );
				$changed = true;
			}
		}

		if ( array_key_exists( 'manage_stock', $fields ) ) {
			$variation->set_manage_stock( (bool) $fields['manage_stock'] );
			$changed = true;
		}

		if ( array_key_exists( 'stock_quantity', $fields ) ) {
			$variation->set_stock_quantity( (int) $fields['stock_quantity'] );
			$changed = true;
		}

		if ( ! $changed ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: field list. */
					__( 'Nothing to change. Accepted fields are: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', array_merge( array_keys( $setters ), array( 'manage_stock', 'stock_quantity' ) ) )
				)
			);
		}

		$saved = self::save_and_reread( $variation );

		if ( ! is_wp_error( $saved ) && $variation->get_parent_id() > 0 ) {
			\WC_Product_Variable::sync( $variation->get_parent_id() );
		}

		return $saved;
	}

	/* ---------------------------------------------------------------- stock */

	/**
	 * Where this product's stock is actually kept.
	 *
	 * The answer is not always "here". A variation can have stock managed on its PARENT, in which
	 * case the variation's own `_stock` row is read by nothing — the most common silent stock bug,
	 * and the reason this is a separate ability rather than a field on the product read.
	 *
	 * @since  0.0.34
	 * @param  int $id Product or variation id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function stock_state( int $id ) {
		$product = self::load( $id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$managed_by = (int) $product->get_stock_managed_by_id();
		$authority  = $managed_by === $product->get_id() ? $product : self::load( $managed_by );

		if ( is_wp_error( $authority ) ) {
			return $authority;
		}

		/*
		 * The reported quantity is the AUTHORITY's, not this object's own field.
		 *
		 * For a variation whose stock is managed by its parent, the variation's own `stock_quantity`
		 * prop is not refreshed when the parent's stock moves within the same request. Measured:
		 * after decreasing by 2, the parent held 5 and a fresh request agreed — while the variation's
		 * own field still read 7 in the request that made the change. Reporting that as "after" would
		 * be a stale read-back dressed up as a confirmation, which is the exact failure this suite
		 * exists to prevent. `own_row_quantity` keeps the raw field visible for anyone who needs it.
		 */
		$effective = $managed_by === $product->get_id()
			? $product->get_stock_quantity()
			: $authority->get_stock_quantity();

		return array(
			'id'                  => $product->get_id(),
			'type'                => $product->get_type(),
			'manage_stock'        => $product->get_manage_stock(),
			'stock_quantity'      => $effective,
			'own_row_quantity'    => $product->get_stock_quantity(),
			'stock_status'        => $product->get_stock_status(),
			'backorders'          => $product->get_backorders(),
			'managed_by_id'       => $managed_by,
			'managed_here'        => $managed_by === $product->get_id(),
			'authoritative_stock' => $authority->get_stock_quantity(),
			'note'                => $managed_by === $product->get_id()
				? ''
				: sprintf(
					/* translators: %d: parent product id. */
					__( 'Stock for this variation is managed on the parent product (%d). Writing a stock value on the variation itself changes a row nothing reads.', 'acrossai-abilities-manager' ),
					$managed_by
				),
		);
	}

	/**
	 * Change stock through WooCommerce's own stock function.
	 *
	 * `wc_update_product_stock()` resolves the managing object, updates atomically, recomputes the
	 * stock status, refreshes the `outofstock` visibility term and the lookup row, and fires the
	 * hooks every inventory integration listens on. None of that happens on a meta write.
	 *
	 * @since  0.0.34
	 * @param  int    $id        Product or variation id.
	 * @param  float  $quantity  Amount.
	 * @param  string $operation set, increase or decrease.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function adjust_stock( int $id, float $quantity, string $operation ) {
		$product = self::load( $id );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		if ( ! in_array( $operation, array( 'set', 'increase', 'decrease' ), true ) ) {
			return new WP_Error( 'invalid_input', __( 'operation must be set, increase or decrease.', 'acrossai-abilities-manager' ) );
		}

		if ( ! $product->get_manage_stock() ) {
			$managed_by = (int) $product->get_stock_managed_by_id();

			if ( $managed_by === $product->get_id() ) {
				/*
				 * Name an ability that can actually do it. Measured: woocommerce/product-update
				 * refuses both variations and variable parents with
				 * woocommerce_product_type_unsupported, so sending a variation there was a dead
				 * end -- the caller follows the instruction, is refused again, and has no next
				 * move. store/update-variation handles a variation; nothing can switch stock
				 * management on for a variable parent, and saying so is better than a pointer
				 * that fails.
				 */
				if ( 'variation' === $product->get_type() ) {
					return new WP_Error(
						'stock_not_managed',
						__( 'This variation does not track stock quantities, so there is no number to change. Turn stock management on with store/update-variation, passing manage_stock: true and a stock_quantity.', 'acrossai-abilities-manager' )
					);
				}

				if ( 'variable' === $product->get_type() ) {
					return new WP_Error(
						'stock_not_managed',
						__( 'This variable product does not track stock at the parent level, so there is no number to change here. Stock for a variable product is normally held per variation: turn it on for each one with store/update-variation. Switching it on for the parent itself has to be done in the product editor - neither this plugin nor WooCommerce exposes an ability for it.', 'acrossai-abilities-manager' )
					);
				}

				return new WP_Error(
					'stock_not_managed',
					__( 'This product does not track stock quantities, so there is no number to change. Turn stock management on through woocommerce/product-update first.', 'acrossai-abilities-manager' )
				);
			}
		}

		$before = self::stock_state( $id );

		wc_update_product_stock( $product, $quantity, $operation );

		/*
		 * Drop the caches before reading back, for BOTH this product and whichever one actually owns
		 * the stock. Without it the after-reading is stale: measured on a variation whose stock is
		 * managed by its parent, the authoritative quantity moved 7 -> 5 while the read-back still
		 * reported 7. An ability that says "after: 7" when the number is 5 is the same lie as a write
		 * that reports success and changes nothing.
		 */
		foreach ( array_unique( array( $id, (int) $product->get_stock_managed_by_id() ) ) as $cached ) {
			wp_cache_delete( $cached, 'post_meta' );

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $cached );
			}
		}

		$after = self::stock_state( $id );

		return is_wp_error( $after ) ? $after : array(
			'before' => is_wp_error( $before ) ? null : $before,
			'after'  => $after,
		);
	}

	/**
	 * Products at or below a stock threshold.
	 *
	 * @since  0.0.34
	 * @param  int $threshold Quantity at or below which a product counts as low.
	 * @param  int $limit     Rows to return.
	 * @return array<string, mixed>
	 */
	public static function low_stock( int $threshold, int $limit ): array {
		$products = wc_get_products(
			array(
				'limit'        => $limit,
				'status'       => 'publish',
				'manage_stock' => true,
				'return'       => 'objects',
				'orderby'      => 'title',
				'order'        => 'ASC',
			)
		);

		$low = array();

		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$qty = $product->get_stock_quantity();

			if ( null === $qty || (int) $qty > $threshold ) {
				continue;
			}

			$low[] = array(
				'id'             => $product->get_id(),
				'name'           => $product->get_name(),
				'sku'            => $product->get_sku(),
				'stock_quantity' => (int) $qty,
				'stock_status'   => $product->get_stock_status(),
			);
		}

		return array(
			'threshold' => $threshold,
			'products'  => $low,
			'count'     => count( $low ),
		);
	}
}
