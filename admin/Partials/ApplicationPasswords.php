<?php
/**
 * Application Passwords integration for per-server access tokens.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Per-server Application Passwords manager.
 *
 * Minimal scope for Phase 2: create + list endpoints + render_for_server()
 * for the Tokens tab. The source repo's MCPClient-dependent get_client_config
 * endpoint is deferred — it requires the `Includes\MCPClients\*` namespace
 * (7 client classes) which is out of US3 scope and tracked for a later phase.
 *
 * Preserves Constitution §III bullet 7 hashed-storage contract: passwords are
 * shown once on creation and never persisted in plaintext (WordPress core's
 * WP_Application_Passwords API handles that).
 *
 * Constitution: singleton + private __construct + zero add_action/add_filter.
 * REST routes wired by Includes\Main::define_admin_hooks().
 */
class ApplicationPasswords {

	const REST_NAMESPACE  = 'acrossai-mcp-manager/v1';
	const APP_NAME_PREFIX = 'AcrossAI MCP Manager';

	/** @var ApplicationPasswords|null */
	protected static $_instance = null;

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {
		// NO add_action / add_filter — wired by Includes\Main::define_admin_hooks().
	}

	/**
	 * Register the REST routes (wired on `rest_api_init`).
	 */
	public function register_rest_routes(): void {
		$admin_only_perm = static function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route(
			self::REST_NAMESPACE,
			'/generate-app-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_app_password' ),
				'permission_callback' => $admin_only_perm,
				'args'                => array(
					'server_id' => array(
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					// F073+ — optional caller-supplied app-password name so the
					// wizard can label wizard-generated tokens (e.g. "Quick Edit -
					// Claude Desktop") distinct from tab-generated ones. Sanitised
					// to text; empty falls back to build_app_name(server_id).
					'name'      => array(
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/list-app-passwords',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_app_passwords' ),
				'permission_callback' => $admin_only_perm,
			)
		);
	}

	/**
	 * POST /acrossai-mcp-manager/v1/generate-app-password
	 *
	 * Body: { server_id?: int }
	 *
	 * @param \WP_REST_Request $request Incoming REST request with `server_id` and optional `name` params.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate_app_password( \WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new \WP_Error(
				'not_supported',
				__( 'Application Passwords are not supported on this WordPress version.', 'acrossai-mcp-manager' ),
				array( 'status' => 501 )
			);
		}

		$server_id    = (int) $request->get_param( 'server_id' );
		$current_user = wp_get_current_user();

		// F073+ — honour caller-supplied name when non-empty; otherwise fall
		// back to the deterministic per-server name so the Tokens tab keeps
		// its historical labelling.
		$custom_name = (string) $request->get_param( 'name' );
		$app_name    = '' !== trim( $custom_name )
			? $custom_name
			: $this->build_app_name( $server_id );

		$result = \WP_Application_Passwords::create_new_application_password(
			$current_user->ID,
			array( 'name' => $app_name )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		[ $password, $app_details ] = $result;

		return rest_ensure_response(
			array(
				'success'  => true,
				'password' => $password,
				'username' => $current_user->user_login,
				'app_id'   => $app_details['uuid'] ?? '',
				'message'  => __( 'Application Password created. Store it safely — it is shown only once.', 'acrossai-mcp-manager' ),
			)
		);
	}

	/**
	 * GET /acrossai-mcp-manager/v1/list-app-passwords
	 *
	 * @return \WP_REST_Response
	 */
	public function list_app_passwords(): \WP_REST_Response {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return rest_ensure_response(
				array(
					'success'   => true,
					'passwords' => array(),
				)
			);
		}

		$user_id   = get_current_user_id();
		$all       = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		$prefix    = self::APP_NAME_PREFIX;
		$passwords = array_values(
			array_filter(
				$all,
				static function ( $pwd ) use ( $prefix ) {
					return isset( $pwd['name'] ) && false !== strpos( (string) $pwd['name'], $prefix );
				}
			)
		);

		return rest_ensure_response(
			array(
				'success'   => true,
				'passwords' => $passwords,
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Server-edit Tokens tab renderer.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render the Tokens tab body for one server. Called from Settings.
	 *
	 * Lists existing AcrossAI Application Passwords (filtered by name prefix)
	 * + a button that POSTs to /generate-app-password via fetch().
	 *
	 * @param int $server_id Server row ID being edited.
	 */
	public function render_for_server( int $server_id ): void {
		$user_id  = get_current_user_id();
		$has_api  = class_exists( 'WP_Application_Passwords' );
		$generate = esc_url( rest_url( self::REST_NAMESPACE . '/generate-app-password' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );

		echo '<div class="acrossai-tokens-tab">';

		if ( ! $has_api ) {
			echo '<div class="notice notice-warning inline"><p>';
			esc_html_e( 'Application Passwords are not supported on this WordPress version.', 'acrossai-mcp-manager' );
			echo '</p></div></div>';
			return;
		}

		$existing = $has_api ? \WP_Application_Passwords::get_user_application_passwords( $user_id ) : array();
		$existing = array_values(
			array_filter(
				$existing,
				static function ( $pwd ) {
					return isset( $pwd['name'] )
						&& false !== strpos( (string) $pwd['name'], self::APP_NAME_PREFIX );
				}
			)
		);

		echo '<p>';
		esc_html_e( 'Generate an Application Password to grant an MCP client access to this server. The plaintext password is shown only once.', 'acrossai-mcp-manager' );
		echo '</p>';

		printf(
			'<p><button type="button" class="button button-primary acrossai-generate-token" data-server-id="%d" data-endpoint="%s" data-nonce="%s">%s</button> <span class="acrossai-token-output" style="display:none; margin-left:1em;"></span></p>',
			(int) $server_id,
			esc_attr( $generate ),
			esc_attr( $nonce ),
			esc_html__( 'Generate New Application Password', 'acrossai-mcp-manager' )
		);

		echo '<h3>' . esc_html__( 'Existing Application Passwords', 'acrossai-mcp-manager' ) . '</h3>';
		if ( empty( $existing ) ) {
			echo '<p><em>' . esc_html__( 'No Application Passwords created by this plugin yet.', 'acrossai-mcp-manager' ) . '</em></p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>'
				. esc_html__( 'Name', 'acrossai-mcp-manager' )
				. '</th><th>' . esc_html__( 'Created', 'acrossai-mcp-manager' )
				. '</th><th>' . esc_html__( 'Last Used', 'acrossai-mcp-manager' )
				. '</th></tr></thead><tbody>';
			foreach ( $existing as $pwd ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( (string) ( $pwd['name'] ?? '' ) ),
					esc_html( isset( $pwd['created'] ) ? gmdate( 'Y-m-d H:i', (int) $pwd['created'] ) : '—' ),
					esc_html( isset( $pwd['last_used'] ) && $pwd['last_used'] ? gmdate( 'Y-m-d H:i', (int) $pwd['last_used'] ) : '—' )
				);
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Build the Application Password display name for a server.
	 *
	 * Falls back to the bare prefix when no server row matches.
	 *
	 * @param int $server_id Server row ID; 0 yields the generic prefix.
	 * @return string
	 */
	private function build_app_name( int $server_id ): string {
		if ( $server_id <= 0 ) {
			return self::APP_NAME_PREFIX;
		}
		$rows = Query::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			return self::APP_NAME_PREFIX;
		}
		return sprintf( '%s (%s)', self::APP_NAME_PREFIX, $rows[0]->server_name );
	}
}
