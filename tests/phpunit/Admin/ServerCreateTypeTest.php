<?php
/**
 * Feature 090 — every creation path writes `server_type` EXPLICITLY (T036, SEC-001).
 *
 * The security review caught this: the plan listed one server-creation path and a
 * second existed in Quick Connect. A path that omits `server_type` does not fail —
 * the row silently takes the COLUMN default instead of the REGISTRY default, so the
 * stored type disagrees with what the operator chose and the enablement gate then
 * evaluates a type they never picked.
 *
 * The divergence is the whole point, and the first test here demonstrates it
 * rather than asserting it abstractly: the column default is fixed at
 * `'mcp-adapter'` because that is what must backfill pre-090 rows, while
 * `ServerTypes::default_slug()` resolves live and is `'acrossai'` on a site where
 * the add-on is present. Omit the write and those two answers part company.
 *
 * **Scope, stated honestly.** Both creation handlers are `private` and end in
 * `exit`, so neither can be invoked under PHPUnit without terminating the run.
 * What is asserted is the divergence itself (behaviour) plus a source contract on
 * each path — the same approach `SettingsBulkEnableTest` documents.
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\Admin
 */

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Admin;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use ReflectionMethod;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class ServerCreateTypeTest extends WP_UnitTestCase {

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

	// ------------------------------------------- why the write must exist ----

	public function test_omitting_server_type_takes_the_column_default_not_the_registry_default(): void {
		// Register a type that is available AND flagged default, so the registry
		// answer is demonstrably not 'mcp-adapter'.
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['preferred'] = array(
					'label'      => 'Preferred',
					'is_default' => true,
				);
				return $types;
			}
		);

		$this->assertSame( 'preferred', ServerTypes::default_slug(), 'sanity: the registry prefers this type' );

		$id  = $this->create_without_type();
		$row = MCPServerQuery::instance()->query( array( 'id' => $id, 'number' => 1 ) )[0];

		// This is the SEC-001 failure in one assertion: the row disagrees with
		// the registry, silently, and nothing errors.
		$this->assertSame( 'mcp-adapter', (string) $row->server_type );
		$this->assertNotSame( ServerTypes::default_slug(), (string) $row->server_type );
	}

	public function test_writing_server_type_explicitly_stores_the_chosen_value(): void {
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['chosen'] = array( 'label' => 'Chosen' );
				return $types;
			}
		);

		$id  = $this->create_with_type( 'chosen' );
		$row = MCPServerQuery::instance()->query( array( 'id' => $id, 'number' => 1 ) )[0];

		$this->assertSame( 'chosen', (string) $row->server_type );
	}

	public function test_the_registry_default_never_resolves_to_an_unavailable_type(): void {
		// FR-009. The create forms preselect default_slug(), so if it could return
		// an unusable type both forms would offer a server that cannot be enabled.
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['unusable'] = array(
					'label'      => 'Unusable',
					'requires'   => 'a-plugin-that-is-not-installed-here',
					'is_default' => true,
				);
				return $types;
			}
		);

		$this->assertTrue( ServerTypes::is_available( ServerTypes::default_slug() ) );
	}

	// --------------------------------------------- both paths, contracts ----

	public function test_the_classic_create_form_writes_server_type(): void {
		$this->assertStringContainsString(
			"'server_type'",
			$this->method_source( '\AcrossAI_MCP_Manager\Admin\Partials\Settings', 'handle_create_server' ),
			'SEC-001: the add_item() array must name the column explicitly.'
		);
	}

	public function test_the_quick_connect_create_path_writes_server_type(): void {
		// The path the first plan draft missed entirely.
		$this->assertStringContainsString(
			"'server_type'",
			$this->method_source( '\AcrossAI_MCP_Manager\Includes\REST\QuickConnectController', 'apply_step_2' )
		);
	}

	/**
	 * @dataProvider provideCreationPaths
	 *
	 * @param string $class  Declaring class.
	 * @param string $method Creation handler.
	 */
	public function test_each_creation_path_validates_the_submitted_type( string $class, string $method ): void {
		$source = $this->method_source( $class, $method );

		// A submitted value must be checked against the registry before it is
		// stored, or the picker's "only available types" rule is cosmetic — any
		// client can post any slug.
		$this->assertStringContainsString( 'ServerTypes::', $source );
		$this->assertStringContainsString( 'sanitize_key', $source );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideCreationPaths(): array {
		return array(
			'classic form'  => array( '\AcrossAI_MCP_Manager\Admin\Partials\Settings', 'handle_create_server' ),
			'quick connect' => array( '\AcrossAI_MCP_Manager\Includes\REST\QuickConnectController', 'apply_step_2' ),
		);
	}

	// ---------------------------------------------------------- helpers ----

	private function method_source( string $class, string $method ): string {
		$reflection = new ReflectionMethod( $class, $method );
		$lines      = file( (string) $reflection->getFileName() );
		$start      = (int) $reflection->getStartLine() - 1;
		$length     = (int) $reflection->getEndLine() - $start;

		return implode( '', array_slice( (array) $lines, $start, $length ) );
	}

	private function create_without_type(): int {
		return $this->insert( array() );
	}

	private function create_with_type( string $server_type ): int {
		return $this->insert( array( 'server_type' => $server_type ) );
	}

	/**
	 * @param array<string, mixed> $extra Columns beyond the required minimum.
	 */
	private function insert( array $extra ): int {
		$slug = 'create-' . uniqid();
		$id   = (int) MCPServerQuery::instance()->add_item(
			array_merge(
				array(
					'server_name'            => 'Create test server',
					'server_slug'            => $slug,
					'description'            => 'Seeded by ServerCreateTypeTest',
					'is_enabled'             => 0,
					'registered_from'        => 'database',
					'server_route_namespace' => 'mcp',
					'server_route'           => $slug,
					'server_version'         => 'v1.0.0',
				),
				$extra
			)
		);

		$this->created[] = $id;

		return $id;
	}
}
