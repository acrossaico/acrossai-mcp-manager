<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Comments
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Comments;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WP ability category used by all Comment abilities.
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
			'acrossai-comments',
			array(
				'label'       => __( 'Acrossai Abilities Manager â Comments', 'acrossai-abilities-manager' ),
				'description' => __( 'Abilities for managing comments: CRUD, moderation (approve / hold / spam), and meta.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
