<?php
/**
 * Feature 067 — static Elementor.com guidance data.
 *
 * Returns canonical widget and layout guidance grounded in Elementor's
 * official documentation. Consumed by:
 *   • get-official-widget-catalog
 *   • get-official-pattern-guidance
 *   • the 28 design-audit abilities as source of truth
 *
 * Skeleton implementation for Feature 067 initial ship — the full
 * catalog is populated incrementally as the design-audit abilities land.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor.com pattern & widget guidance catalog.
 */
final class Guidance_Catalog {

	/** Transient key for the cached official widget catalog fetch. */
	private const CATALOG_TRANSIENT = 'acrossai_elementor_widget_catalog';

	/** Transient TTL (12 hours). */
	private const CATALOG_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Categories used by the official widget catalog.
	 *
	 * @var string[]
	 */
	public const CATEGORIES = array( 'basic', 'pro', 'theme', 'woocommerce' );

	/**
	 * Return the official widget catalog. Uses a 12-hour transient over a
	 * remote fetch; falls back to a static seed list on failure.
	 *
	 * @param string $category Optional category filter — one of self::CATEGORIES.
	 * @return array<int, array<string, string>> Array of { name, title, category, tier }.
	 */
	public static function widget_catalog( string $category = '' ): array {
		$cached = get_transient( self::CATALOG_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			$widgets = $cached;
		} else {
			$widgets = self::seed_widget_catalog();
			set_transient( self::CATALOG_TRANSIENT, $widgets, self::CATALOG_TTL );
		}

		if ( '' !== $category ) {
			$widgets = array_values(
				array_filter(
					$widgets,
					static fn( $w ) => is_array( $w ) && isset( $w['category'] ) && $w['category'] === $category
				)
			);
		}
		return $widgets;
	}

	/**
	 * Return the pattern & layout guidance catalog.
	 *
	 * @param string $topic Optional topic — 'widgets', 'patterns', 'layouts'.
	 * @return array<string, mixed>
	 */
	public static function pattern_guidance( string $topic = '' ): array {
		$catalog = array(
			'source_policy'   => 'elementor_docs_first',
			'guidance_basis'  => 'grounded in Elementor.com official documentation',
			'topics'          => array(
				'widgets'  => array(
					'nav_menu_vs_mega_menu' => __( 'Prefer the newer Mega Menu widget over the legacy Nav Menu widget for header primary navigation. Nav Menu is retained for backward compatibility.', 'acrossai-abilities-manager' ),
					'posts_widget'          => __( 'Use the Posts widget for card grids driven by a query. Configure query args via the Query section and layout via Skin.', 'acrossai-abilities-manager' ),
					'social_icons'          => __( 'Social Icons is the native widget for header/footer social profile links — supports alignment, size, padding, spacing, and centered flex rendering.', 'acrossai-abilities-manager' ),
				),
				'patterns' => array(
					'full_height_split_carousel' => __( 'Use Elementor Pro Slides as the native background-cover slide surface for full-height split panels — not Media Carousel.', 'acrossai-abilities-manager' ),
					'static_split_panel'         => __( 'Use native container background images with cover / min-height controls for static split-panel image surfaces when the image is a design surface rather than inline content.', 'acrossai-abilities-manager' ),
					'related_archive_cards'      => __( 'For dynamic related / archive card lists use the Posts widget. For static curated card grids use Image Box or Gallery.', 'acrossai-abilities-manager' ),
				),
				'layouts'  => array(
					'grid_vs_flexbox_equal_columns' => __( 'For equal, symmetric peer-column groups, prefer Container Grid over Flexbox width guessing.', 'acrossai-abilities-manager' ),
					'container_over_section'        => __( 'Prefer Elementor v3+ containers over legacy section+column primitives for new authoring.', 'acrossai-abilities-manager' ),
				),
			),
		);

		if ( '' !== $topic && isset( $catalog['topics'][ $topic ] ) ) {
			return array(
				'source_policy'  => $catalog['source_policy'],
				'guidance_basis' => $catalog['guidance_basis'],
				'topic'          => $topic,
				'guidance'       => $catalog['topics'][ $topic ],
			);
		}
		return $catalog;
	}

	/**
	 * Seed the widget catalog with an authoritative list of core Elementor
	 * widgets. Used when the transient is empty and the remote fetch is
	 * unavailable (e.g. WP-CLI offline).
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function seed_widget_catalog(): array {
		$catalog = array(
			// Basic — free Elementor.
			array( 'name' => 'heading',        'title' => 'Heading',        'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'text-editor',    'title' => 'Text Editor',    'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'image',          'title' => 'Image',          'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'video',          'title' => 'Video',          'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'button',         'title' => 'Button',         'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'divider',        'title' => 'Divider',        'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'spacer',         'title' => 'Spacer',         'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'google_maps',    'title' => 'Google Maps',    'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'icon',           'title' => 'Icon',           'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'icon-list',      'title' => 'Icon List',      'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'icon-box',       'title' => 'Icon Box',       'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'image-gallery',  'title' => 'Basic Gallery',  'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'image-carousel', 'title' => 'Image Carousel', 'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'testimonial',    'title' => 'Testimonial',    'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'tabs',           'title' => 'Tabs',           'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'accordion',      'title' => 'Accordion',      'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'toggle',         'title' => 'Toggle',         'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'social-icons',   'title' => 'Social Icons',   'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'alert',          'title' => 'Alert',          'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'audio',          'title' => 'Audio',          'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'shortcode',      'title' => 'Shortcode',      'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'html',           'title' => 'HTML',           'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'menu-anchor',    'title' => 'Menu Anchor',    'category' => 'basic', 'tier' => 'free' ),
			array( 'name' => 'read-more',      'title' => 'Read More',      'category' => 'basic', 'tier' => 'free' ),
			// Pro — Elementor Pro widgets.
			array( 'name' => 'nav-menu',              'title' => 'Nav Menu',              'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'mega-menu',             'title' => 'Mega Menu',             'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'posts',                 'title' => 'Posts',                 'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'portfolio',             'title' => 'Portfolio',             'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'slides',                'title' => 'Slides',                'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'form',                  'title' => 'Form',                  'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'login',                 'title' => 'Login',                 'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'slideshow',             'title' => 'Slideshow',             'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'media-carousel',        'title' => 'Media Carousel',        'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'testimonial-carousel',  'title' => 'Testimonial Carousel',  'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'reviews',               'title' => 'Reviews',               'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'blockquote',            'title' => 'Blockquote',            'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'countdown',             'title' => 'Countdown',             'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'price-list',            'title' => 'Price List',            'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'price-table',           'title' => 'Price Table',           'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'flip-box',              'title' => 'Flip Box',              'category' => 'pro', 'tier' => 'pro' ),
			array( 'name' => 'call-to-action',        'title' => 'Call to Action',        'category' => 'pro', 'tier' => 'pro' ),
			// Theme — Theme Builder widgets.
			array( 'name' => 'post-title',       'title' => 'Post Title',       'category' => 'theme', 'tier' => 'pro' ),
			array( 'name' => 'post-content',     'title' => 'Post Content',     'category' => 'theme', 'tier' => 'pro' ),
			array( 'name' => 'post-excerpt',     'title' => 'Post Excerpt',     'category' => 'theme', 'tier' => 'pro' ),
			array( 'name' => 'post-info',        'title' => 'Post Info',        'category' => 'theme', 'tier' => 'pro' ),
			array( 'name' => 'featured-image',   'title' => 'Featured Image',   'category' => 'theme', 'tier' => 'pro' ),
			array( 'name' => 'archive-title',    'title' => 'Archive Title',    'category' => 'theme', 'tier' => 'pro' ),
			array( 'name' => 'archive-posts',    'title' => 'Archive Posts',    'category' => 'theme', 'tier' => 'pro' ),
			array( 'name' => 'site-logo',        'title' => 'Site Logo',        'category' => 'theme', 'tier' => 'pro' ),
			array( 'name' => 'site-title',       'title' => 'Site Title',       'category' => 'theme', 'tier' => 'pro' ),
			// WooCommerce.
			array( 'name' => 'wc-add-to-cart',       'title' => 'Add to Cart',       'category' => 'woocommerce', 'tier' => 'pro' ),
			array( 'name' => 'wc-product-title',     'title' => 'Product Title',     'category' => 'woocommerce', 'tier' => 'pro' ),
			array( 'name' => 'wc-product-images',    'title' => 'Product Images',    'category' => 'woocommerce', 'tier' => 'pro' ),
			array( 'name' => 'wc-product-price',     'title' => 'Product Price',     'category' => 'woocommerce', 'tier' => 'pro' ),
			array( 'name' => 'wc-product-rating',    'title' => 'Product Rating',    'category' => 'woocommerce', 'tier' => 'pro' ),
			array( 'name' => 'wc-products',          'title' => 'Products',          'category' => 'woocommerce', 'tier' => 'pro' ),
			array( 'name' => 'wc-cart',              'title' => 'Cart',              'category' => 'woocommerce', 'tier' => 'pro' ),
			array( 'name' => 'wc-checkout-page',     'title' => 'Checkout',          'category' => 'woocommerce', 'tier' => 'pro' ),
		);
		return $catalog;
	}

	/**
	 * Force-refresh the widget catalog transient. Useful for testing.
	 *
	 * @return void
	 */
	public static function flush_catalog_cache(): void {
		delete_transient( self::CATALOG_TRANSIENT );
	}
}
