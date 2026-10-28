<?php
/**
 * Application Passwords integration for per-server access tokens.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;
use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;
use AcrossAI_MCP_Manager\Includes\Utilities\CredentialNaming;

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

	const REST_NAMESPACE = 'acrossai-mcp-manager/v1';

	/**
	 * Credential name prefix.
	 *
	 * Kept as a class constant for backwards compatibility — it is public API
	 * that companion plugins and tests reference — but the value now lives in
	 * `Includes\Utilities\CredentialNaming` because `Public\Renderers` needs
	 * it too and a public class must not reach into admin (A9).
	 *
	 * @var string
	 */
	const APP_NAME_PREFIX = CredentialNaming::PREFIX;

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
					// F099 — optional client slug, folded into the credential name
					// so the Tokens tab carries provenance for as long as the
					// credential exists (B76). Never trusted for authorisation.
					'client'    => array(
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
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
		$blocker = $this->application_password_blocker();

		if ( $blocker instanceof \WP_Error ) {
			return $blocker;
		}

		$server_id = (int) $request->get_param( 'server_id' );

		// SEC-013-002 / FR-016 — the credential is always minted for the caller.
		// A request-supplied user id is never honoured, here or anywhere.
		$user_id      = get_current_user_id();
		$current_user = wp_get_current_user();

		// D33 / FR-015 — every connection-time credential-issuance surface gates
		// on server access BEFORE issuing. Fails open when the Access Control
		// package is absent (D19), so a site without it keeps working.
		if ( $server_id > 0 && ! AcrossAI_MCP_Access_Control::instance()->user_has_server_access( $user_id, $server_id ) ) {
			/**
			 * Fires when credential issuance is refused by Access Control.
			 *
			 * @param int    $user_id     The refused user.
			 * @param string $server_slug Server slug, or '' when unresolved.
			 * @param string $tool        Unused for this surface; '' by contract.
			 * @param string $context     Distinct per surface — see D33.
			 */
			do_action( 'acrossai_mcp_access_control_denied', $user_id, $this->server_slug( $server_id ), '', 'app_password_generate_prompt' );

			$this->record_issuance( $server_id, '', '', 'denied' );

			return new \WP_Error(
				'forbidden',
				__( 'You do not have access to this MCP server.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		$app_name = $this->build_app_name(
			$server_id,
			(string) $request->get_param( 'client' ),
			(string) $request->get_param( 'name' )
		);

		$result = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => $app_name )
		);

		if ( is_wp_error( $result ) ) {
			// FR-022 — hand-authored message. Core's own error text is not
			// surfaced: it can name internals the operator cannot act on.
			return new \WP_Error(
				'create_failed',
				__( 'The Application Password could not be created. Check that Application Passwords are enabled for your account.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}

		[ $password, $app_details ] = $result;

		$uuid = (string) $app_details['uuid'];

		// FR-027 / SC-C19 — record the issuance with the credential's UUID and
		// name ONLY. The password itself never reaches the recorder: key-name
		// redaction matches 24 patterns and a value outside them is stored as
		// sent, so this is enforced here rather than relied on downstream.
		$this->record_issuance( $server_id, $uuid, $app_name, 'success' );

		$response = rest_ensure_response(
			array(
				'success'  => true,
				'password' => $password,
				'username' => $current_user->user_login,
				'app_id'   => $uuid,
				'message'  => __( 'Application Password created. Store it safely — it is shown only once.', 'acrossai-mcp-manager' ),
			)
		);

		// FR-017 — the body carries a plaintext credential; no cache between
		// here and the browser may retain it.
		return CacheHeaders::apply_to_rest_response( $response );
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
	 * Build the Application Password display name.
	 *
	 * The name is this feature's DURABLE provenance record (B76): it carries the
	 * server, the client and the date, and it lives exactly as long as the
	 * credential does. The activity-log entry written alongside it is a
	 * convenience with ordinary retention, not the system of record.
	 *
	 * The prefix is ALWAYS present. Before F099 a caller-supplied `name`
	 * replaced it outright, which is why wizard-generated credentials
	 * ("Quick Edit - Claude Desktop") were invisible to both the Tokens tab and
	 * `/list-app-passwords` — each filters on that substring. A caller-supplied
	 * label is now appended, never substituted.
	 *
	 * @param int    $server_id   Server row ID; 0 yields the generic prefix.
	 * @param string $client_slug Optional MCP client slug.
	 * @param string $custom_name Optional caller-supplied label, appended.
	 * @return string
	 */
	private function build_app_name( int $server_id, string $client_slug = '', string $custom_name = '' ): string {
		$parts = array( self::APP_NAME_PREFIX );

		$server_name = $this->server_name( $server_id );

		if ( '' !== $server_name ) {
			$parts[] = $server_name;
		}

		if ( '' !== $client_slug ) {
			$parts[] = $client_slug;
		}

		$custom_name = trim( $custom_name );

		if ( '' !== $custom_name ) {
			$parts[] = $custom_name;
		}

		// gmdate() rather than a localised format: this string is parsed by eye
		// across timezones and must not shift meaning with site settings.
		$parts[] = gmdate( 'Y-m-d' );

		return implode( CredentialNaming::SEPARATOR, $parts );
	}

	/**
	 * Resolve a server's display name, or '' when it cannot be resolved.
	 *
	 * @param int $server_id Server row ID.
	 * @return string
	 */
	private function server_name( int $server_id ): string {
		$row = $this->server_row( $server_id );

		return null === $row ? '' : (string) $row->server_name;
	}

	/**
	 * Resolve a server's slug, or '' when it cannot be resolved.
	 *
	 * Used for the Access Control denial signal, which is keyed on slug.
	 *
	 * @param int $server_id Server row ID.
	 * @return string
	 */
	private function server_slug( int $server_id ): string {
		$row = $this->server_row( $server_id );

		return null === $row ? '' : (string) $row->server_slug;
	}

	/**
	 * Fetch one server row.
	 *
	 * @param int $server_id Server row ID.
	 * @return Row|null
	 */
	private function server_row( int $server_id ): ?Row {
		if ( $server_id <= 0 ) {
			return null;
		}

		$rows = Query::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return null;
		}

		$row = reset( $rows );

		return $row instanceof Row ? $row : null;
	}

	/**
	 * Refuse issuance when Application Passwords are unavailable.
	 *
	 * Nothing in this plugin checked availability before F099, so a site that
	 * had switched them off by constant or filter produced a confusing failure
	 * rather than a stated cause. Modelled on Elementor's blocker pattern.
	 *
	 * @return \WP_Error|null WP_Error when blocked, null when issuance may proceed.
	 */
	private function application_password_blocker(): ?\WP_Error {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new \WP_Error(
				'not_supported',
				__( 'Application Passwords are not supported on this WordPress version.', 'acrossai-mcp-manager' ),
				array( 'status' => 501 )
			);
		}

		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			return new \WP_Error(
				'app_passwords_disabled',
				__( 'Application Passwords are switched off for this site, so a connection password cannot be created. An administrator can re-enable them.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		if ( function_exists( 'wp_is_application_passwords_available_for_user' ) && ! wp_is_application_passwords_available_for_user( get_current_user_id() ) ) {
			return new \WP_Error(
				'app_passwords_disabled_for_user',
				__( 'Application Passwords are switched off for your account, so a connection password cannot be created.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		return null;
	}

	/**
	 * Record one credential issuance to the activity log.
	 *
	 * SC-C19: the UUID and the name, never the password. Best-effort — a
	 * logging failure must never cost the operator their credential.
	 *
	 * @param int    $server_id Server the credential was scoped to.
	 * @param string $uuid      Credential UUID; '' on a denial.
	 * @param string $app_name  Credential display name; '' on a denial.
	 * @param string $status    'success' or 'denied'.
	 */
	private function record_issuance( int $server_id, string $uuid, string $app_name, string $status ): void {
		try {
			Recorder::record_request(
				array(
					'event_source'  => ActivityLogConstants::SOURCE_AUTH,
					'server_id'     => $server_id,
					'server_slug'   => $this->server_slug( $server_id ),
					'method'        => 'app-password/issue',
					'status'        => $status,
					'user_id'       => get_current_user_id(),
					'input_preview' => wp_json_encode(
						array(
							'uuid' => $uuid,
							'name' => $app_name,
						)
					),
				)
			);
		} catch ( \Throwable $e ) {
			// Best-effort per the recorder's own contract (A15). Deliberately
			// swallowed: the credential has already been issued and returning
			// an error here would strand the operator.
			return;
		}
	}
}
