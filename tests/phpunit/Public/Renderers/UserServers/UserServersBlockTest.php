<?php
/**
 * F038 T015 + T021 — UserServersBlock rendering tests.
 *
 * Covers the 12 test cases enumerated in
 * contracts/UserServersBlock.contract.md §Test contract, plus the T021
 * US3 HTML-filter wrapping test (SEC-002 documentation).
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Renderers\UserServers
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Public\Renderers\UserServers;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as ServerMetaQuery;
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;
use AcrossAI_MCP_Manager\Public\Renderers\UserServers\UserServersBlock;
use ReflectionClass;
use WP_UnitTestCase;

final class UserServersBlockTest extends WP_UnitTestCase {

	private int $user_id = 0;

	protected function setUp(): void {
		parent::setUp();
		AbstractEmbedTransport::flush_cache();
		UserServersBlock::reset_style_emitted_for_tests();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user_id );

		// Register the shortcode (init doesn't fire in unit-test env).
		UserServersBlock::instance()->register_shortcode();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}acrossai_mcp_servers_meta" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}acrossai_mcp_servers" );

		AbstractEmbedTransport::flush_cache();
		UserServersBlock::reset_style_emitted_for_tests();
		remove_all_filters( 'acrossai_mcp_servers_shortcode_html' );
		remove_all_filters( 'acrossai_mcp_user_accessible_servers' );
		remove_shortcode( 'acrossai_mcp_servers' );

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function create_and_enable_server( string $slug, string $name, string $client_slug = 'claude-desktop' ): int {
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => $name,
				'server_slug'            => $slug,
				'description'            => 'desc for ' . $name,
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'mcp-client' => array( $client_slug ) )
		);
		AbstractEmbedTransport::flush_cache();
		return $server_id;
	}

	// ────────────────────────────────────────────────────────────────
	// Anonymous handling
	// ────────────────────────────────────────────────────────────────

	public function test_anonymous_returns_empty_string(): void {
		wp_set_current_user( 0 );
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertSame( '', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Empty-state
	// ────────────────────────────────────────────────────────────────

	public function test_empty_state_renders_wrapper_and_default_message(): void {
		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( 'class="acrossai-mcp-servers acrossai-mcp-servers--empty"', $out );
		$this->assertStringContainsString(
			'You do not have access to any MCP server yet.',
			$out
		);
	}

	public function test_custom_empty_message_attribute(): void {
		$out = do_shortcode( '[acrossai_mcp_servers empty_message="Nothing here"]' );

		$this->assertStringContainsString( 'Nothing here', $out );
		$this->assertStringNotContainsString( 'You do not have access', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Default render shape
	// ────────────────────────────────────────────────────────────────

	public function test_default_render_shape(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString( '<div class="acrossai-mcp-servers">', $out );
		$this->assertStringContainsString( '<ul class="acrossai-mcp-servers__list">', $out );
		$this->assertStringContainsString( 'class="acrossai-mcp-servers__server"', $out );
		$this->assertStringContainsString( 'data-server-slug="srv-a"', $out );
		$this->assertStringContainsString( 'Server A', $out );
		$this->assertStringContainsString( 'data-key="client"', $out );
		$this->assertStringContainsString( 'data-slug="claude-desktop"', $out );
	}

	public function test_heading_attribute_renders_h2(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers heading="My servers"]' );

		$this->assertStringContainsString( '<h2 class="acrossai-mcp-servers__heading">My servers</h2>', $out );
	}

	public function test_show_description_false_omits_desc(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$with_desc = do_shortcode( '[acrossai_mcp_servers]' );
		$without   = do_shortcode( '[acrossai_mcp_servers show_description="0"]' );

		$this->assertStringContainsString( 'class="acrossai-mcp-servers__server-desc"', $with_desc );
		$this->assertStringNotContainsString( 'class="acrossai-mcp-servers__server-desc"', $without );
	}

	// ────────────────────────────────────────────────────────────────
	// Style emission
	// ────────────────────────────────────────────────────────────────

	public function test_style_emitted_exactly_once(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out1 = do_shortcode( '[acrossai_mcp_servers]' );
		$out2 = do_shortcode( '[acrossai_mcp_servers]' );

		$combined = $out1 . $out2;
		$this->assertSame(
			1,
			substr_count( $combined, '<style type="text/css">' ),
			'<style> block must be emitted exactly once per request.'
		);
	}

	public function test_style_reset_between_requests(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out1 = do_shortcode( '[acrossai_mcp_servers]' );
		$this->assertStringContainsString( '<style type="text/css">', $out1 );

		UserServersBlock::reset_style_emitted_for_tests(); // Simulate new request.
		$out2 = do_shortcode( '[acrossai_mcp_servers]' );
		$this->assertStringContainsString( '<style type="text/css">', $out2 );
	}

	// ────────────────────────────────────────────────────────────────
	// Icon rendering
	// ────────────────────────────────────────────────────────────────

	public function test_icon_url_becomes_img(): void {
		// Build a custom transport that yields a URL icon so we can test.
		add_filter(
			'acrossai_mcp_embed_transports',
			static function ( array $classes ): array {
				$classes[] = FakeTransportUrlIcon::class;
				return $classes;
			}
		);
		AbstractEmbedTransport::flush_cache();

		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Server URL',
				'server_slug'            => 'srv-url',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'srv-url',
				'server_version'         => 'v1.0.0',
			)
		);
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'fake-url-icon' => array( 'has-url' ) )
		);
		AbstractEmbedTransport::flush_cache();

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringContainsString(
			'<img class="acrossai-mcp-servers__icon" src="https://cdn.example.com/icon.png"',
			$out
		);
	}

	public function test_icon_non_url_becomes_text(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		// Claude Desktop's icon is 🍰 (emoji) — should render as text span.
		$this->assertMatchesRegularExpression(
			'#<span class="acrossai-mcp-servers__icon" aria-hidden="true">\s*[^<]+\s*</span>#',
			$out
		);
		$this->assertStringNotContainsString( '<img class="acrossai-mcp-servers__icon"', $out );
	}

	// ────────────────────────────────────────────────────────────────
	// Escape at boundary
	// ────────────────────────────────────────────────────────────────

	public function test_escape_at_boundary_server_name(): void {
		// Directly create a server with a hostile name via the Query.
		$server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Foo <script>alert(1)</script>',
				'server_slug'            => 'srv-xss',
				'description'            => 'ok',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'srv-xss',
				'server_version'         => 'v1.0.0',
			)
		);
		ServerMetaQuery::update_meta( $server_id, '_embeds_enabled', '1' );
		AbstractEmbedTransport::save_items_for_server(
			$server_id,
			array( 'mcp-client' => array( 'claude-desktop' ) )
		);
		AbstractEmbedTransport::flush_cache();

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$diagnostic = sprintf(
			'server_id=%d current_user=%d master=%s items=%s enabled=%s accessible=%d',
			$server_id,
			get_current_user_id(),
			var_export( ServerMetaQuery::get_meta( $server_id, '_embeds_enabled' ), true ),
			(string) wp_json_encode( AbstractEmbedTransport::get_items_for_server( $server_id ) ),
			AbstractEmbedTransport::is_enabled_for_server( $server_id, 'client', 'claude-desktop' ) ? 'yes' : 'no',
			count( UserServersBlock::instance()->get_accessible_servers() )
		);

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $out, $diagnostic );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $out, $diagnostic );
	}

	// ────────────────────────────────────────────────────────────────
	// Singleton contract (S6 + A2)
	// ────────────────────────────────────────────────────────────────

	public function test_singleton_private_ctor(): void {
		$reflect = new ReflectionClass( UserServersBlock::class );
		$ctor    = $reflect->getConstructor();

		$this->assertNotNull( $ctor, 'Constructor must exist.' );
		$this->assertTrue( $ctor->isPrivate(), 'Constructor MUST be private per S6.' );
		$this->assertTrue( $reflect->isFinal(), 'Class MUST be final per D36.' );
	}

	public function test_singleton_returns_same_instance(): void {
		$a = UserServersBlock::instance();
		$b = UserServersBlock::instance();
		$this->assertSame( $a, $b );
	}

	// ────────────────────────────────────────────────────────────────
	// T021 — US3 HTML-filter wrapping (SEC-002 documentation)
	//
	// NOTE (SEC-002): F038 returns the `acrossai_mcp_servers_shortcode_html`
	// filter result verbatim without re-escaping. Listener plugins are
	// trusted at the filter boundary — this test documents the seam is
	// functional AND documents the trust boundary. See
	// UserServersBlock.contract.md §Trust boundary disclosure.
	// ────────────────────────────────────────────────────────────────

	public function test_html_filter_round_trip_prepends_comment(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		add_filter(
			'acrossai_mcp_servers_shortcode_html',
			static function ( $html ): string {
				return '<!-- f038-filter-applied -->' . (string) $html;
			}
		);

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringStartsWith( '<!-- f038-filter-applied -->', $out );
		$this->assertStringContainsString( 'class="acrossai-mcp-servers"', $out );
	}

	public function test_html_filter_can_wrap_output(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		add_filter(
			'acrossai_mcp_servers_shortcode_html',
			static function ( $html ): string {
				return '<div class="my-brand-wrapper">' . (string) $html . '</div>';
			}
		);

		$out = do_shortcode( '[acrossai_mcp_servers]' );

		$this->assertStringStartsWith( '<div class="my-brand-wrapper">', $out );
		$this->assertStringEndsWith( '</div>', $out );
		$this->assertStringContainsString( 'class="acrossai-mcp-servers"', $out );
	}

	public function test_html_filter_fires_exactly_once_per_render(): void {
		$this->create_and_enable_server( 'srv-a', 'Server A' );

		$fires = 0;
		add_filter(
			'acrossai_mcp_servers_shortcode_html',
			static function ( $html ) use ( &$fires ) {
				++$fires;
				return $html;
			}
		);

		do_shortcode( '[acrossai_mcp_servers]' );
		$this->assertSame( 1, $fires );
	}
}

// ────────────────────────────────────────────────────────────────
// Fake transport fixture — DTO with URL icon
// ────────────────────────────────────────────────────────────────

final class FakeTransportUrlIcon extends AbstractEmbedTransport {

	public function get_transport_key(): string {
		return 'fake-url-icon';
	}

	public function get_checkbox_label(): string {
		return 'Fake URL Icon';
	}

	public function get_priority(): int {
		return 50;
	}

	public function get_dtos(): array {
		return array(
			array(
				'slug'        => 'has-url',
				'name'        => 'URL Icon Item',
				'icon'        => 'https://cdn.example.com/icon.png',
				'description' => '',
				'meta'        => array(),
			),
		);
	}
}
