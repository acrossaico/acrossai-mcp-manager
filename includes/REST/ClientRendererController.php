<?php
/**
 * REST controller for public/Renderers/ Application Password generation.
 *
 * Feature 013. POST /wp-json/acrossai-mcp-manager/v1/generate-app-password.
 *
 * Security-critical permission_callback enforces THREE invariants:
 *   1. is_user_logged_in()  — no anonymous access.
 *   2. get_current_user_id() === (int) $body['user_id']  — SEC-013-002 App
 *      Password lockdown. Even a manage_options-holding admin cannot mint an
 *      Application Password for a different user via this endpoint. Prevents
 *      admin-impersonation in third-party embedded contexts (e.g. BuddyBoss
 *      admin viewing another user's profile).
 *   3. wp_verify_nonce() against a context-bound action name  — SEC-013-001
 *      cross-context nonce replay defense. Nonce action name binds
 *      $server_id AND context slug; a nonce minted for context='admin' MUST
 *      NOT validate against a POST with context='buddyboss-profile'.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes/REST
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 *
 * See DEC-CLIENT-RENDERER-PUBLIC-API.
 */

namespace AcrossAI_MCP_Manager\Includes\REST;

use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;
use WP_Application_Passwords;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * REST controller. Singleton per A2.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes/REST
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 */
final class ClientRendererController {

	private const REST_NAMESPACE = 'acrossai-mcp-manager/v1';
	private const REST_ROUTE     = '/generate-app-password';

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.6
	 * @var ClientRendererController|null
	 */
	protected static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @since 0.0.6
	 * @return self
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
	 * @since 0.0.6
	 */
	private function __construct() {}

	/**
	 * Registers the REST route. Called by Main::define_public_hooks() Loader.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_generate' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'server_id'   => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'client_slug' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'context'     => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'user_id'     => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission callback. Enforces THREE invariants — see class docblock.
	 *
	 * @since 0.0.6
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error True on success, WP_Error(403) on any mismatch.
	 */
	public function permission_check( WP_REST_Request $request ) {
		// (1) Logged-in check.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		// (2) SEC-013-002 App Password lockdown — user_id must equal current.
		$body_user_id = absint( (string) $request->get_param( 'user_id' ) );
		if ( get_current_user_id() !== $body_user_id ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Application Passwords can only be generated for the current user.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		// (3) SEC-013-001 cross-context nonce replay defense — action name binds context slug.
		$server_id      = absint( (string) $request->get_param( 'server_id' ) );
		$client_slug    = sanitize_key( (string) $request->get_param( 'client_slug' ) );
		$context_slug   = sanitize_key( (string) $request->get_param( 'context' ) );
		$expected_nonce = 'acrossai_mcp_render_' . $client_slug . '_' . $server_id . '_' . $context_slug;
		$nonce          = (string) $request->get_header( 'X-WP-Nonce' );

		if ( false === wp_verify_nonce( $nonce, $expected_nonce ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Nonce verification failed.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Registers the 3 shortcodes + the action-hook dispatcher on init.
	 *
	 * Called from Main::define_public_hooks() Loader per A1. The `init`
	 * action fires AFTER F011's Main::load_hooks() has booted BerlinDB
	 * Tables per DEC-BERLINDB-TABLE-REQUEST-BOOT, so Renderer::render()'s
	 * MCPServerQuery calls are safe.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @return void
	 */
	public function register_shortcodes_and_actions(): void {
		add_shortcode(
			'acrossai_mcp_npm_block',
			array( \AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock::class, 'shortcode' )
		);
		add_shortcode(
			'acrossai_mcp_clients_block',
			array( \AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock::class, 'shortcode' )
		);
		add_action(
			'acrossai_mcp_render_client_block',
			array( $this, 'dispatch_render_action' ),
			10,
			3
		);
	}

	/**
	 * Dispatcher for do_action( 'acrossai_mcp_render_client_block', ... ).
	 *
	 * Third-party plugins use this hook to render a block without knowing
	 * the concrete Renderer class name. Unknown $renderer_slug values are
	 * silently no-op'd per FR-015.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @param string $renderer_slug Renderer slug: 'npm', 'clients'.
	 * @param int    $server_id     MCP server row ID.
	 * @param array  $context       Optional context array.
	 * @return void
	 */
	public function dispatch_render_action( string $renderer_slug, int $server_id, array $context = array() ): void {
		$map  = array(
			'npm'     => \AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock::class,
			'clients' => \AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock::class,
		);
		$slug = sanitize_key( $renderer_slug );
		if ( ! isset( $map[ $slug ] ) ) {
			return;
		}
		$fqn = $map[ $slug ];
		$fqn::instance()->render( $server_id, $context );
	}

	/**
	 * Handles the actual App Password generation. Runs only after
	 * permission_check() has passed.
	 *
	 * Uses get_current_user_id() as the target user — NEVER the body param —
	 * as defense-in-depth: even if permission_check() were somehow bypassed,
	 * this callback still refuses to mint for a different user.
	 *
	 * @since 0.0.6
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_generate( WP_REST_Request $request ) {
		$user_id     = get_current_user_id();
		$client_slug = sanitize_key( (string) $request->get_param( 'client_slug' ) );
		$server_id   = absint( (string) $request->get_param( 'server_id' ) );

		// F015 Access Control connection-time gate — reject App Password generation for
		// users whose roles are not in the allow-list. Without this, a user with the WP
		// `create_application_passwords` cap but no MCP AC permission could generate a
		// password that authenticates successfully at HTTP Basic but then 403s on every
		// tool call. Matches the OAuth authorize gate on AuthorizationController.
		if ( $server_id > 0 ) {
			$ac = \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance();
			if ( ! $ac->user_has_server_access( $user_id, $server_id ) ) {
				do_action(
					'acrossai_mcp_access_control_denied',
					$user_id,
					(string) $server_id,
					$client_slug,
					'app_password_generate'
				);
				return new WP_Error(
					'acrossai_mcp_access_denied',
					__( 'Your account does not have permission to connect to this MCP server. Contact a site administrator to request access.', 'acrossai-mcp-manager' ),
					array( 'status' => 403 )
				);
			}
		}

		$app_name = sprintf(
			'AcrossAI MCP — %1$s (server #%2$d)',
			$client_slug,
			$server_id
		);

		$created = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => $app_name )
		);

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		// Raw Application Password in response body — MUST NOT cache. See DEC-OAUTH-DONOTCACHEPAGE-PATTERN.
		return CacheHeaders::apply_to_rest_response(
			new WP_REST_Response(
				array(
					'password' => $created[0],
					'app_id'   => $created[1]['uuid'] ?? '',
				),
				201
			)
		);
	}
}
