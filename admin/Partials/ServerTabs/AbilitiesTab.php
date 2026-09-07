<?php
/**
 * The Abilities tab — per-server ability exposure surface.
 *
 * Feature 017 rewrites this tab from read-only PHP tables to a
 * `@wordpress/dataviews`-driven React app. Effective exposure is stored in
 * the new `MCPServerAbility` BerlinDB module and computed via
 * `ExposureResolver::resolve_effective()` (F082) — was `resolve()` pre-F082.
 *
 * Feature 013's private tab-body helpers are retired here — the REST
 * controller + React app own that logic now.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Abilities tab.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.6
 */
final class AbilitiesTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'abilities';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function label(): string {
		return __( 'Abilities', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return 60;
	}

	/**
	 * Render the tab body.
	 *
	 * Two graceful-degradation branches preserved from the F013 shape:
	 *   - Server disabled → warning notice AND picker still mounts so
	 *     operators can prepare the exposure set in advance.
	 *   - `wp_get_abilities()` absent → warning notice; picker cannot mount.
	 *
	 * When the Abilities API is present, emit a mount div for the F017 React
	 * app (bundle enqueued by `admin/Main.php::maybe_enqueue_abilities_app()`).
	 *
	 * @since 0.0.6
	 * @param array $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$enabled = ! empty( $server['is_enabled'] );

		echo '<div class="mcp-tab-panel">';
		printf( '<h2>%s</h2>', esc_html__( 'WordPress Abilities', 'acrossai-mcp-manager' ) );

		if ( ! $enabled ) {
			printf(
				'<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Server is disabled.', 'acrossai-mcp-manager' ),
				esc_html__( 'Enable the server on the Overview tab to expose these abilities to MCP clients. You can still curate the exposure set below — your selection will take effect the moment the server is enabled.', 'acrossai-mcp-manager' )
			);
			// Fall through — the picker is still editable while the server is
			// disabled so the operator can prepare the exposure set in advance.
		}

		// Nudge the operator toward the sibling AcrossAI Abilities Manager
		// plugin when it's not active — the add-on registers a rich library
		// of built-in WordPress abilities that would populate the picker
		// below. Same message + link for both "not installed" and
		// "installed-but-off" states (single is_plugin_active check); the
		// shared Add-ons page handles the install/activate transition.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( 'acrossai-abilities-manager/acrossai-abilities-manager.php' ) ) {
			printf(
				'<div class="notice notice-info inline"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'AcrossAI Abilities Manager', 'acrossai-mcp-manager' ),
				esc_html__( '— the add-on ships a rich library of built-in WordPress abilities you can expose here, a big head start for developing and building sites.', 'acrossai-mcp-manager' ),
				esc_url( admin_url( 'admin.php?page=acrossai-addons' ) ),
				esc_html__( 'Get it from the Add-ons page →', 'acrossai-mcp-manager' )
			);
		}

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'The WordPress Abilities API is not available on this installation.', 'acrossai-mcp-manager' )
			);
			echo '</div>';
			return;
		}

		printf(
			'<div id="acrossai-mcp-abilities-root" data-server-id="%1$d" data-server-slug="%2$s"><p class="description">%3$s</p></div>',
			(int) $server['id'],
			esc_attr( (string) ( $server['server_slug'] ?? '' ) ),
			esc_html__( 'Loading abilities…', 'acrossai-mcp-manager' )
		);
		echo '</div>';
	}
}
