<?php
/**
 * Issue #202 — the opt-in that asks Yoast to register its own abilities off production.
 *
 * Separate from {@see Yoast_Seo}, which declares the toolset and is constructed inside
 * `AcrossAI_Toolset_Integrations::built_in()`. That method runs behind a filter and may be called
 * more than once per request; this class hooks from its constructor, so folding the two together
 * would attach the enable filter and push the display rows twice — the hazard already documented on
 * ACF's absence from `built_in()`. One job each, constructed once from `Main::define_public_hooks()`.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Integrations\AcrossAI_Integration_Ability_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an operator switch Yoast's own abilities on where Yoast itself will not.
 *
 * This could not ship before #204. Yoast registers from `Loader::load_integrations()` on `init`,
 * later than the Library Processor's `wp_abilities_api_init` P5 — so while display-only rows were
 * still registered as real abilities, ours claimed the `yoast-seo/` names first and Yoast's real
 * registrations were refused as duplicates. Measured: five dead placeholders, and executing one
 * returned `Permission denied`. With the rows no longer registered, the names are free.
 *
 * @since 0.0.34
 */
final class Yoast_Seo_Opt_In extends AcrossAI_Integration_Ability_Base {

	/**
	 * Matches Yoast_Seo::TAB_GROUP so the rows land in the same tab as the rest.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return Yoast_Seo::TAB_GROUP;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function label(): string {
		return __( 'Yoast SEO (its own post abilities)', 'acrossai-abilities-manager' );
	}

	/**
	 * Two stable public symbols rather than one, per SEC-002.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function is_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) && class_exists( '\WPSEO_Options' );
	}

	/**
	 * Tell Yoast this site may build indexables, which is what its abilities are gated on.
	 *
	 * Yoast registers its abilities behind `Should_Index_Indexables_Conditional`, which resolves to
	 * `is_production_mode()` (indexable-helper.php:210). On any local or staging install they simply
	 * do not exist — no error and no notice, so an operator can have Yoast active, a Yoast tab on
	 * screen, and none of Yoast's own abilities.
	 *
	 * The gate is deliberate on Yoast's part: indexables record permalinks, and building them on a
	 * staging copy bakes in URLs that are wrong for production. So this is an opt-in an operator
	 * turns on knowingly for a working copy, never a default — which is why it is a toggle and why
	 * the label says whose abilities it enables.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	protected function enable_filter(): void {
		add_filter( 'Yoast\WP\SEO\should_index_indexables', '__return_true' );
	}

	/**
	 * Yoast's own abilities, namespaced `yoast-seo/`.
	 *
	 * Display-only rows: they describe what the toggle enables and are never registered as abilities
	 * (#204). Verified against the live registry by Test_Integration_Row_Accuracy whenever Yoast is
	 * active and switched on.
	 *
	 * Yoast declares five, but not all five register even with the gate open — the others carry
	 * further conditions of their own. The two listed here are the ones measured as actually
	 * appearing; listing the other three would repeat the mistake that made the ACF count wrong.
	 *
	 * @since  0.0.34
	 * @return array<int, array{slug: string, label: string, description: string}>
	 */
	protected function abilities(): array {
		return array(
			array(
				'slug'        => 'yoast-seo/get-seo-scores',
				'label'       => __( 'Get SEO Scores', 'acrossai-abilities-manager' ),
				'description' => __( 'SEO scores for the most recently modified posts, from Yoast itself.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'        => 'yoast-seo/get-readability-scores',
				'label'       => __( 'Get Readability Scores', 'acrossai-abilities-manager' ),
				'description' => __( 'Readability scores for the most recently modified posts, from Yoast itself.', 'acrossai-abilities-manager' ),
			),
		);
	}
}
