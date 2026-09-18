<?php
/**
 * The one-shot `mcp-adapter/server-guide` backfill.
 *
 * The guide has no `tool_*` column, so it exists only as a curated row. Servers
 * that predate it — and the SEEDED default server, which gets no tool state at
 * all — therefore served three tools where their type declares four.
 *
 * What these tests are really protecting is the three ways this could go wrong
 * rather than merely not work: adding the guide to a server whose type never
 * declared it, destroying curated picks alongside the insert, and re-asserting
 * the row against an operator who removed it.
 *
 * Harness notes:
 *   - `set_up()` / `tear_down()` MUST be public — this WP test library fatals
 *     on `protected` ones (B56).
 *   - The done-flag is restored in `tear_down()` unconditionally, so a failure
 *     mid-test cannot leave a stamped flag that silently disables every later
 *     test in the file.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Abilities\ServerGuide;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerGuideBackfill;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class ServerGuideBackfillTest extends WP_UnitTestCase {

	/** @var int[] */
	private $created = array();

	public function set_up(): void {
		parent::set_up();
		delete_option( ServerGuideBackfill::DONE_OPTION );
	}

	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->created as $id ) {
			$wpdb->delete( $wpdb->prefix . 'acrossai_mcp_server_tools', array( 'server_id' => $id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'acrossai_mcp_servers', array( 'id' => $id ), array( '%d' ) );
		}

		$this->created = array();
		delete_option( ServerGuideBackfill::DONE_OPTION );
		remove_all_filters( ServerTypes::FILTER );
		parent::tear_down();
	}

	public function test_adds_the_guide_to_an_mcp_adapter_server_that_lacks_it(): void {
		$id = $this->server( ServerTypes::LEGACY );
		$this->assertNotContains( ServerGuide::SLUG, $this->slugs( $id ), 'setup' );

		ServerGuideBackfill::maybe_backfill();

		$this->assertContains( ServerGuide::SLUG, $this->slugs( $id ) );
	}

	/**
	 * An `acrossai` server carries the sibling's `toolset/server-guide`. Giving
	 * it this one would put a tool in its list that its own type never declared.
	 */
	public function test_leaves_a_server_of_another_type_alone(): void {
		$id = $this->server( ServerTypes::ACROSSAI );

		ServerGuideBackfill::maybe_backfill();

		$this->assertNotContains( ServerGuide::SLUG, $this->slugs( $id ) );
	}

	/**
	 * The reason this uses `add_item()` and not `replace_set()`, which would
	 * delete every slug not in the desired list.
	 */
	public function test_leaves_other_curated_rows_untouched(): void {
		$id = $this->server( ServerTypes::LEGACY );
		MCPServerToolQuery::instance()->add_item(
			array(
				'server_id'    => $id,
				'ability_slug' => 'toolset/content',
			)
		);

		ServerGuideBackfill::maybe_backfill();

		$slugs = $this->slugs( $id );
		$this->assertContains( 'toolset/content', $slugs, 'An operator pick must survive the backfill.' );
		$this->assertContains( ServerGuide::SLUG, $slugs );
	}

	public function test_a_second_run_adds_nothing(): void {
		$id = $this->server( ServerTypes::LEGACY );

		ServerGuideBackfill::maybe_backfill();
		ServerGuideBackfill::maybe_backfill();

		$this->assertSame(
			1,
			count( array_keys( $this->slugs( $id ), ServerGuide::SLUG, true ) ),
			'The unique index would refuse a duplicate, but the flag should mean we never try.'
		);
	}

	/**
	 * The operator-intent guarantee, and the reason this is one-shot rather than
	 * a reconciler: a row removed on purpose must STAY removed.
	 */
	public function test_a_removed_guide_is_not_re_added_on_a_later_run(): void {
		global $wpdb;

		$id = $this->server( ServerTypes::LEGACY );
		ServerGuideBackfill::maybe_backfill();
		$this->assertContains( ServerGuide::SLUG, $this->slugs( $id ), 'setup' );

		$wpdb->delete(
			$wpdb->prefix . 'acrossai_mcp_server_tools',
			array(
				'server_id'    => $id,
				'ability_slug' => ServerGuide::SLUG,
			),
			array( '%d', '%s' )
		);

		ServerGuideBackfill::maybe_backfill();

		$this->assertNotContains(
			ServerGuide::SLUG,
			$this->slugs( $id ),
			'Re-asserting would undo a deliberate removal from the Tools tab.'
		);
	}

	/**
	 * It must NOT resolve the slug through `ServerTypes::tools_for()`.
	 *
	 * That path runs `registered_only()`, which exempts the three protocol
	 * slugs but NOT this guide — so on a request where the registry is populated
	 * before `ServerGuide::register()` has run, the guide is filtered out and a
	 * tools_for-based backfill silently writes nothing. It also applies the
	 * `acrossai_mcp_server_types` filter, running third-party code.
	 *
	 * Simulated here by emptying the legacy type's declared tools: a backfill
	 * that asked the type would now find nothing to write. This one still
	 * writes, because it holds the constant directly.
	 */
	public function test_does_not_resolve_the_slug_through_the_type_registry(): void {
		$id = $this->server( ServerTypes::LEGACY );

		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types[ ServerTypes::LEGACY ]['tools'] = array();
				return $types;
			}
		);

		$this->assertNotContains(
			ServerGuide::SLUG,
			ServerTypes::tools_for( ServerTypes::LEGACY ),
			'setup: the type must no longer declare the guide, or this proves nothing.'
		);

		ServerGuideBackfill::maybe_backfill();

		$this->assertContains( ServerGuide::SLUG, $this->slugs( $id ) );
	}

	// ---------------------------------------------------------- helpers ----

	private function server( string $server_type ): int {
		$slug = 'guide-backfill-' . uniqid();
		$id   = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Guide backfill test',
				'server_slug'            => $slug,
				'description'            => 'Seeded by ServerGuideBackfillTest',
				'is_enabled'             => 0,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
				'server_type'            => $server_type,
			)
		);

		$this->created[] = $id;

		return $id;
	}

	/**
	 * @return string[]
	 */
	private function slugs( int $server_id ): array {
		return MCPServerToolQuery::instance()->get_added_slugs( $server_id );
	}
}
