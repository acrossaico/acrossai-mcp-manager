<?php
/**
 * Tests for AbstractServerTab shared helpers.
 *
 * Feature 013. PHPUnit 9.6 note: use the `@dataProvider` annotation. The WordPress test
 * suite caps at PHPUnit 9.x, which predates PHP attributes.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\ServerTabs
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs;

use WP_UnitTestCase;

require_once __DIR__ . '/TestServerTabFixture.php';

final class AbstractServerTabTest extends WP_UnitTestCase {

	/**
	 * Verifies open_form() emits a POST form with the expected hidden fields.
	 */
	public function test_open_form_emits_post_form_with_hidden_fields(): void {
		$tab = new TestServerTabFixture();
		ob_start();
		$tab->call_open_form( array( 'id' => 42 ), 'save_general' );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<form method="post"', $output );
		$this->assertStringContainsString( 'name="page"', $output );
		$this->assertStringContainsString( 'value="acrossai_mcp_manager"', $output );
		$this->assertStringContainsString( 'name="action"', $output );
		$this->assertStringContainsString( 'value="save_general"', $output );
		$this->assertStringContainsString( 'name="server"', $output );
		$this->assertStringContainsString( 'value="42"', $output );
	}

	/**
	 * Verifies nonce_field() binds the nonce action to (int) $server['id'].
	 */
	public function test_nonce_field_binds_action_to_server_id(): void {
		$tab = new TestServerTabFixture();
		ob_start();
		$tab->call_nonce_field( array( 'id' => 42 ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="_wpnonce"', $output );
		// Nonce action = 'acrossai_mcp_manager_server_42'.
		// wp_nonce_field() doesn't emit the action name in HTML, but we can verify
		// the emitted nonce validates against the expected action.
		if ( preg_match( '/value="([^"]+)"/', $output, $matches ) ) {
			$this->assertNotFalse(
				wp_verify_nonce( $matches[1], 'acrossai_mcp_manager_server_42' )
			);
		}
	}

	/**
	 * Verifies json_config_block() emits a <pre><code> block with escaped JSON.
	 */
	public function test_json_config_block_emits_pre_code_with_json(): void {
		$tab = new TestServerTabFixture();
		ob_start();
		$tab->call_json_config_block( array( 'id' => 42 ), 'test-client', array( 'foo' => 'bar' ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<pre id="acrossai-mcp-test-client-config-42">', $output );
		$this->assertStringContainsString( '<code>', $output );
		$this->assertStringContainsString( '&quot;foo&quot;: &quot;bar&quot;', $output );
	}

	/**
	 * Verifies server_edit_url() returns a URL with the expected query args.
	 */
	public function test_server_edit_url_includes_expected_query_args(): void {
		$tab = new TestServerTabFixture();
		$url = $tab->call_server_edit_url( array( 'id' => 42 ), 'npm' );

		$this->assertStringContainsString( 'page=acrossai_mcp_manager', $url );
		$this->assertStringContainsString( 'action=edit', $url );
		$this->assertStringContainsString( 'server=42', $url );
		$this->assertStringContainsString( 'tab=npm', $url );
	}
}
