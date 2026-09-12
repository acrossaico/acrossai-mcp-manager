<?php
/**
 * Danger Zone tab — server deletion form.
 *
 * Feature 013 — visible only when $server['registered_from'] === 'database'.
 * Port from reference plugin lines 2524-2592.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ProtectedServers;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Danger Zone tab. DB-only visibility.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class DangerZoneTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'danger-zone';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Danger Zone', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot — rightmost tab.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 100;
	}

	/**
	 * Visible only for operator-created, database-registered servers.
	 *
	 * F088 — plugin-managed rows (DefaultServerSeeder) are excluded even
	 * though the AcrossAI one is `registered_from = 'database'`: the seeder
	 * re-asserts its managed columns on every admin request, so edits here
	 * would silently revert.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return bool
	 */
	public function visible_for( array $server ): bool {
		return 'database' === ( $server['registered_from'] ?? '' )
			&& ! ProtectedServers::is_protected_server( $server );
	}

	/**
	 * Renders the delete-with-confirmation form.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$post_url = add_query_arg(
			array(
				'page'   => AdminPageSlugs::PARENT,
				'action' => 'delete',
				'server' => (int) $server['id'],
			),
			admin_url( 'admin.php' )
		);

		printf(
			'<h2 style="color:#d63638;">%s</h2>',
			esc_html__( 'Danger Zone', 'acrossai-mcp-manager' )
		);
		printf(
			'<div class="notice notice-error inline"><p><strong>%s</strong></p><p>%s</p></div>',
			esc_html__( 'This action cannot be undone.', 'acrossai-mcp-manager' ),
			esc_html__( 'Deleting this server removes its database row, associated CLI auth log entries, and Claude Connector configuration. WordPress Application Passwords issued for CLI connections to this server are NOT automatically revoked.', 'acrossai-mcp-manager' )
		);

		$this->open_form( $server, 'delete', $post_url );
		$this->nonce_field( $server, 'acrossai_mcp_delete_' . (int) $server['id'] );

		// data-acrossai-confirm attribute is picked up by src/js/backend.js.
		printf(
			'<p><label><input type="checkbox" name="confirm_delete" value="1" required /> %s</label></p>',
			esc_html__( 'Yes, I understand this is permanent.', 'acrossai-mcp-manager' )
		);
		printf(
			'<p><button type="submit" class="button button-primary" style="background:#d63638; border-color:#d63638;" data-acrossai-confirm="%s">%s</button></p>',
			esc_attr__( 'Delete this server? This action cannot be undone.', 'acrossai-mcp-manager' ),
			esc_html__( 'Delete Server', 'acrossai-mcp-manager' )
		);

		echo '</form>';
	}
}
