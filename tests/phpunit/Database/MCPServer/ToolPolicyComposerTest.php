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
 * Since schema 1.1.7 the only thing that separates them is the unmet-requirement
 * swap (plus registration narrowing). The coarse `tools_default_policy` rule that
 * used to be the other source of divergence is gone.
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

	public function test_on_a_healthy_server_the_two_composers_agree(): void {
		$row = $this->row( ServerTypes::LEGACY );

		$this->assertSame(
			ToolPolicy::compose_for_row( $row ),
			ToolPolicy::compose_effective_tools_for_row( $row ),
			'With no rule above curation, configured IS served.'
		);
	}

	public function test_an_unmet_requirement_makes_served_diverge_from_configured(): void {
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

		// The ONE remaining source of divergence. A server cannot advertise
		// tools whose abilities are not registered, so the client is told what
		// is wrong instead of being handed a list that cannot work.
		$row = $this->row( 'blocked' );

		$this->assertNotEmpty( ToolPolicy::compose_for_row( $row ), 'The configuration is untouched…' );
		$this->assertSame(
			array( SetupRequired::SLUG ),
			ToolPolicy::compose_effective_tools_for_row( $row ),
			'…but only the diagnostic is served.'
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

		$row = $this->row( 'blocked' );

		// FR-023: the diagnostic swap is a READ-TIME substitution. The operator's
		// curation must still be there to come back to.
		$this->assertNotEmpty( ToolPolicy::compose_for_row( $row ) );
	}

	/**
	 * @param string               $server_type Type slug to store.
	 * @param array<string, mixed> $columns     Extra columns, e.g. the tool_* flags.
	 */
	private function row( string $server_type, array $columns = array() ) {
		$slug = 'composer-' . uniqid();
		$id   = (int) MCPServerQuery::instance()->add_item(
			array_merge(
				array(
					'server_name'            => 'Composer test server',
					'server_slug'            => $slug,
					'description'            => 'Seeded by ToolPolicyComposerTest',
					'is_enabled'             => 0,
					'server_type'            => $server_type,
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
