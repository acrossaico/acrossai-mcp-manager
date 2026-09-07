<?php
/**
 * The npm tab — thin delegate to NpmClientBlock.
 *
 * Feature 013 — the full client-config render lives in public/Renderers/
 * so third-party plugins (BuddyBoss, WooCommerce) can embed the same UI.
 * This tab class is a ~15-line wrapper that supplies the admin context.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Public\Renderers\NpmClientBlock;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The npm tab — thin delegate to NpmClientBlock.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class NpmTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'npm';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'npm', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 30;
	}

	/**
	 * Delegates render to NpmClientBlock.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		NpmClientBlock::instance()->render(
			(int) $server['id'],
			array(
				'context'           => 'admin',
				'cap'               => 'manage_options',
				'submit_target_url' => ConnectTab::method_url( $server, 'npm' ),
				'nonce_action'      => 'acrossai_mcp_manager_server_' . (int) $server['id'],
			)
		);
	}
}
