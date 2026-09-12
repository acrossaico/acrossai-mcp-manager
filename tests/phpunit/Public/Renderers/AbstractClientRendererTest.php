<?php
/**
 * Tests for AbstractClientRenderer — resolve_context + cap check + missing server.
 *
 * Feature 013. PHPUnit 9.6 note: use the `@dataProvider` annotation. The WordPress test
 * suite caps at PHPUnit 9.x, which predates PHP attributes.
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Renderers
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Public\Renderers;

use AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock;
use WP_UnitTestCase;

final class AbstractClientRendererTest extends WP_UnitTestCase {

	/**
	 * SEC-013-003 — filter returning non-array MUST NOT fatal; defaults apply.
	 */
	public function test_resolve_context_casts_non_array_filter_return(): void {
		$filter = static function () {
			return null;  // Non-array intentionally.
		};
		add_filter( 'acrossai_mcp_client_block_context', $filter, 10, 3 );

		ob_start();
		NpmClientBlock::instance()->render( 999999, array() );
		$output = (string) ob_get_clean();

		// Missing server → notice; no fatal.
		$this->assertStringContainsString( 'not found', strtolower( $output ) );

		remove_filter( 'acrossai_mcp_client_block_context', $filter, 10 );
	}

	/**
	 * Verifies render() fails silently when current user lacks context cap.
	 */
	public function test_render_silent_no_op_when_cap_fails(): void {
		wp_set_current_user( 0 );  // Not logged in.

		ob_start();
		NpmClientBlock::instance()->render(
			1,
			array( 'cap' => 'manage_options' )
		);
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Verifies missing-server notice when server_id is invalid.
	 */
	public function test_missing_server_notice_when_invalid_id(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		NpmClientBlock::instance()->render( 999999, array() );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'not found', strtolower( $output ) );
	}
}
