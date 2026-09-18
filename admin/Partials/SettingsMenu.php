<?php
/**
 * MCP tab on the shared AcrossAI Settings page.
 *
 * Registers the "MCP" tab via the `acrossai_settings_tabs` filter provided
 * by acrossai-co/main-menu, and wires the tab's sections + fields onto the
 * per-tab page slug returned by the vendor's SettingsPageRenderer, accessed
 * via \AcrossAI_Main_Menu\SettingsPage::get_settings_renderer(). Vendor 0.0.13
 * scopes each tab's form to that same per-tab slug (see TabbedPageRenderer::render),
 * so register_setting() calls use the tab-scoped slug as the option_group —
 * NOT the shared parent page slug used prior to 0.0.13. See Feature 018 for
 * background on the vendor bump and the FR-011 grep audit that prevents any
 * literal reintroduction of the shared-group registration shape.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials
 * @since      0.1.0
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Abilities\Discover;

use AcrossAI_MCP_Manager\Public\Partials\FrontendAuth;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The MCP tab on the shared AcrossAI Settings page.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials
 * @since      0.1.0
 */
class SettingsMenu {

	/**
	 * Singleton instance.
	 *
	 * @since 0.1.0
	 * @var SettingsMenu|null
	 */
	protected static $instance = null;

	/**
	 * Returns the singleton instance of this class.
	 *
	 * @since 0.1.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Tab slug for this plugin's sections on the shared host Settings page.
	 *
	 * Kept in sync with \AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs::SETTINGS_TAB.
	 * Lowercase a-z0-9-_ only — sanitize_key() compliant.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const TAB_SLUG = 'mcp';

	/**
	 * Option holding the default page size for `mcp-adapter/discover-abilities`.
	 *
	 * Swept on uninstall by the `acrossai_mcp_%` prefix rule in uninstall.php —
	 * no explicit delete_option() needed.
	 */
	public const DISCOVER_PER_PAGE_OPTION = 'acrossai_mcp_discover_per_page';

	/**
	 * Registers the "MCP" tab on the shared AcrossAI Settings page.
	 *
	 * Hooked to the `acrossai_settings_tabs` filter provided by
	 * acrossai-co/main-menu.
	 *
	 * @since 0.1.0
	 * @param array $tabs Tabs collected from previous filter calls.
	 * @return array
	 */
	public function register_tab( $tabs ): array {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}

		$tabs[] = array(
			'slug'     => self::TAB_SLUG,
			'label'    => __( 'MCP', 'acrossai-mcp-manager' ),
			'priority' => 20,
		);

		return $tabs;
	}

	/**
	 * Registers settings sections and fields via the WordPress Settings API.
	 *
	 * Hooked to admin_init. Both `option_group` and `page` arguments are set to
	 * the per-tab page slug derived from `SettingsPageRenderer::tab_page_slug()`,
	 * matching the tab-scoped `option_page` value that vendor 0.0.13's
	 * TabbedPageRenderer::render() emits — the options.php whitelist walks only
	 * this tab's registrations on save.
	 *
	 * The vendor package acrossai-co/main-menu is a hard-require in composer.json,
	 * so the `SettingsPage` class is guaranteed present at admin_init and no
	 * class_exists() guard is needed. If that dependency is ever demoted to an
	 * optional integration, revisit DEC-VENDOR-SETTINGS-TAB-INTEGRATION and add
	 * a guard here.
	 *
	 * The renderer is obtained via SettingsPage::get_settings_renderer() (added
	 * in vendor 0.0.13). A fall-through slug matching the vendor's own format is
	 * kept for the edge case where get_settings_renderer() has not yet populated
	 * (e.g. if another consumer disabled the SettingsPage bootstrap for this
	 * request); this keeps admin_init non-fatal.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register_settings(): void {
		$renderer  = \AcrossAI_Main_Menu\SettingsPage::get_settings_renderer();
		$page_slug = $renderer
			? $renderer->tab_page_slug( self::TAB_SLUG )
			: \AcrossAI_Main_Menu\SettingsPage::SETTINGS_SLUG . '-' . sanitize_key( self::TAB_SLUG );

		// Vendor 0.0.13 posts each tab's form with `option_page = <tab-scoped
		// slug>` (see TabbedPageRenderer::render), so register against the same
		// tab-scoped slug — not the shared parent page slug used pre-0.0.13 —
		// or options.php will reject the save with "not in the allowed options
		// list".
		$option_group = $page_slug;

		// npm / CLI login toggle.
		register_setting(
			$option_group,
			'acrossai_mcp_npm_login_enabled',
			array(
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
			)
		);

		// F089 — discover-abilities page size. Bounded by the same 1..200 range
		// the ability's own input_schema advertises, so the admin cannot set a
		// default the schema would then reject.
		register_setting(
			$option_group,
			self::DISCOVER_PER_PAGE_OPTION,
			array(
				'sanitize_callback' => array( $this, 'sanitize_discover_per_page' ),
				'default'           => Discover::PER_PAGE_DEFAULT,
			)
		);

		// Uninstall opt-in flag.
		register_setting(
			$option_group,
			'acrossai_mcp_uninstall_delete_data',
			array(
				'sanitize_callback' => array( $this, 'sanitize_uninstall_flag' ),
				'default'           => 0,
			)
		);

		// Section: npm / CLI Settings.
		add_settings_section(
			'acrossai_mcp_npm_section',
			__( 'npm / CLI Settings', 'acrossai-mcp-manager' ),
			array( $this, 'render_npm_section_description' ),
			$page_slug
		);

		add_settings_field(
			'acrossai_mcp_npm_login_enabled',
			__( 'Enable CLI Connections', 'acrossai-mcp-manager' ),
			array( $this, 'render_npm_login_field' ),
			$page_slug,
			'acrossai_mcp_npm_section'
		);

		// Section: Ability Discovery.
		add_settings_section(
			'acrossai_mcp_discovery_section',
			__( 'Ability Discovery', 'acrossai-mcp-manager' ),
			array( $this, 'render_discovery_section_description' ),
			$page_slug
		);

		add_settings_field(
			self::DISCOVER_PER_PAGE_OPTION,
			__( 'Abilities per page', 'acrossai-mcp-manager' ),
			array( $this, 'render_discover_per_page_field' ),
			$page_slug,
			'acrossai_mcp_discovery_section'
		);

		// Section: Uninstall Settings.
		add_settings_section(
			'acrossai_mcp_uninstall_section',
			__( 'Uninstall Settings', 'acrossai-mcp-manager' ),
			'__return_false',
			$page_slug
		);

		add_settings_field(
			'acrossai_mcp_uninstall_delete_data',
			__( 'Delete all data on uninstall', 'acrossai-mcp-manager' ),
			array( $this, 'render_uninstall_field' ),
			$page_slug,
			'acrossai_mcp_uninstall_section'
		);
	}

	/**
	 * Renders the description + warning banner for the npm / CLI section.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_npm_section_description(): void {
		$auth_url = FrontendAuth::get_base_url();

		printf(
			'<p class="description">%s</p><div class="notice notice-warning inline" style="margin:8px 0 0;"><p><strong>%s</strong> ' .
			/* translators: %s: the frontend authorization URL */
			wp_kses_post( __( 'The frontend authorization page at <code>%s</code> contains time-sensitive auth codes and nonces. If your hosting, CDN, or caching plugin caches this URL, authentication will silently fail. Exclude this path from all page-caching rules.', 'acrossai-mcp-manager' ) ) .
			'</p></div>',
			esc_html__( 'Control whether the npm / npx CLI connection flow is available on server edit pages.', 'acrossai-mcp-manager' ),
			esc_html__( 'Do not cache the CLI auth URL.', 'acrossai-mcp-manager' ),
			esc_url( $auth_url )
		);
	}

	/**
	 * Renders the npm / CLI login enable checkbox.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_npm_login_field(): void {
		$checked = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
		printf(
			'<label><input type="checkbox" id="acrossai_mcp_npm_login_enabled" name="acrossai_mcp_npm_login_enabled" value="1" %s /> %s</label><p class="description">%s</p>',
			checked( $checked, true, false ),
			esc_html__( 'Allow CLI connections via npm / npx', 'acrossai-mcp-manager' ),
			esc_html__( 'When enabled, the npm tab on each server\'s edit page will display the npx CLI command and let users connect the AcrossAI MCP Manager CLI tool to this site. Users still sign in to WordPress in the browser, then approve access so the CLI can receive a WordPress Application Password without any manual JSON editing. Keep this disabled if you do not want to expose CLI-based connections.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Sanitizes the uninstall delete data checkbox value.
	 *
	 * Returns 1 when the checkbox is checked, 0 when unchecked or absent.
	 * Browsers do not send unchecked checkboxes, so an absent value means 0.
	 *
	 * @since 0.1.0
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_uninstall_flag( $value ): int {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Explains what the page size buys, in the terms that actually matter to
	 * the operator — context tokens spent on the agent's first orientation call.
	 *
	 * @since 0.3.4
	 * @return void
	 */
	public function render_discovery_section_description(): void {
		printf(
			'<p>%s</p>',
			esc_html__(
				'How many abilities mcp-adapter/discover-abilities returns per call. This is the first tool most AI agents call, so its response is paid for out of the model\'s context window on every new conversation.',
				'acrossai-mcp-manager'
			)
		);
	}

	/**
	 * Number input for the discover-abilities page size.
	 *
	 * @since 0.3.4
	 * @return void
	 */
	public function render_discover_per_page_field(): void {
		$value = (int) get_option( self::DISCOVER_PER_PAGE_OPTION, Discover::PER_PAGE_DEFAULT );

		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$s" min="1" max="%3$s" step="1" class="small-text" />',
			esc_attr( self::DISCOVER_PER_PAGE_OPTION ),
			esc_attr( (string) $value ),
			esc_attr( (string) Discover::PER_PAGE_MAXIMUM )
		);

		printf(
			' <span class="description">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: default page size, 2: maximum page size */
					__( 'Default %1$d, maximum %2$d.', 'acrossai-mcp-manager' ),
					Discover::PER_PAGE_DEFAULT,
					Discover::PER_PAGE_MAXIMUM
				)
			)
		);

		// Measured on a real install: a name/label/description/category entry
		// averages ~320 bytes, so ~80 tokens. Showing the cost of the CURRENT
		// setting beats asking an operator to guess at an abstract number.
		$estimated_tokens = (int) round( $value * 80 );

		// Deliberately NOT showing "this site exposes N abilities". Most
		// providers register their abilities only during an MCP/REST request,
		// so a count taken here in wp-admin reads 6 on a site whose MCP client
		// sees 439 — a confidently wrong number is worse than none.
		$note = sprintf(
			/* translators: 1: page size, 2: estimated tokens */
			__( 'About %1$d abilities ≈ %2$s tokens per call, at roughly 80 tokens each. Agents should narrow with search, category or namespace rather than paging through everything.', 'acrossai-mcp-manager' ),
			$value,
			number_format_i18n( $estimated_tokens )
		);

		printf( '<p class="description">%s</p>', esc_html( $note ) );
	}

	/**
	 * Clamp the submitted page size into the range the ability's input_schema
	 * advertises. An out-of-range default would be rejected by core at call
	 * time, which the operator would only discover through a failing agent.
	 *
	 * @since 0.3.4
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_discover_per_page( $value ): int {
		$per_page = (int) $value;

		if ( $per_page < 1 ) {
			return Discover::PER_PAGE_DEFAULT;
		}

		return min( Discover::PER_PAGE_MAXIMUM, $per_page );
	}

	/**
	 * Renders the uninstall delete data checkbox field.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_uninstall_field(): void {
		$checked = (bool) get_option( 'acrossai_mcp_uninstall_delete_data', 0 );
		printf(
			'<label><input type="checkbox" id="acrossai_mcp_uninstall_delete_data" name="acrossai_mcp_uninstall_delete_data" value="1" %s /> %s</label><p class="description"><span style="color: #d63638;">%s</span></p>',
			checked( $checked, true, false ),
			esc_html__( 'Delete all data on uninstall', 'acrossai-mcp-manager' ),
			esc_html__( '⚠ Warning: When checked, uninstalling this plugin will permanently delete all custom database tables and plugin options. This cannot be undone.', 'acrossai-mcp-manager' )
		);
	}
}
