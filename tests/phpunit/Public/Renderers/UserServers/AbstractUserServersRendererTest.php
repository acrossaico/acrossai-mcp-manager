<?php
/**
 * F038 T014 + T020 — AbstractUserServersRenderer algorithm tests.
 *
 * Covers the 11 test cases enumerated in
 * contracts/AbstractUserServersRenderer.contract.md §Test contract, plus
 * the T020 US3 payload-filter mutation test (SEC-004 documentation).
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Renderers\UserServers
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Public\Renderers\UserServers;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as ServerMetaQuery;
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;
use AcrossAI_MCP_Manager\Public\Renderers\UserServers\AbstractUserServersRenderer;
use WP_UnitTestCase;

final class AbstractUserServersRendererTest extends WP_UnitTestCase {

	private int $user_id = 0;

	/**
	 * Concrete stub of the abstract base for testing purposes.
	 *
	 * @var AbstractUserServersRenderer
	 */
	private AbstractUserServersRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		AbstractEmbedTransport::flush_cache();

		// Seed a test user + set as current.
		$this->user_id = self::factory()->user->create(
			array( 'role' => 'subscriber' )
		);
		wp_set_current_user( $this->user_id );

		// Anonymous concrete subclass exposing the abstract's public API.
		$this->renderer = new class() extends AbstractUserServersRenderer {};
	}

	protected function tearDown(): void {
		// Prune any meta rows + server rows created by the test.
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}acrossai_mcp_servers_meta" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}acrossai_mcp_servers" );

		AbstractEmbedTransport::flush_cache();
		remove_all_filters( 'acrossai_mcp_user_accessible_servers' );
		remove_all_filters( 'acrossai_mcp_embed_transports' );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ────────────────────────────────────────────────────────────────
	// Helpers
	// ────────────────────────────────────────────────────────────────

	private function create_server( string $slug, string $name = '', string $description = '', int $is_enabled = 1 ): int {
		return (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => '' !== $name ? $name : $slug,
				'server_slug'            => $slug,
				'description'            => $description,
				'is_enabled'             => $is_enabled,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);
	}

	/**
	 * @param array<string, mixed> $embeds_clients_map JSON blob shape for _embeds_clients.
	 */
	private function enable_embeds( int $server_id, array $embeds_clients_map = array() ): void {
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		if ( ! empty( $embeds_clients_map ) ) {
			AbstractEmbedTransport::save_items_for_server( $server_id, $embeds_clients_map );
		}
		AbstractEmbedTransport::flush_cache();
	}

	// ────────────────────────────────────────────────────────────────
	// Tests
	// ────────────────────────────────────────────────────────────────

	public function test_anonymous_returns_empty(): void {
		wp_set_current_user( 0 );
		$this->assertSame( array(), $this->renderer->get_accessible_servers() );
	}

	public function test_explicit_zero_user_id_returns_empty(): void {
		$this->assertSame( array(), $this->renderer->get_accessible_servers( 0 ) );
	}

	public function test_negative_user_id_returns_empty(): void {
		$this->assertSame( array(), $this->renderer->get_accessible_servers( -5 ) );
	}

	public function test_no_servers_returns_empty(): void {
		$this->assertSame( array(), $this->renderer->get_accessible_servers( $this->user_id ) );
	}

	public function test_master_toggle_off_drops_server(): void {
		$server_id = $this->create_server( 'srv-a', 'Server A' );
		// No _embeds_enabled meta → master toggle OFF.
		$this->assertSame( array(), $this->renderer->get_accessible_servers( $this->user_id ) );
		unset( $server_id );
	}

	public function test_zero_dtos_drops_server(): void {
		$server_id = $this->create_server( 'srv-a', 'Server A' );
		// Master ON but no DTOs enabled.
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::flush_cache();

		$this->assertSame( array(), $this->renderer->get_accessible_servers( $this->user_id ) );
	}

	public function test_one_dto_enabled_includes_server(): void {
		$server_id = $this->create_server( 'srv-a', 'Server A', 'A description.' );
		$this->enable_embeds(
			$server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);

		$data = $this->renderer->get_accessible_servers( $this->user_id );

		$this->assertCount( 1, $data );
		$this->assertSame( $server_id, $data[0]['server_id'] );
		$this->assertSame( 'srv-a', $data[0]['server_slug'] );
		$this->assertSame( 'Server A', $data[0]['server_name'] );
		$this->assertSame( 'A description.', $data[0]['description'] );

		$this->assertCount( 1, $data[0]['transports'] );
		$this->assertSame( 'client', $data[0]['transports'][0]['key'] );
		$this->assertIsString( $data[0]['transports'][0]['label'] );
		$this->assertSame( 20, $data[0]['transports'][0]['priority'] );

		$this->assertCount( 1, $data[0]['transports'][0]['dtos'] );
		$this->assertSame( 'claude-desktop', $data[0]['transports'][0]['dtos'][0]['slug'] );
	}

	public function test_disabled_server_row_dropped(): void {
		// is_enabled = 0 → Query filter drops it.
		$server_id = $this->create_server( 'srv-off', 'Off Server', '', 0 );
		$this->enable_embeds(
			$server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);

		$this->assertSame( array(), $this->renderer->get_accessible_servers( $this->user_id ) );
	}

	public function test_f015_fail_open_when_package_absent(): void {
		// In the test env the wpb-access-control package is not loaded;
		// F015 wrapper falls open. Verify we DO see the server.
		$server_id = $this->create_server( 'srv-a', 'Server A' );
		$this->enable_embeds(
			$server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);

		$data = $this->renderer->get_accessible_servers( $this->user_id );

		$this->assertCount( 1, $data, 'Fail-open must include server when AC package absent.' );
	}

	public function test_sort_by_server_name_case_insensitive(): void {
		$zebra_id = $this->create_server( 'zebra-slug', 'zebra' );
		$alpha_id = $this->create_server( 'alpha-slug', 'Alpha' );
		$beta_id  = $this->create_server( 'beta-slug', 'beta' );

		foreach ( array( $zebra_id, $alpha_id, $beta_id ) as $id ) {
			$this->enable_embeds(
				$id,
				array( 'mcp-client' => array( 'claude-desktop' ) )
			);
		}

		$data = $this->renderer->get_accessible_servers( $this->user_id );

		$per_server = array();
		foreach ( array( 'zebra' => $zebra_id, 'alpha' => $alpha_id, 'beta' => $beta_id ) as $label => $id ) {
			$per_server[] = sprintf(
				'%s(id=%d master=%s items=%s enabled=%s)',
				$label,
				$id,
				var_export( ServerMetaQuery::get_meta( $id, '_embeds_enabled' ), true ),
				wp_json_encode( AbstractEmbedTransport::get_items_for_server( $id ) ),
				AbstractEmbedTransport::is_enabled_for_server( $id, 'client', 'claude-desktop' ) ? 'yes' : 'no'
			);
		}

		$this->assertCount(
			3,
			$data,
			'returned slugs: [' . implode( ', ', array_column( $data, 'server_slug' ) ) . '] | '
			. implode( ' ', $per_server )
		);
		$this->assertSame( 'Alpha', $data[0]['server_name'] );
		$this->assertSame( 'beta',  $data[1]['server_name'] );
		$this->assertSame( 'zebra', $data[2]['server_name'] );
	}

	public function test_transport_priority_order_preserved(): void {
		$server_id = $this->create_server( 'srv-a', 'Server A' );
		// Master ON + one DTO enabled per transport (NPM, client, ai_connector).
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array(
				'npm'        => 1,
				'mcp-client' => array( 'claude-desktop' ),
				'connectors' => array( 'chatgpt' ),
			)
		);
		AbstractEmbedTransport::flush_cache();

		$data = $this->renderer->get_accessible_servers( $this->user_id );

		$this->assertCount( 1, $data );
		$transport_keys = array_column( $data[0]['transports'], 'key' );

		// Priority ASC — npm (10), client (20), ai_connector (30).
		// Only transports whose DTOs pass the gate appear; NPM DTO is
		// npx-acrossai-mcp-manager (single-item), client is claude-desktop,
		// ai_connector is chatgpt (may or may not be registered — the
		// AI Connectors DTO surfaces only if a connector plugin registers
		// the 'chatgpt' profile).
		$this->assertContains( 'client', $transport_keys );
		// NPM DTO always ships built-in, so it should appear.
		$this->assertContains( 'npm', $transport_keys );

		// Verify order: any two of (npm, client, ai_connector) must be
		// in priority order.
		$positions = array();
		foreach ( array( 'npm' => 10, 'client' => 20, 'ai_connector' => 30 ) as $key => $priority ) {
			$idx = array_search( $key, $transport_keys, true );
			if ( false !== $idx ) {
				$positions[ $priority ] = $idx;
			}
		}
		$priority_sorted = $positions;
		ksort( $priority_sorted );
		$this->assertSame(
			array_values( $priority_sorted ),
			array_values( $positions ),
			'Transports must appear in priority ASC order.'
		);
	}

	public function test_dto_with_missing_slug_dropped(): void {
		// Register a fake transport whose get_dtos() returns a DTO with
		// no `slug` key.
		add_filter(
			'acrossai_mcp_embed_transports',
			static function ( array $classes ): array {
				$classes[] = FakeTransportMissingSlug::class;
				return $classes;
			}
		);
		AbstractEmbedTransport::flush_cache();

		$server_id = $this->create_server( 'srv-a', 'Server A' );
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'fake-missing-slug' => array( 'anything' ) )
		);
		AbstractEmbedTransport::flush_cache();

		$data = $this->renderer->get_accessible_servers( $this->user_id );

		// Fake transport had no valid DTO → server dropped.
		$this->assertSame( array(), $data );
	}

	// ────────────────────────────────────────────────────────────────
	// T020 — US3 payload-filter round-trip (SEC-004 documentation)
	//
	// NOTE (SEC-004): the `acrossai_mcp_user_accessible_servers` filter
	// is a MUTATION seam, not a GATE-BYPASS seam. This test documents
	// the seam is functional — a listener that adds unauthorized entries
	// would be a consumer defect (F038 does NOT re-verify appended
	// entries). See AbstractUserServersRenderer.contract.md §Non-goals.
	// ────────────────────────────────────────────────────────────────

	public function test_filter_round_trip(): void {
		$server_id = $this->create_server( 'srv-a', 'Server A' );
		$this->enable_embeds(
			$server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);

		add_filter(
			'acrossai_mcp_user_accessible_servers',
			static function ( array $data ): array {
				foreach ( $data as $idx => $entry ) {
					$data[ $idx ]['server_name'] = strtoupper( (string) $entry['server_name'] );
				}
				return $data;
			}
		);

		$data = $this->renderer->get_accessible_servers( $this->user_id );

		$this->assertCount( 1, $data );
		$this->assertSame( 'SERVER A', $data[0]['server_name'], 'Filter mutation must be visible in return.' );
	}

	public function test_filter_fires_exactly_once_per_call(): void {
		$this->create_server( 'srv-a', 'Server A' );

		$fires = 0;
		add_filter(
			'acrossai_mcp_user_accessible_servers',
			static function ( $data ) use ( &$fires ) {
				++$fires;
				return $data;
			}
		);

		$this->renderer->get_accessible_servers( $this->user_id );
		$this->assertSame( 1, $fires, 'Filter must fire exactly once per get_accessible_servers() call.' );
	}

	public function test_filter_non_array_return_defensively_coerced_to_empty(): void {
		$this->create_server( 'srv-a', 'Server A' );

		add_filter(
			'acrossai_mcp_user_accessible_servers',
			static fn(): string => 'not-an-array'
		);

		// Fail-soft: abstract returns [] rather than propagating garbage.
		$this->assertSame( array(), $this->renderer->get_accessible_servers( $this->user_id ) );
	}
}

// ────────────────────────────────────────────────────────────────
// Fake transport fixtures
// ────────────────────────────────────────────────────────────────

final class FakeTransportMissingSlug extends AbstractEmbedTransport {

	public function get_transport_key(): string {
		return 'fake-missing-slug';
	}

	public function get_checkbox_label(): string {
		return 'Fake Missing Slug';
	}

	public function get_priority(): int {
		return 50;
	}

	public function get_dtos(): array {
		// DTO with NO `slug` key → must be dropped silently.
		return array(
			array(
				'name' => 'Missing slug DTO',
				'icon' => '❌',
			),
		);
	}
}
