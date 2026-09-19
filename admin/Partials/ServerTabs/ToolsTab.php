<?php
/**
 * The Tools tab — per-server curation of which registered abilities this
 * MCP server exposes as callable MCP tools.
 *
 * Feature 020 rewrites `render_body` from a static three-row reference table
 * into a React mount div. The React app (`build/js/tools.js`) reads its own
 * config from `window.acrossaiMcpTools` (localized by
 * `Admin\Main::maybe_enqueue_tools_app()`).
 *
 * Slug, label, and priority (50 — before Abilities @ 60) are preserved so
 * F019's `acrossai_mcp_manager_server_tabs` filter output is unchanged.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Partials\TypeRequirementNotice;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Tools tab.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class ToolsTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'tools';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Tools', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot — unchanged from F013 / F019 (slot 50, before Abilities @ 60).
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 50;
	}

	/**
	 * Renders the Tools tab body — React shuttle picker mount + graceful degrades.
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$enabled = ! empty( $server['is_enabled'] );

		echo '<div class="mcp-tab-panel">';
		printf( '<h2>%s</h2>', esc_html__( 'MCP Tools', 'acrossai-mcp-manager' ) );

		if ( ! $enabled ) {
			printf(
				'<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Server is disabled.', 'acrossai-mcp-manager' ),
				esc_html__( 'Enable the server on the Overview tab to make these tools available to MCP clients. You can still curate the tool set below — your selection will take effect the moment the server is enabled.', 'acrossai-mcp-manager' )
			);
			// Fall through — the picker is still editable while the server is
			// disabled so the operator can prepare the tool set in advance.
		}

		// The add-on promo that used to sit here is gone. It appeared on every
		// server whatever its type, said nothing specific to the screen it was
		// on, and stacked above the notices that DO carry information.
		//
		// This is what replaced it: the same notice the Overview tab shows, so
		// the tab displaying the tools that are not being served explains why
		// and offers the fix, instead of the bare sentence it used to render
		// from JavaScript. `current_tab` drops the "Change the server type"
		// button here, because that control is already on this page.
		TypeRequirementNotice::instance()->render( $server, 'tools' );

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			printf(
				'<div class="notice notice-error inline"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'WordPress Abilities API unavailable.', 'acrossai-mcp-manager' ),
				esc_html__( 'The Tools tab requires the WordPress Abilities API. Install or enable a plugin that provides it (e.g. the AcrossAI Core Abilities plugin).', 'acrossai-mcp-manager' )
			);
			echo '</div>';
			return;
		}

		printf(
			'<div id="acrossai-mcp-tools-root" data-server-id="%1$d" data-server-slug="%2$s"><p class="description">%3$s</p></div>',
			(int) $server['id'],
			esc_attr( (string) ( $server['server_slug'] ?? '' ) ),
			esc_html__( 'Loading tools…', 'acrossai-mcp-manager' )
		);

		echo '</div>';
	}
}
