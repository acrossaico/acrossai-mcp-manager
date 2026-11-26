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

	/**
	 * A real ability made to vanish for one test, as [slug, captured args] —
	 * or null. Restored unconditionally in tear_down(), see vanish_ability().
	 *
	 * @var array{0: string, 1: array<string, mixed>}|null
	 */
	private $vanished = null;

	public function set_up(): void {
		parent::set_up();
		delete_option( SeededToolsBackfill::DONE_OPTION );
		DefaultServerSeeder::seed();
	}

	/**
	 * Restore whatever vanish_ability() removed, regardless of how the test
	 * ended. The abilities registry is a process-wide static singleton (see
	 * tests/bootstrap-wp.php) — leaving a real ability unregistered here would
	 * break every later test in this process that assumes it exists, not just
	 * this file's own.
	 */
	public function tear_down(): void {
		if ( null !== $this->vanished ) {
			list( $slug, $args ) = $this->vanished;
			if ( ! wp_has_ability( $slug ) ) {
				\WP_Abilities_Registry::get_instance()->register( $slug, $args );
			}
			$this->vanished = null;
		}
		delete_option( SeededToolsBackfill::DONE_OPTION );
		parent::tear_down();
	}

	/**
	 * Make a real, currently-registered ability disappear for one test.
	 *
	 * See the identical helper (and its full reasoning) in
	 * DefaultServerSeederTest — F106 means no declared slug on this type is
	 * reliably dormant in this plugin's own test environment any more, so a
	 * test that needs to prove "stored even though unregistered" has to
	 * manufacture that condition rather than find it lying around.
	 *
	 * @param string $slug Ability slug to vanish, e.g. `toolset/content`.
	 * @return void
	 */
	private function vanish_ability( string $slug ): void {
		$ability = wp_get_ability( $slug );
		$this->assertNotNull( $ability, "Precondition: {$slug} must actually be registered before it can be made to vanish." );

		$reflection = new \ReflectionObject( $ability );
		$read       = static function ( string $property ) use ( $reflection, $ability ) {
			$prop = $reflection->getProperty( $property );
			$prop->setAccessible( true );
			return $prop->getValue( $ability );
		};

		$this->vanished = array(
			$slug,
			array(
				'label'               => $ability->get_label(),
				'description'         => $ability->get_description(),
				'category'            => $ability->get_category(),
				'input_schema'        => $ability->get_input_schema(),
				'output_schema'       => $ability->get_output_schema(),
				'meta'                => $ability->get_meta(),
				'execute_callback'    => $read( 'execute_callback' ),
				'permission_callback' => $read( 'permission_callback' ),
			),
		);

		wp_unregister_ability( $slug );
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
	 * The abilities behind these slugs were not registered in the test
	 * environment, which was the same condition as a site without the add-on —
	 * and is exactly why resolving through the narrowing accessor would restore
	 * nothing. Asserting the absence made the intent unmistakable to whoever
	 * next reads a list of tools that do not exist.
	 *
	 * F106 made that "were" untrue of all of them, not just most.
	 * `AcrossAI_Core_Abilities_Bootstrap` now boots on every request, and its
	 * ability classes behind every one of `Registrar::CORE`'s groups — plus
	 * `toolset/other`, `toolset/integrations` (via the unconditionally-registered
	 * backup-suite groups) and Guide's `toolset/server-guide` — all have at
	 * least one member here regardless of which third-party plugin is active.
	 * There is no longer a real dormant slug on this type to loop over.
	 *
	 * So this manufactures one, the same way
	 * DefaultServerSeederTest::test_declared_tools_are_written_without_the_abilities_existing()
	 * does and for the same reason: vanish_ability() removes a real,
	 * currently-registered ability for the duration of the test and restores
	 * it in tear_down(), which lets `toolset/content` stand in for "a slug
	 * this site happens not to have yet" without touching the bootstrap.
	 */
	public function test_the_restored_tools_are_not_registered_abilities(): void {
		$id = $this->acrossai_server_id();
		$this->strip_curated( $id );
		$this->vanish_ability( 'toolset/content' );

		SeededToolsBackfill::maybe_backfill();

		$restored = MCPServerToolQuery::instance()->get_added_slugs( $id );
		$this->assertNotEmpty( $restored );
		$this->assertContains( 'toolset/content', $restored, 'Precondition: the slug this test vanished must actually have been restored.' );

		$this->assertFalse(
			wp_has_ability( 'toolset/content' ),
			'toolset/content is NOT registered here, and was restored anyway.'
		);
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
