<?php
/**
 * Feature 090 — the diagnostic ability (T039, T040, T045).
 *
 * When a server's type requirement is unmet the composed tool list is replaced by
 * a single self-describing entry. Three properties make that safe, and each has
 * its own test here because each fails differently:
 *
 *   T039  the server STILL REGISTERS. Skipping create_server() would 404 the
 *         route and kill a live client session instead of explaining itself.
 *   T040  the diagnostic must not leak. It is a plugin-owned repair notice, not
 *         a tool: absent from the tool pool, absent from discovery, and absent
 *         from any server whose requirement IS met.
 *   T045  the swap WRITES NOTHING. A deactivate/reactivate cycle must leave the
 *         operator's curated rows byte-identical (FR-023, SC-006).
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\MCP
 */

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\MCP;

use AcrossAI_MCP_Manager\Includes\Abilities\SetupRequired;
use AcrossAI_MCP_Manager\Includes\Abilities\ToolAbilities;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class SetupRequiredTest extends WP_UnitTestCase {

	/** @var int[] */
	private $created = array();

	public function set_up(): void {
		parent::set_up();

		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['blocked'] = array(
					'label'    => 'Blocked',
					'requires' => 'a-plugin-that-is-not-installed-here',
				);
				$types['fine']    = array(
					'label' => 'Fine',
					'tools' => ToolPolicy::PROTOCOL_TOOLS,
				);
				return $types;
			}
		);
	}

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

	// ------------------------------------------------------------- T039 ----

	public function test_an_unmet_requirement_serves_exactly_the_diagnostic(): void {
		$row = $this->row( 'blocked' );

		$this->assertSame(
			array( SetupRequired::SLUG ),
			ToolPolicy::compose_effective_tools_for_row( $row ),
			'Exactly one entry — not an empty list, which tells a client nothing.'
		);
	}

	public function test_the_server_still_has_a_composable_list(): void {
		// The route must keep answering. An empty or error-throwing composition
		// here is what would make registration skip the server and 404 a live
		// session — the failure this design explicitly refuses.
		$row = $this->row( 'blocked' );

		$this->assertIsArray( ToolPolicy::compose_effective_tools_for_row( $row ) );
		$this->assertNotEmpty( ToolPolicy::compose_effective_tools_for_row( $row ) );
	}

	public function test_the_message_names_the_required_plugin(): void {
		// FR-022: the description AND the return value both name what is missing.
		// FR-022a: it is translatable like every other string, so this asserts
		// content rather than an exact literal.
		$this->assertStringContainsString( 'Abilities Manager', SetupRequired::message() );
	}

	public function test_the_message_discloses_no_paths_or_versions(): void {
		// Security checklist: only the plugin's public NAME. No filesystem paths,
		// no versions, no site configuration.
		$message = SetupRequired::message();

		$this->assertStringNotContainsString( ABSPATH, $message );
		$this->assertStringNotContainsString( '.php', $message );
		$this->assertStringNotContainsString( WP_CONTENT_DIR, $message );
	}

	// ------------------------------------------------------------- T040 ----

	public function test_the_diagnostic_is_not_a_tool_level_ability(): void {
		// It is a repair notice the plugin owns, not something an operator picks.
		// Leaking it into the pool would let it be curated onto a healthy server.
		$this->assertNotContains( SetupRequired::SLUG, ToolAbilities::get_slugs() );
	}

	public function test_the_diagnostic_is_absent_from_the_picker_pool(): void {
		$this->assertNotContains( SetupRequired::SLUG, ServerTypes::pool() );
	}

	public function test_a_healthy_server_never_serves_the_diagnostic(): void {
		$row = $this->row( 'fine' );

		$this->assertNotContains(
			SetupRequired::SLUG,
			ToolPolicy::compose_effective_tools_for_row( $row ),
			'The diagnostic appears ONLY where the requirement is unmet.'
		);
	}

	public function test_the_diagnostic_is_not_reachable_by_curating_it(): void {
		// Defence in depth: even if the slug were written into a healthy server's
		// curated rows, it is not a registered tool-level ability, so the
		// narrowing in registered_only() keeps it out of what is served.
		$row = $this->row( 'fine' );
		MCPServerToolQuery::instance()->replace_set( (int) $row->id, array( SetupRequired::SLUG ) );

		$row = MCPServerQuery::instance()->query( array( 'id' => (int) $row->id, 'number' => 1 ) )[0];

		$this->assertNotContains( SetupRequired::SLUG, ToolPolicy::compose_effective_tools_for_row( $row ) );
	}

	// ------------------------------------------------------------- T045 ----

	public function test_the_swap_writes_nothing_to_curated_rows(): void {
		$row       = $this->row( 'blocked' );
		$server_id = (int) $row->id;

		$curated = array( ToolPolicy::PROTOCOL_TOOLS[0], ToolPolicy::PROTOCOL_TOOLS[1] );
		MCPServerToolQuery::instance()->replace_set( $server_id, $curated );
		$before = MCPServerToolQuery::instance()->get_added_slugs( $server_id );

		// Compose repeatedly — this is what every MCP request does while the
		// dependency is missing. If the swap wrote, the operator's selection
		// would erode with traffic rather than survive it.
		$row = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )[0];
		ToolPolicy::compose_effective_tools_for_row( $row );
		ToolPolicy::compose_effective_tools_for_row( $row );
		ToolPolicy::compose_effective_tools_for_row( $row );

		$this->assertSame(
			$before,
			MCPServerToolQuery::instance()->get_added_slugs( $server_id ),
			'FR-023 / SC-006 — a deactivate cycle must leave curation byte-identical.'
		);
	}

	public function test_the_selection_returns_when_the_requirement_is_met_again(): void {
		$row       = $this->row( 'blocked' );
		$server_id = (int) $row->id;

		$curated = array( ToolPolicy::PROTOCOL_TOOLS[0] );
		MCPServerToolQuery::instance()->replace_set( $server_id, $curated );

		$row = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )[0];
		$this->assertSame( array( SetupRequired::SLUG ), ToolPolicy::compose_effective_tools_for_row( $row ) );

		// "Reactivate": move the row to a type whose requirement is satisfied.
		MCPServerQuery::instance()->update_item( $server_id, array( 'server_type' => 'fine' ) );
		$row = MCPServerQuery::instance()->query( array( 'id' => $server_id, 'number' => 1 ) )[0];

		$this->assertContains(
			$curated[0],
			ToolPolicy::compose_effective_tools_for_row( $row ),
			'The curated pick returns intact — it was never rewritten.'
		);
	}

	private function row( string $server_type ) {
		$slug = 'setupreq-' . uniqid();
		$id   = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'             => 'SetupRequired test server',
				'server_slug'             => $slug,
				'description'             => 'Seeded by SetupRequiredTest',
				'is_enabled'              => 0,
				'server_type'             => $server_type,
				'tool_discover_abilities' => 0,
				'tool_get_ability_info'   => 0,
				'tool_execute_ability'    => 0,
				'registered_from'         => 'database',
				'server_route_namespace'  => 'mcp',
				'server_route'            => $slug,
				'server_version'          => 'v1.0.0',
			)
		);

		$this->created[] = $id;

		return MCPServerQuery::instance()->query( array( 'id' => $id, 'number' => 1 ) )[0];
	}
}
