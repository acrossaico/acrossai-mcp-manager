<?php
/**
 * The MCP Tracker tab — link to the WPVMCPT plugin if installed.
 *
 * Feature 013 — ported from reference plugin's render_mcp_tracker_tab
 * (src/Admin/Settings.php:2293–2379). Detection-only render — never
 * executes tracker code directly; class_exists/defined guard per D8.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ActivityLogPage;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The MCP Tracker tab.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class McpTrackerTab extends AbstractServerTab {


	/**
	 * Returns the tab slug.
	 *
	 * Note: the slug is `mcp-log` (renamed from `mcp-tracker`); the class,
	 * body copy, and outbound links still reference the third-party
	 * "MCP Tracker" plugin (WPVMCPT), which is a distinct product name.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'mcp-log';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Logs', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 80;
	}

	/**
	 * Renders MCP Tracker integration links. Gated on WPVMCPT presence per D8.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$server_id   = isset( $server['id'] ) ? (int) $server['id'] : 0;
		$server_slug = ! empty( $server['server_slug'] )
			? (string) $server['server_slug']
			: sanitize_title( (string) $server['server_name'] );

		// No heading or lead paragraph here. The tab the operator just clicked
		// is already labelled "Logs", and the card below opens with the stat
		// tiles, so an <h2> saying "Logs" and a line restating the tab's
		// purpose only pushed the content down.
		echo '<div class="mcp-tab-panel">';

		// F096 — the same React app the global Activity page mounts, filtered to
		// this server with the Server column hidden. One component, two mount
		// points; the only difference is this bootstrap payload.
		ActivityLogPage::instance()->enqueue_bundle(
			array(
				'serverId'   => $server_id,
				'showServer' => false,
				'rootId'     => 'acrossai-mcp-activity-root',
			)
		);

		echo '<div id="acrossai-mcp-activity-root"></div>';

		// The third-party plugin this tab used to advertise. Kept as a
		// secondary note rather than an install CTA: both logs can run at once
		// (different tables, no interference), and an operator who already has
		// it should be told why they are seeing two.
		if ( defined( 'WPVMCPT_PLUGIN_VERSION' ) || class_exists( 'WPVMCPT\\Plugin' ) ) {
			$tracker_url = admin_url( 'admin.php?page=wpvmcpt-requests-list&server=' . rawurlencode( $server_slug ) );

			printf(
				'<p class="description" style="margin-top:16px">%s <a href="%s">%s</a></p>',
				esc_html__( 'MCP Tracker is also active on this site and logs the same traffic independently.', 'acrossai-mcp-manager' ),
				esc_url( $tracker_url ),
				esc_html__( 'Open MCP Tracker', 'acrossai-mcp-manager' )
			);
		}

		echo '</div>';
	}
}
