<?php
/**
 * QuickConnectPage — hijacks the plugin's admin page render when
 * ?quick-connect=1 is present.
 *
 * Feature 069 T016 — Emits the React mount div + a <noscript> fallback
 * inside the standard WP admin `<div class="wrap">` chrome. The
 * `Settings::render_settings_page()` dispatcher (T017) delegates to this
 * class when the wizard URL is active; otherwise the list-table view
 * renders as before (spec FR-006 / FR-029 additive-only).
 *
 * All React bootstrap is done via `wp_localize_script` from
 * `admin/Main::enqueue_scripts()` (T018) — this class emits ONLY the
 * mount point + noscript fallback. Zero inline JavaScript.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/QuickConnect
 * @since      0.2.11
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\QuickConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the wizard's React mount point.
 *
 * @since 0.2.11
 */
final class QuickConnectPage {

	/**
	 * Singleton instance.
	 *
	 * @since 0.2.11
	 * @var self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Singleton accessor.
	 *
	 * @since 0.2.11
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor enforces singleton pattern (S6).
	 *
	 * @since 0.2.11
	 */
	private function __construct() {}

	/**
	 * Emit the React mount div + noscript fallback.
	 *
	 * @since 0.2.11
	 * @return void
	 */
	public function render(): void {
		echo '<div class="wrap acrossai-mcp-quick-connect-wrap">';
		echo '<div id="acrossai-mcp-quick-connect-root"></div>';
		echo '<noscript><p>';
		echo esc_html__(
			'The Quick Connect via AcrossAI wizard requires JavaScript. Enable JavaScript in your browser to use it.',
			'acrossai-mcp-manager'
		);
		echo '</p></noscript>';
		echo '</div>';
	}
}
