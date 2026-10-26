<?php
/**
 * Feature 096 — MCP Activity admin page.
 *
 * Registers a submenu under the shared AcrossAI parent and mounts the React
 * DataViews app. Deliberately NOT under `tools.php`: the WordPress AI plugin
 * puts its request log there because it has no menu of its own, and we do.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Abilities\AbilityActivityRecorder;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder as ActivityLogRecorder;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;
use AcrossAI_Main_Menu\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page for the activity log. Hook wiring lives in Main.php per A1.
 */
final class ActivityLogPage {

	/** `?page=` slug for this screen. */
	public const PAGE_SLUG = 'acrossai_mcp_activity';

	/** Script handle and localized object name stem. */
	private const HANDLE = 'acrossai-mcp-activity-log';

	/** DOM id the React app mounts into. */
	private const ROOT_ID = 'acrossai-mcp-activity-root';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/** Get the singleton instance. */
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
	 * Position 8 keeps it after the server and connector screens: an operator
	 * reaches for the log after configuring something, not before.
	 */
	public function register_menu(): void {
		add_submenu_page(
			SettingsPage::PARENT_SLUG,
			__( 'MCP Activity', 'acrossai-mcp-manager' ),
			__( 'Activity', 'acrossai-mcp-manager' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			8
		);
	}

	/**
	 * Enqueue the bundle on this screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( false === strpos( (string) $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		$this->enqueue_bundle(
			array(
				'serverId'   => 0,
				'showServer' => true,
			)
		);
	}

	/**
	 * Enqueue the shared bundle with a bootstrap payload.
	 *
	 * Public so the per-server Logs tab can mount the same app with a server
	 * filter — one component, two mount points.
	 *
	 * @param array<string, mixed> $context Mount-specific bootstrap values.
	 */
	public function enqueue_bundle( array $context = array() ): void {
		// Boot constants are defined by Includes\Main::define_constants() before any
		// admin_enqueue_scripts hook can fire. If they are absent the plugin is not
		// booted, and guessing paths from __DIR__ is how this screen shipped broken
		// in 0.4.4 — ACROSSAI_MCP_MANAGER_PLUGIN_DIR never existed (the constant is
		// ..._PLUGIN_PATH), so the guess silently won every time and resolved to
		// admin/Partials/ rather than the plugin root.
		if ( ! defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_PATH' ) || ! defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_URL' ) ) {
			return;
		}

		$base     = ACROSSAI_MCP_MANAGER_PLUGIN_URL;
		$dir      = ACROSSAI_MCP_MANAGER_PLUGIN_PATH;
		$manifest = $dir . 'build/js/activity-log.asset.php';

		// No hand-maintained dependency fallback. webpack decides this bundle's
		// externals at build time, so any array written here is wrong as soon as the
		// bundle changes — and the one that used to live here named 'wp-dataviews',
		// which WordPress does not register on any version, so wp_enqueue_script()
		// emitted no tag at all. A missing manifest means an incomplete install;
		// bail rather than enqueue a React app with guessed dependencies. Mirrors
		// Admin\Main::read_asset_manifest().
		if ( ! file_exists( $manifest ) ) {
			return;
		}

		$asset = include $manifest;

		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) || ! is_array( $asset['dependencies'] ) ) {
			return;
		}

		$deps    = $asset['dependencies'];
		$version = (string) $asset['version'];

		wp_enqueue_script( self::HANDLE, $base . 'build/js/activity-log.js', $deps, $version, true );

		$style = $dir . 'build/js/activity-log.css';

		if ( file_exists( $style ) ) {
			wp_enqueue_style( self::HANDLE, $base . 'build/js/activity-log.css', array( 'wp-components' ), $version );
		}

		wp_set_script_translations( self::HANDLE, 'acrossai-mcp-manager' );

		$payload = array_merge(
			array(
				'root'             => esc_url_raw( rest_url( 'acrossai-mcp-manager/v1' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'rootId'           => self::ROOT_ID,
				'requestLogging'   => (bool) get_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, ActivityLogConstants::DEFAULT_REQUEST_LOGGING ),
				'abilityLogging'   => (bool) get_option( ActivityLogConstants::OPTION_ABILITY_LOGGING, ActivityLogConstants::DEFAULT_ABILITY_LOGGING ),
				'settingsUrl'      => esc_url_raw( admin_url( 'admin.php?page=acrossai_settings&tab=mcp' ) ),
				'bytesPerToken'    => (int) apply_filters(
					ActivityLogConstants::FILTER_BYTES_PER_TOKEN,
					ActivityLogConstants::DEFAULT_BYTES_PER_TOKEN
				),
				// Lets the screen state the WP 6.9/7.0 ability-coverage limit
				// rather than implying complete capture (T064).
				'richAbilityHooks' => AbilityActivityRecorder::has_rich_hooks(),
				// Lets the screen say "recording is failing" rather than
				// "nothing happened" — opposite facts that look identical.
				'writeFailures'    => ActivityLogRecorder::failure_count(),
				'serverId'         => 0,
				'showServer'       => true,
			),
			$context
		);

		// wp_json_encode, never esc_html/esc_attr: B36 records that both are
		// HTML-context escapers and neither makes a value safe inside a JS
		// string literal. wp_localize_script encodes correctly for this context.
		wp_localize_script( self::HANDLE, 'acrossaiMcpActivity', $payload );
	}

	/** Render the mount point. */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the MCP activity log.', 'acrossai-mcp-manager' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MCP Activity', 'acrossai-mcp-manager' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Every request your MCP servers answered, and what ran behind it.', 'acrossai-mcp-manager' ); ?>
			</p>
			<div id="<?php echo esc_attr( self::ROOT_ID ); ?>"></div>
		</div>
		<?php
	}
}
