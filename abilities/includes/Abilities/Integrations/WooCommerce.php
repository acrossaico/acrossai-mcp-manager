<?php
/**
 * The email delivery toolset declaration.
 *
 * The mail plugin registers its own abilities under `wp-mail-smtp`, so the prefix is CLAIMED and they
 * are adopted into this tab rather than re-registered. Bare, with no trailing slash: the tagger
 * matches the segment before the first slash (#209).
 *
 * The mixed shape: our abilities are ours, and WooCommerce's own are ADOPTED. It registers seven
 * under `woocommerce/` unconditionally, carrying `meta.mcp = ['public' => true, 'type' => 'tool']`
 * — built for a generic adapter like this one — so the prefix is claimed and they arrive filed here
 * without a release from us. Bare, no trailing slash (#209).
 *
 * Our own abilities are `store/*` and can never be anything else: WooCommerce reserves the whole
 * `woocommerce/` prefix and will unregister a shadowing registration and take the name back.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups the Email Delivery abilities.
 */
final class WooCommerce implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal Base_Store_Ability::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'woocommerce';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'WooCommerce', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'WooCommerce store: the catalogue, prices, stock, orders, customers and store health. This group holds abilities from two sources - WooCommerce own seven for querying products and orders, creating and updating products and changing order status, alongside ours for everything they leave out. One thing to know before writing. WooCommerce keeps derived copies of its data that the shop actually reads: a product lookup table behind SKU search and price sorting, a separate price field derived from the regular and sale prices, and cached on-sale and featured lists that hold for thirty days. Orders on a modern store are not in the posts table at all. Writing any of it through the Content or Database tools changes the record while leaving those copies stale, so the shop carries on using the old values and the call reports success - and saving the product correctly afterwards does not repair it, because WooCommerce sees the field already changed and concludes nothing happened. Run store/get-store-status when a change appears not to have taken effect. Note that the two sources carry different permissions: WooCommerce own abilities admit a shop manager, ours require an administrator. Payment gateway settings and keys are never readable here.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * The prefix this group adopts.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array( 'woocommerce' );
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return \AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Store_Guard::is_available();
	}
}
