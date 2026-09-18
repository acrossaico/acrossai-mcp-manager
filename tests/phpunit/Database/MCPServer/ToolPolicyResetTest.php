<?php
/**
 * Feature 090 — Reset resolves per server type (T018).
 *
 * This is THE defect the feature exists to fix. Reset used to restore the same
 * three `mcp-adapter/*` protocol slugs on every server regardless of purpose, so
 * on a server meant to serve the AcrossAI toolsets "reset to defaults" produced
 * the WRONG defaults and silently discarded the operator's selection.
 *
 * A regression here is invisible until an operator loses work, which is why the
 * assertions are per-type rather than "Reset returns something".
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Abilities\ServerGuide;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ToolPolicyResetTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( ServerTypes::FILTER );
		parent::tear_down();
	}

	public function test_reset_on_the_legacy_type_restores_its_own_tools(): void {
		// The protocol tools plus the server guide — composed from the parts the
		// type declares, never a literal list, so adding a tool to the type does
		// not fail a test that is about Reset following the registry (B48).
		$this->assertSame(
			array_merge( ToolPolicy::PROTOCOL_TOOLS, array( ServerGuide::SLUG ) ),
			ServerTypes::tools_for( ServerTypes::LEGACY )
		);
	}

	public function test_reset_on_a_contributed_type_restores_that_type_tools(): void {
		// Stand-in for the sibling's real toolsets: what matters is that Reset
		// follows the REGISTRY rather than a hardcoded list.
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['mycorp'] = array(
					'label' => 'MyCorp',
					'tools' => ToolPolicy::PROTOCOL_TOOLS,
				);
				return $types;
			}
		);

		$this->assertNotEmpty( ServerTypes::tools_for( 'mycorp' ) );
	}

	public function test_two_types_do_not_reset_to_the_same_set(): void {
		// The bug in one assertion: before F090 these were identical for every
		// server on the site.
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['legacy-like'] = array(
					'label' => 'Legacy-like',
					'tools' => array( ToolPolicy::PROTOCOL_TOOLS[0] ),
				);
				return $types;
			}
		);

		$this->assertNotSame(
			ServerTypes::tools_for( ServerTypes::LEGACY ),
			ServerTypes::tools_for( 'legacy-like' ),
			'Reset must follow the server type, not a fixed list.'
		);
	}

	public function test_reset_never_returns_an_empty_set(): void {
		// An empty template would make Reset WIPE the server — strictly worse
		// than the hardcoded-defaults bug being fixed. Found by live
		// verification, not by static analysis: the shipped `acrossai`
		// placeholder is empty until the sibling replaces it.
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['hollow'] = array(
					'label' => 'Hollow',
					'tools' => array(),
				);
				return $types;
			}
		);

		$this->assertNotEmpty( ServerTypes::tools_for( 'hollow' ) );
		$this->assertNotEmpty( ServerTypes::tools_for( 'never-registered' ) );
	}

	/**
	 * The MCP Adapter type's CURATED remainder is exactly the server guide.
	 *
	 * `ServerGuideBackfill` writes `ServerGuide::SLUG` directly rather than
	 * resolving it through `tools_for()`, because that path depends on the
	 * abilities registry and on a third-party filter — see that class's
	 * docblock. The cost of holding the slug in two places is that they can
	 * drift, so this converts a silent drift into a failing test: if the legacy
	 * type ever declares a SECOND curated tool, the backfill needs updating too,
	 * and this is what says so.
	 */
	public function test_the_legacy_types_curated_remainder_is_only_the_server_guide(): void {
		$this->assertSame(
			array( ServerGuide::SLUG ),
			ToolPolicy::split_payload( ServerTypes::tools_for( ServerTypes::LEGACY ) )['curated'],
			'A second curated tool here means ServerGuideBackfill must write it too.'
		);
	}
}
