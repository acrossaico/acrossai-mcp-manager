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

use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

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

		// F096 — activity logging. TWO independent toggles: ability logging is
		// far noisier than request logging (one tools/call fans out into several
		// abilities, and non-MCP callers add rows with no MCP traffic at all),
		// so an operator must be able to run one without the other. Both off on
		// install — logging every request is a real cost and the WordPress AI
		// plugin's own equivalent sits behind an experiment gate for the same
		// reason. Laid out so Feature 097's change-recording toggle slots in as
		// a third row without restructuring.
		// The declared default MUST match the constant every reader passes to
		// get_option(). They are two different mechanisms and nothing makes
		// them agree, but unchecking the box depends on them agreeing:
		//
		// An unchecked checkbox posts no field, so `options.php` calls
		// update_option() with null, which sanitizes to false. update_option()
		// then compares that against `get_option( $option )` called WITHOUT an
		// explicit default — which resolves to whatever is declared HERE. If
		// this said false while readers defaulted to true, the comparison would
		// find false === false, write nothing, leave the option absent, and the
		// readers' true fallback would keep logging ON. The operator unticks
		// the box, the page reports success, and nothing changes.
		//
		// Verified directly: update_option( $option, false ) against an absent
		// option returns false and stores nothing.
		register_setting(
			$option_group,
			ActivityLogConstants::OPTION_REQUEST_LOGGING,
			array(
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => ActivityLogConstants::DEFAULT_REQUEST_LOGGING,
			)
		);

		register_setting(
			$option_group,
			ActivityLogConstants::OPTION_ABILITY_LOGGING,
			array(
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => ActivityLogConstants::DEFAULT_ABILITY_LOGGING,
			)
		);

		register_setting(
			$option_group,
			ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS,
			array(
				'sanitize_callback' => 'absint',
				'default'           => ActivityLogConstants::DEFAULT_RETENTION_REQUEST_DAYS,
			)
		);

		register_setting(
			$option_group,
			ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS,
			array(
				'sanitize_callback' => 'absint',
				'default'           => ActivityLogConstants::DEFAULT_RETENTION_ABILITY_DAYS,
			)
		);

		register_setting(
			$option_group,
			ActivityLogConstants::OPTION_MAX_EVENTS,
			array(
				'sanitize_callback' => 'absint',
				'default'           => ActivityLogConstants::DEFAULT_MAX_EVENTS,
			)
		);

		add_settings_section(
			'acrossai_mcp_activity_section',
			__( 'MCP Activity Log', 'acrossai-mcp-manager' ),
			array( $this, 'render_activity_section_description' ),
			$page_slug
		);

		add_settings_field(
			ActivityLogConstants::OPTION_REQUEST_LOGGING,
			__( 'Log MCP requests', 'acrossai-mcp-manager' ),
			array( $this, 'render_activity_request_field' ),
			$page_slug,
			'acrossai_mcp_activity_section'
		);

		add_settings_field(
			ActivityLogConstants::OPTION_ABILITY_LOGGING,
			__( 'Log ability runs', 'acrossai-mcp-manager' ),
			array( $this, 'render_activity_ability_field' ),
			$page_slug,
			'acrossai_mcp_activity_section'
		);

		add_settings_field(
			'acrossai_mcp_activity_retention',
			__( 'Keep events for', 'acrossai-mcp-manager' ),
			array( $this, 'render_activity_retention_field' ),
			$page_slug,
			'acrossai_mcp_activity_section'
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

	/** Describe the activity-log section. */
	public function render_activity_section_description(): void {
		printf(
			'<p>%s</p>',
			esc_html__(
				'Records what AI clients asked your MCP servers to do. On by default, and bounded by default: requests are kept for 30 days, ability runs for 7, and the log is capped at 50,000 events. A daily job applies all three. Switch either half off if you would rather it recorded nothing.',
				'acrossai-mcp-manager'
			)
		);
	}

	/** Request-logging toggle. */
	public function render_activity_request_field(): void {
		$enabled = (bool) get_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, ActivityLogConstants::DEFAULT_REQUEST_LOGGING );

		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label><p class="description">%4$s</p>',
			esc_attr( ActivityLogConstants::OPTION_REQUEST_LOGGING ),
			checked( $enabled, true, false ),
			esc_html__( 'Record one row per MCP request', 'acrossai-mcp-manager' ),
			esc_html__( 'What was asked, by which client, how it authenticated, whether it worked and how long it took.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Ability-logging toggle.
	 *
	 * The description states that arguments are recorded. Redaction matches
	 * sensitive KEY NAMES (token, secret, api_key and 21 more); it cannot cover
	 * a secret pasted into a free-text value. An operator deserves to know that
	 * before switching this on, rather than discovering it in a support thread.
	 */
	public function render_activity_ability_field(): void {
		$enabled = (bool) get_option( ActivityLogConstants::OPTION_ABILITY_LOGGING, ActivityLogConstants::DEFAULT_ABILITY_LOGGING );

		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label><p class="description">%4$s</p>',
			esc_attr( ActivityLogConstants::OPTION_ABILITY_LOGGING ),
			checked( $enabled, true, false ),
			esc_html__( 'Record one row per ability run', 'acrossai-mcp-manager' ),
			esc_html__(
				'Much noisier: one tool call can run several abilities, and REST, WP-CLI and admin callers are recorded too. Ability arguments are recorded — sensitive values are redacted by key name, so a secret inside a free-text field is stored as sent.',
				'acrossai-mcp-manager'
			)
		);
	}

	/** Retention and the retained-event ceiling. */
	public function render_activity_retention_field(): void {
		printf(
			'<p><label>%1$s <input type="number" min="0" name="%2$s" value="%3$d" class="small-text" /></label></p>'
			. '<p><label>%4$s <input type="number" min="0" name="%5$s" value="%6$d" class="small-text" /></label></p>'
			. '<p><label>%7$s <input type="number" min="0" name="%8$s" value="%9$d" class="small-text" /></label></p>'
			. '<p class="description">%10$s</p>',
			esc_html__( 'Requests, in days', 'acrossai-mcp-manager' ),
			esc_attr( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS ),
			(int) get_option( ActivityLogConstants::OPTION_RETENTION_REQUEST_DAYS, ActivityLogConstants::DEFAULT_RETENTION_REQUEST_DAYS ),
			esc_html__( 'Ability runs, in days', 'acrossai-mcp-manager' ),
			esc_attr( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS ),
			(int) get_option( ActivityLogConstants::OPTION_RETENTION_ABILITY_DAYS, ActivityLogConstants::DEFAULT_RETENTION_ABILITY_DAYS ),
			esc_html__( 'Maximum events kept, both halves together', 'acrossai-mcp-manager' ),
			esc_attr( ActivityLogConstants::OPTION_MAX_EVENTS ),
			(int) get_option( ActivityLogConstants::OPTION_MAX_EVENTS, ActivityLogConstants::DEFAULT_MAX_EVENTS ),
			// The two retention fields are PER HALF and the ceiling is SHARED, so this
			// paragraph has to switch scope mid-way. The previous wording said "the
			// maximum is a hard ceiling" directly after a sentence about each half
			// ageing out separately, which reads as a third per-half number — see the
			// 2026-10-22 clarification. Say "shared", and say what it costs: heavy
			// ability traffic shortening the request history is the surprising part.
			esc_html__( 'The two day counts are separate, so noisy ability rows cannot force their cutoff onto the quieter request history. The maximum is different: it is one hard ceiling shared by both halves, and once the log passes it the oldest events are shed first whatever their kind — so heavy ability traffic can shorten your request history too. 0 means no limit.', 'acrossai-mcp-manager' )
		);
	}
}
