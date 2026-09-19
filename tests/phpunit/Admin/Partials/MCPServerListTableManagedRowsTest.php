<?php
/**
 * Feature 088 — servers list table behaviour for plugin-managed rows: they
 * expose no delete affordance (row action or bulk checkbox), and every row
 * comes back in plain id order.
 *
 * The Recommended badge and the pin that put the AcrossAI row first were
 * removed; `test_rows_come_back_in_plain_id_order()` below is what replaced
 * the pinning test, because "no special ordering" is itself a contract worth
 * holding — a reintroduced sort would otherwise pass unnoticed.
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

		$this->assertSame( '', $table->column_cb( $this->item( DefaultServerSeeder::SLUG, 'plugin' ) ) );

		// INVERTED in 0.3.6: the AcrossAI row is seeded and protected again, so
		// it is managed again and gets no bulk checkbox either. A checkbox on a
		// row bulk-delete cannot remove is an affordance that lies.
		$this->assertSame( '', $table->column_cb( $this->item( DefaultServerSeeder::ACROSSAI_SLUG ) ) );

		$this->assertStringContainsString(
			'type="checkbox"',
			$table->column_cb( $this->item( 'operator-server' ) )
		);
	}

	public function test_managed_rows_render_no_delete_row_action(): void {
		$table = new MCPServerListTable();

		$managed = $table->column_name( $this->item( DefaultServerSeeder::SLUG, 'plugin' ) );
		$this->assertStringNotContainsString( 'action=delete', $managed );

		$operator = $table->column_name( $this->item( 'operator-server' ) );
		$this->assertStringContainsString( 'action=delete', $operator );
	}

	public function test_no_row_carries_a_promotional_badge(): void {
		$table = new MCPServerListTable();

		foreach ( array( DefaultServerSeeder::ACROSSAI_SLUG, DefaultServerSeeder::SLUG ) as $slug ) {
			$this->assertStringNotContainsString(
				'acrossai-recommended-badge',
				$table->column_name( $this->item( $slug ) ),
				'No server is promoted in the Name column any more.'
			);
		}
	}

	/**
	 * Ordering is whatever the query asked for, and nothing else.
	 *
	 * `prepare_items()` used to re-sort so the AcrossAI row led the list. That
	 * pass is gone, so the rows must arrive in the `id ASC` the query already
	 * requests. Asserted against a freshly inserted operator row, which holds
	 * the HIGHEST id and therefore must come LAST — under the old pin it would
	 * have been second regardless.
	 */
	public function test_rows_come_back_in_plain_id_order(): void {
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

		$ids = array_map( 'intval', array_column( $table->items, 'id' ) );

		$this->assertNotEmpty( $ids );

		$sorted = $ids;
		sort( $sorted );
		$this->assertSame( $sorted, $ids, 'Rows must be in ascending id order.' );

		$slugs = array_column( $table->items, 'slug' );
		$this->assertSame(
			'operator-server',
			end( $slugs ),
			'The newest row holds the highest id, so it must come last.'
		);
	}
}
