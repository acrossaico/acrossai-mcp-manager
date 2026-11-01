<?php
/**
 * Feature 101 — connection troubleshooter admin page.
 *
 * Registers a submenu under the shared AcrossAI parent and renders a shell
 * the bundle fills in. Nothing runs on page load: the checks make outbound
 * HTTP requests, so the operator presses a button.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_Main_Menu\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The Troubleshoot screen. Hook wiring lives in Main.php per A1.
 */
final class TroubleshootPage {

	/** `?page=` slug for this screen. */
	public const PAGE_SLUG = 'acrossai_mcp_troubleshoot';

	/** Script handle and localized object name stem. */
	private const HANDLE = 'acrossai-mcp-troubleshoot';

	/** DOM id the results render into. */
	private const ROOT_ID = 'acrossai-mcp-troubleshoot-root';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private — wiring lives in Main::define_admin_hooks() per A1. */
	private function __construct() {}

	/**
	 * Register the submenu.
	 *
	 * Position 9, directly after Logs: an operator reaches for diagnostics
	 * next to the record of what happened, and both are things you open when
	 * something is already wrong.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			SettingsPage::PARENT_SLUG,
			__( 'Troubleshoot', 'acrossai-mcp-manager' ),
			__( 'Troubleshoot', 'acrossai-mcp-manager' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			9
		);
	}

	/**
	 * Enqueue the bundle on this screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		// Boot constants, never a path guessed from __DIR__. Guessing is how
		// the activity-log screen shipped broken in 0.4.4 — see the note on
		// ActivityLogPage::enqueue_bundle(). Absent constants mean the plugin
		// is not booted, in which case there is nothing to enqueue.
		if ( ! defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_PATH' ) || ! defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_URL' ) ) {
			return;
		}

		$base     = \ACROSSAI_MCP_MANAGER_PLUGIN_URL;
		$dir      = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH;
		$manifest = $dir . 'build/js/troubleshoot.asset.php';

		// No hand-written dependency fallback: webpack decides this bundle's
		// externals at build time, so any array written here is wrong the
		// moment the bundle changes (B77). A missing manifest means an
		// incomplete install — bail rather than enqueue with guesses.
		if ( ! file_exists( $manifest ) ) {
			return;
		}

		$asset = include $manifest;

		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) || ! is_array( $asset['dependencies'] ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			$base . 'build/js/troubleshoot.js',
			$asset['dependencies'],
			(string) $asset['version'],
			true
		);

		if ( file_exists( $dir . 'build/js/troubleshoot.css' ) ) {
			wp_enqueue_style( self::HANDLE, $base . 'build/js/troubleshoot.css', array(), (string) $asset['version'] );
		}

		wp_set_script_translations( self::HANDLE, 'acrossai-mcp-manager' );

		wp_localize_script(
			self::HANDLE,
			'acrossaiMcpTroubleshoot',
			array(
				'root'   => esc_url_raw( rest_url( 'acrossai-mcp-manager/v1/troubleshoot/run' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'rootId' => self::ROOT_ID,
			)
		);
	}

	/**
	 * Render the page shell.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap acrossai-mcp-troubleshoot">
			<h1><?php esc_html_e( 'Troubleshoot the connection', 'acrossai-mcp-manager' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Run these when an AI client cannot connect. They probe this site the way a client does and point at what to fix.', 'acrossai-mcp-manager' ); ?>
			</p>

			<p>
				<button type="button" class="button button-primary" id="acrossai-mcp-troubleshoot-run">
					<?php esc_html_e( 'Run checks', 'acrossai-mcp-manager' ); ?>
				</button>
			</p>

			<div id="<?php echo esc_attr( self::ROOT_ID ); ?>"></div>

			<noscript>
				<p class="description">
					<?php esc_html_e( 'These checks need JavaScript — they run on demand rather than on page load, because each one makes an outbound HTTP request.', 'acrossai-mcp-manager' ); ?>
				</p>
			</noscript>
		</div>
		<?php
	}
}
