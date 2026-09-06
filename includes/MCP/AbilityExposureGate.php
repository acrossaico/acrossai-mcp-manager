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
		$ability = \wp_get_ability( $tool_name );
		if ( ! $ability ) {
			return $args; // Ability not registered — nothing to enforce.
		}
		$meta = $ability->get_meta();

		if ( ExposureResolver::resolve_effective( $server_id, $tool_name, $meta ) ) {
			return $args;
		}

		return new \WP_Error(
			'acrossai_mcp_ability_not_exposed',
			__( 'This ability is not exposed on this MCP server.', 'acrossai-mcp-manager' ),
			array( 'status' => 403 )
		);
	}
}
