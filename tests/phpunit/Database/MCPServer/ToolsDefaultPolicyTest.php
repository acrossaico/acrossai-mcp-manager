<?php
/**
 * Feature 090 — the standing tool rule (T046, T047).
 *
 * `tools_default_policy` is a STANDING RULE, not a one-time sweep. That distinction
 * is the whole reason it is a column rather than a bulk write: choosing "expose"
 * must cover tools that become available LATER, when a companion plugin is
 * installed, without the operator noticing and repeating the action (FR-026).
 *
 * T047 covers the counterpart: switching the server type resets a coarse rule back
 * to `per-tool` (FR-012a), so the new type's set takes effect immediately instead
 * of appearing to do nothing — a switch that silently changed nothing visible
 * would read as a bug.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Abilities\ToolAbilities;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use AcrossAI_MCP_Manager\Includes\REST\ToolsController;
use WP_REST_Request;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class ToolsDefaultPolicyTest extends WP_UnitTestCase {

	/** @var int[] */
	private $created = array();

	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->created as $id ) {
			MCPServerToolQuery::instance()->delete_items_for_server( $id );
			$wpdb->delete( $wpdb->prefix . 'acrossai_mcp_servers', array( 'id' => $id ), array( '%d' ) );
		}

		$this->created = array();
		remove_all_filters( ServerTypes::FILTER );
		remove_all_filters( 'acrossai_mcp_manager_tool_abilities' );
		parent::tear_down();
	}

	// ------------------------------------------------------------- T046 ----

	public function test_hide_serves_nothing(): void {
		$row = $this->row( ToolPolicy::POLICY_HIDE );

		$this->assertSame( array(), ToolPolicy::compose_effective_tools_for_row( $row ) );
	}

	public function test_per_tool_reproduces_the_configured_set(): void {
		$row = $this->row( ToolPolicy::POLICY_PER_TOOL );

		$this->assertSame(
			ToolPolicy::compose_for_row( $row ),
			ToolPolicy::compose_effective_tools_for_row( $row )
		);
	}

	public function test_expose_serves_the_whole_pool(): void {
		$row = $this->row( ToolPolicy::POLICY_EXPOSE );

		$this->assertSame(
			ServerTypes::pool(),
			ToolPolicy::compose_effective_tools_for_row( $row )
		);
	}

	public function test_expose_is_a_standing_rule_not_a_snapshot(): void {
		// The property FR-026 actually asks for, and the reason this is a column.
		// A tool that becomes available AFTER the choice was made is served with
		// no further admin action — resolved live on every request, never
		// captured at the moment the button was clicked.
		$row    = $this->row( ToolPolicy::POLICY_EXPOSE );
		$before = ToolPolicy::compose_effective_tools_for_row( $row );

		// A companion plugin is activated and contributes a tool-level ability.
		// Use an already-registered slug so `registered_only()` keeps it — what
		// is under test is the rule's liveness, not ability registration.
		$late = ToolPolicy::PROTOCOL_TOOLS[2];
		add_filter(
			'acrossai_mcp_manager_tool_abilities',
			static function ( array $slugs ) use ( $late ): array {
				return array_values( array_unique( array_merge( array_diff( $slugs, array( $late ) ), array( $late ) ) ) );
			}
		);

		$after = ToolPolicy::compose_effective_tools_for_row( $row );

		$this->assertContains( $late, $after );
		$this->assertNotEmpty( $before, 'sanity: the rule was already serving before the late arrival' );
	}

	public function test_a_coarse_rule_outranks_the_curated_set(): void {
		// FR-027: `expose` and `hide` are STANDING rules that win over individual
		// curation, exactly as abilities_default_policy wins over per-ability rows.
		$row = $this->row( ToolPolicy::POLICY_HIDE );
		MCPServerToolQuery::instance()->replace_set( (int) $row->id, array( ToolPolicy::PROTOCOL_TOOLS[0] ) );

		$row = $this->refresh( (int) $row->id );

		$this->assertNotEmpty( ToolPolicy::compose_for_row( $row ), 'The curation is still stored…' );
		$this->assertSame( array(), ToolPolicy::compose_effective_tools_for_row( $row ), '…and the rule still wins.' );
	}

	// ------------------------------------------------------------- T047 ----

	/**
	 * @dataProvider provideCoarsePolicies
	 *
	 * @param string $policy A coarse rule that a type switch must clear.
	 */
	public function test_switching_type_resets_a_coarse_rule_to_per_tool( string $policy ): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		do_action( 'rest_api_init' );
		ToolsController::instance()->register_routes();

		$row       = $this->row( $policy );
		$server_id = (int) $row->id;

		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['switch-target'] = array(
					'label' => 'Switch Target',
					'tools' => ToolPolicy::PROTOCOL_TOOLS,
				);
				return $types;
			}
		);

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/servers/' . $server_id . '/tools' );
		$req->set_param( 'tools', ToolPolicy::PROTOCOL_TOOLS );
		$req->set_param( 'server_type', 'switch-target' );
		$res = rest_do_request( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame(
			ToolPolicy::POLICY_PER_TOOL,
			(string) $this->refresh( $server_id )->tools_default_policy,
			'FR-012a — otherwise the new type\'s set appears to do nothing.'
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provideCoarsePolicies(): array {
		return array(
			'expose' => array( ToolPolicy::POLICY_EXPOSE ),
			'hide'   => array( ToolPolicy::POLICY_HIDE ),
		);
	}

	public function test_a_switch_that_changes_nothing_leaves_the_rule_alone(): void {
		// The reset is triggered by a CHANGE of type, not by any write. Resetting
		// on a no-op save would quietly undo a rule the operator still wants.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		do_action( 'rest_api_init' );
		ToolsController::instance()->register_routes();

		$row       = $this->row( ToolPolicy::POLICY_EXPOSE );
		$server_id = (int) $row->id;

		$req = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/servers/' . $server_id . '/tools' );
		$req->set_param( 'tools', ToolPolicy::PROTOCOL_TOOLS );
		$req->set_param( 'server_type', ServerTypes::LEGACY ); // Same type it already has.
		$res = rest_do_request( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame(
			ToolPolicy::POLICY_EXPOSE,
			(string) $this->refresh( $server_id )->tools_default_policy
		);
	}

	// ---------------------------------------------------------- helpers ----

	private function row( string $policy ) {
		$slug = 'policy-' . uniqid();
		$id   = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Policy test server',
				'server_slug'            => $slug,
				'description'            => 'Seeded by ToolsDefaultPolicyTest',
				'is_enabled'             => 0,
				'server_type'            => ServerTypes::LEGACY,
				'tools_default_policy'   => $policy,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);

		$this->created[] = $id;

		return $this->refresh( $id );
	}

	private function refresh( int $server_id ) {
		return MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )[0];
	}
}
