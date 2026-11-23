<?php
/**
 * Category_Registrar for the Toolset dispatchers.
 *
 * Every Toolset declares this category, and it deliberately holds nothing
 * else. Sharing a category with the abilities a Toolset dispatches to would
 * mean the operator's setting for that category applied to the Toolset
 * itself — which is what forced four separate requirements out of the earlier
 * category-based design. See DEC-ABILITY-GROUP-IDENTIFIER-LOAD-BEARING.
 *
 * Follows the house pattern used by the other 25 folders rather than folding
 * the registration into the abstract class, because
 * `Test_Category_Registrar_Wiring` guards that convention in both directions —
 * and a category registrar that exists but is never wired makes every ability
 * claiming it silently cease to exist (BUG-UNWIRED-CATEGORY-REGISTRAR).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Toolset
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Toolset;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the category shared by every Toolset.
 */
final class Category_Registrar {

	/** @var self|null */
	protected static $instance = null;

	/**
	 * Private constructor — access via instance().
	 */
	private function __construct() {}

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the ability category with the WP Abilities API.
	 *
	 * @return void
	 */
	public function register(): void {
		wp_register_ability_category(
			Base_Toolset_Ability::CATEGORY,
			array(
				'label'       => __( 'Acrossai Abilities Manager — Toolsets', 'acrossai-abilities-manager' ),
				'description' => __( 'Dispatcher abilities, one per ability group. Each answers three questions about its own group — what is in it, what one ability needs, and run one — so an assistant works through the catalogue a group at a time instead of receiving all of it at once. These are infrastructure rather than abilities in their own right.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
