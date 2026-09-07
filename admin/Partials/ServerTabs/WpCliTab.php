<?php
/**
 * The WP-CLI tab — STDIO transport instructions for this server.
 *
 * Feature 013 — ported from the reference plugin's render_wpcli_tab
 * (src/Admin/Settings.php:1762–1880). Uses the vendor mcp-adapter
 * package's built-in `wp mcp-adapter list` + `wp mcp-adapter serve`
 * commands (registered by wordpress/mcp-adapter's McpAdapter class).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The WP-CLI tab — STDIO transport commands + JSON config block.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class WpCliTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'wp-cli';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'WP-CLI', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 50;
	}

	/**
	 * Renders the WP-CLI STDIO transport instructions.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$server_id   = (int) $server['id'];
		$server_slug = ! empty( $server['server_slug'] )
			? (string) $server['server_slug']
			: sanitize_title( (string) $server['server_name'] );
		$site_slug   = sanitize_title( (string) get_bloginfo( 'name' ) );
		$server_key  = $site_slug ? $site_slug . '-' . $server_slug : $server_slug;

		$cmd_list  = 'wp mcp-adapter list';
		$cmd_serve = sprintf( 'wp mcp-adapter serve --server=%s --user=admin', $server_slug );

		// get_home_path() lives in wp-admin/includes/file.php — always loaded
		// on admin pages but safe to require_once again if not.
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$wp_root      = untrailingslashit( get_home_path() );
		$stdio_config = array(
			'command' => 'wp',
			'args'    => array(
				'mcp-adapter',
				'serve',
				'--server=' . $server_slug,
				'--user=admin',
				'--path=' . $wp_root,
			),
		);
		$stdio_json   = (string) wp_json_encode( $stdio_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		echo '<div class="mcp-tab-panel">';

		printf( '<h2>%s</h2>', esc_html__( 'WP-CLI (STDIO Transport)', 'acrossai-mcp-manager' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'MCP clients can connect by launching WP-CLI as a subprocess instead of calling the HTTP endpoint. This is ideal for local WordPress installs — no credentials are transmitted over the network.', 'acrossai-mcp-manager' )
		);

		printf( '<h3>%s</h3>', esc_html__( 'STDIO Transport (Local / Subprocess Mode)', 'acrossai-mcp-manager' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'MCP clients can also connect by launching WP-CLI as a subprocess instead of calling the HTTP endpoint. This is ideal for local WordPress installs — no credentials are transmitted over the network.', 'acrossai-mcp-manager' )
		);

		$this->render_command_block(
			'wpcli_list_' . $server_id,
			__( 'List all registered MCP servers', 'acrossai-mcp-manager' ),
			'',
			$cmd_list,
			1
		);

		$this->render_command_block(
			'wpcli_serve_' . $server_id,
			__( 'Start this server via STDIO', 'acrossai-mcp-manager' ),
			__( 'Blocks until the MCP client disconnects. Replace "admin" with any WordPress user login or ID.', 'acrossai-mcp-manager' ),
			$cmd_serve,
			1
		);

		$this->render_command_block(
			'wpcli_stdio_config_' . $server_id,
			__( 'STDIO config block (paste into your MCP client)', 'acrossai-mcp-manager' ),
			sprintf(
				/* translators: %s: server key */
				__( 'Add this under the key "%s" in your MCP client\'s config file. WP-CLI must be in your PATH, or replace "wp" with the full path to the binary.', 'acrossai-mcp-manager' ),
				$server_key
			),
			$stdio_json,
			8
		);

		$this->render_transport_notice();

		echo '</div>';
	}

	/**
	 * Renders a single label + textarea + copy-button block.
	 *
	 * @since 0.0.6
	 * @param string $field_id    DOM id — used by the copy handler's data-field.
	 * @param string $label       Label text (i18n'd, plain string).
	 * @param string $description Optional description shown under the label.
	 * @param string $body        Textarea content.
	 * @param int    $rows        Row count (1 for single-line command; 8 for JSON).
	 * @return void
	 */
	private function render_command_block( string $field_id, string $label, string $description, string $body, int $rows ): void {
		echo '<div class="mcp-config-json" style="margin-top:16px;">';
		printf(
			'<label for="%1$s"><strong>%2$s</strong></label>',
			esc_attr( $field_id ),
			esc_html( $label )
		);
		if ( '' !== $description ) {
			printf(
				'<p class="description" style="margin-bottom:6px;">%s</p>',
				esc_html( $description )
			);
		}
		$css_class = 1 === $rows ? 'widefat code mcp-cmd' : 'widefat code';
		printf(
			'<textarea id="%1$s" class="%2$s" rows="%3$d" readonly>%4$s</textarea>',
			esc_attr( $field_id ),
			esc_attr( $css_class ),
			(int) $rows,
			esc_textarea( $body )
		);
		printf(
			'<button type="button" class="button copy-to-clipboard" data-field="%1$s">%2$s</button>',
			esc_attr( $field_id ),
			esc_html__( 'Copy', 'acrossai-mcp-manager' )
		);
		echo '</div>';
	}

	/**
	 * Renders the STDIO-vs-HTTP explanation notice.
	 *
	 * @since 0.0.6
	 * @return void
	 */
	private function render_transport_notice(): void {
		echo '<div class="notice notice-warning inline" style="margin-top:16px;">';
		printf(
			'<p><strong>%s</strong></p>',
			esc_html__( 'STDIO vs HTTP transport', 'acrossai-mcp-manager' )
		);
		echo '<ul style="list-style:disc;margin-left:18px;">';
		printf(
			'<li>%s</li>',
			esc_html__( 'STDIO — MCP client spawns wp as a subprocess. Best for local development; no network exposure.', 'acrossai-mcp-manager' )
		);
		printf(
			'<li>%s</li>',
			esc_html__( 'HTTP (npx) — MCP client connects to the REST endpoint over the network. Best for remote or shared servers.', 'acrossai-mcp-manager' )
		);
		echo '</ul>';
		printf(
			'<p>%s</p>',
			esc_html__( 'The --path flag in the STDIO config is the absolute path to this WordPress installation on disk. Adjust it if the wp binary cannot find WordPress automatically.', 'acrossai-mcp-manager' )
		);
		echo '</div>';
	}
}
