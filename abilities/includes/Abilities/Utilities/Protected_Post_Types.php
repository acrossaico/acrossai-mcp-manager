<?php
/**
 * Feature 120 — post types whose rows are not the whole truth.
 *
 * The generic content writers take any post type and write it with `wp_insert_post()` /
 * `wp_update_post()` plus `meta_input`. For an ordinary post type that is exactly right. For a post
 * type whose plugin keeps derived state elsewhere it is wrong in one of two ways, and both report
 * success:
 *
 *   INCOMPLETE — the row is real and the write lands, but a lookup table, a taxonomy term, a
 *                transient or a derived meta value is not updated, so the site keeps reading the old
 *                value. Measured on WooCommerce 11.1: writing `_regular_price` through
 *                `content/update-cpt-item` leaves `_price` and `wc_product_meta_lookup` untouched, so
 *                the store carries on charging the previous price. The `wc_products_onsale` transient
 *                that decides the on-sale list holds for THIRTY DAYS.
 *
 *   DISCARDED  — the row is not what the site reads at all. WooCommerce stores orders in
 *                `wp_wc_orders` and three sibling tables when HPOS is on, which is the default for
 *                new installs. Measured on this site: HPOS enabled, sync disabled. A posts-table
 *                write is never read back (sync-on-read defaults to false) and is eventually deleted
 *                outright by the legacy cleanup batch processor.
 *
 * Modelled on {@see Post_Builder_Detector}, which answers the same question for page builders and
 * post_content. The two are deliberately separate: that one is about WHERE the content lives, this
 * one about WHETHER the row is the authority.
 *
 * This file must not require WooCommerce. It is a generic mechanism whose first entries happen to be
 * WooCommerce's, and `acrossai_protected_post_types` lets any plugin add its own.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Which post types the generic writers must not treat as ordinary.
 *
 * @since 0.0.34
 */
final class Protected_Post_Types {

	/**
	 * Ordinary post type; the generic writers are correct.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const WRITES_APPLY = 'applies';

	/**
	 * The write lands, but derived state is left stale and the site reads the old value.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const WRITES_INCOMPLETE = 'incomplete';

	/**
	 * The row is not what the site reads; the write is a no-op and is eventually deleted.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const WRITES_DISCARDED = 'discarded';

	/**
	 * Refusal code, matching the existing envelope key used by Update_Post and Patch_Option_Value.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const BLOCKED_REASON = 'protected_post_type';

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The descriptor table.
	 *
	 * `active_if` is the same shape as Post_Builder_Detector's: a class or function whose presence
	 * proves the owning plugin is still here. When it is absent the verdict downgrades to
	 * WRITES_APPLY, because with nothing reading the lookup table a plain write really does apply —
	 * and will be wrong again the moment the plugin returns.
	 *
	 * `hpos_only` marks a type whose verdict depends on WooCommerce's order storage setting rather
	 * than on the plugin merely being present.
	 *
	 * @since  0.0.34
	 * @return array<string, array<string, mixed>>
	 */
	private static function descriptors(): array {
		$woo = array( 'function' => 'wc_get_product' );

		return array(
			'product'              => array(
				'owner'      => __( 'WooCommerce', 'acrossai-abilities-manager' ),
				'writes'     => self::WRITES_INCOMPLETE,
				'active_if'  => $woo,
				'authority'  => __( 'the posts table, plus a lookup table and several taxonomy terms', 'acrossai-abilities-manager' ),
				'stale'      => array( 'wc_product_meta_lookup', '_price', 'product_type term', 'product_visibility terms', 'wc_products_onsale transient' ),
				'instead'    => array( 'woocommerce/product-update', 'store/update-product-details', 'store/schedule-sale' ),
			),
			'product_variation'    => array(
				'owner'      => __( 'WooCommerce', 'acrossai-abilities-manager' ),
				'writes'     => self::WRITES_INCOMPLETE,
				'active_if'  => $woo,
				'authority'  => __( 'the posts table, plus a lookup table — and stock may be managed on the parent product rather than here', 'acrossai-abilities-manager' ),
				'stale'      => array( 'wc_product_meta_lookup', '_price', 'parent stock row' ),
				'instead'    => array( 'store/update-variation', 'store/adjust-stock' ),
			),
			'shop_order'           => array(
				'owner'      => __( 'WooCommerce', 'acrossai-abilities-manager' ),
				'writes'     => self::WRITES_DISCARDED,
				'active_if'  => $woo,
				'hpos_only'  => true,
				'authority'  => __( 'the wc_orders table and three sibling tables', 'acrossai-abilities-manager' ),
				'stale'      => array( 'wp_wc_orders', 'wp_wc_order_operational_data', 'wp_wc_order_addresses' ),
				'instead'    => array( 'woocommerce/orders-query', 'woocommerce/order-update-status', 'store/get-order' ),
			),
			'shop_order_refund'    => array(
				'owner'      => __( 'WooCommerce', 'acrossai-abilities-manager' ),
				'writes'     => self::WRITES_DISCARDED,
				'active_if'  => $woo,
				'hpos_only'  => true,
				'authority'  => __( 'the wc_orders table', 'acrossai-abilities-manager' ),
				'stale'      => array( 'wp_wc_orders' ),
				'instead'    => array( 'store/refund-order' ),
			),
			'shop_order_placehold' => array(
				'owner'      => __( 'WooCommerce', 'acrossai-abilities-manager' ),
				'writes'     => self::WRITES_DISCARDED,
				'active_if'  => $woo,
				'authority'  => __( 'the wc_orders table; this post type is only a placeholder left behind by the order tables', 'acrossai-abilities-manager' ),
				'stale'      => array( 'wp_wc_orders' ),
				'instead'    => array( 'woocommerce/orders-query' ),
			),
		);
	}

	/**
	 * Every descriptor, after the extension filter.
	 *
	 * @since  0.0.34
	 * @return array<string, array<string, mixed>>
	 */
	private static function all(): array {
		/**
		 * Filters the post types the generic content writers must not treat as ordinary.
		 *
		 * Prefer this over editing the table: an e-commerce, LMS or membership plugin knows its own
		 * storage far better than this file can.
		 *
		 * @since 0.0.34
		 * Each entry takes `owner`, `writes` (one of the WRITES_* constants), `authority`, `stale` and
		 * `instead`. `active_if` is OPTIONAL — supply `array( 'class' => ... )`, `'function'` or
		 * `'constant'` to have the verdict downgrade when the owning plugin is deactivated; omit it and
		 * the owner is treated as always present.
		 *
		 * @param array<string, array<string, mixed>> $descriptors Keyed by post type.
		 */
		$filtered = apply_filters( 'acrossai_protected_post_types', self::descriptors() );

		return is_array( $filtered ) ? $filtered : self::descriptors();
	}

	/**
	 * Whether the owning plugin is still installed.
	 *
	 * @since  0.0.34
	 * @param  array<string, string> $test Class or function to probe.
	 * @return bool
	 */
	private static function owner_present( array $test ): bool {
		if ( isset( $test['class'] ) ) {
			return class_exists( (string) $test['class'] );
		}

		if ( isset( $test['function'] ) ) {
			return function_exists( (string) $test['function'] );
		}

		if ( isset( $test['constant'] ) ) {
			return defined( (string) $test['constant'] );
		}

		/*
		 * No probe supplied. Treat the owner as PRESENT rather than absent: a third party registering
		 * a descriptor through the filter is telling us its post type is special, and answering
		 * "ordinary" would be the exact opposite of what it asked for — silently, which is worse than
		 * either answer. The probe is an optional refinement, not a requirement.
		 */
		return array() === $test;
	}

	/**
	 * Whether WooCommerce is keeping orders outside the posts table.
	 *
	 * Read through WooCommerce's own utility rather than the option, so a site that moves the switch
	 * is answered correctly without this file knowing the option name.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	private static function orders_are_elsewhere(): bool {
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
	 * The verdict for one post type.
	 *
	 * @since  0.0.34
	 * @param  string $post_type Post type slug.
	 * @return array<string, mixed>
	 */
	public static function inspect( string $post_type ): array {
		$post_type   = sanitize_key( $post_type );
		$descriptors = self::all();

		if ( ! isset( $descriptors[ $post_type ] ) || ! is_array( $descriptors[ $post_type ] ) ) {
			return self::ordinary( $post_type );
		}

		$d       = $descriptors[ $post_type ];
		$present = self::owner_present( (array) ( $d['active_if'] ?? array() ) );
		$writes  = (string) ( $d['writes'] ?? self::WRITES_APPLY );

		if ( ! $present ) {
			$result           = self::ordinary( $post_type );
			$result['note']   = sprintf(
				/* translators: %s: plugin name. */
				__( 'This post type belongs to %s, which is not active here, so nothing is reading the data it would normally derive. A plain write applies now — and would be wrong again the moment that plugin is reactivated.', 'acrossai-abilities-manager' ),
				(string) ( $d['owner'] ?? '' )
			);
			$result['owner']  = (string) ( $d['owner'] ?? '' );

			return $result;
		}

		/*
		 * A type whose verdict depends on where the plugin is currently storing the records.
		 *
		 * The whole descriptor has to change, not just the verdict. An earlier version moved only
		 * $writes and left `authority` and `stale` describing the order tables — so on a store with
		 * HPOS switched OFF, where orders really are in the posts table, the one ability whose job is
		 * to answer this correctly reported the exact inverse of the truth.
		 */
		if ( ! empty( $d['hpos_only'] ) && ! self::orders_are_elsewhere() ) {
			$writes        = self::WRITES_INCOMPLETE;
			$d['authority'] = __( 'the posts table — this store keeps orders there rather than in the dedicated order tables', 'acrossai-abilities-manager' );
			$d['stale']     = array( 'order totals and counts', 'order status transitions', 'analytics tables' );
		}

		return array(
			'post_type'      => $post_type,
			'owner'          => (string) ( $d['owner'] ?? '' ),
			'owner_active'   => true,
			'writes'         => $writes,
			'authority'      => (string) ( $d['authority'] ?? '' ),
			'goes_stale'     => array_values( (array) ( $d['stale'] ?? array() ) ),
			'use_instead'    => self::resolvable( (array) ( $d['instead'] ?? array() ) ),
			'guidance'       => self::guidance( $post_type, $writes, $d ),
		);
	}

	/**
	 * Keep only the abilities that exist on this site right now.
	 *
	 * `use_instead` is machine-readable and is interpolated into the refusal sentence, so naming an
	 * ability that does not resolve is worse than naming none: the caller tries each, gets "ability
	 * not found", and the only path left is the override — which performs the corrupting write this
	 * guard exists to prevent. Filtering at call time also means abilities added later appear here
	 * without anyone remembering to update the table.
	 *
	 * @since  0.0.34
	 * @param  string[] $slugs Candidate ability names.
	 * @return string[]
	 */
	private static function resolvable( array $slugs ): array {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return array();
		}

		$out = array();

		foreach ( $slugs as $slug ) {
			if ( null !== wp_get_ability( (string) $slug ) ) {
				$out[] = (string) $slug;
			}
		}

		return array_values( $out );
	}

	/**
	 * @since  0.0.34
	 * @param  string $post_type Post type slug.
	 * @return array<string, mixed>
	 */
	private static function ordinary( string $post_type ): array {
		return array(
			'post_type'    => $post_type,
			'owner'        => '',
			'owner_active' => false,
			'writes'       => self::WRITES_APPLY,
			'authority'    => __( 'the posts table', 'acrossai-abilities-manager' ),
			'goes_stale'   => array(),
			'use_instead'  => array(),
			'guidance'     => __( 'An ordinary post type. The content abilities write it correctly.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @param  string               $post_type Post type.
	 * @param  string               $writes    Verdict.
	 * @param  array<string, mixed> $d         Descriptor.
	 * @return string
	 */
	private static function guidance( string $post_type, string $writes, array $d ): string {
		$resolved = self::resolvable( (array) ( $d['instead'] ?? array() ) );
		$instead  = array() === $resolved
			? __( 'the plugin\'s own tools', 'acrossai-abilities-manager' )
			: implode( ', ', $resolved );
		$owner   = (string) ( $d['owner'] ?? '' );

		if ( self::WRITES_DISCARDED === $writes ) {
			return sprintf(
				/* translators: 1: owner plugin, 2: where the data really lives, 3: ability list. */
				__( '%1$s does not keep these records in the posts table — they live in %2$s. A write here changes a row nothing reads, and %1$s eventually deletes it. Use %3$s instead.', 'acrossai-abilities-manager' ),
				$owner,
				(string) ( $d['authority'] ?? '' ),
				$instead
			);
		}

		if ( self::WRITES_INCOMPLETE === $writes ) {
			return sprintf(
				/* translators: 1: owner plugin, 2: comma-separated derived stores, 3: ability list. */
				__( 'The row is real, but %1$s derives other data from it that a plain write does not update: %2$s. The change appears saved while the site keeps using the old values. Editing the title, description or status here is fine; anything in meta should go through %3$s.', 'acrossai-abilities-manager' ),
				$owner,
				implode( ', ', (array) ( $d['stale'] ?? array() ) ),
				$instead
			);
		}

		return __( 'An ordinary post type. The content abilities write it correctly.', 'acrossai-abilities-manager' );
	}

	/**
	 * What the caller should be told when the write is allowed to proceed.
	 *
	 * Centralised because the writers kept getting it subtly wrong. Two cases, and the second was
	 * unreachable before this existed:
	 *
	 *   - A non-ordinary verdict carries the guidance, so a title-only product edit says what it did
	 *     and did not touch.
	 *   - An ABSENT owner carries `note`, and that branch always has the ordinary verdict. Writers
	 *     that emitted warnings only when the verdict was non-ordinary therefore never surfaced it —
	 *     so on a site with WooCommerce temporarily deactivated, writing a product price succeeded
	 *     with no hint that it becomes wrong the moment WooCommerce is switched back on. That is
	 *     precisely the caveat the note was written to deliver.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $verdict From inspect().
	 * @return string[]
	 */
	public static function warnings_for( array $verdict ): array {
		$warnings = array();

		if ( isset( $verdict['writes'] ) && self::WRITES_APPLY !== $verdict['writes'] ) {
			$warnings[] = (string) ( $verdict['guidance'] ?? '' );
		}

		if ( ! empty( $verdict['note'] ) ) {
			$warnings[] = (string) $verdict['note'];
		}

		return array_values( array_filter( $warnings ) );
	}

	/**
	 * Whether a write must be refused, and why.
	 *
	 * The scoping is the point. A blanket ban on a protected type would be wrong and would make the
	 * guard something people route around: a product's `post_content` genuinely IS its description,
	 * and the block writers edit it legitimately. What must not happen is a meta write, because that
	 * is where the derived values live — and it is the case that costs money.
	 *
	 * @since  0.0.34
	 * @param  string $post_type  Post type.
	 * @param  bool   $touches_meta Whether the call writes meta.
	 * @return array{blocked: bool, verdict: array<string, mixed>}
	 */
	public static function assess( string $post_type, bool $touches_meta ): array {
		$verdict = self::inspect( $post_type );

		$blocked = self::WRITES_DISCARDED === $verdict['writes']
			|| ( self::WRITES_INCOMPLETE === $verdict['writes'] && $touches_meta );

		return array(
			'blocked' => $blocked,
			'verdict' => $verdict,
		);
	}

	/**
	 * The refusal payload, in the existing envelope shape.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $verdict From inspect().
	 * @return array<string, mixed>
	 */
	public static function refusal( array $verdict ): array {
		return array(
			'success'        => false,
			'blocked_reason' => self::BLOCKED_REASON,
			'post_type'      => (string) $verdict['post_type'],
			'writes'         => (string) $verdict['writes'],
			'use_instead'    => (array) $verdict['use_instead'],
			'message'        => sprintf(
				/* translators: 1: guidance sentence, 2: the override flag. */
				__( '%1$s If you have read that and still want the raw write, pass %2$s: true.', 'acrossai-abilities-manager' ),
				(string) $verdict['guidance'],
				'allow_protected_post_type'
			),
		);
	}
}
