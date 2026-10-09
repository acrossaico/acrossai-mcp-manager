<?php
/**
 * F093 — WebMCP REST surface.
 *
 * Three routes, all cookie-authenticated as the logged-in admin:
 *
 *   GET  /webmcp/tools    the selected server's composed tool list
 *   POST /webmcp/execute  run one tool
 *   GET  /webmcp/nonce    refresh the REST nonce
 *
 * WHY NOT THE GENERIC ABILITIES ROUTE
 * -----------------------------------
 * `/wp-json/wp-abilities/v1/…` never touches the vendor MCP transport, so
 * the per-server exposure gate no-ops there and every `is_exposed = 0`
 * ability becomes callable. This controller exists to have a path where the
 * gates can be made to run, which is what `WebMcpContext` does.
 *
 * WHY SLUGS ARE IN THE BODY AND NEVER THE PATH
 * --------------------------------------------
 * Every ability slug contains a slash — `toolset/content`,
 * `mcp-adapter/execute-ability`. Apache's `AllowEncodedSlashes Off` (the
 * default) rejects an encoded slash in a path segment with a 404 before PHP
 * runs. `webmcp-for-wordpress` hit this and works around it by encoding `/`
 * as `__` and mapping it back in a sanitizer. Putting the slug in the JSON
 * body avoids the problem rather than reimplementing the workaround.
 *
 * NONCE EXPIRY IS NOT HYPOTHETICAL
 * --------------------------------
 * WP nonces last 12–24h. An agent working a long-open block-editor tab will
 * outlive one and start getting 403s mid-session with no visible cause,
 * which is why `/nonce` ships in v1 rather than being added after the first
 * bug report.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\REST
 * @since      0.4.2
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\REST;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\WebMCP\BrowserEligibility;
use AcrossAI_MCP_Manager\Includes\WebMCP\Settings as WebMcpSettings;
use AcrossAI_MCP_Manager\Includes\WebMCP\WebMcpContext;
use WP\MCP\Core\McpServer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton per A11. Public methods are hook callbacks; wired in
 * `Includes\Main::define_public_hooks()`.
 *
 * @since 0.4.2
 */
final class WebMcpController {

	private const REST_NAMESPACE = 'acrossai-mcp-manager/v1';
	private const ROUTE_PREFIX   = '/webmcp';

	/** @var self|null */
	protected static $_instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @since 0.4.2
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/** Private — use instance(). */
	private function __construct() {}

	/**
	 * Register the three routes on `rest_api_init`.
	 *
	 * @since 0.4.2
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/tools',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_tools' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/execute',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_execute' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					// In the BODY, never the path — see the file docblock.
					'slug' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREFIX . '/nonce',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_nonce' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/**
	 * Cookie auth as a logged-in administrator.
	 *
	 * OAuth and the transport permission layer are bypassed entirely on this
	 * path, so capability gating carries the whole load here. v1 is
	 * admin-screens-only, hence `manage_options` rather than something
	 * finer: a lower bar would widen the browser surface past the remote one.
	 *
	 * The per-server access rule is NOT checked here — it is enforced inside
	 * `WebMcpContext` by the replayed gates, so there is exactly one
	 * implementation of it.
	 *
	 * @since 0.4.2
	 * @return bool|WP_Error
	 */
	public function permission_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'acrossai_mcp_webmcp_not_logged_in',
				__( 'Authentication is required.', 'acrossai-mcp-manager' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'acrossai_mcp_webmcp_forbidden',
				__( 'You do not have permission to use WebMCP on this site.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET /webmcp/tools — the selected server's composed tool list.
	 *
	 * The list is `ToolPolicy::compose_for_row()`, which is the same source
	 * of truth behind the Tools tab's "Added as tools" panel. It is NOT a
	 * hardcoded set: an `acrossai`-type server runs with the three protocol
	 * flags off and fourteen `toolset/*` entries instead, so hardcoding the
	 * protocol triple would expose tools the admin switched off and none of
	 * the ones they curated.
	 *
	 * The composed list then passes through the replayed `mcp_adapter_tools_list`
	 * filter, which is where the access-control gate can empty it for a user
	 * the server's rule excludes. Hiding the list matters independently of
	 * blocking the call: the audit that produced `gate_mcp_tools_list()`
	 * found a user outside a rule being refused execution while still served
	 * every tool name and description. In a browser that list goes straight
	 * to an AI.
	 *
	 * @since 0.4.2
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_tools() {
		$row = WebMcpSettings::selected_row();
		if ( null === $row ) {
			return $this->unavailable();
		}

		$composed = ToolPolicy::compose_for_row( $row );

		$result = WebMcpContext::with(
			(string) $row->server_slug,
			static function ( McpServer $server ) use ( $composed ): array {
				$gated = WebMcpContext::filter_tools_list( $composed, $server );

				return array_values( array_filter( array_map( array( self::class, 'describe_tool' ), $gated ) ) );
			}
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'server' => array(
					'slug' => (string) $row->server_slug,
					'name' => (string) $row->server_name,
				),
				'tools'  => $result,
			)
		);
	}

	/**
	 * POST /webmcp/execute — run one tool.
	 *
	 * Order is load-bearing. The replayed `mcp_adapter_pre_tool_call` filter
	 * carries all three call-time gates (10 access control, 20 ability
	 * exposure, 30 tool curation) and expresses denial ONLY through its
	 * return value, so a `WP_Error` back from it must stop the request. It is
	 * also the gate chain's single chance to run: nothing downstream of
	 * `wp_execute_ability()` re-checks per-server exposure.
	 *
	 * @since 0.4.2
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_execute( WP_REST_Request $request ) {
		$row = WebMcpSettings::selected_row();
		if ( null === $row ) {
			return $this->unavailable();
		}

		// Two-step consent. Enabling WebMCP publishes the catalogue; running
		// a tool is a second, separate decision. Refused at the route rather
		// than by omitting tools, so an operator can verify the wiring works
		// before granting the site-changing half.
		if ( ! WebMcpSettings::allows_execute() ) {
			return new WP_Error(
				'acrossai_mcp_webmcp_execute_disabled',
				__( 'Tool execution from the browser is switched off for this site.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		$slug = trim( (string) $request->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error(
				'acrossai_mcp_webmcp_missing_slug',
				__( 'A tool slug is required.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		// Refuse anything the server does not actually expose, before the
		// gates see it. This is not a substitute for them — it is a cheap
		// rejection of a slug that was never on the menu.
		if ( ! in_array( $slug, ToolPolicy::compose_for_row( $row ), true ) ) {
			return new WP_Error(
				'acrossai_mcp_webmcp_tool_not_exposed',
				__( 'That tool is not exposed on the selected server.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		// The author's veto applies here too, not only to the listing.
		// Hiding a tool an agent can still invoke by guessing its slug is
		// not a veto — it is a wish.
		if ( null === self::describe_tool( $slug ) ) {
			return new WP_Error(
				'acrossai_mcp_webmcp_tool_not_eligible',
				__( 'That tool cannot be run from a browser.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		$input = $request->get_param( 'input' );
		$input = is_array( $input ) ? $input : array();

		return WebMcpContext::with(
			(string) $row->server_slug,
			static function ( McpServer $server ) use ( $slug, $input ) {
				$gated = WebMcpContext::filter_pre_tool_call( $input, $slug, null, $server );
				if ( $gated instanceof WP_Error ) {
					return $gated;
				}

				if ( ! function_exists( 'wp_execute_ability' ) ) {
					return new WP_Error(
						'acrossai_mcp_webmcp_abilities_api_missing',
						__( 'The WordPress Abilities API is not available.', 'acrossai-mcp-manager' ),
						array( 'status' => 500 )
					);
				}

				$output = wp_execute_ability( $slug, $gated );
				if ( $output instanceof WP_Error ) {
					return $output;
				}

				return new WP_REST_Response(
					array(
						'slug'   => $slug,
						'result' => $output,
					)
				);
			}
		);
	}

	/**
	 * GET /webmcp/nonce — a fresh `wp_rest` nonce.
	 *
	 * Exists so an agent in a tab older than the nonce lifetime can recover
	 * instead of failing with an unexplained 403.
	 *
	 * @since 0.4.2
	 * @return WP_REST_Response
	 */
	public function handle_nonce(): WP_REST_Response {
		return new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * Describe one composed slug for the bridge.
	 *
	 * Returns null for a slug with no registered ability — a curated row can
	 * outlive the plugin that registered its ability, and advertising a tool
	 * that cannot run is worse than omitting it: an AI client caches the tool
	 * list when it connects, so the broken entry persists for the session.
	 *
	 * @since 0.4.2
	 * @param mixed $slug Composed tool slug.
	 * @return array<string, mixed>|null
	 */
	public static function describe_tool( $slug ): ?array {
		$slug = (string) $slug;

		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}
		// Checked first: since WP 6.9 looking up an unregistered ability makes
		// the registry emit a _doing_it_wrong notice, and "not found" is an
		// expected outcome here rather than incorrect core usage.
		if ( ! wp_has_ability( $slug ) ) {
			return null;
		}

		$ability = wp_get_ability( $slug );
		if ( null === $ability ) {
			return null;
		}

		// The author's veto. Narrowing only — an ability the operator did not
		// curate never reaches here, because the caller composes from the
		// Tools tab first.
		if ( ! BrowserEligibility::is_allowed( $slug, $ability->get_meta() ) ) {
			return null;
		}

		return array(
			'slug'        => $slug,
			'name'        => self::tool_name( $slug ),
			'label'       => $ability->get_label(),
			'description' => $ability->get_description(),
			'inputSchema' => $ability->get_input_schema(),
		);
	}

	/**
	 * Derive the WebMCP tool name from an ability slug.
	 *
	 * WebMCP names must be identifier-shaped — no slashes — and STABLE, since
	 * an agent that has seen the site before will reuse them. One
	 * deterministic rule rather than a mapping table: a hand-maintained map
	 * drifts the moment a toolset is added.
	 *
	 *   toolset/content              → wp_toolset_content
	 *   mcp-adapter/execute-ability  → wp_mcp_adapter_execute_ability
	 *
	 * @since 0.4.2
	 * @param string $slug Ability slug.
	 * @return string
	 */
	public static function tool_name( string $slug ): string {
		$name = strtolower( $slug );
		$name = (string) preg_replace( '/[^a-z0-9]+/', '_', $name );
		$name = trim( $name, '_' );

		return 'wp_' . $name;
	}

	/**
	 * The single refusal used whenever no usable server is selected.
	 *
	 * One shared shape so "feature off", "slug resolves to nothing" and
	 * "server disabled" are indistinguishable to the caller — a browser agent
	 * has no business learning which of those is true.
	 *
	 * @since 0.4.2
	 * @return WP_Error
	 */
	private function unavailable(): WP_Error {
		return new WP_Error(
			'acrossai_mcp_webmcp_unavailable',
			__( 'WebMCP is not available on this site.', 'acrossai-mcp-manager' ),
			array( 'status' => 403 )
		);
	}
}
