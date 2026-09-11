<?php
/**
 * Feature 017 — call-time enforcement gate for per-server ability exposure.
 *
 * Wired on the vendor's `mcp_adapter_pre_tool_call` filter at priority 20 —
 * runs LATER than F015's `AcrossAI_MCP_Access_Control::gate_mcp_tool_call`
 * (priority 10) so a hidden-on-this-server decision from F017 supersedes any
 * F015 AccessControl "allow" verdict. Never overrides an F015 deny.
 *
 * A11 pure-service exception — no singleton, no ctor. Wired via
 * `Main::define_admin_hooks()` per A1. The class is instantiated once by
 * Main.php and passed to the Loader as the callback target.
 *
 * SEC-001 closure (Session 2026-07-07 Q4 — FR-030).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\MCP
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\MCP;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Call-time enforcement gate.
 *
 * @since 0.1.0
 */
final class AbilityExposureGate {

	/**
	 * Singleton instance — needed because the Loader binds a callback to
	 * `[ $object, 'method' ]` and Main.php passes an instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 0.1.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — singleton enforcement (S6).
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * `mcp_adapter_pre_tool_call` callback — 403 on hidden abilities.
	 *
	 * Signature matches the vendor's filter:
	 *   apply_filters( 'mcp_adapter_pre_tool_call', $args, $tool_name, $mcp_tool, $server )
	 *
	 * WordPress chains callbacks — an earlier priority-10 F015 callback may
	 * have already returned a `WP_Error` in `$args`. This callback MUST
	 * propagate that WP_Error unchanged (F017 only ADDS denials; never
	 * removes an F015 deny).
	 *
	 * Fail-open when the server slug cannot be resolved (matches D19
	 * fail-open observability — same convention F015 uses).
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed>|\WP_Error       $args      Tool call args (or a WP_Error
	 *                                                already returned by an earlier
	 *                                                priority callback).
	 * @param string                       $tool_name The MCP tool name (== ability slug).
	 * @param mixed                        $mcp_tool  Vendor McpTool instance (unused).
	 * @param \WP\MCP\Core\McpServer|mixed $server    Vendor McpServer instance.
	 * @return array<mixed>|\WP_Error Original `$args` on allow / fail-open; WP_Error on deny.
	 */
	public function gate_tool_call_by_exposure( $args, string $tool_name, $mcp_tool, $server ) {
		unset( $mcp_tool );

		// Propagate an earlier-priority WP_Error unchanged — F017 never
		// overrides an F015 deny with an allow.
		if ( is_wp_error( $args ) ) {
			return $args;
		}

		// Fail-open when the server accessor is missing.
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_id' ) ) {
			return $args;
		}

		$server_slug = (string) $server->get_server_id();
		if ( '' === $server_slug ) {
			return $args;
		}

		// Resolve slug → integer server_id via the F011 MCPServer table.
		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => $server_slug,
				'number'      => 1,
			)
		);
		if ( empty( $rows ) ) {
			// Server row missing — matches the F015 race pattern; fail-open.
			return $args;
		}
		$server_id = (int) $rows[0]->id;

		// Resolve the ability metadata for the fallback path.
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return $args; // Abilities API absent — nothing to enforce.
		}
		$ability = self::resolve_ability( $tool_name, $mcp_tool );
		if ( ! $ability ) {
			return $args; // Ability not registered — nothing to enforce.
		}
		$slug = $ability->get_name();

		// Tool-level abilities are exempt. They are the transport, not cargo:
		// the mcp-adapter protocol tools every client needs to bootstrap, plus
		// any dispatcher a companion plugin declares. Letting the Abilities
		// tab's hide policy 403 them would mean "Disable All" silently breaks
		// every connected client instead of hiding abilities from them — the
		// hiding still happens, one layer down, inside those tools' own
		// callbacks (Execute::check_permission, acrossai_toolset_member_visible).
		if ( in_array( $slug, self::exempt_slugs(), true ) ) {
			return $args;
		}

		if ( ExposureResolver::resolve_effective( $server_id, $slug, $ability->get_meta() ) ) {
			return $args;
		}

		return new \WP_Error(
			'acrossai_mcp_ability_not_exposed',
			__( 'This ability is not exposed on this MCP server.', 'acrossai-mcp-manager' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Ability slugs this gate never denies.
	 *
	 * Seeded with the three mcp-adapter protocol tools and widened by the same
	 * filter the admin pickers read, so a companion plugin that declares a
	 * dispatcher tool-level gets it exempted here for free.
	 *
	 * @since 0.1.1
	 * @return string[]
	 */
	private static function exempt_slugs(): array {
		/** This filter is documented in includes/Abilities/ToolAbilities.php */
		$slugs = apply_filters( 'acrossai_mcp_manager_tool_abilities', ToolPolicy::PROTOCOL_TOOLS );

		return array_values( array_unique( array_map( 'strval', (array) $slugs ) ) );
	}

	/**
	 * The ability behind the tool being called.
	 *
	 * `$tool_name` is NOT an ability slug. The vendor registers each tool under
	 * `McpNameSanitizer::sanitize_name()` of the ability's name — at minimum
	 * `/` → `-`, plus accent folding, character replacement and a hash suffix
	 * past 128 characters — and `mcp_adapter_tool_name` can rewrite it outright.
	 * Every bundled ability slug contains a slash, so the pre-0.1.1 code
	 * (`wp_get_ability( $tool_name )`) missed on all of them, fail-opened on
	 * every call, and emitted a `_doing_it_wrong()` notice each time. Same
	 * defect ToolExposureGate carried; same resolution order used here.
	 *
	 * @since 0.1.1
	 * @param string $tool_name Tool name as invoked.
	 * @param mixed  $mcp_tool  Vendor McpTool instance, when supplied.
	 * @return \WP_Ability|null
	 */
	private static function resolve_ability( string $tool_name, $mcp_tool ): ?\WP_Ability {
		// Authoritative: the tool's own record of the ability behind it. Also
		// survives a `mcp_adapter_tool_name` rename, which no lookup could.
		$reported = self::ability_name_of( $mcp_tool );
		if ( '' !== $reported && \wp_has_ability( $reported ) ) {
			return \wp_get_ability( $reported );
		}

		// A slug the sanitizer left untouched (no slash, already valid).
		if ( \wp_has_ability( $tool_name ) ) {
			return \wp_get_ability( $tool_name );
		}

		// Last resort: the sanitizer is not invertible, so compare forward.
		foreach ( \wp_get_abilities() as $ability ) {
			if ( self::sanitize_slug( $ability->get_name() ) === $tool_name ) {
				return $ability;
			}
		}

		return null;
	}

	/**
	 * The ability slug a tool reports for itself, or '' when it reports none.
	 *
	 * @since 0.1.1
	 * @param mixed $mcp_tool Vendor McpTool instance, when supplied.
	 * @return string
	 */
	private static function ability_name_of( $mcp_tool ): string {
		if ( ! is_object( $mcp_tool ) || ! method_exists( $mcp_tool, 'get_observability_context' ) ) {
			return '';
		}

		$context = $mcp_tool->get_observability_context();
		if ( ! is_array( $context ) || ! isset( $context['ability_name'] ) || ! is_string( $context['ability_name'] ) ) {
			return '';
		}

		return $context['ability_name'];
	}

	/**
	 * A raw slug in the form the vendor would have registered it under.
	 *
	 * Delegates to the vendor sanitizer when loadable so the two cannot drift;
	 * the local fallback covers the `/` → `-` step, the only transformation
	 * every bundled ability slug actually undergoes.
	 *
	 * @since 0.1.1
	 * @param string $slug Raw ability slug.
	 * @return string
	 */
	private static function sanitize_slug( string $slug ): string {
		$sanitizer = '\\WP\\MCP\\Domain\\Utils\\McpNameSanitizer';

		if ( class_exists( $sanitizer ) && is_callable( array( $sanitizer, 'sanitize_name' ) ) ) {
			$sanitized = call_user_func( array( $sanitizer, 'sanitize_name' ), $slug );
			if ( is_string( $sanitized ) ) {
				return $sanitized;
			}
		}

		return str_replace( '/', '-', $slug );
	}
}
