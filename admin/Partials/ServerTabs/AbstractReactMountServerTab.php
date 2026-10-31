<?php
/**
 * Reusable base class for React-mount per-server admin tabs.
 *
 * Extends AbstractServerTab with the four subsystems every React-mount tab
 * needs: asset enqueue, REST controller, storage-state contract, and
 * self-registration. A concrete subclass declares identity + config + two
 * state methods, then calls `MyTab::register()` from its plugin's boot code
 * — that's the entire integration surface.
 *
 * =========================================================================
 * INTENDED CONSUMERS
 * =========================================================================
 *
 * 1. Built-in tabs shipped with acrossai-mcp-manager (none currently;
 *    AbilitiesTab + ToolsTab may migrate later).
 * 2. Third-party companion plugins adding their own tabs to the
 *    "Edit MCP Server" screen — this class is the sanctioned extension
 *    point for that use case.
 *
 * =========================================================================
 * METHOD CONTRACT
 * =========================================================================
 *
 * MUST override (abstract):
 *   - get_state_for_server( int $server_id ): array
 *   - set_state_for_server( int $server_id, array $submitted ): array
 *   - slug(): string                (inherited from AbstractServerTab)
 *   - label(): string               (inherited from AbstractServerTab)
 *
 * SHOULD override (defaults exist but almost always need customization):
 *   - get_asset_handle(): string
 *   - get_asset_manifest_path(): string
 *   - get_asset_script_url(): string
 *   - get_asset_style_url(): string
 *   - get_localize_object_name(): string
 *   - get_rest_route_path(): string
 *   - get_react_root_id(): string   (inherited; empty = no mount)
 *
 * MAY override (sensible defaults):
 *   - priority(): int               (inherited; defaults to 100)
 *   - get_heading(): string         (inherited; defaults to label())
 *   - get_description(): string     (inherited; defaults to '')
 *   - get_rest_namespace(): string  (defaults to 'acrossai-mcp-manager/v1')
 *   - get_rest_capability(): string (defaults to 'manage_options')
 *   - get_save_request_args(): array (defaults to '[]' — subclass adds schema)
 *   - build_bootstrap_payload( $server_id, $server ): array (defaults to state + wp-standard fields)
 *   - summary_rows_from_state( $state ): array (defaults to naive flatten)
 *
 * =========================================================================
 * THIRD-PARTY CONSUMER EXAMPLE (copy-paste)
 * =========================================================================
 *
 * ```php
 * // my-widgets-plugin/src/WidgetsTab.php
 * namespace MyWidgets;
 *
 * use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractReactMountServerTab;
 *
 * final class WidgetsTab extends AbstractReactMountServerTab {
 *
 *     protected static $instance = null;
 *     public static function instance(): self {
 *         if ( null === self::$instance ) { self::$instance = new self(); }
 *         return self::$instance;
 *     }
 *     private function __construct() {}
 *
 *     // Identity
 *     public function slug(): string     { return 'widgets'; }
 *     public function label(): string    { return __( 'Widgets', 'my-widgets' ); }
 *     public function priority(): int    { return 95; }
 *
 *     // Body chrome
 *     public function get_heading(): string     { return __( 'Server Widgets', 'my-widgets' ); }
 *     public function get_description(): string { return __( 'Manage the widgets…', 'my-widgets' ); }
 *     public function get_react_root_id(): string { return 'my-widgets-root'; }
 *
 *     // Assets
 *     public function get_asset_handle(): string         { return 'my-widgets-tab'; }
 *     public function get_asset_manifest_path(): string  { return MY_WIDGETS_PATH . 'build/tab.asset.php'; }
 *     public function get_asset_script_url(): string     { return MY_WIDGETS_URL . 'build/tab.js'; }
 *     public function get_asset_style_url(): string      { return MY_WIDGETS_URL . 'build/tab.css'; }
 *     public function get_localize_object_name(): string { return 'myWidgetsTab'; }
 *
 *     // REST
 *     public function get_rest_namespace(): string  { return 'my-widgets/v1'; }
 *     public function get_rest_route_path(): string { return '/servers/(?P<server_id>\d+)/widgets'; }
 *
 *     // State — the actual per-tab business logic
 *     public function get_state_for_server( int $server_id ): array {
 *         return array(
 *             'widgets' => (array) get_post_meta( $server_id, '_my_widgets', true ),
 *         );
 *     }
 *     public function set_state_for_server( int $server_id, array $submitted ): array {
 *         update_post_meta( $server_id, '_my_widgets', (array) ( $submitted['widgets'] ?? array() ) );
 *         return $this->get_state_for_server( $server_id );
 *     }
 * }
 *
 * // my-widgets-plugin/plugin.php
 * add_action( 'plugins_loaded', static function () {
 *     if ( class_exists( AbstractReactMountServerTab::class ) ) {
 *         WidgetsTab::register();
 *     }
 * } );
 * ```
 *
 * The `class_exists()` guard makes the consumer plugin degrade silently
 * when acrossai-mcp-manager is inactive.
 *
 * =========================================================================
 * RELATED
 * =========================================================================
 *
 * - `AbstractServerTab` — parent class; use directly for tabs that don't
 *   need React / REST / persistent state (e.g. read-only info panels).
 * - `FilteredServerTab` — for tabs whose visibility depends on the server.
 *
 * The F037 transport gate used to be listed here as a thing companion plugins
 * extend alongside this class. It lives in acrossai-pro now.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.1.11
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

abstract class AbstractReactMountServerTab extends AbstractServerTab {

	/**
	 * Idempotency guard for register() — prevents double-hooking when
	 * consumers call register() twice or when a built-in and a filter
	 * both wire the same subclass.
	 *
	 * @var array<class-string, bool>
	 */
	private static array $registered = array();

	// ================================================================
	// Subsystem 1 — Registration (single integration point).
	// ================================================================

	/**
	 * Wire enqueue + REST + tab-list hooks. Idempotent: safe to call
	 * multiple times per subclass (only the first invocation registers).
	 *
	 * Consuming plugin (host or third-party) calls this once from its
	 * boot code. Third-parties MUST guard with
	 * `class_exists( self::class )` first so the consumer degrades
	 * silently when acrossai-mcp-manager is inactive.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! empty( self::$registered[ static::class ] ) ) {
			return;
		}
		self::$registered[ static::class ] = true;

		$instance = static::instance();

		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_assets_if_active' ) );
		add_action( 'rest_api_init', array( $instance, 'register_rest_routes' ) );
		add_filter(
			Registry::FILTER_NAME,
			static function ( array $tabs ) use ( $instance ): array {
				$slug = $instance->slug();

				// Skip if a prior entry already claims this slug — happens
				// when the tab is ALSO seeded from `Registry::all_tabs()`
				// (host-plugin built-ins). Registry's normalize_entries()
				// would fire `_doing_it_wrong` on the duplicate otherwise.
				// Third-party consumers not in `all_tabs()` fall through
				// and get appended normally.
				foreach ( $tabs as $existing ) {
					if ( is_array( $existing ) && isset( $existing['slug'] ) && $existing['slug'] === $slug ) {
						return $tabs;
					}
				}

				$tabs[] = array(
					'slug'            => $slug,
					'label'           => $instance->label(),
					'priority'        => $instance->priority(),
					'capability'      => 'manage_options',
					'render_callback' => array( $instance, 'render' ),
				);
				return $tabs;
			}
		);
	}

	/**
	 * Singleton accessor — subclasses MUST implement this + a private
	 * constructor (A2 pattern). Declared here so `register()` can call
	 * it via `static::instance()` without knowing the concrete class.
	 *
	 * @return static
	 */
	abstract public static function instance();

	// ================================================================
	// Subsystem 2 — Asset enqueue.
	// ================================================================

	/**
	 * Handle used for `wp_enqueue_script/style` + `wp_localize_script`.
	 * Should be unique across the WP install.
	 *
	 * @return string
	 */
	abstract public function get_asset_handle(): string;

	/**
	 * Absolute filesystem path to the `*.asset.php` manifest webpack
	 * emits alongside the JS bundle. Returns empty string to skip
	 * enqueue entirely (rare — mostly for tests).
	 *
	 * @return string
	 */
	abstract public function get_asset_manifest_path(): string;

	/**
	 * Public URL of the JS bundle to enqueue.
	 *
	 * @return string
	 */
	abstract public function get_asset_script_url(): string;

	/**
	 * Public URL of the CSS bundle to enqueue. Empty string = no CSS.
	 *
	 * @return string
	 */
	public function get_asset_style_url(): string {
		return '';
	}

	/**
	 * Name of the `window.*` global the React app reads its bootstrap
	 * from — passed as the 2nd arg to `wp_localize_script()`.
	 *
	 * @return string
	 */
	abstract public function get_localize_object_name(): string;

	/**
	 * Assemble the bootstrap payload that gets localized under
	 * `get_localize_object_name()`. Default = state + standard REST
	 * fields (serverId, namespace, nonce). Override to inject extra
	 * fields React needs at first render.
	 *
	 * @param int   $server_id Server PK.
	 * @param array $server    Server row data (for callers that need slug/name).
	 * @return array<string, mixed>
	 */
	public function build_bootstrap_payload( int $server_id, array $server ): array {
		unset( $server );
		$state = $this->get_state_for_server( $server_id );
		return array_merge(
			array(
				'serverId'  => $server_id,
				'namespace' => $this->get_rest_namespace(),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
			),
			$state
		);
	}

	/**
	 * `admin_enqueue_scripts` callback — no-op unless the current
	 * screen is the MCP server edit screen AND `?tab=` matches this
	 * tab's slug. Reads asset manifest, enqueues script + optional
	 * CSS, localizes the bootstrap payload.
	 *
	 * @return void
	 */
	final public function enqueue_assets_if_active(): void {
		if ( ! $this->is_active_screen() ) {
			return;
		}

		$manifest_path = $this->get_asset_manifest_path();
		if ( '' === $manifest_path || ! file_exists( $manifest_path ) ) {
			return;
		}
		$asset = include $manifest_path;
		if ( ! is_array( $asset ) || ! isset( $asset['version'], $asset['dependencies'] ) ) {
			return;
		}

		$handle = $this->get_asset_handle();

		wp_enqueue_script(
			$handle,
			esc_url( $this->get_asset_script_url() ),
			(array) $asset['dependencies'],
			(string) $asset['version'],
			true
		);

		$style_url = $this->get_asset_style_url();
		if ( '' !== $style_url ) {
			wp_enqueue_style(
				$handle,
				esc_url( $style_url ),
				array(),
				(string) $asset['version']
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		$server_id = isset( $_GET['server'] ) ? absint( wp_unslash( $_GET['server'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$server = array( 'id' => $server_id );
		if ( $server_id > 0 ) {
			$row = $this->find_server_row( $server_id );
			if ( is_array( $row ) ) {
				$server = $row;
			}
		}

		wp_localize_script(
			$handle,
			$this->get_localize_object_name(),
			$this->build_bootstrap_payload( $server_id, $server )
		);
	}

	/**
	 * True when the current admin screen is the MCP server edit page
	 * AND `?action=edit&tab={slug}` — matches the enqueue guard the
	 * legacy `maybe_enqueue_*_app()` functions in admin/Main.php used.
	 *
	 * @return bool
	 */
	protected function is_active_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, AdminPageSlugs::plugin_screen_ids(), true ) ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		$is_edit = isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) );
		$is_tab  = isset( $_GET['tab'] ) && $this->slug() === sanitize_key( wp_unslash( $_GET['tab'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $is_edit && $is_tab;
	}

	// ================================================================
	// Subsystem 3 — REST controller (GET + POST).
	// ================================================================

	/**
	 * REST route namespace — defaults to the plugin's shared namespace.
	 * Third-parties should override with their own.
	 *
	 * @return string
	 */
	public function get_rest_namespace(): string {
		return 'acrossai-mcp-manager/v1';
	}

	/**
	 * REST route path pattern — MUST include a named `(?P<server_id>\d+)`
	 * capture group. Example: `'/servers/(?P<server_id>\d+)/embeds'`.
	 *
	 * @return string
	 */
	abstract public function get_rest_route_path(): string;

	/**
	 * Capability required for both GET and POST — defaults to
	 * `'manage_options'`. Override for a different permission model.
	 *
	 * @return string
	 */
	public function get_rest_capability(): string {
		return 'manage_options';
	}

	/**
	 * REST args schema for the POST body — subclass declares its own
	 * shape. Default `[]` accepts arbitrary payload. Merged with the
	 * mandatory `server_id` arg by `register_rest_routes()`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_save_request_args(): array {
		return array();
	}

	/**
	 * `rest_api_init` callback — registers GET + POST routes.
	 *
	 * @return void
	 */
	final public function register_rest_routes(): void {
		$server_id_arg = array(
			'server_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $val ): bool {
					return is_numeric( $val ) && (int) $val > 0;
				},
			),
		);

		register_rest_route(
			$this->get_rest_namespace(),
			$this->get_rest_route_path(),
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_read' ),
					'permission_callback' => array( $this, 'rest_permission_callback' ),
					'args'                => $server_id_arg,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_save' ),
					'permission_callback' => array( $this, 'rest_permission_callback' ),
					'args'                => array_merge( $server_id_arg, $this->get_save_request_args() ),
				),
			)
		);
	}

	/**
	 * REST permission check — `current_user_can( get_rest_capability() )`.
	 *
	 * @return bool|WP_Error
	 */
	final public function rest_permission_callback() {
		if ( ! current_user_can( $this->get_rest_capability() ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage this tab.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * REST GET handler — delegates to `get_state_for_server()`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	final public function rest_read( WP_REST_Request $request ) {
		$server_id = (int) $request['server_id'];
		$server    = $this->find_server_row( $server_id );
		if ( $server instanceof WP_Error ) {
			return $server;
		}
		return new WP_REST_Response( $this->get_state_for_server( $server_id ), 200 );
	}

	/**
	 * REST POST handler — delegates to `set_state_for_server()`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	final public function rest_save( WP_REST_Request $request ) {
		$server_id = (int) $request['server_id'];
		$server    = $this->find_server_row( $server_id );
		if ( $server instanceof WP_Error ) {
			return $server;
		}
		$fresh_state = $this->set_state_for_server( $server_id, $request->get_params() );
		return new WP_REST_Response( $fresh_state, 200 );
	}

	/**
	 * Look up the target server row by id. Returns `WP_Error(404)` when
	 * missing. `protected` and non-`final` so subclasses can override —
	 * a third-party companion plugin whose "server" concept is not backed
	 * by `AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query` can
	 * substitute its own lookup (e.g. `get_post()`, an external service
	 * fetch, a custom BerlinDB module).
	 *
	 * The default implementation is coupled to the host plugin's
	 * `MCPServer\Query` for convenience of built-in tabs shipped inside
	 * `acrossai-mcp-manager` itself. Third-party subclasses SHOULD
	 * override when their data layer differs.
	 *
	 * Contract for overrides:
	 *   - Return an assoc array containing at minimum an `id` key on
	 *     success.
	 *   - Return `WP_Error` with `['status' => 404]` on miss.
	 *   - Fire NO side effects (this method may be called on any REST
	 *     request; must be idempotent + safe).
	 *
	 * @param int $server_id Server PK.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function find_server_row( int $server_id ) {
		$rows = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			return new WP_Error(
				'rest_server_not_found',
				__( 'Server not found.', 'acrossai-mcp-manager' ),
				array( 'status' => 404 )
			);
		}
		return (array) $rows[0];
	}

	// ================================================================
	// Subsystem 4 — State (storage) contract.
	// ================================================================

	/**
	 * Return the fully-hydrated per-server state. Consumed by:
	 *   - REST GET response body
	 *   - `build_bootstrap_payload()` for first-render hydration
	 *   - `render_noscript_fallback()` for the read-only summary table
	 *
	 * Shape is subclass-defined. Consumer decides the storage backend
	 * (dedicated table / meta blob / options / external) — the base
	 * doesn't care.
	 *
	 * @param int $server_id Server PK.
	 * @return array<string, mixed>
	 */
	abstract public function get_state_for_server( int $server_id ): array;

	/**
	 * Persist submitted state and return the freshly-hydrated state so
	 * the REST POST response echoes it back without a second GET.
	 *
	 * Consumer decides schema validation, observability events, cache
	 * flushes, etc. Typical body:
	 *
	 *   1. Validate `$submitted` payload
	 *   2. Diff against current state (call `get_state_for_server()`)
	 *   3. Write changed values to storage
	 *   4. Fire per-change observability actions (fail-forward per R3)
	 *   5. Flush any per-request caches
	 *   6. `return $this->get_state_for_server( $server_id );`
	 *
	 * @param int   $server_id Server PK.
	 * @param array $submitted Sanitized POST body (from `WP_REST_Request::get_params()`).
	 * @return array<string, mixed>
	 */
	abstract public function set_state_for_server( int $server_id, array $submitted ): array;

	// ================================================================
	// Subsystem 5 — Noscript summary (auto-derived from state).
	// ================================================================

	/**
	 * Turn a state array into read-only summary rows for the noscript
	 * fallback table. Default = naive flatten (top-level keys become
	 * labels; scalar values stringified; nested structures collapsed
	 * to "N items"). Override for a domain-shaped view.
	 *
	 * @param array<string, mixed> $state State returned by `get_state_for_server()`.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function summary_rows_from_state( array $state ): array {
		$rows = array();
		foreach ( $state as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$stringified = is_bool( $value )
					? ( $value ? __( 'Enabled', 'acrossai-mcp-manager' ) : __( 'Disabled', 'acrossai-mcp-manager' ) )
					: (string) $value;
			} elseif ( is_array( $value ) ) {
				$stringified = sprintf(
					/* translators: %d: item count */
					_n( '%d item', '%d items', count( $value ), 'acrossai-mcp-manager' ),
					count( $value )
				);
			} else {
				$stringified = '';
			}
			$rows[] = array(
				'label' => (string) $key,
				'value' => $stringified,
			);
		}
		return $rows;
	}

	/**
	 * Override — pull summary rows from state via
	 * `summary_rows_from_state( get_state_for_server( ... ) )`. Zero
	 * subclass code required for a working (if naive) noscript fallback.
	 *
	 * @param array $server Server row data.
	 * @return array<int, array{label: string, value: string}>
	 */
	public function get_noscript_summary_rows( array $server ): array {
		$server_id = (int) ( $server['id'] ?? 0 );
		if ( $server_id <= 0 ) {
			return array();
		}
		return $this->summary_rows_from_state(
			$this->get_state_for_server( $server_id )
		);
	}
}
