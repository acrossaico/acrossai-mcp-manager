<?php
/**
 * MCP access-control integration wrapper (v2 adoption).
 *
 * Feature 015 — wraps the wpb-access-control v2 vendor package with a
 * plugin-scoped singleton that owns the AccessControlManager instance,
 * the DB table slug, and the providers filter tag. Copy-adapted verbatim
 * from the sibling acrossai-abilities-manager plugin's
 * AcrossAI_Abilities_Access_Control (see DEC-ACCESS-CONTROL-V2-ADOPTION).
 *
 * Observability hooks (per FR-026 + Clarifications Q2 + Q3, D19 pattern):
 *   - do_action( 'acrossai_mcp_access_control_denied', int $user_id, string $server_slug_or_route, ?string $subject, string $context_slug )
 *     fires BEFORE the WP_Error / empty-list return at every enforcement site.
 *     $subject is null at the /servers site (no MCP subject), non-null at MCP
 *     boundaries — where it carries the tool name / resource URI / prompt name
 *     depending on the gate that fired.
 *     $context_slug ∈ { 'cli_servers', 'mcp_tool_call', 'mcp_resource_read', 'mcp_prompt_get' }.
 *   - do_action( 'acrossai_mcp_access_control_missing_server', int $server_id_or_slug, string $subject, int $user_id )
 *     fires on race with concurrent DELETE (MCPServerQuery returns 0 rows for
 *     the mcp-adapter-supplied server_id). Fail-open follows.
 *
 * Since 0.2.8 (F1 fix): the MCP boundary hooks fire from THREE gates —
 * `gate_mcp_tool_call`, `gate_mcp_resource_read`, `gate_mcp_prompt_get` —
 * all routed through the private `apply_ac_gate()` helper.
 *
 * @package AcrossAI_MCP_Manager
 * @since   0.0.7
 */

namespace AcrossAI_MCP_Manager\Includes\AccessControl;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WPBoilerplate\AccessControl\AccessControlManager;

defined( 'ABSPATH' ) || exit;

/**
 * MCP access-control integration wrapper (v2 adoption).
 *
 * @since 0.0.7
 */
final class AcrossAI_MCP_Access_Control {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.7
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Plugin-scoped provider filter tag.
	 *
	 * @since 0.0.7
	 * @var string
	 */
	public const PROVIDERS_FILTER = 'acrossai_mcp_access_control_providers';

	/**
	 * Per-consumer table slug (wpb-access-control v2+).
	 *
	 * Drives: `{$wpdb->prefix}mcp_access_control` table,
	 * `wpb_ac_mcp_db_version` schema option, `wpb_ac_mcp` cache group,
	 * `/wpb-ac/v1/mcp/...` REST namespace. Must match `^[a-z0-9_]{1,32}$`
	 * (validated upstream at construction time).
	 *
	 * @since 0.0.7
	 * @var string
	 */
	public const TABLE_SLUG = 'mcp';

	/**
	 * Access-control manager instance.
	 *
	 * @since 0.0.7
	 * @var AccessControlManager|null
	 */
	private $manager = null;

	/**
	 * Return singleton instance.
	 *
	 * @since 0.0.7
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
	 * @since 0.0.7
	 */
	private function __construct() {}

	/**
	 * Boot the access-control manager.
	 *
	 * @since 0.0.7
	 * @return void
	 */
	public function boot_manager(): void {
		if ( ! $this->is_available() || $this->manager instanceof AccessControlManager ) {
			return;
		}

		$this->manager = new AccessControlManager( self::PROVIDERS_FILTER, self::TABLE_SLUG );
	}

	/**
	 * Register the library REST routes when available.
	 *
	 * @since 0.0.7
	 * @return void
	 */
	public function register_rest_api(): void {
		$manager = $this->get_manager();

		if ( null === $manager ) {
			return;
		}

		$manager->register_rest_api();
	}

	/**
	 * Check whether the access-control library is available.
	 *
	 * @since 0.0.7
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( AccessControlManager::class );
	}

	/**
	 * Return the manager instance when available.
	 *
	 * @since 0.0.7
	 * @return AccessControlManager|null
	 */
	public function get_manager(): ?AccessControlManager {
		if ( ! $this->manager instanceof AccessControlManager ) {
			$this->boot_manager();
		}

		return $this->manager;
	}

	/**
	 * Enumerate every WordPress capability registered across all roles.
	 *
	 * The result is the union of `capabilities` arrays across every role in
	 * `wp_roles()->role_objects`, deduplicated + sorted alphabetically. This
	 * matches the sibling `acrossai-abilities-manager` plugin's User Access
	 * capability picker shape per Clarifications Q4 (supersedes the earlier
	 * curated allow-list that shipped in the initial F015 draft).
	 *
	 * Administrators bypass every rule per the v2 access-hierarchy step 2,
	 * so exposing high-privilege capabilities (manage_options, edit_users) in
	 * this list is harmless: only admins hold them, and admins already have
	 * unrestricted access. A rule allowing `manage_options` is a no-op, not
	 * a privilege-escalation vector.
	 *
	 * @since 0.0.7
	 * @return array<int, string>
	 */
	public function get_available_capabilities(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}
		$roles_obj = wp_roles();
		if ( ! is_object( $roles_obj ) || empty( $roles_obj->role_objects ) ) {
			return array();
		}
		$caps = array();
		foreach ( $roles_obj->role_objects as $role ) {
			if ( is_object( $role ) && ! empty( $role->capabilities ) && is_array( $role->capabilities ) ) {
				$caps = array_merge( $caps, array_keys( $role->capabilities ) );
			}
		}
		/**
		 * Filter — third-party plugins may append custom capabilities the
		 * operator should see in the picker (e.g., `manage_woocommerce`).
		 *
		 * @since 0.0.7
		 *
		 * @param array<int, string> $capabilities Enumerated role capabilities.
		 */
		$caps = (array) apply_filters( 'acrossai_mcp_ac_available_capabilities', $caps );
		$caps = array_values( array_unique( array_filter( array_map( 'strval', $caps ) ) ) );
		sort( $caps );
		return $caps;
	}

	/**
	 * F032 (F015 amendment) — shared connection-time AC check.
	 *
	 * Used by the OAuth authorize gate, CLI device-grant consent gate, and
	 * Application Password generation gate so unauthorized users are blocked
	 * BEFORE receiving any credential — instead of being blocked only at
	 * tool-call time (which is confusing UX because clients like Claude show
	 * "connected" then silently 403 on invocation).
	 *
	 * Fail-open per D18/D19 / DEC-ACCESS-CONTROL-V2-ADOPTION: returns TRUE if
	 * the AC package is absent, the server row is missing (Q2 race), or the
	 * manager is null — degrades gracefully without breaking connect flows.
	 * v2 vendor hierarchy handles admin bypass internally.
	 *
	 * @since 0.1.6 (F032)
	 * @param int $user_id   The WordPress user id attempting to connect.
	 * @param int $server_id The MCP server row id being connected to.
	 * @return bool True if user may connect; false only if AC explicitly denies.
	 */
	public function user_has_server_access( int $user_id, int $server_id ): bool {
		if ( ! $this->is_available() ) {
			return true; // Fail-open.
		}
		if ( $user_id <= 0 || $server_id <= 0 ) {
			return true; // No context to evaluate; defer to downstream gates.
		}

		$rows = MCPServerQuery::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			return true; // Server missing — same Q2 fail-open as tool-call gate.
		}

		$server_slug = (string) $rows[0]->server_slug;
		if ( '' === $server_slug ) {
			return true;
		}

		$manager = $this->get_manager();
		if ( null === $manager ) {
			return true; // Manager boot failed — fail-open.
		}

		return (bool) $manager->user_has_access( $user_id, 'acrossai-mcp-manager', $server_slug );
	}

	/**
	 * Filter callback for `mcp_adapter_pre_tool_call` — the MCP-boundary
	 * enforcement site (FR-007, D18 canonical hook, Q2 + Q3 observability).
	 *
	 * Wired via `Main::define_public_hooks()` per A1.
	 *
	 * @since 0.0.7
	 *
	 * @param array<mixed>                 $args      Tool call args.
	 * @param string                       $tool_name The MCP tool name.
	 * @param mixed                        $mcp_tool  The McpTool instance (unused here).
	 * @param \WP\MCP\Core\McpServer|mixed $server    The McpServer instance.
	 * @return array<mixed>|\WP_Error Original args on allow / fail-open; WP_Error on deny.
	 */
	public function gate_mcp_tool_call( $args, $tool_name, $mcp_tool, $server ) {
		unset( $mcp_tool );
		return $this->apply_ac_gate( $args, $server, (string) $tool_name, 'mcp_tool_call' );
	}

	/**
	 * Filter callback for `mcp_adapter_pre_resource_read` — F1 fix.
	 *
	 * Sibling of `gate_mcp_tool_call` for the MCP `resources/read` primitive.
	 * Without this gate, F042 defers to the tool-call filter for rule-configured
	 * servers, meaning any authenticated user could `POST {"method":"resources/read"}`
	 * against an "Editors only" server and bypass the operator's rule entirely.
	 * Vendor hook: {@see \WP\MCP\Handlers\Resources\ResourcesHandler} line 138.
	 *
	 * @since 0.2.8
	 *
	 * @param array<mixed>                               $request_params Params from the JSON-RPC request.
	 * @param string                                     $uri            The resource URI being read.
	 * @param \WP\MCP\Domain\Resources\McpResource|mixed $mcp_resource   The McpResource instance (unused).
	 * @param \WP\MCP\Core\McpServer|mixed               $server         The McpServer instance.
	 * @return array<mixed>|\WP_Error Original params on allow / fail-open; WP_Error on deny.
	 */
	public function gate_mcp_resource_read( $request_params, $uri, $mcp_resource, $server ) {
		unset( $mcp_resource );
		return $this->apply_ac_gate( $request_params, $server, (string) $uri, 'mcp_resource_read' );
	}

	/**
	 * Filter callback for `mcp_adapter_pre_prompt_get` — F1 fix.
	 *
	 * Sibling of `gate_mcp_tool_call` for the MCP `prompts/get` primitive.
	 * See `gate_mcp_resource_read` docblock for rationale — same bypass class
	 * without this gate. Vendor hook:
	 * {@see \WP\MCP\Handlers\Prompts\PromptsHandler} line 157.
	 *
	 * @since 0.2.8
	 *
	 * @param array<mixed>                           $arguments    Prompt arguments from the JSON-RPC request.
	 * @param string                                 $prompt_name  The prompt name being fetched.
	 * @param \WP\MCP\Domain\Prompts\McpPrompt|mixed $mcp_prompt   The McpPrompt instance (unused).
	 * @param \WP\MCP\Core\McpServer|mixed           $server       The McpServer instance.
	 * @return array<mixed>|\WP_Error Original arguments on allow / fail-open; WP_Error on deny.
	 */
	public function gate_mcp_prompt_get( $arguments, $prompt_name, $mcp_prompt, $server ) {
		unset( $mcp_prompt );
		return $this->apply_ac_gate( $arguments, $server, (string) $prompt_name, 'mcp_prompt_get' );
	}

	/**
	 * Filter callback for `mcp_adapter_tools_list` — the discovery sibling of
	 * {@see self::gate_mcp_tool_call()}.
	 *
	 * The three pre-dispatch gates above stop a denied user *executing*, but
	 * they never ran for `tools/list`, so an excluded user could still read the
	 * whole catalogue. That mattered because configuring ANY rule drops the
	 * transport gate from `manage_options` to the vendor default `read`
	 * ({@see TransportPermissionDefault::filter_default_capability()} — rule
	 * present means "defer to the call-time gate"), and every logged-in user
	 * holds `read`. Measured on a server whose rule was "Editor only": a
	 * subscriber completed `initialize` and received 14 tools with names and
	 * descriptions, while `tools/call` correctly denied. Disclosure, not
	 * privilege escalation — but the operator asked for those users to be kept
	 * out, so the list has to be empty for them too.
	 *
	 * Same reasoning that added {@see self::gate_mcp_resource_read()} in 0.2.8.
	 *
	 * Returns an EMPTY list rather than a WP_Error: `tools/list` has no error
	 * contract a client must honour, and an empty catalogue is the
	 * minimum-disclosure answer — a denied user cannot tell a server they may
	 * not see from one that simply exposes nothing.
	 *
	 * @since 0.3.9
	 *
	 * @param array<mixed>                 $tools  Tool records about to be returned.
	 * @param \WP\MCP\Core\McpServer|mixed $server The McpServer instance.
	 * @param mixed                        $schema Selected schema (unused; 0.7.0 third arg).
	 * @return array<mixed> Original list on allow / fail-open; empty array on deny.
	 */
	public function gate_mcp_tools_list( $tools, $server, $schema = null ) {
		unset( $schema );
		return $this->hide_list_from_current_user( $server, 'mcp_tools_list' ) ? array() : $tools;
	}

	/**
	 * Filter callback for `mcp_adapter_resources_list`.
	 *
	 * Sibling of {@see self::gate_mcp_tools_list()}; see that method for why
	 * the list primitives need gating at all.
	 *
	 * @since 0.3.9
	 *
	 * @param array<mixed>                 $resources Resource records about to be returned.
	 * @param \WP\MCP\Core\McpServer|mixed $server    The McpServer instance.
	 * @param mixed                        $schema    Selected schema (unused).
	 * @return array<mixed> Original list on allow / fail-open; empty array on deny.
	 */
	public function gate_mcp_resources_list( $resources, $server, $schema = null ) {
		unset( $schema );
		return $this->hide_list_from_current_user( $server, 'mcp_resources_list' ) ? array() : $resources;
	}

	/**
	 * Filter callback for `mcp_adapter_prompts_list`.
	 *
	 * Sibling of {@see self::gate_mcp_tools_list()}; see that method for why
	 * the list primitives need gating at all.
	 *
	 * @since 0.3.9
	 *
	 * @param array<mixed>                 $prompts Prompt records about to be returned.
	 * @param \WP\MCP\Core\McpServer|mixed $server  The McpServer instance.
	 * @param mixed                        $schema  Selected schema (unused).
	 * @return array<mixed> Original list on allow / fail-open; empty array on deny.
	 */
	public function gate_mcp_prompts_list( $prompts, $server, $schema = null ) {
		unset( $schema );
		return $this->hide_list_from_current_user( $server, 'mcp_prompts_list' ) ? array() : $prompts;
	}

	/**
	 * Shared predicate behind the three list gates.
	 *
	 * Deliberately mirrors {@see self::apply_ac_gate()}'s resolution and
	 * fail-open ladder so a user can never be denied discovery on a server
	 * where they would have been allowed execution — the two would drift if
	 * each re-derived access independently.
	 *
	 * Every uncertain branch returns FALSE (show the list). Hiding is reserved
	 * for a definite "the operator's rule excludes this user": library present,
	 * server object usable, row still in the F011 DB, manager booted, and
	 * `user_has_access()` false. That is the same posture as the call-time
	 * gates — a configuration problem must not silently empty every catalogue
	 * on the site.
	 *
	 * @since 0.3.9
	 *
	 * @param \WP\MCP\Core\McpServer|mixed $server The McpServer instance.
	 * @param string                       $gate   Gate slug for the audit actions.
	 * @return bool True only when the list must be hidden from the current user.
	 */
	private function hide_list_from_current_user( $server, string $gate ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}

		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
			return false;
		}

		$server_slug = (string) $server->get_server_id();
		$user_id     = get_current_user_id();

		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => $server_slug,
				'number'      => 1,
			)
		);

		if ( empty( $rows ) ) {
			/** This action is documented in includes/AccessControl/AcrossAI_MCP_Access_Control.php */
			do_action( 'acrossai_mcp_access_control_missing_server', $server_slug, '', $user_id );
			return false;
		}

		$manager = $this->get_manager();
		if ( null === $manager ) {
			return false;
		}

		if ( $manager->user_has_access( $user_id, 'acrossai-mcp-manager', $server_slug ) ) {
			return false;
		}

		/** This action is documented in includes/AccessControl/AcrossAI_MCP_Access_Control.php */
		do_action( 'acrossai_mcp_access_control_denied', $user_id, $server_slug, '', $gate );

		return true;
	}

	/**
	 * Shared enforcement for the three MCP pre-dispatch filters
	 * (`pre_tool_call`, `pre_resource_read`, `pre_prompt_get`).
	 *
	 * Fail-open semantics identical to the original `gate_mcp_tool_call`:
	 *   - AC library missing → return $params unchanged. Paired with F042's
	 *     fail-CLOSED behavior on the same condition — see
	 *     {@see TransportPermissionDefault::filter_default_capability}.
	 *   - Malformed server arg → return $params unchanged.
	 *   - Server row not found (concurrent DELETE race) → fire
	 *     `acrossai_mcp_access_control_missing_server`, return $params.
	 *   - Manager boot failed → return $params.
	 *
	 * Deny path fires `acrossai_mcp_access_control_denied` with $context set
	 * to the concrete gate slug so operators subscribed to the hook can
	 * disambiguate tool vs resource vs prompt denials.
	 *
	 * @since 0.2.8
	 *
	 * @param mixed  $params  The filter's first arg — args / request_params /
	 *                        arguments — returned unchanged on allow so the
	 *                        vendor dispatch proceeds.
	 * @param mixed  $server  The McpServer instance passed as the 4th filter arg.
	 * @param string $subject Tool name / resource URI / prompt name — surfaced
	 *                        in observability hooks and error data.
	 * @param string $gate    Concrete gate slug — 'mcp_tool_call',
	 *                        'mcp_resource_read', or 'mcp_prompt_get'.
	 * @return mixed|\WP_Error $params on allow / fail-open; WP_Error on deny.
	 */
	private function apply_ac_gate( $params, $server, string $subject, string $gate ) {
		if ( ! $this->is_available() ) {
			return $params;
		}

		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
			return $params;
		}

		$server_slug = (string) $server->get_server_id();
		$user_id     = get_current_user_id();

		// Defensive resolution — verify the server still exists in our F011 DB
		// (Clarifications Q2 covers the race with a concurrent DELETE).
		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => $server_slug,
				'number'      => 1,
			)
		);

		if ( empty( $rows ) ) {
			/**
			 * Fires when the mcp-adapter routes a call to a server ID that no
			 * longer exists in the F011 DB (race with concurrent DELETE).
			 * Fire-and-forget; fail-open follows.
			 *
			 * The second arg carries the MCP subject: tool name for the
			 * pre_tool_call gate, resource URI for pre_resource_read, prompt
			 * name for pre_prompt_get. Subscribers should treat it as an
			 * opaque label for logging, not a tool-name assumption.
			 *
			 * @since 0.0.7
			 * @since 0.2.8 Second arg is now polymorphic (was always tool_name).
			 *
			 * @param string $server_slug The server_id string from mcp-adapter.
			 * @param string $subject     Tool name / resource URI / prompt name.
			 * @param int    $user_id     The current user id.
			 */
			do_action( 'acrossai_mcp_access_control_missing_server', $server_slug, $subject, $user_id );
			return $params;
		}

		$manager = $this->get_manager();
		if ( null === $manager ) {
			return $params;
		}

		if ( $manager->user_has_access( $user_id, 'acrossai-mcp-manager', $server_slug ) ) {
			return $params;
		}

		/**
		 * Fires immediately BEFORE returning the WP_Error on deny at any MCP
		 * pre-dispatch gate. Fire-and-forget; operators may hook for audit.
		 *
		 * @since 0.0.7
		 * @since 0.2.8 $context can now be 'mcp_resource_read' or 'mcp_prompt_get'
		 *              in addition to 'mcp_tool_call'; $subject is the gate's
		 *              polymorphic subject (tool name / URI / prompt name).
		 *
		 * @param int    $user_id     The current user id.
		 * @param string $server_slug The rule key.
		 * @param string $subject     Tool name / resource URI / prompt name.
		 * @param string $context     Gate slug — 'mcp_tool_call' | 'mcp_resource_read' | 'mcp_prompt_get'.
		 */
		do_action( 'acrossai_mcp_access_control_denied', $user_id, $server_slug, $subject, $gate );

		// Enriched error data — surfaced to any MCP client that renders WP_Error details.
		// Includes the current user's roles + resolved server slug so operators can debug
		// without hunting through server logs. Never leaks admin-only info: user_login /
		// email / capabilities are intentionally omitted.
		$user       = get_userdata( $user_id );
		$user_roles = ( $user instanceof \WP_User ) ? array_values( $user->roles ) : array();

		return new \WP_Error(
			'acrossai_mcp_access_denied',
			sprintf(
				/* translators: 1: MCP server slug, 2: comma-separated list of user's WP roles */
				__( 'Access denied by the "%1$s" server\'s Access Control policy. Your account roles (%2$s) are not in the allow-list. Contact a site administrator to request access.', 'acrossai-mcp-manager' ),
				$server_slug,
				empty( $user_roles ) ? __( 'none', 'acrossai-mcp-manager' ) : implode( ', ', $user_roles )
			),
			array(
				'status'      => 403,
				'server_slug' => $server_slug,
				'user_roles'  => $user_roles,
				'gate'        => $gate,
			)
		);
	}

	/**
	 * Provider filter callback — registers the 3 built-in v2 providers.
	 * Third-party plugins may append their own via the same filter tag.
	 *
	 * @since 0.0.7
	 *
	 * @param array<int, object> $providers Existing provider instances.
	 * @return array<int, object>
	 */
	public static function register_default_providers( array $providers ): array {
		$providers[] = new \WPBoilerplate\AccessControl\WpRoleProvider();
		$providers[] = new \WPBoilerplate\AccessControl\WpUserProvider();
		$providers[] = new \WPBoilerplate\AccessControl\WpCapabilityProvider();

		return $providers;
	}
}
