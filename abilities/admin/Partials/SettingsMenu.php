<?php
/**
 * The settings submenu page for the plugin.
 *
 * Provides the settings submenu page for the plugin and registers
 * the plugin settings using the WordPress Settings API.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Admin/Partials
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Admin\Partials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The settings submenu page for the plugin.
 *
 * Defines the plugin settings submenu page and registers settings sections
 * and fields using the WordPress Settings API.
 *
 * @package    AcrossAI_Abilities_Manager
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
	 * Used everywhere a per-tab page slug is needed (host filter, section
	 * registration, field registration). Lowercase a-z0-9-_ only — sanitize_key()
	 * compliant.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const TAB_SLUG = 'abilities';

	/**
	 * Largest page size the abilities list can actually serve.
	 *
	 * Must match `per_page`'s `maximum` in `AcrossAI_Abilities_Read_Controller` — the REST argument
	 * is the real bound, and anything above it is clamped there with no error. This field used to
	 * offer 200 while REST capped at 100, so an operator setting 150 or 200 kept seeing 100 rows
	 * per page with nothing indicating the setting had been ignored (issue #185).
	 *
	 * The cap exists to bound response size: each row carries its full input and output JSON
	 * Schema, measured at roughly 1.4 KB, so 100 rows is already ~140 KB.
	 *
	 * @since 0.0.34
	 * @var   int
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Registers the "Abilities" tab on the shared AcrossAI Settings page.
	 *
	 * Hooked to the `acrossai_settings_tabs` filter provided by
	 * acrossai-co/main-menu v0.0.14+. The tab carries the plugin's scope so
	 * individual section titles can stay plain ("Display Settings",
	 * "Log Settings", "Uninstall Settings") rather than each repeating
	 * "Abilities".
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
			'label'    => __( 'Abilities', 'acrossai-abilities-manager' ),
			'priority' => 10,
		);

		return $tabs;
	}

	/**
	 * Registers settings sections and fields via the WordPress Settings API.
	 *
	 * Hooked to admin_init. Sections AND `option_group` both target the
	 * per-tab page slug derived from the host package's
	 * `SettingsPage::get_settings_renderer()->tab_page_slug()` helper
	 * (acrossai-co/main-menu v0.0.14+). Each tab having its own
	 * `option_group` is what prevents the cross-tab option-clobber bug that
	 * shared-`acrossai-settings` had in 0.0.12 (saving one tab silently
	 * wiped other tabs' options).
	 *
	 * Returns silently if the main-menu package has not booted this request
	 * (defensive guard — the bootstrap in acrossai-abilities-manager.php on
	 * plugins_loaded priority 0 makes this practically unreachable, but a
	 * null renderer here just means "skip Settings API registration this
	 * request" rather than fataling).
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register_settings(): void {
		$renderer = \AcrossAI_Main_Menu\SettingsPage::get_settings_renderer();
		if ( ! $renderer ) {
			return;
		}
		$page_slug = $renderer->tab_page_slug( self::TAB_SLUG );

		// Both persisted options are registered here so the option_group whitelist
		// is complete before either section renders (option_group is one-shot per
		// admin_init request).
		register_setting(
			$page_slug,
			'acrossai_abilities_per_page',
			array(
				'sanitize_callback' => array( $this, 'sanitize_per_page' ),
				'default'           => 20,
			)
		);
		register_setting(
			$page_slug,
			'acrossai_abilities_uninstall_delete_data',
			array(
				'sanitize_callback' => array( $this, 'sanitize_uninstall_flag' ),
				'default'           => 0,
			)
		);
		// Feature 088 — Ability-level suggested-plugins kill-switch.
		register_setting(
			$page_slug,
			'acrossai_disable_plugin_suggestions',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_disable_plugin_suggestions' ),
				'default'           => 0,
			)
		);

		// Feature 095 — Ability-level suggested-abilities kill-switch.
		register_setting(
			$page_slug,
			'acrossai_disable_ability_suggestions',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_disable_ability_suggestions' ),
				'default'           => 0,
			)
		);

		// Section 1 of 3 (rendered first): Display settings.
		//
		// The Uninstall Settings section is registered separately by
		// register_uninstall_settings() at admin_init priority 20 so it always
		// renders LAST — after Core_Settings_Menu's Upload Media Abilities
		// section (Feature 046). Sections render in the order they were added.
		add_settings_section(
			'acrossai_display_settings_section',
			__( 'Display Settings', 'acrossai-abilities-manager' ),
			'__return_false',
			$page_slug
		);

		add_settings_field(
			'acrossai_abilities_per_page',
			__( 'Abilities per page', 'acrossai-abilities-manager' ),
			array( $this, 'render_per_page_field' ),
			$page_slug,
			'acrossai_display_settings_section'
		);

		// Feature 088 — Plugin Suggestions section (adjacent to Display Settings).
		add_settings_section(
			'acrossai_plugin_suggestions_section',
			__( 'Plugin Suggestions', 'acrossai-abilities-manager' ),
			'__return_false',
			$page_slug
		);

		add_settings_field(
			'acrossai_disable_plugin_suggestions',
			__( 'Disable the Plugin suggestion', 'acrossai-abilities-manager' ),
			array( $this, 'render_disable_plugin_suggestions_field' ),
			$page_slug,
			'acrossai_plugin_suggestions_section'
		);

		// Feature 095 — Ability Suggestions section (rendered between
		// "Plugin Suggestions" and "Uninstall Settings"; declaration order
		// controls display order because the Uninstall section is registered
		// separately at admin_init priority 20).
		add_settings_section(
			'acrossai_ability_suggestions_settings_section',
			__( 'Ability Suggestions', 'acrossai-abilities-manager' ),
			'__return_false',
			$page_slug
		);

		add_settings_field(
			'acrossai_disable_ability_suggestions',
			__( 'Disable ability suggestions', 'acrossai-abilities-manager' ),
			array( $this, 'render_disable_ability_suggestions_field' ),
			$page_slug,
			'acrossai_ability_suggestions_settings_section'
		);
	}

	/**
	 * Registers the "Uninstall Settings" section at admin_init priority 20.
	 *
	 * Wired separately so it lands AFTER every other section registered at
	 * default priority 10 (Display Settings above, plus
	 * `Core_Settings_Menu`'s Upload Media Abilities section — Feature 046).
	 * Sections render in the order they were added, so a later hook priority
	 * pushes this section to the bottom of the Abilities tab.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register_uninstall_settings(): void {
		$renderer = \AcrossAI_Main_Menu\SettingsPage::get_settings_renderer();
		if ( ! $renderer ) {
			return;
		}
		$page_slug = $renderer->tab_page_slug( self::TAB_SLUG );

		add_settings_section(
			'acrossai_uninstall_settings_section',
			__( 'Uninstall Settings', 'acrossai-abilities-manager' ),
			'__return_false',
			$page_slug
		);

		add_settings_field(
			'acrossai_abilities_uninstall_delete_data',
			__( 'Delete all data on uninstall', 'acrossai-abilities-manager' ),
			array( $this, 'render_uninstall_field' ),
			$page_slug,
			'acrossai_uninstall_settings_section'
		);
	}

	/**
	 * Sanitizes the abilities per-page value.
	 *
	 * Accepts integers in [1, 200]; returns 20 for anything outside that range.
	 *
	 * @since 0.1.0
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_per_page( $value ): int {
		$int = absint( $value );

		// Out-of-range resets to the default — unchanged behaviour. Only the upper bound moved,
		// from 200 to the value REST actually honours (issue #185).
		return ( $int < 1 || $int > self::MAX_PER_PAGE ) ? 20 : $int;
	}

	/**
	 * Renders the abilities per-page number input field.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_per_page_field(): void {
		$value = (int) get_option( 'acrossai_abilities_per_page', 20 );
		printf(
			'<input type="number" id="acrossai_abilities_per_page" name="acrossai_abilities_per_page" value="%s" min="1" max="%s" step="1" /><p class="description">%s</p>',
			esc_attr( (string) $value ),
			esc_attr( (string) self::MAX_PER_PAGE ),
			esc_html(
				sprintf(
					/* translators: %d is the maximum abilities per page. */
					__( 'Number of abilities shown per page. Default: 20. Min: 1. Max: %d.', 'acrossai-abilities-manager' ),
					self::MAX_PER_PAGE
				)
			)
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
	 * Renders the uninstall delete data checkbox field.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_uninstall_field(): void {
		$checked = (bool) get_option( 'acrossai_abilities_uninstall_delete_data', 0 );
		printf(
			'<label><input type="checkbox" id="acrossai_abilities_uninstall_delete_data" name="acrossai_abilities_uninstall_delete_data" value="1" %s /> %s</label><p class="description"><span style="color: #d63638;">%s</span></p>',
			checked( $checked, true, false ),
			esc_html__( 'Delete all data on uninstall', 'acrossai-abilities-manager' ),
			esc_html__( '⚠ Warning: When checked, uninstalling this plugin will permanently delete all custom database tables and plugin options. This cannot be undone.', 'acrossai-abilities-manager' )
		);
	}

	/**
	 * Sanitizes the disable-plugin-suggestions checkbox value.
	 *
	 * Returns 1 when the checkbox is checked, 0 when unchecked or absent.
	 * Per PATTERN-CHECKBOX-SANITIZE — named public method, not closure.
	 *
	 * Feature 088.
	 *
	 * @since 0.0.33
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_disable_plugin_suggestions( $value ): int {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Renders the disable-plugin-suggestions checkbox field.
	 *
	 * When checked, no ability card shows a "Consider also" section and no
	 * `suggested_plugins` field is emitted in ability payloads (REST + MCP).
	 * Default: unchecked (suggestions shown). Feature 088.
	 *
	 * @since 0.0.33
	 * @return void
	 */
	public function render_disable_plugin_suggestions_field(): void {
		$checked = (bool) get_option( 'acrossai_disable_plugin_suggestions', 0 );
		printf(
			'<label><input type="checkbox" id="acrossai_disable_plugin_suggestions" name="acrossai_disable_plugin_suggestions" value="1" %1$s /> %2$s</label><p class="description">%3$s</p>',
			checked( $checked, true, false ),
			esc_html__( 'Disable the Plugin suggestion', 'acrossai-abilities-manager' ),
			esc_html__( 'When enabled, no suggested-plugin cards appear on the Library page and no suggested_plugins field is emitted in ability payloads (REST + MCP). Ability behaviour is unaffected.', 'acrossai-abilities-manager' )
		);
	}

	/**
	 * Sanitizes the disable-ability-suggestions checkbox value.
	 *
	 * Returns 1 when the checkbox is checked, 0 when unchecked or absent.
	 * Per PATTERN-CHECKBOX-SANITIZE — named public method, not closure.
	 *
	 * Feature 095.
	 *
	 * @since 0.0.34
	 * @param mixed $value Raw submitted value.
	 * @return int
	 */
	public function sanitize_disable_ability_suggestions( $value ): int {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Renders the disable-ability-suggestions checkbox field.
	 *
	 * When checked, no ability's payload includes `meta.acrossai.suggested_abilities`
	 * (REST + MCP). Advisory hints stop reaching AI callers immediately on
	 * the next request. Default: unchecked (suggestions surfaced). Feature 095.
	 *
	 * @since 0.0.34
	 * @return void
	 */
	public function render_disable_ability_suggestions_field(): void {
		$checked = (bool) get_option( 'acrossai_disable_ability_suggestions', 0 );
		printf(
			'<label><input type="checkbox" id="acrossai_disable_ability_suggestions" name="acrossai_disable_ability_suggestions" value="1" %1$s /> %2$s</label><p class="description"><span style="color: #d63638;">%3$s</span></p>',
			checked( $checked, true, false ),
			esc_html__( 'Disable ability suggestions', 'acrossai-abilities-manager' ),
			esc_html__( '⚠ When checked, no ability payload includes suggested-alternative-ability entries (REST + MCP). AI callers will not see hints like "consider blocks/outline-post-blocks + blocks/update-post-block instead of content/update-page for small edits". Ability behaviour is unaffected.', 'acrossai-abilities-manager' )
		);
	}
}
