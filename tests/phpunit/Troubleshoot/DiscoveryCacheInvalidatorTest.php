<?php
/**
 * Purging cached discovery documents when what they describe changes (#181).
 *
 * The hooks are BerlinDB's own `transition_mcp_server_{column}` actions, so
 * these tests drive real `update_item()` calls rather than firing the actions
 * by hand — the thing worth proving is that a save of any shape reaches the
 * invalidator, and calling `do_action()` directly would prove only that
 * `do_action()` works.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Troubleshoot;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryCacheInvalidator;
use AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryController;
use WP_UnitTestCase;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryCacheInvalidator
 */
final class DiscoveryCacheInvalidatorTest extends WP_UnitTestCase {

	/**
	 * URL lists captured from the purge action.
	 *
	 * @var array<int, array<int, string>>
	 */
	private $purges = array();

	/**
	 * Server created for the test, if any.
	 *
	 * @var int
	 */
	private $server_id = 0;

	public function set_up(): void {
		parent::set_up();

		// The harness runs on plain permalinks, where rest_url() is the
		// `?rest_route=` form and no path-inserted discovery URL exists. The
		// case under test only arises on a site that can serve the
		// `.well-known` rewrites, so the suite has to be one.
		$this->set_permalink_structure( '/%postname%/' );

		$this->purges = array();
		DiscoveryCacheInvalidator::reset_request_guard();

		add_action(
			'acrossai_mcp_discovery_cache_purge',
			function ( array $urls ): void {
				$this->purges[] = $urls;
			}
		);

		// The invalidator is wired by Main.php, which the test bootstrap does
		// not run, so the transitions are wired here the same way.
		foreach ( DiscoveryCacheInvalidator::watched_columns() as $column ) {
			add_action(
				'transition_mcp_server_' . $column,
				array( DiscoveryCacheInvalidator::class, 'on_change' ),
				10,
				3
			);
		}
		add_action( 'mcp_server_deleted', array( DiscoveryCacheInvalidator::class, 'on_delete' ), 10, 2 );
	}

	public function tear_down(): void {
		if ( $this->server_id > 0 ) {
			MCPServerQuery::instance()->delete_item( $this->server_id );
			$this->server_id = 0;
		}

		remove_all_actions( 'acrossai_mcp_discovery_cache_purge' );
		foreach ( DiscoveryCacheInvalidator::watched_columns() as $column ) {
			remove_all_actions( 'transition_mcp_server_' . $column );
		}
		remove_all_actions( 'mcp_server_deleted' );
		DiscoveryCacheInvalidator::reset_request_guard();
		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	private function create_server(): int {
		$id = MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Invalidator fixture',
				'server_slug'            => 'invalidator-fixture-' . wp_rand( 1000, 9999 ),
				'server_route'           => 'invalidator-fixture',
				'server_route_namespace' => 'mcp',
				'is_enabled'             => 1,
			)
		);

		$this->server_id = (int) $id;
		DiscoveryCacheInvalidator::reset_request_guard();
		$this->purges = array();

		return $this->server_id;
	}

	/**
	 * @dataProvider watched_change_provider
	 *
	 * @param string $column New column to write.
	 * @param mixed  $value  Its new value.
	 */
	public function test_a_watched_column_change_purges( string $column, $value ): void {
		$id = $this->create_server();

		MCPServerQuery::instance()->update_item( $id, array( $column => $value ) );

		self::assertCount(
			1,
			$this->purges,
			sprintf( 'Changing %s leaves every cached discovery document describing the old value.', $column )
		);
	}

	/**
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public function watched_change_provider(): array {
		return array(
			'disabled'         => array( 'is_enabled', 0 ),
			'route changed'    => array( 'server_route', 'moved-somewhere-else' ),
			'namespace changed' => array( 'server_route_namespace', 'custom-ns' ),
		);
	}

	/**
	 * A column that appears in no discovery document must not cost a purge.
	 * Every purge is I/O against whatever cache layers are installed.
	 */
	public function test_an_unwatched_column_change_does_not_purge(): void {
		$id = $this->create_server();

		MCPServerQuery::instance()->update_item( $id, array( 'server_name' => 'Renamed, same routes' ) );

		self::assertSame( array(), $this->purges );
	}

	/**
	 * Writing the same value is not a change. BerlinDB only fires the
	 * transition for columns that actually differ, and this asserts we are
	 * relying on that rather than on every save.
	 */
	public function test_writing_an_unchanged_value_does_not_purge(): void {
		$id = $this->create_server();

		MCPServerQuery::instance()->update_item( $id, array( 'server_route' => 'invalidator-fixture' ) );

		self::assertSame( array(), $this->purges );
	}

	/**
	 * One admin save transitions several columns at once, each firing its own
	 * action — but no URL should be purged twice for one save.
	 */
	public function test_one_save_never_purges_a_url_twice(): void {
		$id = $this->create_server();

		MCPServerQuery::instance()->update_item(
			$id,
			array(
				'server_route'           => 'all-change',
				'server_route_namespace' => 'other-ns',
				'is_enabled'             => 0,
			)
		);

		$all = array_merge( ...$this->purges );

		self::assertNotEmpty( $all );
		self::assertSame(
			array_values( array_unique( $all ) ),
			$all,
			'Three transitions in one save must not purge the same URL three times.'
		);
	}

	/**
	 * Switching a server OFF is the case a naive implementation misses: its
	 * document has just started lying, and building the list from enabled
	 * rows only would leave exactly that URL cached. Caught by watching a
	 * real admin disable, not by reading the code.
	 */
	public function test_disabling_a_server_still_purges_its_own_document(): void {
		$id   = $this->create_server();
		$slug = MCPServerQuery::instance()->get_item( $id )->server_route;

		MCPServerQuery::instance()->update_item( $id, array( 'is_enabled' => 0 ) );

		$all = array_merge( ...$this->purges );

		self::assertNotEmpty(
			array_filter(
				$all,
				static function ( string $url ) use ( $slug ): bool {
					return false !== strpos( $url, '/' . $slug );
				}
			),
			'The disabled server\'s own discovery URL was not purged.'
		);
	}

	/**
	 * Renaming a route moves the path-inserted document. The OLD URL exists
	 * nowhere but the transition's $old_value, so it has to be reconstructed
	 * there or it stays cached pointing at a route that no longer answers.
	 */
	public function test_renaming_a_route_purges_the_url_it_moved_away_from(): void {
		$id = $this->create_server();

		MCPServerQuery::instance()->update_item( $id, array( 'server_route' => 'the-new-route' ) );

		$all = array_merge( ...$this->purges );

		self::assertNotEmpty(
			array_filter(
				$all,
				static function ( string $url ): bool {
					return false !== strpos( $url, 'invalidator-fixture' );
				}
			),
			'The pre-rename URL was not purged.'
		);
		self::assertNotEmpty(
			array_filter(
				$all,
				static function ( string $url ): bool {
					return false !== strpos( $url, 'the-new-route' );
				}
			),
			'The post-rename URL was not purged.'
		);
	}

	public function test_deleting_a_server_purges(): void {
		$id = $this->create_server();

		MCPServerQuery::instance()->delete_item( $id );
		$this->server_id = 0;

		self::assertCount( 1, $this->purges );
	}

	/**
	 * The purge is worthless if it names the wrong URLs.
	 */
	public function test_the_purge_carries_both_bare_documents(): void {
		$this->create_server();
		DiscoveryCacheInvalidator::reset_request_guard();
		DiscoveryCacheInvalidator::purge();

		$urls = $this->purges[0] ?? array();

		self::assertContains( DiscoveryController::origin() . '/.well-known/oauth-authorization-server', $urls );
		self::assertContains( DiscoveryController::origin() . '/.well-known/oauth-protected-resource', $urls );
	}

	/**
	 * The path-inserted form is the URL MCP hosts actually request, and it
	 * carries the same cache headers — a purge that skipped it would leave
	 * the stale copy that matters.
	 */
	public function test_the_purge_carries_the_path_inserted_document(): void {
		$this->create_server();
		DiscoveryCacheInvalidator::reset_request_guard();
		DiscoveryCacheInvalidator::purge();

		$urls = $this->purges[0] ?? array();

		$inserted = array_filter(
			$urls,
			static function ( string $url ): bool {
				return false !== strpos( $url, '/.well-known/oauth-protected-resource/' );
			}
		);

		self::assertNotEmpty( $inserted, 'No path-inserted document was purged.' );
	}

	public function test_urls_are_absolute_and_unique(): void {
		$this->create_server();
		DiscoveryCacheInvalidator::reset_request_guard();
		DiscoveryCacheInvalidator::purge();

		$urls = $this->purges[0] ?? array();

		self::assertNotEmpty( $urls );
		self::assertSame( array_values( array_unique( $urls ) ), $urls );

		foreach ( $urls as $url ) {
			self::assertStringStartsWith( DiscoveryController::origin() . '/', $url );
		}
	}

	/**
	 * A site with no cache plugin installed must not fatal on the vendor
	 * function calls — this runs on a plain WordPress with none of them.
	 */
	public function test_purging_without_any_cache_plugin_is_harmless(): void {
		$this->create_server();

		DiscoveryCacheInvalidator::purge();

		self::assertCount( 1, $this->purges );
	}
}
