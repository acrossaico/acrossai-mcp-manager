<?php
/**
 * REST sub-controller: MCP/REST exposure collections.
 *
 * Handles:
 *   GET /abilities/exposures/{type}  — type ∈ tools | resources | prompts
 *
 * Security contract:
 *   - Admin-only (manage_options), same gate as all other Spec 009 endpoints.
 *
 * Per Feature 034 spec.md "Security posture change" — fail-closed MCP allowlist
 * enforcement deleted; re-implemented by acrossai-mcp-manager via
 * acrossai_abilities.form.extra_sections + its own enforcement at the MCP
 * exposure boundary.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities/Rest
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Rest;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Query;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Abilities_Formatter;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Handles MCP exposure collection endpoints.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Exposure_Controller {

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Abilities_Exposure_Controller|null
	 */
	protected static $instance = null;

	/**
	 * DB query instance.
	 *
	 * @var AcrossAI_Abilities_Query
	 */
	private $db_query;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Abilities_Exposure_Controller
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->db_query = AcrossAI_Abilities_Query::instance();
	}

	/**
	 * Register REST routes owned by this controller.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/abilities/exposures/(?P<type>tools|resources|prompts)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_exposures' ),
					'permission_callback' => array( AcrossAI_Abilities_Rest_Controller::instance(), 'check_permission' ),
					'args'                => array(
						'type' => array(
							'type'              => 'string',
							'required'          => true,
							'enum'              => array( 'tools', 'resources', 'prompts' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Handle GET /abilities/exposures/{type}.
	 *
	 * Maps URL type (tools/resources/prompts) to mcp_type (tool/resource/prompt).
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_exposures( \WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );

		// Map plural URL segment to mcp_type singular value.
		$type_map = array(
			'tools'     => 'tool',
			'resources' => 'resource',
			'prompts'   => 'prompt',
		);

		if ( ! isset( $type_map[ $type ] ) ) {
			return new \WP_Error( 'rest_invalid_param', __( 'Invalid exposure type.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}

		$mcp_type = $type_map[ $type ];
		$rows     = $this->db_query->by_mcp_type( $mcp_type, true );

		return rest_ensure_response( AcrossAI_Abilities_Formatter::format_exposure_collection( $rows ) );
	}
}
