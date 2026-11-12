<?php
/**
 * Per-server view of the clients registry (Feature 104).
 *
 * The slug is `clients-seen`, not `clients`: {@see ClientsTab} already holds
 * that one for the config-file snippet inside the Connect wizard, and two tabs
 * answering to one slug would resolve to whichever sorted first.
 *
 * Renders the same list table as {@see \AcrossAI_MCP_Manager\Admin\Partials\ClientsPage},
 * scoped to this server with the Server column hidden — one table, two mount
 * points, the way `McpTrackerTab` reuses the activity log's app.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ClientsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The Clients tab for one server.
 *
 * @since 0.4.9
 */
final class ClientsSeenTab extends AbstractServerTab {

	/**
	 * Tab slug.
	 *
	 * @since  0.4.9
	 * @return string
	 */
	public function slug(): string {
		return 'clients-seen';
	}

	/**
	 * Tab label.
	 *
	 * @since  0.4.9
	 * @return string
	 */
	public function label(): string {
		return __( 'Clients', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot.
	 *
	 * 75 — immediately before Logs at 80. An operator reaches for both after
	 * configuring something, and "who connected" reads before "what happened".
	 *
	 * @since  0.4.9
	 * @return int
	 */
	public function priority(): int {
		return 75;
	}

	/**
	 * Render the scoped list.
	 *
	 * No heading and no lead paragraph: the tab the operator just clicked is
	 * already labelled Clients, and the summary line opens the panel.
	 *
	 * @since  0.4.9
	 * @param  array<string, mixed> $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$server_id = isset( $server['id'] ) ? (int) $server['id'] : 0;

		echo '<div class="mcp-tab-panel">';

		// Handled here as well as on the global screen, because the row actions
		// post back to whichever screen drew them.
		ClientsPage::maybe_handle_action();

		ClientsPage::render_table( $server_id );

		echo '</div>';
	}
}
