<?php
/**
 * REST API orchestrator for the Abilities module.
 *
 * Thin orchestrator: owns REST_NAMESPACE, delegates register_routes() to each
 * sub-controller, and provides the shared check_permission() callback.
 *
 * All endpoints in this module require manage_options (PD-001 decision: includes
 * exposure endpoints — no separate permission tier in Spec 009).
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities/Rest
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Rest;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Thin orchestrator wired to rest_api_init in includes/Main.php.
 *
 * Responsibilities:
 *  - Expose REST_NAMESPACE constant consumed by all sub-controllers.
 *  - Delegate register_routes() to each per-domain sub-controller.
 *  - Own check_permission() referenced by every route as the shared callback.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Rest_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'acrossai/v1';

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Abilities_Rest_Controller|null
	 */
	protected static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Abilities_Rest_Controller
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
	 * Delegate route registration to each per-domain sub-controller.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function register_routes(): void {
		// Literal-segment routes first. AcrossAI_Abilities_Write_Controller registers
		// '/abilities/(?P<slug>[^/]+)', and WP_REST_Server::dispatch() takes the first regex match in
		// registration order — so any literal registered after it is unreachable
		// (BUG-REST-ROUTE-ORDER-LITERAL-BEFORE-WILDCARD).
		AcrossAI_Abilities_Read_Controller::instance()->register_literal_routes();

		AcrossAI_Abilities_Category_Controller::instance()->register_routes();
		AcrossAI_Abilities_Write_Controller::instance()->register_routes();
		AcrossAI_Abilities_Read_Controller::instance()->register_routes();
		AcrossAI_Abilities_Exposure_Controller::instance()->register_routes();
		AcrossAI_Quick_Connect_Controller::instance()->register_routes();
	}

	/**
	 * Verify manage_options capability and a valid REST nonce.
	 *
	 * Referenced by every sub-controller route as:
	 *   array( AcrossAI_Abilities_Rest_Controller::instance(), 'check_permission' )
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return bool|\WP_Error
	 */
	public function check_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage abilities.', 'acrossai-abilities-manager' ),
				array( 'status' => 403 )
			);
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Nonce verification failed.', 'acrossai-abilities-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
