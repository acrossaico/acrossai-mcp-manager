<?php
/**
 * Feature 136 — the ability category for version targeting.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Versions
 * @since      0.0.45
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Versions;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `acrossai-versions`.
 *
 * **Registered unconditionally, and that is deliberate.** The abilities in this suite are registered
 * whether or not WP Rollback or WP Downgrade is installed, because on a site without them the
 * abilities are the thing that says so. A category guarded on a host plugin while the abilities are
 * unconditional is exactly the shape that silently dropped all seventeen backup abilities (#258):
 * WordPress rejects an ability whose category does not exist, with no runtime signal anyone sees.
 *
 * @since 0.0.45
 */
class Category_Registrar {

	/**
	 * Singleton.
	 *
	 * @since 0.0.45
	 * @var   self|null
	 */
	private static $instance = null;

	/**
	 * The category slug every ability in this suite claims.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const CATEGORY = 'acrossai-versions';

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Return the singleton instance.
	 *
	 * @since  0.0.45
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
	 * @since  0.0.45
	 * @return void
	 */
	public function register(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Acrossai Abilities Manager — Versions', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for putting a plugin, a theme or WordPress core on a chosen version: listing what WordPress.org published, checking whether a move is safe, rolling a plugin or theme to an earlier release through WP Rollback, and pinning a core version through WP Downgrade.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
