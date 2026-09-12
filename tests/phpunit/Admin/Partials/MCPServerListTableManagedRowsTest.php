<?php
/**
 * Feature 088 — servers list table behaviour for plugin-managed rows:
 * the Recommended row is pinned first and badged, and managed rows expose
 * no delete affordance (row action or bulk checkbox).
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\Partials
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\Partials;

use AcrossAI_MCP_Manager\Admin\Partials\MCPServerListTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

final class MCPServerListTableManagedRowsTest extends WP_UnitTestCase {

	/**
	 * Build a list-table item in the shape prepare_items() produces.
	 *
	 * @param string $slug            Server slug.
	 * @param string $registered_from Origin column value.
	 * @return array<string, mixed>
	 */
	private function item( string $slug, string $registered_from = 'database' ): array {
		return array(
			'id'                     => 7,
			'name'                   => 'Fixture',
			'slug'                   => $slug,
			'description'            => '',
			'enabled'                => false,
			'registered_from'        => $registered_from,
			'server_route_namespace' => 'mcp',
			'server_route'           => $slug,
			'server_version'         => 'v1.0.0',
		);
	}

	public function test_managed_rows_render_no_bulk_checkbox(): void {
		$table = new MCPServerListTable();

		$this->assertSame( '', $table->column_cb( $this->item( DefaultServerSeeder::ACROSSAI_SLUG ) ) );
		$this->assertSame( '', $table->column_cb( $this->item( DefaultServerSeeder::SLUG, 'plugin' ) ) );
		$this->assertStringContainsString(
			'type="checkbox"',
			$table->column_cb( $this->item( 'operator-server' ) )
		);
	}

	public function test_managed_rows_render_no_delete_row_action(): void {
		$table = new MCPServerListTable();

		$managed = $table->column_name( $this->item( DefaultServerSeeder::ACROSSAI_SLUG ) );
		$this->assertStringNotContainsString( 'action=delete', $managed );

		$operator = $table->column_name( $this->item( 'operator-server' ) );
		$this->assertStringContainsString( 'action=delete', $operator );
	}

	public function test_only_the_recommended_row_is_badged(): void {
		$table = new MCPServerListTable();

		$this->assertStringContainsString(
			'acrossai-recommended-badge',
			$table->column_name( $this->item( DefaultServerSeeder::ACROSSAI_SLUG ) )
		);
		$this->assertStringNotContainsString(
			'acrossai-recommended-badge',
			$table->column_name( $this->item( DefaultServerSeeder::SLUG, 'plugin' ) )
		);
	}

	/**
	 * The recommended row is seeded first but an operator row could hold a
	 * lower id on a pre-F088 install, so ordering must be explicit rather
	 * than relying on insertion order.
	 */
	public function test_recommended_row_is_pinned_first_regardless_of_id(): void {
		MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Operator Server',
				'server_slug'            => 'operator-server',
				'description'            => '',
				'is_enabled'             => 0,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'operator-server',
				'server_version'         => 'v1.0.0',
			)
		);

		$table = new MCPServerListTable();
		$table->prepare_items();

		$slugs = array_column( $table->items, 'slug' );

		$this->assertNotEmpty( $slugs );
		$this->assertSame(
			DefaultServerSeeder::ACROSSAI_SLUG,
			$slugs[0],
			'The Recommended managed server must lead the list.'
		);
		$this->assertContains( 'operator-server', $slugs );
		$this->assertContains( DefaultServerSeeder::SLUG, $slugs );
	}
}
