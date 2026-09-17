<?php
/**
 * Feature 090 — the two composers answer DIFFERENT questions (T019).
 *
 * Before F090 `compose_effective_tools_for_row()` was a straight passthrough to
 * `compose_for_row()`. The architecture review rated splitting the precedence
 * chain across `ToolPolicy` and `MCP\Controller` High severity, because REST reads
 * one composer and MCP registration reads the other: a rule implemented in only
 * one of them lets the Tools tab show the operator a list the server is not
 * serving.
 *
 *   compose_for_row()                 -> what the operator CONFIGURED
 *   compose_effective_tools_for_row() -> what the server ACTUALLY SERVES
 *
 * This file exists to fail the moment they collapse back into each other.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Abilities\SetupRequired;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class ToolPolicyComposerTest extends WP_UnitTestCase {

	/** @var int[] */
	private $created = array();

	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->created as $id ) {
			$wpdb->delete( $wpdb->prefix . 'acrossai_mcp_servers', array( 'id' => $id ), array( '%d' ) );
		}

		$this->created = array();
		remove_all_filters( ServerTypes::FILTER );
		parent::tear_down();
	}

	public function test_under_per_tool_the_two_composers_agree(): void {
		$row = $this->row( ServerTypes::LEGACY, ToolPolicy::POLICY_PER_TOOL );

		$this->assertSame(
			ToolPolicy::compose_for_row( $row ),
			ToolPolicy::compose_effective_tools_for_row( $row ),
			'per-tool is the pass-through case — configured IS served.'
		);
	}

	public function test_hide_makes_served_diverge_from_configured(): void {
		$row = $this->row( ServerTypes::LEGACY, ToolPolicy::POLICY_HIDE );

		$this->assertNotEmpty( ToolPolicy::compose_for_row( $row ), 'The configuration is untouched…' );
		$this->assertSame( array(), ToolPolicy::compose_effective_tools_for_row( $row ), '…but nothing is served.' );
	}

	public function test_expose_makes_served_diverge_from_configured(): void {
		// Configure NOTHING — all three protocol columns off, no curated rows —
		// so the divergence is deterministic. An earlier version of this test
		// assumed the pool was a superset of a default row's configuration, which
		// is true on a populated site and FALSE in CI, where no companion plugin
		// contributes toolsets and the pool is exactly the three protocol tools.
		$row = $this->row(
			ServerTypes::LEGACY,
			ToolPolicy::POLICY_EXPOSE,
			array(
				'tool_discover_abilities' => 0,
				'tool_get_ability_info'   => 0,
				'tool_execute_ability'    => 0,
			)
		);

		$this->assertSame( array(), ToolPolicy::compose_for_row( $row ), 'nothing configured…' );
		$this->assertNotEmpty( ToolPolicy::compose_effective_tools_for_row( $row ), '…yet the rule serves the pool.' );
	}

	public function test_an_unmet_requirement_outranks_every_policy(): void {
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['blocked'] = array(
					'label'    => 'Blocked',
					'requires' => 'a-plugin-that-is-not-installed-here',
				);
				return $types;
			}
		);

		// Layer 1 beats layer 2: `expose` cannot advertise tools whose abilities
		// are not registered, so the client is told what is wrong instead of
		// being handed a list that cannot work.
		$row = $this->row( 'blocked', ToolPolicy::POLICY_EXPOSE );

		$this->assertSame(
			array( SetupRequired::SLUG ),
			ToolPolicy::compose_effective_tools_for_row( $row )
		);
	}

	public function test_the_unmet_branch_leaves_the_configuration_readable(): void {
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['blocked'] = array(
					'label'    => 'Blocked',
					'requires' => 'a-plugin-that-is-not-installed-here',
				);
				return $types;
			}
		);

		$row = $this->row( 'blocked', ToolPolicy::POLICY_PER_TOOL );

		// FR-023: the diagnostic swap is a READ-TIME substitution. The operator's
		// curation must still be there to come back to.
		$this->assertNotEmpty( ToolPolicy::compose_for_row( $row ) );
	}

	/**
	 * @param string               $server_type Type slug to store.
	 * @param string               $policy      Standing rule to store.
	 * @param array<string, mixed> $columns     Extra columns, e.g. the tool_* flags.
	 */
	private function row( string $server_type, string $policy, array $columns = array() ) {
		$slug = 'composer-' . uniqid();
		$id   = (int) MCPServerQuery::instance()->add_item(
			array_merge(
				array(
					'server_name'            => 'Composer test server',
					'server_slug'            => $slug,
					'description'            => 'Seeded by ToolPolicyComposerTest',
					'is_enabled'             => 0,
					'server_type'            => $server_type,
					'tools_default_policy'   => $policy,
					'registered_from'        => 'database',
					'server_route_namespace' => 'mcp',
					'server_route'           => $slug,
					'server_version'         => 'v1.0.0',
				),
				$columns
			)
		);

		$this->created[] = $id;

		return MCPServerQuery::instance()->query( array( 'id' => $id, 'number' => 1 ) )[0];
	}
}
