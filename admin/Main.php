<?php
/**
 * Admin area entry point — asset enqueue with plugin-page guard.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin
 */

namespace AcrossAI_MCP_Manager\Admin;

use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;
use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;

defined( 'ABSPATH' ) || exit;

/**
 * Loads backend.js / backend.css on plugin admin pages only (US5).
 *
 * Per FR-017 / FR-018 / FR-019 + research.md R5:
 *   - get_current_screen() whitelist of three plugin screen IDs
 *   - file_exists() guard around the *.asset.php include
 *   - version + dependencies sourced from build/{js,css}/backend.asset.php
 *     — no hardcoded version or dependency array
 *
 * Constitution: singleton + private __construct + zero add_action/add_filter.
 * Hooks wired by Includes\Main::define_admin_hooks().
 */
class Main {

	/** @var Main|null */
	protected static $_instance = null;

	/** @var string */
	private $plugin_name;

	/** @var string */
	private $version;

	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {
		$this->plugin_name = ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG;
		$this->version     = ACROSSAI_MCP_MANAGER_VERSION;
		// Asset manifest reads are deferred to enqueue_*() — see notes there
		// for the file_exists() guard (FR-019) and the screen-ID guard (FR-017).
	}

	private function is_plugin_admin_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		return in_array( $screen->id, AdminPageSlugs::plugin_screen_ids(), true );
	}

	/**
	 * Lazy-load an asset manifest. Returns null when the file is missing
	 * so callers can silently skip enqueue (FR-019).
	 *
	 * @return array{dependencies: string[], version: string}|null
	 */
	private function read_asset_manifest( string $relative_path ): ?array {
		$path = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . $relative_path;
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$asset = include $path;
		if ( ! is_array( $asset ) || ! isset( $asset['version'], $asset['dependencies'] ) ) {
			return null;
		}
		return $asset;
	}

	/**
	 * Enqueue backend.css on plugin admin pages only.
	 *
	 * Wired on `admin_enqueue_scripts` by Includes\Main::define_admin_hooks().
	 */
	public function enqueue_styles(): void {
		if ( ! $this->is_plugin_admin_screen() ) {
			return;
		}
		$asset = $this->read_asset_manifest( 'build/css/backend.asset.php' );
		if ( null === $asset ) {
			return;
		}
		wp_enqueue_style(
			$this->plugin_name,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/css/backend.css' ),
			$asset['dependencies'],
			$asset['version'],
			'all'
		);
	}

	/**
	 * Enqueue backend.js on plugin admin pages only.
	 *
	 * Wired on `admin_enqueue_scripts` by Includes\Main::define_admin_hooks().
	 */
	public function enqueue_scripts(): void {
		if ( ! $this->is_plugin_admin_screen() ) {
			return;
		}
		$asset = $this->read_asset_manifest( 'build/js/backend.asset.php' );
		if ( null === $asset ) {
			return;
		}
		wp_enqueue_script(
			$this->plugin_name,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/backend.js' ),
			$asset['dependencies'],
			$asset['version'],
			true // load in footer
		);

		// F015 — Access Control tab React app (vendor's <AccessControl> component).
		// Only enqueue on the per-server-edit page with tab=access-control so we
		// don't ship the React bundle on unrelated screens.
		$this->maybe_enqueue_access_control_app();

		// F017 — Abilities tab React app (@wordpress/dataviews).
		// Scoped to the Abilities tab only — same guard shape as F015.
		$this->maybe_enqueue_abilities_app();

		// F020 — Tools tab React app (hand-rolled shuttle picker).
		// Scoped to the Tools tab only — same guard shape as F015/F017.
		$this->maybe_enqueue_tools_app();

		// F037 Embeds — enqueue is self-registered by the tab class itself
		// (EmbedsTab::register() wires it on admin_enqueue_scripts). Nothing
		// to do here. See AbstractReactMountServerTab for the pattern.

		// F069 Quick Setup Wizard — enqueue only on ?quick-setup=1.
		// SC-007: the ~200KB React bundle MUST NOT load on the list-table
		// view or any per-server-edit tab.
		$this->maybe_enqueue_quick_setup_app();
	}

	/**
	 * F069 T018 — Enqueue the Quick Setup wizard React app.
	 *
	 * Gated on `?quick-setup=1` — bundle never loads on any other admin
	 * page (SC-007). Mirrors the F017 / F020 enqueue shape (asset.php
	 * manifest → wp_enqueue_script → wp_localize_script for bootstrap
	 * payload → optional CSS enqueue via file_exists guard).
	 *
	 * @since 0.2.11
	 * @return void
	 */
	private function maybe_enqueue_quick_setup_app(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		if ( empty( $_GET['quick-setup'] ) || '1' !== (string) $_GET['quick-setup'] ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$asset = $this->read_asset_manifest( 'build/js/quick-setup.asset.php' );
		if ( null === $asset ) {
			return;
		}

		wp_enqueue_script(
			'acrossai-mcp-manager-quick-setup',
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/quick-setup.js' ),
			$asset['dependencies'],
			$asset['version'],
			true // load in footer.
		);
		wp_set_script_translations( 'acrossai-mcp-manager-quick-setup', 'acrossai-mcp-manager' );

		$css_path = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/quick-setup.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'acrossai-mcp-manager-quick-setup',
				esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/quick-setup.css' ),
				array(),
				$asset['version']
			);
		}

		wp_localize_script(
			'acrossai-mcp-manager-quick-setup',
			'acrossaiMcpQuickSetup',
			array(
				'restUrl'          => esc_url_raw( rest_url( 'acrossai-mcp-manager/v1/quick-setup' ) ),
				'restNonce'        => wp_create_nonce( 'wp_rest' ),
				'adminUrl'         => esc_url_raw( admin_url( 'admin.php?page=acrossai_mcp_manager' ) ),
				// F069 Step 9 — landing page for activating AcrossAI Pro.
				// The wizard's Pro-activation gate links here so the user
				// ends up on the AcrossAI Add-ons page rather than the raw
				// Plugins list.
				'addonsUrl'        => esc_url_raw( admin_url( 'admin.php?page=acrossai-addons' ) ),
				// F074 Step 8 — after starting the Pro trial the operator's
				// next job is installing the plugin they were just emailed,
				// so the trial-started state swaps Continue for a link to
				// the Add Plugins screen (upload-zip lives behind it).
				// Localized rather than derived client-side so subdirectory
				// installs and custom admin URLs resolve correctly.
				'pluginInstallUrl' => esc_url_raw( admin_url( 'plugin-install.php' ) ),
				'siteUrl'          => esc_url_raw( untrailingslashit( home_url() ) ),
				'logoUrl'          => esc_url_raw( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'assets/quick-setup/acrossai-logo.svg' ),
				// F069 — square brand icon shown on the initial-hydrate
				// loading screen. Kept at assets/quick-setup/icon.svg (a
				// direct copy of .wordpress-org/icon.svg — that dotfile
				// directory is routinely blocked at the host / Apache level,
				// so pointing the browser there 404s on real installs).
				// When updating the icon, replace BOTH files so the WP.org
				// plugin listing and the wizard stay in sync.
				'iconUrl'          => esc_url_raw( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'assets/quick-setup/icon.svg' ),
				// Access Control wiring — MUST mirror the values passed to
				// the per-server-edit tab bootstrap (see the AC-tab enqueue
				// block above) so the wizard's Step 2 uses the same slug +
				// REST root the server tab does.
				'acPluginSlug'     => \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::TABLE_SLUG,
				'acNamespace'      => 'acrossai-mcp-manager',
				'restApiRoot'      => esc_url_raw( untrailingslashit( rest_url() ) ),
				// F074 — Freemius Checkout credentials for Step 8's Pro trial
				// CTA. All three values are PUBLIC identifiers per Freemius
				// conventions (safe to ship in a WP.org plugin — analogous
				// to Stripe pk_live_* keys). Consumed by
				// src/js/quick-setup/steps/Step8_ProPromo.jsx which calls
				// `new FS.Checkout({product_id, public_key}).open({plan_id, trial: 'free', …})`.
				'freemiusPro'      => array(
					'product_id' => '34763',
					'public_key' => 'pk_22d5131412bed600815c5b30ae044',
					'plan_id'    => '60904',
				),
				// F075 — local-dev TLS bypass affordance. When `enabled` is true,
				// Step 11 renders a warning callout above the client config code
				// block. The JSON string itself already contains
				// NODE_TLS_REJECT_UNAUTHORIZED because ConnectionMethodRegistry
				// calls get_config_snippet() which routes through
				// AbstractMCPClient::build_env() (same source-of-truth as the
				// per-server tab notice — copy MUST match MCPClientsBlock).
				'tlsBypass'        => array(
					'enabled'  => LocalEnvironment::needs_tls_bypass(),
					'message'  => __( 'Local dev detected — we added NODE_TLS_REJECT_UNAUTHORIZED: "0" to this snippet as an insecure convenience for local testing. On a local HTTPS site with a self-signed certificate this stops the proxy from rejecting the cert. On a plain-HTTP local site the flag does nothing but is harmless. Never use this setting against a live site — it disables all TLS verification.', 'acrossai-mcp-manager' ),
					'hint'     => __( 'If your MCP client still shows zero tools after copying this JSON, check the troubleshooting doc for other common local-dev fixes:', 'acrossai-mcp-manager' ),
					'linkText' => __( 'Automattic mcp-wordpress-remote troubleshooting.', 'acrossai-mcp-manager' ),
					'docUrl'   => LocalEnvironment::troubleshooting_doc_url(),
				),
			)
		);

		// F074 — Freemius Checkout script for Step 8's Pro trial modal.
		// Same enqueue pattern as the Freemius plugin's own Buy Button block
		// (wp-content/plugins/freemius/includes/class-freemius-button.php:78).
		// Gated on the same `?quick-setup=1` check as the wizard bundle
		// enqueue above, so it never loads on any other admin surface.
		// window.FS.Checkout becomes available before the wizard mounts
		// Step 8 — no dep chain needed on the wizard bundle.
		wp_enqueue_script(
			'acrossai-mcp-manager-freemius-checkout',
			'https://checkout.freemius.com/js/v1/',
			array(),
			'v1',
			true
		);
	}

	/**
	 * F069 — Full-page mode body class.
	 *
	 * Appends `acrossai-mcp-quick-setup-fullpage` to `<body>` when the wizard
	 * URL is active (`?quick-setup=1`). The CSS scoped under that class hides
	 * the WP admin sidebar + admin bar + footer so the wizard fills the entire
	 * viewport (WooCommerce setup-wizard pattern).
	 *
	 * @since 0.2.11
	 * @param string $classes Space-separated body class string.
	 * @return string Amended class string.
	 */
	public function full_page_body_class( $classes ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		if ( empty( $_GET['quick-setup'] ) || '1' !== (string) $_GET['quick-setup'] ) {
			return (string) $classes;
		}
		return trim( $classes . ' acrossai-mcp-quick-setup-fullpage' );
	}

	/**
	 * F069 — Suppress core admin notices while the wizard is active.
	 *
	 * Notices from other plugins (update prompts, promo banners) break the
	 * wizard's focused-attention layout. Fires on `in_admin_header` — after
	 * WordPress has set up its notice queue but before it's rendered.
	 *
	 * @since 0.2.11
	 * @return void
	 */
	public function suppress_admin_notices_on_quick_setup(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		if ( empty( $_GET['quick-setup'] ) || '1' !== (string) $_GET['quick-setup'] ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Enqueue the vendor AccessControl React app on the Access Control tab.
	 *
	 * @since 0.0.7
	 * @return void
	 */
	private function maybe_enqueue_access_control_app(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		$is_edit = isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) );
		$is_ac   = isset( $_GET['tab'] ) && 'access-control' === sanitize_key( wp_unslash( $_GET['tab'] ) );
		if ( ! $is_edit || ! $is_ac ) {
			return;
		}
		// phpcs:enable

		$asset = $this->read_asset_manifest( 'build/js/access-control.asset.php' );
		if ( null === $asset ) {
			return;
		}

		$handle = $this->plugin_name . '-access-control';
		wp_enqueue_script(
			$handle,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/access-control.js' ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Vendor CSS bundled alongside the JS entry (webpack emits alongside
		// the .js/.asset.php). Same handle so the style is deregistered when
		// the script is.
		wp_enqueue_style(
			$handle,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/access-control.css' ),
			array(),
			$asset['version']
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$server_id = isset( $_GET['server'] ) ? absint( wp_unslash( $_GET['server'] ) ) : 0;
		// phpcs:enable
		$server_slug = '';
		if ( $server_id > 0 ) {
			$rows = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query(
				array(
					'id'     => $server_id,
					'number' => 1,
				)
			);
			if ( ! empty( $rows ) ) {
				$server_slug = (string) $rows[0]->server_slug;
			}
		}

		wp_localize_script(
			$handle,
			'acrossaiMcpAccessControl',
			array(
				'pluginSlug'  => \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::TABLE_SLUG,
				'namespace'   => 'acrossai-mcp-manager',
				'resourceKey' => $server_slug,
				// The vendor's React component concatenates restApiRoot + '/wpb-ac/…'.
				// `rest_url()` returns with a trailing slash, which would produce
				// `/wp-json//wpb-ac/…` → 404. Strip the trailing slash here.
				'restApiRoot' => esc_url_raw( untrailingslashit( rest_url() ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Enqueue the F017 Abilities tab React app on the Abilities tab only.
	 *
	 * Mirrors the F015 `maybe_enqueue_access_control_app()` shape verbatim —
	 * `?action=edit` + `?tab=abilities` guard, silent bail on missing asset
	 * manifest (FR-019), localize the `acrossaiMcpAbilities` config.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private function maybe_enqueue_abilities_app(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		$is_edit      = isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) );
		$is_abilities = isset( $_GET['tab'] ) && 'abilities' === sanitize_key( wp_unslash( $_GET['tab'] ) );
		if ( ! $is_edit || ! $is_abilities ) {
			return;
		}
		// phpcs:enable

		$asset = $this->read_asset_manifest( 'build/js/abilities.asset.php' );
		if ( null === $asset ) {
			return;
		}

		$handle = $this->plugin_name . '-abilities';
		wp_enqueue_script(
			$handle,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/abilities.js' ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// SCSS is optional — emit a matching stylesheet only if webpack
		// produced `build/js/abilities.css` alongside the JS.
		$css_path = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/abilities.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				$handle,
				esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/abilities.css' ),
				array(),
				$asset['version']
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$server_id = isset( $_GET['server'] ) ? absint( wp_unslash( $_GET['server'] ) ) : 0;
		// phpcs:enable
		$server_slug = '';
		if ( $server_id > 0 ) {
			$rows = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query(
				array(
					'id'     => $server_id,
					'number' => 1,
				)
			);
			if ( ! empty( $rows ) ) {
				$server_slug = (string) $rows[0]->server_slug;
			}
		}

		wp_localize_script(
			$handle,
			'acrossaiMcpAbilities',
			array(
				'serverId'    => $server_id,
				'serverSlug'  => $server_slug,
				// B17 defense — `rest_url()` returns with a trailing slash;
				// the client concatenates `restApiRoot + '/acrossai-mcp-manager/v1/…'`
				// so we strip the slash here to avoid `//`-doubled routes → 404.
				'restApiRoot' => esc_url_raw( untrailingslashit( rest_url() ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'namespace'   => 'acrossai-mcp-manager/v1',
			)
		);
	}

	/**
	 * Enqueue the F020 Tools tab React shuttle picker on the Tools tab.
	 *
	 * Mirrors `maybe_enqueue_abilities_app()` verbatim — `?action=edit` +
	 * `?tab=tools` guard, silent bail on missing asset manifest (FR-019),
	 * localize `window.acrossaiMcpTools`.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private function maybe_enqueue_tools_app(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing check.
		$is_edit  = isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) );
		$is_tools = isset( $_GET['tab'] ) && 'tools' === sanitize_key( wp_unslash( $_GET['tab'] ) );
		if ( ! $is_edit || ! $is_tools ) {
			return;
		}
		// phpcs:enable

		$asset = $this->read_asset_manifest( 'build/js/tools.asset.php' );
		if ( null === $asset ) {
			return;
		}

		$handle = $this->plugin_name . '-tools';
		wp_enqueue_script(
			$handle,
			esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/tools.js' ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Optional CSS — auto-extracted if src/scss/tools.scss is imported.
		$css_path = \ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/tools.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				$handle,
				esc_url( \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'build/js/tools.css' ),
				array(),
				$asset['version']
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$server_id = isset( $_GET['server'] ) ? absint( wp_unslash( $_GET['server'] ) ) : 0;
		// phpcs:enable
		$server_slug = '';
		if ( $server_id > 0 ) {
			$rows = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query(
				array(
					'id'     => $server_id,
					'number' => 1,
				)
			);
			if ( ! empty( $rows ) ) {
				$server_slug = (string) $rows[0]->server_slug;
			}
		}

		wp_localize_script(
			$handle,
			'acrossaiMcpTools',
			array(
				'serverId'    => $server_id,
				'serverSlug'  => $server_slug,
				'restApiRoot' => esc_url_raw( untrailingslashit( rest_url() ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'namespace'   => 'acrossai-mcp-manager/v1',
			)
		);
	}
}
