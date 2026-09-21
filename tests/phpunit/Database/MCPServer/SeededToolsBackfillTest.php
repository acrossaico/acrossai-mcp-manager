<?php
/**
 * The repair for servers that lost their tools to the activation-order bug.
 *
 * Until 0.3.6 the activator seeded its servers before creating the server-tools
 * table, so every curated row went into a table that did not exist and vanished
 * without an error. Curated rows are written once, at INSERT, so the reconcile
 * pass on the next admin request never gets another chance — an affected site
 * would carry an empty AcrossAI server forever.
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\Database\MCPServer
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\SeededToolsBackfill;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class SeededToolsBackfillTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( SeededToolsBackfill::DONE_OPTION );
		DefaultServerSeeder::seed();
	}

	public function tear_down(): void {
		delete_option( SeededToolsBackfill::DONE_OPTION );
		parent::tear_down();
	}

	/**
	 * @return int
	 */
	private function acrossai_server_id(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE server_slug = %s',
				$wpdb->prefix . 'acrossai_mcp_servers',
				DefaultServerSeeder::ACROSSAI_SLUG
			)
		);
	}

	/**
	 * @param  int $server_id Server row id.
	 * @return void
	 */
	private function strip_curated( int $server_id ): void {
		global $wpdb;

		$wpdb->delete(
			$wpdb->prefix . 'acrossai_mcp_server_tools',
			array( 'server_id' => $server_id ),
			array( '%d' )
		);
	}

	public function test_an_empty_seeded_server_gets_its_declared_tools_back(): void {
		$id = $this->acrossai_server_id();
		$this->assertGreaterThan( 0, $id );

		$this->strip_curated( $id );
		$this->assertSame( array(), MCPServerToolQuery::instance()->get_added_slugs( $id ) );

		SeededToolsBackfill::maybe_backfill();

		$expected = ServerTypes::seeded_servers()[ DefaultServerSeeder::ACROSSAI_SLUG ]['tools'];
		$actual   = MCPServerToolQuery::instance()->get_added_slugs( $id );

		sort( $expected );
		sort( $actual );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * DECLARED, not `tools_for()`.
	 *
	 * The abilities behind these slugs are not registered in the test
	 * environment, which is the same condition as a site without the add-on —
	 * and is exactly why resolving through the narrowing accessor would restore
	 * nothing. Asserting the absence makes the intent unmistakable to whoever
	 * next reads a list of tools that do not exist.
	 */
	public function test_the_restored_tools_are_not_registered_abilities(): void {
		$id = $this->acrossai_server_id();
		$this->strip_curated( $id );

		SeededToolsBackfill::maybe_backfill();

		$restored = MCPServerToolQuery::instance()->get_added_slugs( $id );
		$this->assertNotEmpty( $restored );

		foreach ( $restored as $slug ) {
			$this->assertFalse( wp_has_ability( (string) $slug ), "{$slug} is dormant, and was restored anyway." );
		}
	}

	/**
	 * A deliberate curation is not a gap to be filled.
	 *
	 * The condition is ZERO rows precisely so this cannot happen: an operator
	 * who pruned the list would otherwise have it topped back up on the next
	 * admin request, forever, with no way to say no.
	 */
	public function test_a_server_the_operator_curated_is_left_alone(): void {
		$id = $this->acrossai_server_id();
		$this->strip_curated( $id );

		MCPServerToolQuery::instance()->add_item(
			array(
				'server_id'    => $id,
				'ability_slug' => 'toolset/content',
			)
		);

		SeededToolsBackfill::maybe_backfill();

		$this->assertSame( array( 'toolset/content' ), MCPServerToolQuery::instance()->get_added_slugs( $id ) );
	}

	public function test_it_runs_once(): void {
		$id = $this->acrossai_server_id();

		$this->strip_curated( $id );
		SeededToolsBackfill::maybe_backfill();
		$this->assertNotEmpty( MCPServerToolQuery::instance()->get_added_slugs( $id ) );

		// A second emptying is the operator's business, not a bug to re-repair.
		$this->strip_curated( $id );
		SeededToolsBackfill::maybe_backfill();

		$this->assertSame( array(), MCPServerToolQuery::instance()->get_added_slugs( $id ) );
	}
}
