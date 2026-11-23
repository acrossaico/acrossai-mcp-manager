<?php
/**
 * Library Processor — registers add-on abilities at wp_abilities_api_init P5.
 *
 * Runs before the database Processor at P10, applying the saved keys config to
 * gate which add-on abilities are registered into the WordPress Abilities API.
 *
 * Default behavior when a key is absent from saved config (D6):
 *   - category missing → enabled=true, mode='all'
 *   - slug missing in Specific mode → enabled=false
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage includes/Modules/Library
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Library;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Gates add-on abilities against the saved keys config and registers approved ones.
 *
 * @since 0.1.0
 */
class AcrossAI_Ability_Library_Processor {

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Ability_Library_Processor|null
	 */
	protected static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Ability_Library_Processor
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Register approved add-on abilities into the WordPress Abilities API.
	 *
	 * Wired at wp_abilities_api_init P5 via includes/Main.php.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$definitions = AcrossAI_Ability_Library_Registry::instance()->get_definitions();

		// Feature 102: every definition registers. The per-category gate that used to sit here
		// (is_permitted()) could stop an ability existing at all, which made it invisible to the
		// abilities screen — an operator who switched a category off then searched for one of its
		// abilities was told it did not exist. Availability is now decided solely by the
		// per-ability site_allowed override, which AcrossAI_Ability_Override_Processor enforces by
		// unregistering blocked abilities at wp_abilities_api_init P100001. Same fail-closed
		// outcome, one owner, and the ability stays listed with its reason on screen.
		foreach ( $definitions as $definition ) {
			/*
			 * Issue #204: integration rows describe abilities a HOST plugin owns. They are
			 * display-only — an inert execute callback and a permission callback that returns false
			 * — and registering them would occupy the real name. The registry refuses duplicates
			 * (first registration wins, the second gets _doing_it_wrong and null), so whoever
			 * registers first decides whether an operator gets the real ability or our placeholder.
			 *
			 * Measured before this guard: six ACF names contested on every request, and a Yoast
			 * opt-in that registered five dead placeholders and PREVENTED Yoast's real abilities
			 * existing, because Yoast registers on init — later than this P5.
			 *
			 * They still reach the Library registry, so the Integrations tab keeps listing them and
			 * counting them. What they no longer do is exist as abilities: when the host's switch is
			 * on, its real abilities are registered and listed by the host; when it is off, there is
			 * nothing to list, which is the truth. A card for an ability that cannot run is what
			 * made Force Block and User Access silently no-op on those slugs (#202).
			 */
			if ( isset( $definition['card_variant'] ) && 'integration' === $definition['card_variant'] ) {
				continue;
			}

			wp_register_ability( $definition['name'], $definition['args'] );
		}
	}
}
