<?php
/**
 * Feature 090 — bulk enable partial success, and the boundary it depends on (T029).
 *
 * **Scope, stated honestly.** `Settings::handle_bulk_actions()` is `private` and
 * every branch ends in `exit` (Settings.php:425/446/468/494), so it cannot be
 * invoked under PHPUnit without terminating the run. Refactoring production code
 * purely to make it callable is not a trade worth making for this.
 *
 * What is tested instead is the thing that actually carries the guarantee:
 *
 *   1. The BEHAVIOUR — `ServerEnablement::set_many()` is the whole of FR-016a.
 *      The admin handler is a thin adapter that calls it and renders the result.
 *   2. The BOUNDARY — a source contract asserting the admin path routes through
 *      the facade and writes no `is_enabled` of its own. This is the ARCH-1
 *      invariant, and the repo already uses source-contract tests for exactly
 *      this shape of guarantee (the F080 rename gate).
 *
 * `bin/verify-f021-gates.sh` enforces the same rule in CI. Both exist because the
 * original call-site inventory was built by grep and had already missed a path.
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\Admin
 */

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Admin;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerEnablement;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use ReflectionMethod;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class SettingsBulkEnableTest extends WP_UnitTestCase {

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

	// ---------------------------------------------------- the behaviour ----

	public function test_a_mixed_selection_enables_the_eligible_and_names_the_rest(): void {
		$ok      = $this->server( ServerTypes::LEGACY, false );
		$blocked = $this->server( 'blocked', false );

		$result = ServerEnablement::set_many( array( $ok, $blocked ), true );

		// FR-016a names all three properties explicitly, because each failure
		// mode strands the operator differently: a wholesale failure hides the
		// servers that could have been enabled, and a silent skip hides the ones
		// that could not.
		$this->assertSame( array( $ok ), $result['changed'], 'eligible rows are enabled' );
		$this->assertArrayHasKey( $blocked, $result['skipped'], 'ineligible rows are reported' );
		$this->assertNotEmpty( $result['skipped'][ $blocked ], 'and each carries a reason' );
	}

	public function test_an_all_blocked_selection_is_not_a_wholesale_failure(): void {
		$a = $this->server( 'blocked', false );
		$b = $this->server( 'blocked', false );

		$result = ServerEnablement::set_many( array( $a, $b ), true );

		$this->assertSame( array(), $result['changed'] );
		$this->assertCount( 2, $result['skipped'], 'Every skipped row is named, not just the first.' );
	}

	public function test_bulk_disable_skips_nothing_even_when_stranded(): void {
		$ok      = $this->server( ServerTypes::LEGACY, true );
		$blocked = $this->server( 'blocked', true );

		$result = ServerEnablement::set_many( array( $ok, $blocked ), false );

		$this->assertSame( array(), $result['skipped'] );
		$this->assertCount( 2, $result['changed'] );
	}

	public function test_the_reported_skip_reason_is_operator_readable(): void {
		$blocked = $this->server( 'blocked', false );
		$result  = ServerEnablement::set_many( array( $blocked ), true );

		// The admin renders this verbatim, so it has to be a sentence an operator
		// can act on rather than an error code.
		$this->assertStringContainsString( 'Abilities Manager', $result['skipped'][ $blocked ] );
	}

	// ------------------------------------------------------ the boundary ----

	public function test_the_admin_bulk_handler_routes_through_the_facade(): void {
		$source = $this->method_source( 'handle_bulk_actions' );

		$this->assertStringContainsString(
			'ServerEnablement::set',
			$source,
			'ARCH-1: every enable/disable goes through the one sanctioned writer.'
		);
	}

	public function test_the_admin_bulk_handler_writes_no_is_enabled_of_its_own(): void {
		$source = $this->method_source( 'handle_bulk_actions' );

		// A direct update_item() here would bypass the type requirement entirely
		// — the exact gap the facade and its CI grep gate exist to close.
		$this->assertDoesNotMatchRegularExpression(
			"/update_item\([^)]*'is_enabled'/s",
			$source
		);
	}

	public function test_the_single_toggle_also_routes_through_the_facade(): void {
		$this->assertStringContainsString( 'ServerEnablement::set', $this->method_source( 'handle_actions' ) );
	}

	// ---------------------------------------------------------- helpers ----

	/**
	 * Read one method's source text straight from the file.
	 *
	 * Reflection gives the line range; the file gives the body. This is how the
	 * repo's existing rename-gate contract tests work, and it is the only way to
	 * assert a boundary inside a method that cannot be invoked.
	 *
	 * @param string $method Method name on Settings.
	 * @return string
	 */
	private function method_source( string $method ): string {
		$reflection = new ReflectionMethod( '\AcrossAI_MCP_Manager\Admin\Partials\Settings', $method );
		$lines      = file( (string) $reflection->getFileName() );
		$start      = (int) $reflection->getStartLine() - 1;
		$length     = (int) $reflection->getEndLine() - $start;

		return implode( '', array_slice( (array) $lines, $start, $length ) );
	}

	private function server( string $server_type, bool $enabled ): int {
		if ( 'blocked' === $server_type ) {
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
		}

		$slug = 'bulk-' . uniqid();
		$id   = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Bulk enable test server',
				'server_slug'            => $slug,
				'description'            => 'Seeded by SettingsBulkEnableTest',
				'is_enabled'             => $enabled ? 1 : 0,
				'server_type'            => $server_type,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);

		$this->created[] = $id;

		return $id;
	}
}
