<?php
/**
 * F034 — MCPClientsBlock render regression + SEC-034-001 hardening.
 *
 * Verifies (a) FR-016 render byte-identity for a representative built-in
 * client (claude-desktop) using DOM-marker assertions, and (b) the
 * SEC-034-001 preservation-invariant: metadata returned by a third-party
 * AbstractMCPClient subclass is escaped at render, not injected as raw HTML.
 *
 * Runs under the `renderers` suite with WP bootstrap.
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Renderers
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Public\Renderers;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP_UnitTestCase;

// Named fake subclass returning attacker-controllable metadata — used to
// prove that MCPClientsBlock render helpers still escape values at output
// (per SEC-034-001 preservation invariant + Constitution §III).
final class HostileMetadataClient extends AbstractMCPClient {
	public function get_client_slug(): string {
		return 'hostile-metadata';
	}
	public function get_client_name(): string {
		return 'Hostile Metadata';
	}
	public function get_config_snippet( string $server_url, string $auth_token ) {
		return array();
	}
	public function get_description(): string {
		return '<script>alert(1)</script>';
	}
	public function get_config_file(): string {
		return '<script>alert(2)</script>';
	}
}

final class MCPClientsBlockRenderTest extends WP_UnitTestCase {

	/**
	 * Fixture server id.
	 *
	 * These tests used to call render( 1, … ), assuming a row with id 1 had
	 * survived from an earlier suite. Suites share one database and several
	 * TRUNCATE their tables, so that assumption held only by accident — and
	 * stopped holding as soon as the suites actually ran. Each test now owns
	 * its fixture.
	 *
	 * @var int
	 */
	private $server_id = 0;

	public function setUp(): void {
		parent::setUp();
		$this->server_id = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Renderer Fixture Server',
				'server_slug'            => 'renderer-fixture-server',
				'description'            => 'Fixture for the renderers suite.',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => 'renderer-fixture-server',
				'server_version'         => 'v1.0.0',
			)
		);
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * FR-016 render byte-identity check — Claude Desktop panel MUST contain
	 * the migrated metadata: emoji, config file path, top-level key label,
	 * and the first phrase of the instructions.
	 *
	 * Uses server_id=1 with context sub_client=claude-desktop. Missing-server
	 * gracefully handled by AbstractClientRenderer; sub-nav still renders
	 * because MCPClientsBlock::render_body iterates the client list independently.
	 */
	public function test_claude_desktop_panel_renders_migrated_metadata(): void {
		ob_start();
		MCPClientsBlock::instance()->render( $this->server_id,
			array(
				'context'    => 'admin',
				'sub_client' => 'claude-desktop',
			)
		);
		$output = (string) ob_get_clean();

		// Sub-nav shows the claude-desktop emoji (from ClaudeDesktopClient::get_icon()).
		$this->assertStringContainsString( '🍰', $output, 'FR-016: sub-nav MUST render the migrated emoji from get_icon().' );

		// Panel body shows the config file path (from ClaudeDesktopClient::get_config_file()).
		$this->assertStringContainsString(
			'~/Library/Application Support/Claude/claude_desktop_config.json',
			$output,
			'FR-016: panel MUST render the migrated config file path from get_config_file().'
		);

		// Panel body shows the top-level key label (from ClaudeDesktopClient::get_top_level_key()).
		$this->assertStringContainsString( 'mcpServers', $output, 'FR-016: panel MUST render the migrated top-level key.' );

		// Instructions text renders (from ClaudeDesktopClient::get_instructions()).
		$this->assertStringContainsString( 'Generate a password', $output, 'FR-016: panel MUST render the migrated instructions.' );
	}

	/**
	 * SEC-034-001 preservation invariant — a hostile third-party subclass
	 * returning `<script>` payloads from get_description() / get_config_file()
	 * MUST have its return values escaped at output. Failing this assertion
	 * indicates T016/T017 removed an esc_* call from the render helpers.
	 */
	public function test_hostile_third_party_metadata_is_escaped_at_render(): void {
		add_filter(
			'acrossai_mcp_client_classes',
			static function ( array $fqns ): array {
				$fqns[] = HostileMetadataClient::class;
				return $fqns;
			}
		);

		ob_start();
		MCPClientsBlock::instance()->render( $this->server_id,
			array(
				'context'    => 'admin',
				'sub_client' => 'hostile-metadata',
			)
		);
		$output = (string) ob_get_clean();

		// The raw script tag MUST NOT appear anywhere in the rendered output.
		$this->assertStringNotContainsString(
			'<script>alert(1)</script>',
			$output,
			'SEC-034-001: get_description() output MUST be escaped. Raw <script> tag present indicates a missing esc_* call.'
		);
		$this->assertStringNotContainsString(
			'<script>alert(2)</script>',
			$output,
			'SEC-034-001: get_config_file() output MUST be escaped. Raw <script> tag present indicates a missing esc_* call.'
		);

		// The escaped form MUST appear (proof that the escape function ran).
		$this->assertStringContainsString(
			'&lt;script&gt;alert(1)&lt;/script&gt;',
			$output,
			'SEC-034-001: escaped description MUST appear in rendered output.'
		);
	}
}
