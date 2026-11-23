<?php
/**
 * Quick Connect onboarding wizard — page surface.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/Admin/Partials/QuickConnect
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Admin\Partials\QuickConnect;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Renders and gates the Quick Connect wizard.
 *
 * The wizard is not a separate admin page. It hijacks the existing Abilities
 * Manager page whenever `?quick-connect=1` is present, which keeps the
 * capability surface unchanged and avoids registering a second page for one
 * onboarding flow (DEC-ADMIN-UI-NOT-MODULE).
 *
 * Every gate in this class routes through {@see self::is_quick_connect_request()}
 * rather than the WordPress hook suffix. Hook suffixes on the shared `acrossai`
 * parent menu derive from `sanitize_title( parent_menu_title )` and are fragile
 * (DEC-MENU-HOOK-SUFFIX, DEC-MENU-HOOK-SUFFIX-SUBMENU-DERIVATION, and the
 * BUG-LIBRARY-HOOK-SUFFIX regression).
 *
 * Asset enqueueing lives here rather than in Admin\Main, following
 * PATTERN-PARTIALS-SELF-ENQUEUE and the File_Manager_Settings_Menu precedent —
 * it keeps a page-specific bundle out of the shared enqueue method.
 *
 * @since 0.0.34
 */
class QuickConnectPage {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.34
	 * @var   self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- plugin-wide singleton convention.

	/**
	 * Query argument that activates the wizard.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const QUERY_ARG = 'quick-connect';

	/**
	 * React mount element id.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const ROOT_ID = 'acrossai-quick-connect-root';

	/**
	 * Body class applied for the full-screen takeover.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const BODY_CLASS = 'acrossai-quick-connect-fullpage';

	/**
	 * Script and style handle.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const HANDLE = 'acrossai-quick-connect';

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.0.34
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Private constructor — use instance().
	 *
	 * @since 0.0.34
	 */
	private function __construct() {}

	/**
	 * Whether the current request is asking for the wizard.
	 *
	 * The single gate used by every entry point in this class. Yoda strict
	 * comparison against a sanitized value, with no intermediate `strpos`
	 * variables (PATTERN-ENQUEUE-PAGE-GUARD).
	 *
	 * No nonce is verified: this flag only selects which view renders, it
	 * mutates nothing.
	 *
	 * @since  0.0.34
	 * @return bool True when `?quick-connect=1` is present.
	 */
	public function is_quick_connect_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector; no state is changed.
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector; no state is changed.
		$flag = sanitize_key( wp_unslash( $_GET[ self::QUERY_ARG ] ) );

		return '1' === $flag;
	}

	/**
	 * Render the wizard mount point.
	 *
	 * Emits only the container: the entire interface is client-rendered. The
	 * capability check is repeated here rather than relying on the parent page's
	 * registration, so the gate travels with the branch if it is ever reached
	 * from another call path (defence in depth).
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'acrossai-abilities-manager' ) );
		}

		printf(
			'<div class="wrap acrossai-quick-connect-wrap"><div id="%1$s"></div><noscript><p>%2$s</p></noscript></div>',
			esc_attr( self::ROOT_ID ),
			esc_html__( 'The Quick Connect wizard requires JavaScript. Enable JavaScript in your browser to use it.', 'acrossai-abilities-manager' )
		);
	}

	/**
	 * Add the full-screen takeover body class.
	 *
	 * Hooked to `admin_body_class`.
	 *
	 * @since  0.0.34
	 * @param  string $classes Space-separated body classes.
	 * @return string Possibly-extended class list.
	 */
	public function add_body_class( $classes ): string {
		if ( ! $this->is_quick_connect_request() ) {
			return (string) $classes;
		}

		return trim( $classes . ' ' . self::BODY_CLASS );
	}

	/**
	 * Suppress unrelated admin notices while the wizard is on screen.
	 *
	 * Hooked to `in_admin_header` at priority 1000. The CSS takeover hides
	 * WordPress chrome but notices render inside our own content column, so they
	 * must be removed rather than hidden.
	 *
	 * Deliberately request-scoped: without the guard this would strip notices
	 * site-wide, including security-critical ones from core and other plugins.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public function suppress_admin_notices(): void {
		if ( ! $this->is_quick_connect_request() ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Enqueue the wizard bundle.
	 *
	 * Hooked to `admin_enqueue_scripts`. Gated on the request flag, never the
	 * hook suffix, so the ~200 KB bundle cannot leak onto the abilities table,
	 * the settings page, or the Integrations page (spec FR-042 / SC-010).
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_quick_connect_request() ) {
			return;
		}

		$build_url  = defined( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL' )
			? \ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL . 'build/'
			: plugins_url( 'build/', dirname( __DIR__, 4 ) . '/acrossai-mcp-manager.php' );
		$build_path = defined( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH' )
			? \ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH . 'build/'
			: dirname( __DIR__, 4 ) . '/build/';

		$asset_file = $build_path . 'js/abilities/quick-connect.asset.php';

		// Never include an asset manifest unguarded: a missing or unbuilt bundle
		// would fatal the whole admin (BUG-UNCONDITIONAL-ASSET-INCLUDE).
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset   = require $asset_file;
		$version = (string) ( $asset['version'] ?? '0.0.0' );

		wp_enqueue_script(
			self::HANDLE,
			$build_url . 'js/abilities/quick-connect.js',
			(array) ( $asset['dependencies'] ?? array() ),
			$version,
			true
		);

		wp_set_script_translations( self::HANDLE, 'acrossai-abilities-manager' );

		wp_localize_script( self::HANDLE, 'acrossaiQuickConnect', $this->bootstrap_data() );

		if ( file_exists( $build_path . 'css/abilities/quick-connect.css' ) ) {
			wp_enqueue_style(
				self::HANDLE,
				$build_url . 'css/abilities/quick-connect.css',
				array(),
				$version
			);
		}
	}

	/**
	 * Data handed to the React bundle.
	 *
	 * Data-minimal by design: URLs the client cannot safely build, plus the REST
	 * nonce. No secrets, no PII.
	 *
	 * @since  0.0.34
	 * @return array<string, string> Bootstrap payload.
	 */
	private function bootstrap_data(): array {
		$assets_url = defined( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL' )
			? \ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL . 'assets/abilities/quick-connect/'
			: plugins_url( 'assets/abilities/quick-connect/', dirname( __DIR__, 4 ) . '/acrossai-mcp-manager.php' );

		return array(
			'restUrl'               => esc_url_raw( rest_url( 'acrossai/v1/quick-connect' ) ),
			'restNonce'             => wp_create_nonce( 'wp_rest' ),
			'adminUrl'              => esc_url_raw( admin_url( 'admin.php?page=acrossai-abilities-manager' ) ),
			'pluginInstallUrl'      => esc_url_raw( admin_url( 'plugin-install.php' ) ),
			'logoUrl'               => esc_url_raw( $assets_url . 'acrossai-logo.svg' ),
			'iconUrl'               => esc_url_raw( $assets_url . 'icon.svg' ),
			'mcpAdapterRepoUrl'     => 'https://github.com/WordPress/mcp-adapter',

			/*
			 * GitHub's permanent "latest release" alias, so this URL does not go
			 * stale as the adapter releases.
			 *
			 * The wizard sends the operator to this page rather than linking the
			 * asset directly. A direct asset link starts a download on click with
			 * nothing on screen saying what arrived; the release page keeps the
			 * version and the assets list visible, so choosing `mcp-adapter.zip`
			 * over the source archive is a decision the operator can see
			 * themselves making. That choice matters: the source zipball unpacks
			 * to `WordPress-mcp-adapter-<sha>`, installing under the wrong folder
			 * name and silently breaking detection and updates.
			 */
			'mcpAdapterReleasesUrl' => 'https://github.com/WordPress/mcp-adapter/releases/latest',
			'pluginUploadUrl'       => esc_url_raw( admin_url( 'plugin-install.php?tab=upload' ) ),
			'pluginsListUrl'        => esc_url_raw( admin_url( 'plugins.php' ) ),
		);
	}
}
