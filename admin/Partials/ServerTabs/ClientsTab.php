<?php
/**
 * MCP Clients tab — thin delegate to MCPClientsBlock.
 *
 * Reads the ?client= URL query param (Clarifications Q2) and passes it as
 * the sub_client context to the block.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Public\Renderers\MCPClientsBlock;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The MCP Clients tab — thin delegate to MCPClientsBlock.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class ClientsTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'clients';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'MCP Clients', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 20;
	}

	/**
	 * Delegates render to MCPClientsBlock. Reads $_GET['client'] for sub-nav.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sub-nav routing only, no state mutation.
		$sub_client = isset( $_GET['client'] ) ? sanitize_key( wp_unslash( (string) $_GET['client'] ) ) : '';

		MCPClientsBlock::instance()->render(
			(int) $server['id'],
			array(
				'context'           => 'admin',
				'cap'               => 'manage_options',
				'submit_target_url' => ConnectTab::method_url( $server, 'clients' ),
				'nonce_action'      => 'acrossai_mcp_manager_server_' . (int) $server['id'],
				'sub_client'        => $sub_client,
			)
		);
	}
}
