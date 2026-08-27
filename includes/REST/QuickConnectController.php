<?php
/**
 * Feature 069 — Quick Connect via AcrossAI wizard REST controller.
 *
 * Registers three routes under the plugin's REST namespace:
 *
 *   GET  /acrossai-mcp-manager/v1/quick-connect/state    — snapshot for the React app
 *   POST /acrossai-mcp-manager/v1/quick-connect/step     — persist per-step scratchpad
 *   POST /acrossai-mcp-manager/v1/quick-connect/complete — clear scratchpad
 *
 * All routes gate on `manage_options` (S2). POSTs additionally require a
 * valid `X-WP-Nonce` header for action `wp_rest` (S1).
 *
 * Error-hygiene invariant (TASK-SEC-003 / T055): every WP_Error returned by
 * any handler in this file MUST use a hand-authored, user-facing message.
 * Raw `$e->getMessage()`, `$wpdb->last_error`, transient key strings, or
 * file paths MUST NEVER appear in the response `message` field. Internal
 * diagnostics MAY be logged via `error_log()` — never surfaced to the client.
 *
 * F017 abilities integration (TASK-SEC-002 / T022): Step 3 "Enable all
 * abilities" DOES NOT round-trip through F017's REST route. Instead we call
 * the underlying service class `MCPServerAbilityQuery::instance()->upsert()`
 * directly for each ability from `wp_get_abilities()`. Single auth check
 * (this controller's outer permission_callback), no internal REST-to-REST
 * nonce lifecycle question, no cross-controller REST-wire coupling. Matches
 * `DEC-ABILITY-OVERRIDE-RESOLUTION` service-call intent.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes/REST
 * @since      0.2.11
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\REST;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver as MCPServerAbilityExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use AcrossAI_MCP_Manager\Includes\Utilities\MCPServerFieldSanitizer;
use AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Quick Connect via AcrossAI wizard REST controller.
 *
 * @since 0.2.11
 */
final class QuickConnectController {

	/**
	 * REST namespace.
	 */
	private const REST_NAMESPACE = 'acrossai-mcp-manager/v1';

	/**
	 * REST route prefix (relative to namespace).
	 */
	private const ROUTE_PREFIX = '/quick-connect';

	/**
	 * Per-user scratchpad transient key prefix. The current user id is
	 * appended at read/write time (data-model.md E1).
	 */
	private const SCRATCHPAD_KEY_PREFIX = 'acrossai_mcp_manager_quick_connect_state_';

	/**
	 * Scratchpad TTL — 30 minutes per FR-026.
	 */
	private const SCRATCHPAD_TTL = 1800;

	/**
	 * Valid step values accepted on POST /step.
	 *
	 * The wizard is a dynamic flow that surfaces between ~5 and ~10 steps
	 * to any given user depending on state. Steps 2, 4, 5, 6, 8-13 are
	 * conditional and skipped at the routing layer when their precondition
	 * is (or isn't) met:
	 *   - Step 2  (server create) — skipped when Step 1 picked an existing server
	 *   - Step 4  (abilities-manager gate) — skipped when the plugin is active
	 *   - Step 5  (abilities picker) — skipped when all abilities are enabled
	 *   - Step 6  (enable endpoint) — skipped when the server is already enabled
	 *   - Step 8  (Pro pitch) — only shown when method=connectors AND pro is missing
	 *   - Step 9  (Pro activate) — only shown when method=connectors AND pro is inactive
	 *   - Step 10 (Connectors detail) — only shown when method=connectors AND pro is active
	 *   - Step 11 (MCP Client detail) — only shown when method=client
	 *   - Step 12 (npm detail) — only shown when method=npm
	 *   - Step 13 (WP-CLI detail) — only shown when method=wpcli
	 * Every step still has a valid backend handler; skipping is purely a
	 * routing-layer optimization.
	 */
	private const VALID_STEPS = array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13 );

	/**
	 * Valid method values accepted on step 7 (connection method).
	 */
	private const VALID_METHODS = array( 'connectors', 'client', 'npm', 'wpcli' );

	/**
	 * Plugin slugs the /install-plugin route is allowed to install from wp.org.
	 * Any slug not on this whitelist is rejected 400 (defense in depth on top
	 * of the install_plugins/activate_plugins capability check).
	 */
	private const INSTALLABLE_PLUGIN_SLUGS = array(
		'acrossai-abilities-manager',
		'acrossai-pro',
	);

	/**
	 * Singleton instance.
	 *
	 * @since 0.2.11
	 * @var self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Singleton accessor.
	 *
	 * @since 0.2.11
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor enforces singleton pattern (S6).
	 *
	 * @since 0.2.11
	 */
	private function __construct() {}

	// ─────────────────────────────────────────────────────────────────────
	// Route registration
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Register the three quick-connect routes. Wired on `rest_api_init` via
	 * `Main::define_public_hooks()` (T024).
	 *
	 * @since 0.2.11
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/state',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_state' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_step' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_complete' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/install-plugin',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_install_plugin' ),
				'permission_callback' => array( $this, 'install_plugin_permission_check' ),
			)
		);
	}

	/**
	 * Permission callback for /install-plugin. Requires BOTH capabilities so
	 * a user who can install but not activate (or vice versa) gets rejected
	 * up front rather than half-way through the pipeline.
	 *
	 * @since 0.2.11
	 * @return bool
	 */
	public function install_plugin_permission_check(): bool {
		return current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
	}

	/**
	 * Shared permission callback for all three routes. Boolean per S2.
	 * WP REST layer additionally validates X-WP-Nonce automatically for
	 * cookie-authenticated requests when the nonce is present.
	 *
	 * @since 0.2.11
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Route: GET /quick-connect/state
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Return the full snapshot the React app needs to render every step.
	 *
	 * Inherits TASK-SEC-003 error-hygiene constraint from the class docblock.
	 *
	 * Accepts an optional `?server_id=N` query param — used when the wizard
	 * deep-links straight to a late step (e.g. Step 11 with `?server=1` in
	 * the browser URL) without having written the scratchpad's `server_id`
	 * via the usual server-picker step. Without this fallback,
	 * `ConnectionMethodRegistry::get_clients()` returns metadata-only DTOs
	 * (no `config` field), which the JSX then dumps raw. Query param loses
	 * to scratchpad when both are present — the scratchpad is the
	 * authoritative wizard state.
	 *
	 * @since 0.2.11
	 * @param WP_REST_Request|null $request Optional request (for `?server_id=` fallback).
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_state( ?WP_REST_Request $request = null ) {
		$wizard_state = $this->read_scratchpad();
		$server_id    = isset( $wizard_state['server_id'] ) ? (int) $wizard_state['server_id'] : 0;
		if ( 0 === $server_id && null !== $request ) {
			$server_id = (int) $request->get_param( 'server_id' );
		}

		$servers   = $this->collect_servers();
		$abilities = $this->collect_abilities_summary( $server_id > 0 ? $server_id : null );
		$plugins   = $this->collect_plugin_states();
		$methods   = ConnectionMethodRegistry::instance()->get_all();

		// F073 — override the generic (server-less) client list with the
		// server-scoped variant so each DTO carries the real Configuration
		// JSON for the wizard's active server. Mirrors the Clients tab, which
		// resolves the same URL in MCPClientsBlock:224-226. Falls back to the
		// generic list (no `config` field) when no server is selected yet.
		if ( $server_id > 0 ) {
			$rows = MCPServerQuery::instance()->query(
				array(
					'id'     => $server_id,
					'number' => 1,
				)
			);
			if ( ! empty( $rows ) ) {
				$methods['clients'] = ConnectionMethodRegistry::instance()->get_clients(
					$rows[0]->to_array()
				);
			}
		}

		return rest_ensure_response(
			array(
				'servers'     => $servers,
				'abilities'   => $abilities,
				'plugins'     => $plugins,
				'methods'     => $methods,
				'wizardState' => $wizard_state,
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Route: POST /quick-connect/step
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Persist per-step scratchpad state + delegate authoritative writes to
	 * existing plugin APIs (MCPServerQuery::add_item/update_item,
	 * MCPServerAbilityQuery::upsert per SEC-002 direct service call).
	 *
	 * Inherits TASK-SEC-003 error-hygiene constraint from the class docblock —
	 * every WP_Error uses a hand-authored user-facing message.
	 *
	 * @since 0.2.11
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_step( WP_REST_Request $request ) {
		$step = (int) $request->get_param( 'step' );
		if ( ! in_array( $step, self::VALID_STEPS, true ) ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_invalid_step',
				esc_html__( 'Invalid step. Expected an integer from 1 to 5.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$data = $request->get_param( 'data' );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$scratchpad      = $this->read_scratchpad();
		$refresh_servers = false;

		switch ( $step ) {
			case 1:
				$result = $this->apply_step_1( $data, $scratchpad );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$scratchpad = $result;
				break;

			case 2:
				$result = $this->apply_step_2( $data, $scratchpad );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$scratchpad      = $result;
				$refresh_servers = true;
				break;

			case 3:
				$scratchpad['access_saved'] = ! empty( $data['access_saved'] );
				break;

			case 4:
				// Abilities Manager gate — nothing to persist in the scratchpad.
				// Plugin activation state is authoritative and read from
				// state.plugins on GET /state. The step exists in the flow so
				// clients can POST /step 4 as a "we've been here" ack for
				// analytics / progress tracking, but no field is required.
				break;

			case 5:
				$result = $this->apply_step_5( $data, $scratchpad );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$scratchpad = $result;
				break;

			case 6:
				$result = $this->apply_step_6( $data, $scratchpad );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$scratchpad      = $result;
				$refresh_servers = true;
				break;

			case 7:
				$method = isset( $data['method'] ) ? (string) $data['method'] : '';
				if ( ! in_array( $method, self::VALID_METHODS, true ) ) {
					return new WP_Error(
						'acrossai_mcp_quick_connect_invalid_method',
						esc_html__( 'Invalid connection method. Choose one of: connectors, client, npm, wpcli.', 'acrossai-mcp-manager' ),
						array( 'status' => 400 )
					);
				}
				$scratchpad['method'] = $method;
				break;

			case 8:
			case 9:
			case 10:
			case 11:
			case 12:
			case 13:
				// Terminal detail / gate steps — nothing to persist beyond
				// current_step. Plugin activation state (steps 8, 9) is read
				// from state.plugins on GET /state; connection details
				// (steps 10-13) are pure-read display and don't mutate the
				// scratchpad. Handlers exist so clients can POST /step {N}
				// as a "we've been here" ack for analytics / progress.
				break;
		}

		$scratchpad['current_step'] = $step;
		if ( ! $this->write_scratchpad( $scratchpad ) ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_persist_failed',
				esc_html__( 'Failed to save your progress. Try again in a moment.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}

		$response = array( 'wizardState' => $scratchpad );
		if ( $refresh_servers ) {
			$response['servers'] = $this->collect_servers();
		}

		// Always ship a fresh abilities summary when the scratchpad has a
		// server. The `enabledForServer` count is only computed from a
		// server_id, and it's used by the frontend's skipAbilities skip
		// predicate (auto-skip Step 5 when everything is enabled). Without
		// this piggyback, `enabledForServer` would only be refreshed on
		// GET /state — meaning after a user picks a server on Step 1, the
		// count would stay null until a manual focus-refetch, and Step 5
		// would fail to auto-skip even when it should.
		$server_id = isset( $scratchpad['server_id'] ) ? (int) $scratchpad['server_id'] : 0;
		if ( $server_id > 0 ) {
			$response['abilities'] = $this->collect_abilities_summary( $server_id );
		}

		return rest_ensure_response( $response );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Route: POST /quick-connect/complete
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Clear the per-user scratchpad. 204 No Content on success. Idempotent.
	 *
	 * Inherits TASK-SEC-003 error-hygiene constraint from the class docblock.
	 *
	 * @since 0.2.11
	 * @return WP_REST_Response
	 */
	public function handle_complete() {
		delete_transient( self::SCRATCHPAD_KEY_PREFIX . get_current_user_id() );
		return new WP_REST_Response( null, 204 );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Route: POST /quick-connect/install-plugin
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Install (if missing) and activate a whitelisted plugin from WordPress.org.
	 *
	 * Only accepts slugs on `INSTALLABLE_PLUGIN_SLUGS`. If the plugin is
	 * already installed, skips the download step and only activates. If it's
	 * already active, returns success (idempotent).
	 *
	 * Inherits TASK-SEC-003 error-hygiene constraint from the class docblock —
	 * upgrader / activate errors are logged but never surfaced verbatim.
	 *
	 * @since 0.2.11
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_install_plugin( WP_REST_Request $request ) {
		$slug = isset( $request['slug'] ) ? sanitize_key( (string) $request['slug'] ) : '';
		if ( ! in_array( $slug, self::INSTALLABLE_PLUGIN_SLUGS, true ) ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_invalid_plugin',
				esc_html__( 'That plugin cannot be installed from here.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$plugin_file = $slug . '/' . $slug . '.php';
		$installed   = get_plugins();

		if ( ! isset( $installed[ $plugin_file ] ) ) {
			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);
			if ( is_wp_error( $api ) ) {
				error_log( sprintf( '[acrossai-mcp-manager] plugins_api failed for %s: %s', $slug, $api->get_error_message() ) );
				return new WP_Error(
					'acrossai_mcp_quick_connect_install_failed',
					esc_html__( 'Could not find that plugin on WordPress.org. Try installing it manually from Plugins → Add New.', 'acrossai-mcp-manager' ),
					array( 'status' => 502 )
				);
			}

			$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( $api->download_link );
			if ( is_wp_error( $result ) ) {
				error_log( sprintf( '[acrossai-mcp-manager] Plugin_Upgrader::install failed for %s: %s', $slug, $result->get_error_message() ) );
				return new WP_Error(
					'acrossai_mcp_quick_connect_install_failed',
					esc_html__( 'Installation failed. Try installing manually from Plugins → Add New.', 'acrossai-mcp-manager' ),
					array( 'status' => 500 )
				);
			}
			if ( false === $result || null === $result ) {
				return new WP_Error(
					'acrossai_mcp_quick_connect_install_failed',
					esc_html__( 'Installation failed. Try installing manually from Plugins → Add New.', 'acrossai-mcp-manager' ),
					array( 'status' => 500 )
				);
			}
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			$activate = activate_plugin( $plugin_file );
			if ( is_wp_error( $activate ) ) {
				error_log( sprintf( '[acrossai-mcp-manager] activate_plugin failed for %s: %s', $plugin_file, $activate->get_error_message() ) );
				return new WP_Error(
					'acrossai_mcp_quick_connect_activate_failed',
					esc_html__( 'Activation failed. Try activating from Plugins.', 'acrossai-mcp-manager' ),
					array( 'status' => 500 )
				);
			}
		}

		return rest_ensure_response(
			array(
				'installed' => true,
				'active'    => true,
				'plugin'    => $plugin_file,
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// Per-step apply helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Step 1 — pick an existing server or signal "create new" intent.
	 *
	 * Accepts either:
	 *   - `server_id`: int  → picks an existing row (Step 2 will be skipped)
	 *   - `create_intent`: true → user chose "+ Create a new server"; Step 2
	 *     will render the create form and POST /step 2 with `new_server`.
	 *
	 * @param array $data       Step payload.
	 * @param array $scratchpad Current scratchpad.
	 * @return array|WP_Error Updated scratchpad or error.
	 */
	private function apply_step_1( array $data, array $scratchpad ) {
		if ( ! empty( $data['create_intent'] ) ) {
			$scratchpad['create_intent'] = true;
			$scratchpad['server_id']     = null; // Clear any prior pick.
			return $scratchpad;
		}

		if ( ! isset( $data['server_id'] ) ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_invalid_data',
				esc_html__( 'Choose a server to continue, or pick "Create a new server".', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$server_id = (int) $data['server_id'];
		if ( $server_id <= 0 ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_invalid_data',
				esc_html__( 'Choose a server to continue.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}
		if ( ! $this->server_exists( $server_id ) ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_server_gone',
				esc_html__( 'That server no longer exists. Restart the wizard.', 'acrossai-mcp-manager' ),
				array( 'status' => 410 )
			);
		}
		$scratchpad['server_id']     = $server_id;
		$scratchpad['create_intent'] = false;
		return $scratchpad;
	}

	/**
	 * Step 2 — create a new server row. Only reached when Step 1 signalled
	 * `create_intent`. Requires `new_server` payload.
	 *
	 * @param array $data       Step payload.
	 * @param array $scratchpad Current scratchpad.
	 * @return array|WP_Error Updated scratchpad or error.
	 */
	private function apply_step_2( array $data, array $scratchpad ) {
		if ( ! isset( $data['new_server'] ) || ! is_array( $data['new_server'] ) ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_invalid_data',
				esc_html__( 'Fill in the new server form to continue.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$sanitized = MCPServerFieldSanitizer::sanitize( $data['new_server'] );
		if ( '' === $sanitized['server_name'] ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_invalid_data',
				esc_html__( 'Server name is required.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$query = MCPServerQuery::instance();

		$existing = $query->query(
			array(
				'server_slug' => $sanitized['server_slug'],
				'number'      => 1,
			)
		);
		if ( ! empty( $existing ) ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_invalid_data',
				esc_html__( 'A server with that name already exists. Choose a different name.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$new_id = $query->add_item(
			array(
				'server_name'            => $sanitized['server_name'],
				'server_slug'            => $sanitized['server_slug'],
				'description'            => $sanitized['description'],
				'is_enabled'             => 0,
				'registered_from'        => 'database',
				'server_route_namespace' => $sanitized['server_route_namespace'],
				'server_route'           => $sanitized['server_route'],
				'server_version'         => $sanitized['server_version'],
			)
		);
		if ( ! $new_id ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_persist_failed',
				esc_html__( 'Failed to create the server. Try again.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}

		$scratchpad['server_id']     = (int) $new_id;
		$scratchpad['create_intent'] = false;
		return $scratchpad;
	}

	/**
	 * Step 5 — optionally bulk-enable all abilities for the wizard's server.
	 *
	 * TASK-SEC-002 remediation: calls MCPServerAbilityQuery::upsert() directly
	 * — no internal REST-to-REST call to the F017 abilities controller.
	 *
	 * @param array $data       Step payload.
	 * @param array $scratchpad Current scratchpad.
	 * @return array|WP_Error Updated scratchpad or error.
	 */
	private function apply_step_5( array $data, array $scratchpad ) {
		if ( empty( $data['enable_all_abilities'] ) ) {
			$scratchpad['abilities_saved'] = true; // "explicit skip" is a valid completion.
			return $scratchpad;
		}

		$server_id = isset( $scratchpad['server_id'] ) ? (int) $scratchpad['server_id'] : 0;
		if ( $server_id <= 0 ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_invalid_data',
				esc_html__( 'Pick a server before enabling abilities.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			// Abilities API not present — nothing to enable; treat as saved.
			$scratchpad['abilities_saved'] = true;
			return $scratchpad;
		}

		$ability_query = MCPServerAbilityQuery::instance();
		foreach ( \wp_get_abilities() as $ability ) {
			$slug = $ability->get_name();
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			$ability_query->upsert( $server_id, $slug, true );
		}

		$scratchpad['abilities_saved'] = true;
		return $scratchpad;
	}

	/**
	 * Step 6 — enable the wizard's server.
	 *
	 * @param array $data       Step payload.
	 * @param array $scratchpad Current scratchpad.
	 * @return array|WP_Error Updated scratchpad or error.
	 */
	private function apply_step_6( array $data, array $scratchpad ) {
		$server_id = isset( $scratchpad['server_id'] ) ? (int) $scratchpad['server_id'] : 0;
		if ( $server_id <= 0 ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_invalid_data',
				esc_html__( 'Pick a server before enabling it.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$enabled = ! empty( $data['enabled'] );
		$updated = MCPServerQuery::instance()->update_item( $server_id, array( 'is_enabled' => $enabled ? 1 : 0 ) );
		if ( false === $updated ) {
			return new WP_Error(
				'acrossai_mcp_quick_connect_persist_failed',
				esc_html__( 'Failed to update the server. Try again.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}
		$scratchpad['enabled'] = $enabled;
		return $scratchpad;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Collect helpers (state assembly)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Server list DTO for GET /state.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_servers(): array {
		$rows = MCPServerQuery::instance()->query(
			array(
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$dtos = array();
		foreach ( (array) $rows as $row ) {
			$namespace  = isset( $row->server_route_namespace ) ? (string) $row->server_route_namespace : 'mcp';
			$route      = isset( $row->server_route ) ? (string) $row->server_route : '';
			$route_full = ltrim( $namespace, '/' ) . ( '' !== $route ? '/' . ltrim( $route, '/' ) : '' );
			$dtos[]     = array(
				'id'              => isset( $row->id ) ? (int) $row->id : 0,
				'name'            => isset( $row->server_name ) ? (string) $row->server_name : '',
				'slug'            => isset( $row->server_slug ) ? (string) $row->server_slug : '',
				'route_namespace' => $namespace,
				'route'           => $route,
				'route_full'      => $route_full,
				'enabled'         => ! empty( $row->is_enabled ),
			);
		}
		return $dtos;
	}

	/**
	 * Abilities summary for GET /state.
	 *
	 * When a `$server_id` is passed, also returns `enabledForServer` — the
	 * number of abilities currently exposed on that server per the F017
	 * canonical resolver (row override → meta.mcp.public fallback). Null when
	 * no server is chosen yet so the frontend can distinguish "not-computed"
	 * from "zero enabled".
	 *
	 * @param int|null $server_id Server id from the wizard scratchpad, if any.
	 * @return array{total:int,enabledForServer:int|null,hasManagerPlugin:bool}
	 */
	private function collect_abilities_summary( ?int $server_id = null ): array {
		$has_abilities_api = function_exists( 'wp_get_abilities' );
		$total             = $has_abilities_api ? count( \wp_get_abilities() ) : 0;

		$enabled_for_server = null;
		if ( $has_abilities_api && null !== $server_id && $server_id > 0 ) {
			$enabled_for_server = 0;
			foreach ( \wp_get_abilities() as $ability ) {
				$slug = $ability->get_name();
				if ( ! is_string( $slug ) || '' === $slug ) {
					continue;
				}
				$meta = $ability->get_meta();
				if ( MCPServerAbilityExposureResolver::resolve(
					$server_id,
					$slug,
					is_array( $meta ) ? $meta : array()
				) ) {
					++$enabled_for_server;
				}
			}
		}

		return array(
			'total'            => $total,
			'enabledForServer' => $enabled_for_server,
			'hasManagerPlugin' => 'active' === $this->plugin_activation_state( 'acrossai-abilities-manager/acrossai-abilities-manager.php' ),
		);
	}

	/**
	 * Plugin activation state map for GET /state.
	 *
	 * When a plugin is installed-but-inactive, an accompanying `*ActivateUrl`
	 * field carries the nonced `plugins.php?action=activate&...` URL so the
	 * frontend can render a one-click activate button. The URL is null (or
	 * absent) for `missing` and `active` states.
	 *
	 * @return array<string,mixed>
	 */
	private function collect_plugin_states(): array {
		$pro_file     = 'acrossai-pro/acrossai-pro.php';
		$manager_file = 'acrossai-abilities-manager/acrossai-abilities-manager.php';

		$pro_state     = $this->plugin_activation_state( $pro_file );
		$manager_state = $this->plugin_activation_state( $manager_file );

		return array(
			'acrossaiPro'                 => $pro_state,
			// F074 Step 9 — activating the plugin is only half the job; until
			// a licence (or trial) is connected, acrossai-pro registers no
			// connector profiles, so "active" alone must NOT let the wizard
			// walk on to the Connectors screen.
			'acrossaiProLicensed'         => $this->pro_license_active(),
			'acrossaiProActivateUrl'      => 'inactive' === $pro_state
				? $this->plugin_activate_url( $pro_file )
				: null,
			'abilitiesManager'            => $manager_state,
			'abilitiesManagerActivateUrl' => 'inactive' === $manager_state
				? $this->plugin_activate_url( $manager_file )
				: null,
			// F069 Step 7 promo bar — computed each request so the "free
			// through" date always shows today + 30 days without needing a
			// cron / cache-invalidation dance. wp_date() respects the site
			// timezone (unlike raw date()).
			'trialEndDate'                => wp_date( 'F j, Y', strtotime( '+30 days' ) ),
		);
	}

	/**
	 * Whether acrossai-pro currently has a usable licence (paid or trial).
	 *
	 * Delegates to the Pro plugin's own Freemius gate — `can_use_premium_code()`
	 * is the exact predicate acrossai-pro uses to decide whether to register
	 * its connector profiles (see acrossai-pro/includes/Main.php), so the
	 * wizard and the plugin can never disagree about what "licensed" means.
	 * Opting into the free version returns false, which is the point: the
	 * Connectors flow is unusable without a licence.
	 *
	 * Returns false whenever the plugin is inactive or its Freemius SDK is
	 * missing — the accessor only exists once acrossai-pro has loaded.
	 *
	 * @return bool
	 */
	private function pro_license_active(): bool {
		if ( ! function_exists( 'acrossai_pro' ) ) {
			return false;
		}
		$fs = \acrossai_pro();
		if ( ! $fs || ! method_exists( $fs, 'can_use_premium_code' ) ) {
			return false;
		}
		return (bool) $fs->can_use_premium_code();
	}

	/**
	 * Build a nonced `plugins.php?action=activate&plugin=...` URL.
	 *
	 * @param string $plugin_file Relative plugin file (e.g. `foo/foo.php`).
	 * @return string Absolute admin URL with the activate-plugin nonce.
	 */
	private function plugin_activate_url( string $plugin_file ): string {
		$url = admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $plugin_file ) );
		return wp_nonce_url( $url, 'activate-plugin_' . $plugin_file );
	}

	/**
	 * Resolve one plugin's activation state to `'missing'|'inactive'|'active'`.
	 * Mirrors F040 AIConnectorsPromoTab tri-state semantics.
	 *
	 * @param string $plugin_file Relative plugin file (e.g. `foo/foo.php`).
	 * @return string
	 */
	private function plugin_activation_state( string $plugin_file ): string {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( $plugin_file ) ) {
			return 'active';
		}
		if ( function_exists( 'get_plugins' ) ) {
			$installed = get_plugins();
			if ( isset( $installed[ $plugin_file ] ) ) {
				return 'inactive';
			}
		}
		return 'missing';
	}

	// ─────────────────────────────────────────────────────────────────────
	// Scratchpad + helpers
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Default scratchpad shape when no transient exists yet.
	 *
	 * @return array<string,mixed>
	 */
	private function default_scratchpad(): array {
		return array(
			'current_step'    => 1,
			'server_id'       => null,
			'create_intent'   => false,
			'access_saved'    => false,
			'abilities_saved' => false,
			'enabled'         => false,
			'method'          => null,
			'created_at'      => time(),
		);
	}

	/**
	 * Read the current user's scratchpad, defaulting when missing.
	 *
	 * @return array<string,mixed>
	 */
	private function read_scratchpad(): array {
		$stored = get_transient( self::SCRATCHPAD_KEY_PREFIX . get_current_user_id() );
		if ( ! is_array( $stored ) ) {
			return $this->default_scratchpad();
		}
		return array_merge( $this->default_scratchpad(), $stored );
	}

	/**
	 * Persist the current user's scratchpad with a fresh TTL.
	 *
	 * WP core caveat: `set_transient()` delegates to `update_option()` when
	 * no external object cache is configured, and `update_option()` returns
	 * false when the new value is identical to the stored value — even
	 * though the write conceptually succeeded (the store already holds what
	 * we wanted). Naively returning that false would surface a spurious
	 * "Failed to save your progress" error to the user any time a wizard
	 * step's payload doesn't alter the scratchpad (e.g. re-clicking
	 * "Enable all and continue" after all abilities are already enabled).
	 *
	 * Fix: when the raw call returns false, read the transient back and
	 * treat "stored value already matches what we tried to write" as
	 * success. Real store failures (disk full, memcached down) will fail
	 * both the write AND the read-back and correctly return false.
	 *
	 * @param array<string,mixed> $data Full scratchpad shape.
	 * @return bool True on success, false on failure.
	 */
	private function write_scratchpad( array $data ): bool {
		$key = self::SCRATCHPAD_KEY_PREFIX . get_current_user_id();
		if ( set_transient( $key, $data, self::SCRATCHPAD_TTL ) ) {
			return true;
		}
		$stored = get_transient( $key );
		// Strict === on arrays checks type + key order + values. Transient
		// serialization (PHP serialize/unserialize) preserves key order, so
		// stored === data holds when the payload is truly identical.
		return is_array( $stored ) && $stored === $data;
	}

	/**
	 * Check whether the given server row still exists.
	 *
	 * @param int $server_id Server row id.
	 * @return bool
	 */
	private function server_exists( int $server_id ): bool {
		$rows = MCPServerQuery::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		return ! empty( $rows );
	}
}
