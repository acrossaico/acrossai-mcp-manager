<?php
/**
 * Overview tab — read-only server info dashboard.
 *
 * TASK-33 enriches this to the full content shown in the reference plugin
 * screenshot: Server Name, Description, Source badge, Slug, Status toggle,
 * MCP API URL, Route Namespace, Route, Version, App Passwords notice, and
 * a Supported MCP Clients list.
 *
 * Adapted to F011 native shape per Clarifications Q1.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Partials\TypeRequirementNotice;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as MCPServerMetaQuery;
use AcrossAI_MCP_Manager\Includes\MCP\Controller as MCPController;
use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\ClaudeCodeClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\ClaudeDesktopClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\CodexClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\CursorClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\CustomClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\GitHubCopilotClient;
use AcrossAI_MCP_Manager\Includes\MCPClients\VSCodeClient;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Overview tab — read-only server info dashboard + toggle-status button.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class OverviewTab extends AbstractServerTab {

	/**
	 * Static descriptions for each MCP client shown in the Supported MCP
	 * Clients section. Keyed by client slug (AbstractMCPClient::get_client_slug()).
	 *
	 * @since 0.0.6
	 * @var array<string, string>
	 */
	private const CLIENT_DESCRIPTIONS = array(
		'claude-desktop' => 'Anthropic Claude Desktop App',
		'claude-code'    => 'Anthropic Claude Code CLI',
		'vscode'         => 'Visual Studio Code',
		'github-copilot' => 'GitHub Copilot in VS Code (user-level MCP config)',
		'codex'          => 'OpenAI Codex CLI',
		'cursor'         => 'Cursor AI Code Editor',
		'custom'         => 'Custom MCP Client Implementation',
	);

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'overview';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Overview', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot — leftmost tab.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 10;
	}

	/**
	 * Renders the read-only info dashboard.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		echo '<div class="mcp-tab-panel">';
		TypeRequirementNotice::instance()->render( $server, 'overview' );
		$this->render_info_table( $server );
		$this->render_instructions_setting( $server );
		$this->render_passwords_notice();
		$this->render_supported_clients();
		echo '</div>';
	}

	/**
	 * Renders the 9-row server info table (Name / Description / Source /
	 * Slug / Status / MCP API URL / Route Namespace / Route / Version).
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	private function render_info_table( array $server ): void {
		echo '<table class="form-table" role="presentation">';

		$this->render_row(
			__( 'Server Name', 'acrossai-mcp-manager' ),
			sprintf( '<strong>%s</strong>', esc_html( (string) $server['server_name'] ) )
		);

		// Match reference — description row hidden when empty.
		if ( ! empty( $server['description'] ) ) {
			$this->render_row(
				__( 'Description', 'acrossai-mcp-manager' ),
				esc_html( (string) $server['description'] )
			);
		}

		$this->render_row(
			__( 'Source', 'acrossai-mcp-manager' ),
			$this->render_source_badge( (string) ( $server['registered_from'] ?? 'plugin' ) )
		);

		$this->render_row(
			__( 'Slug', 'acrossai-mcp-manager' ),
			sprintf( '<code>%s</code>', esc_html( (string) $server['server_slug'] ) )
		);

		$this->render_row(
			__( 'Status', 'acrossai-mcp-manager' ),
			$this->render_status_control( $server )
		);

		$mcp_api_url = rest_url(
			trailingslashit( (string) $server['server_route_namespace'] ) . (string) $server['server_route']
		);
		$this->render_row(
			__( 'MCP API URL', 'acrossai-mcp-manager' ),
			sprintf( '<code>%s</code>', esc_html( $mcp_api_url ) )
		);

		$this->render_row(
			__( 'Route Namespace', 'acrossai-mcp-manager' ),
			sprintf( '<code>%s</code>', esc_html( (string) $server['server_route_namespace'] ) )
		);

		$this->render_row(
			__( 'Route', 'acrossai-mcp-manager' ),
			sprintf( '<code>%s</code>', esc_html( (string) $server['server_route'] ) )
		);

		$this->render_row(
			__( 'Version', 'acrossai-mcp-manager' ),
			sprintf( '<code>%s</code>', esc_html( (string) $server['server_version'] ) )
		);

		echo '</table>';
	}

	/**
	 * Renders a single info row (label + value). Value is expected to be
	 * pre-escaped HTML by the caller (badge/code/text).
	 *
	 * @since 0.0.6
	 * @param string $label Row label (i18n'd).
	 * @param string $value Row value (pre-escaped HTML).
	 * @return void
	 */
	private function render_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			wp_kses_post( $value )
		);
	}

	/**
	 * Renders the Source badge (Plugin | Database).
	 *
	 * @since 0.0.6
	 * @param string $registered_from Either 'plugin' or 'database'.
	 * @return string Pre-escaped HTML.
	 */
	private function render_source_badge( string $registered_from ): string {
		$is_database = 'database' === $registered_from;
		$label       = $is_database
			? __( 'Database', 'acrossai-mcp-manager' )
			: __( 'Plugin', 'acrossai-mcp-manager' );
		$css_class   = $is_database
			? 'acrossai-source-badge acrossai-source-database'
			: 'acrossai-source-badge acrossai-source-plugin';
		return sprintf(
			'<span class="%1$s">%2$s</span>',
			esc_attr( $css_class ),
			esc_html( $label )
		);
	}

	/**
	 * Renders the Status control — colored badge + Enable/Disable toggle link.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return string Pre-escaped HTML.
	 */
	private function render_status_control( array $server ): string {
		$is_enabled = ! empty( $server['is_enabled'] );

		$badge = sprintf(
			'<span class="acrossai-status-badge %1$s">%2$s</span>',
			$is_enabled ? 'acrossai-status-active' : 'acrossai-status-inactive',
			esc_html( $is_enabled ? __( 'Active', 'acrossai-mcp-manager' ) : __( 'Inactive', 'acrossai-mcp-manager' ) )
		);

		// redirect_to=edit tells Settings::handle_actions() to send the
		// user back to this edit page after the toggle, instead of the
		// server list — the toggle button is embedded on the edit page.
		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => AdminPageSlugs::PARENT,
					'action'      => 'toggle_status',
					'server'      => (int) $server['id'],
					'redirect_to' => 'edit',
				),
				admin_url( 'admin.php' )
			),
			'acrossai_mcp_toggle_' . (int) $server['id']
		);

		$toggle_label = $is_enabled ? __( 'Disable', 'acrossai-mcp-manager' ) : __( 'Enable', 'acrossai-mcp-manager' );
		$button_class = $is_enabled ? 'button button-small' : 'button button-primary button-small';

		$toggle_link = sprintf(
			' &nbsp;<a href="%1$s" class="%2$s">%3$s</a>',
			esc_url( $toggle_url ),
			esc_attr( $button_class ),
			esc_html( $toggle_label )
		);

		return $badge . $toggle_link;
	}

	/**
	 * The per-server connect message: system default, or the operator's own.
	 *
	 * Two states on purpose. "System default" stores NOTHING — a saved copy of
	 * the default would freeze the wording as it read on the day it was saved
	 * and quietly stop tracking the plugin when that wording improves. Absence
	 * of the row IS the default, so there is nothing to go stale.
	 *
	 * The textarea is prefilled with what the server sends today, so "custom"
	 * starts from the real text rather than an empty box. It is
	 * `Controller::default_instructions_for()`, the same value the live path
	 * builds, so what an operator edits cannot differ from what they were
	 * getting.
	 *
	 * @since  0.3.6
	 * @param  array<string, mixed> $server Server row data.
	 * @return void
	 */
	private function render_instructions_setting( array $server ): void {
		$server_id = (int) ( $server['id'] ?? 0 );

		if ( $server_id <= 0 ) {
			return;
		}

		$row = Query::instance()->get_item( $server_id );

		if ( ! $row ) {
			return;
		}

		$default = MCPController::default_instructions_for( $row );
		$stored  = MCPServerMetaQuery::get_meta( $server_id, MCPController::INSTRUCTIONS_META_KEY );
		$custom  = null !== $stored && '' !== $stored;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag; the save itself verified its nonce.
		if ( ! empty( $_GET['acrossai_mcp_manager_instructions_saved'] ) ) {
			printf(
				'<div class="notice notice-success inline"><p>%s</p></div>',
				esc_html__( 'Connect message saved. Already-connected AI clients keep the message they received; new connections get this one.', 'acrossai-mcp-manager' )
			);
		}

		printf(
			'<form method="post" action="%s" class="acrossai-mcp-instructions">',
			esc_url(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'save_instructions',
						'server' => $server_id,
					),
					admin_url( 'admin.php' )
				)
			)
		);
		wp_nonce_field(
			'acrossai_mcp_manager_instructions_' . $server_id,
			'acrossai_mcp_manager_instructions_nonce'
		);

		echo '<h3>' . esc_html__( 'Default server message', 'acrossai-mcp-manager' ) . '</h3>';

		echo '<p class="description">';
		esc_html_e(
			'Sent to an AI client when it connects, after this server\'s description. It tells the client what kind of server this is and which tool to call first.',
			'acrossai-mcp-manager'
		);
		echo '</p>';

		printf(
			'<p><label><input type="radio" name="instructions_mode" value="default" %1$s> <strong>%2$s</strong> %3$s</label></p>',
			checked( $custom, false, false ),
			esc_html__( 'System default', 'acrossai-mcp-manager' ),
			esc_html__( '— kept up to date by the plugin. Recommended.', 'acrossai-mcp-manager' )
		);

		printf(
			'<p><label><input type="radio" name="instructions_mode" value="custom" %1$s> <strong>%2$s</strong> %3$s</label></p>',
			checked( $custom, true, false ),
			esc_html__( 'Custom', 'acrossai-mcp-manager' ),
			esc_html__( '— write your own. It replaces the message below, not your description.', 'acrossai-mcp-manager' )
		);

		// Wrapped, and hidden server-side when System default is selected, so
		// the field is not there to read as "this is what will be sent" when it
		// is not. `backend.js` toggles it as the radios change; the `hidden`
		// attribute below is what makes the first paint correct without
		// waiting for script.
		//
		// With JavaScript off the field simply stays as rendered — and the save
		// handler reads the RADIO, never the textarea's visibility, so a
		// no-script operator can still switch to Custom and have it work.
		printf(
			'<div class="acrossai-mcp-instructions__custom"%s>',
			$custom ? '' : ' hidden'
		);

		printf(
			'<p><textarea name="instructions_custom" rows="7" class="large-text code" placeholder="%1$s">%2$s</textarea></p>',
			esc_attr__( 'Leave empty to use the system default.', 'acrossai-mcp-manager' ),
			// Prefilled with the live default when nothing is stored, so
			// choosing Custom starts from what the server actually sends.
			esc_textarea( $custom ? (string) $stored : $default )
		);

		echo '<p class="description">';
		esc_html_e( 'Leaving this empty keeps the system default.', 'acrossai-mcp-manager' );
		echo '</p>';

		echo '</div>';

		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Save connect message', 'acrossai-mcp-manager' )
		);

		echo '</form>';
	}

	/**
	 * Renders the Application Passwords notice.
	 *
	 * @since 0.0.6
	 * @return void
	 */
	private function render_passwords_notice(): void {
		printf(
			'<div class="notice notice-info inline"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Passwords generated in the client tabs are stored as WordPress Application Passwords. View, revoke, or manage them on your', 'acrossai-mcp-manager' ),
			esc_url( admin_url( 'profile.php#application-passwords-section' ) ),
			esc_html__( 'profile page', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Renders the Supported MCP Clients section.
	 *
	 * @since 0.0.6
	 * @return void
	 */
	private function render_supported_clients(): void {
		$client_class_fqns = array(
			ClaudeDesktopClient::class,
			ClaudeCodeClient::class,
			VSCodeClient::class,
			GitHubCopilotClient::class,
			CodexClient::class,
			CursorClient::class,
			CustomClient::class,
		);

		printf( '<h3>%s</h3>', esc_html__( 'Supported MCP Clients', 'acrossai-mcp-manager' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Click a client tab above to generate credentials and copy the ready-to-paste JSON configuration.', 'acrossai-mcp-manager' )
		);

		echo '<ul class="mcp-clients-list">';
		foreach ( $client_class_fqns as $fqn ) {
			if ( ! class_exists( $fqn ) || ! is_subclass_of( $fqn, AbstractMCPClient::class ) ) {
				continue;
			}
			/** @var AbstractMCPClient $client */
			$client      = new $fqn();
			$slug        = $client->get_client_slug();
			$name        = $client->get_client_name();
			$description = self::CLIENT_DESCRIPTIONS[ $slug ] ?? '';

			printf(
				'<li><strong>%1$s</strong> — %2$s</li>',
				esc_html( $name ),
				esc_html( $description )
			);
		}
		echo '</ul>';
	}
}
