<?php
/**
 * Update Server tab — edit form for database-registered servers.
 *
 * Feature 013 — visible only when $server['registered_from'] === 'database'
 * per FR-006. Port from reference plugin lines 2390-2510 (adapted to F011
 * MCPServerQuery API).
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
 * The Update Server tab. DB-only visibility.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class UpdateServerTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'update-server';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Update Server', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 90;
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
	 * Renders the update-server form.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$post_url = add_query_arg(
			array(
				'page'   => AdminPageSlugs::PARENT,
				'action' => 'update',
				'server' => (int) $server['id'],
			),
			admin_url( 'admin.php' )
		);

		printf( '<h2>%s</h2>', esc_html__( 'Update Database-Registered Server', 'acrossai-mcp-manager' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'This server was registered dynamically via the database. Update its metadata here.', 'acrossai-mcp-manager' )
		);

		$this->open_form( $server, 'update', $post_url );
		$this->nonce_field( $server, 'acrossai_mcp_update_' . (int) $server['id'] );

		echo '<table class="form-table" role="presentation">';
		$this->render_text_row( 'server_name', __( 'Name', 'acrossai-mcp-manager' ), (string) $server['server_name'], true );
		$this->render_textarea_row( 'description', __( 'Description', 'acrossai-mcp-manager' ), (string) $server['description'] );
		$this->render_text_row( 'server_route_namespace', __( 'Route Namespace', 'acrossai-mcp-manager' ), (string) $server['server_route_namespace'], false );
		$this->render_text_row( 'server_route', __( 'Route', 'acrossai-mcp-manager' ), (string) $server['server_route'], false );
		$this->render_text_row( 'server_version', __( 'Version', 'acrossai-mcp-manager' ), (string) $server['server_version'], false );
		echo '</table>';

		$this->close_form( __( 'Update Server', 'acrossai-mcp-manager' ) );
	}

	/**
	 * Renders one form-table row with an <input type="text">.
	 *
	 * @since 0.0.6
	 * @param string $name     Field name (also the id).
	 * @param string $label    Label text.
	 * @param string $value    Current field value.
	 * @param bool   $required Whether the field is required.
	 * @return void
	 */
	private function render_text_row( string $name, string $label, string $value, bool $required ): void {
		$required_attr = $required ? ' required' : '';
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="%1$s" class="regular-text" value="%3$s"%4$s /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_attr( $required_attr )
		);
	}

	/**
	 * Renders one form-table row with a <textarea>.
	 *
	 * @since 0.0.6
	 * @param string $name  Field name (also the id).
	 * @param string $label Label text.
	 * @param string $value Current textarea value.
	 * @return void
	 */
	private function render_textarea_row( string $name, string $label, string $value ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea id="%1$s" name="%1$s" class="large-text" rows="3">%3$s</textarea></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( $value )
		);
	}
}
