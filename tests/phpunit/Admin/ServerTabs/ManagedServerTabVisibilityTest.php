<?php
/**
 * Feature 088 — the Update Server and Danger Zone tabs must stay hidden for
 * plugin-managed (seeder-owned) servers.
 *
 * Before F088 both tabs keyed off `registered_from === 'database'` alone.
 * The AcrossAI managed row is deliberately 'database'-sourced (otherwise
 * MCP\Controller never registers its endpoint), so that test is no longer
 * sufficient on its own.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\ServerTabs
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\DangerZoneTab;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\UpdateServerTab;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

final class ManagedServerTabVisibilityTest extends WP_UnitTestCase {

	/**
	 * Build a server array in the Row::to_array() key shape the tab Registry
	 * receives from Settings::render_edit_page().
	 *
	 * @param string $slug            Server slug.
	 * @param string $registered_from Origin column value.
	 * @return array<string, mixed>
	 */
	private function server( string $slug, string $registered_from = 'database' ): array {
		return array(
			'id'                     => 1,
			'server_name'            => 'Fixture',
			'server_slug'            => $slug,
			'registered_from'        => $registered_from,
			'server_route_namespace' => 'mcp',
			'server_route'           => $slug,
			'server_version'         => 'v1.0.0',
		);
	}

	public function test_tabs_are_visible_for_an_operator_created_server(): void {
		$server = $this->server( 'operator-server' );

		$this->assertTrue( ( new UpdateServerTab() )->visible_for( $server ) );
		$this->assertTrue( ( new DangerZoneTab() )->visible_for( $server ) );
	}

	public function test_tabs_are_hidden_for_the_acrossai_managed_server(): void {
		$server = $this->server( DefaultServerSeeder::ACROSSAI_SLUG );

		$this->assertFalse(
			( new UpdateServerTab() )->visible_for( $server ),
			'The seeder re-asserts managed columns, so editing must not be offered.'
		);
		$this->assertFalse( ( new DangerZoneTab() )->visible_for( $server ) );
	}

	public function test_tabs_are_hidden_for_the_default_managed_server(): void {
		$server = $this->server( DefaultServerSeeder::SLUG, 'plugin' );

		$this->assertFalse( ( new UpdateServerTab() )->visible_for( $server ) );
		$this->assertFalse( ( new DangerZoneTab() )->visible_for( $server ) );
	}

	/**
	 * End-to-end through the Registry, which is what actually renders the nav.
	 */
	public function test_registry_omits_both_tabs_for_managed_servers(): void {
		$slugs = array_map(
			static fn( $tab ) => $tab->slug(),
			Registry::instance()->visible_tabs( $this->server( DefaultServerSeeder::ACROSSAI_SLUG ) )
		);

		$this->assertNotContains( 'update-server', $slugs );
		$this->assertNotContains( 'danger-zone', $slugs );
		$this->assertContains( 'overview', $slugs, 'Overview must still render.' );
	}
}
