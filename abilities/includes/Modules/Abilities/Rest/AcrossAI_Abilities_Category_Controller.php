<?php
/**
 * REST sub-controller: ability categories.
 *
 * Handles:
 *   GET /abilities/categories — returns registered WP ability categories
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities/Rest
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Rest;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles the categories discovery endpoint.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Category_Controller {

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Abilities_Category_Controller|null
	 */
	protected static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Abilities_Category_Controller
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
	 * Register REST routes owned by this controller.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/abilities/categories',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_categories' ),
					'permission_callback' => array( AcrossAI_Abilities_Rest_Controller::instance(), 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Handle GET /abilities/categories.
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_categories( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! function_exists( 'wp_get_ability_categories' ) ) {
			return new \WP_Error(
				'rest_not_supported',
				__( 'Abilities API categories not available.', 'acrossai-abilities-manager' ),
				array( 'status' => 501 )
			);
		}

		$raw        = wp_get_ability_categories();
		$categories = array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $slug => $data ) {
				$label = '';
				if ( is_array( $data ) && isset( $data['label'] ) ) {
					$label = (string) $data['label'];
				} elseif ( is_object( $data ) && isset( $data->label ) ) {
					$label = (string) $data->label;
				} elseif ( is_string( $data ) ) {
					$label = $data;
				}
				$categories[] = array(
					'slug'  => (string) $slug,
					'label' => $label,
				);
			}
		}

		return rest_ensure_response( $categories );
	}
}
