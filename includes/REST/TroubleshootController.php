<?php
/**
 * Feature 101 — connection troubleshooter REST controller.
 *
 * One route:
 *
 *   POST /acrossai-mcp-manager/v1/troubleshoot/run
 *
 * POST rather than GET despite reading nothing: the handler makes a dozen
 * outbound HTTP requests, and a GET invites a browser, a prefetcher or a
 * monitoring tool to fire it unasked.
 *
 * Wired via `includes/Main.php::define_admin_hooks()` per A1.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\REST
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\REST;

use AcrossAI_MCP_Manager\Includes\Troubleshoot\CheckRunner;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\SupportReport;
use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the diagnostics and returns them as data.
 */
final class TroubleshootController {

	/** REST namespace shared with the rest of the plugin. */
	private const NS = 'acrossai-mcp-manager/v1';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private — hook wiring lives in Main::define_admin_hooks() per A1. */
	private function __construct() {}

	/**
	 * Register the route on `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/troubleshoot/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/**
	 * Administrators only.
	 *
	 * The report names the security and caching layers in front of the site
	 * and quotes response headers back. That is reconnaissance in the wrong
	 * hands, and useless in any hands that cannot act on it.
	 *
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Run every check.
	 *
	 * @return WP_REST_Response
	 */
	public function run(): WP_REST_Response {
		$results = CheckRunner::run_all();

		$response = new WP_REST_Response(
			array(
				'checks' => $results,
				'report' => SupportReport::build( $results ),
			)
		);

		// The payload describes this site's security posture, and it is also
		// a point-in-time snapshot — a cached copy would be both a leak and a
		// lie about the state of the site.
		return CacheHeaders::apply_to_rest_response( $response );
	}
}
