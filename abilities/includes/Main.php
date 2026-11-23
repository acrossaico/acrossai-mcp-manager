<?php
/**
 * The core plugin class file.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.0.1
 */

namespace AcrossAI_Abilities_Manager\Includes;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://github.com/acrosswp/acrossai-abilities-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      0.0.1
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes
 * @author     AcrossWP <deepak@acrosswp.com>
 */
final class Main {

	/**
	 * The single instance of the class.
	 *
	 * @var AcrossAI_Abilities_Manager
	 * @since 0.0.1
	 */
	protected static $instance = null;

	/**
	 * The autoloader instance.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      Autoloader    $autoloader    The plugin autoloader instance.
	 */
	protected $autoloader;

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      AcrossAI_Abilities_Manager_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The plugin dir path
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      string    $plugin_path    The string for plugin dir path
	 */
	protected $plugin_path;

	/**
	 * The current version of the plugin.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Plugin directory path.
	 *
	 * @var string
	 */
	protected $plugin_dir;

	/**
	 * Boot-resilience flag set by load_composer_dependencies() when
	 * vendor/autoload_packages.php is absent. When true, __construct()
	 * registers a manage_options-gated admin notice and returns before
	 * load_dependencies() — preventing the AcrossAI_Loader class-not-found
	 * fatal at line 228 that was the reproducer for Feature 038 (Constitution
	 * §V Integration Resilience).
	 *
	 * @since 0.0.1
	 * @var bool
	 */
	private bool $vendor_missing = false;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    0.0.1
	 */
	private function __construct() {

		$this->define_constants();

		$this->plugin_name = 'acrossai-abilities-manager';
		$this->version     = ACROSSAI_ABILITIES_MANAGER_VERSION;

		// Load the autoloader class manually before registering it.
		$this->plugin_dir = ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH;

		$this->load_composer_dependencies();

		// Feature 038 boot resilience (T011 / SEC-001 / DEC-FAIL-OPEN-NOTICE):
		// When vendor/autoload_packages.php is absent the PSR-4 autoloader
		// is not registered, so referencing any plugin-namespaced class
		// would fatal. Register a self-contained admin notice (closure
		// uses only WP globals — no $this->, no FQCN under
		// AcrossAI_Abilities_Manager\…) and short-circuit before
		// load_dependencies() and load_hooks().
		if ( $this->vendor_missing ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					printf(
						'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
						esc_html__( 'AcrossAI Abilities Manager:', 'acrossai-abilities-manager' ),
						esc_html__( 'The Composer autoloader is missing. Run "composer install" inside the plugin directory to restore functionality.', 'acrossai-abilities-manager' )
					);
				}
			);
			return;
		}

		$this->load_dependencies();

		$this->load_hooks();
	}

	/**
	 * Main AcrossAI_Abilities_Manager Instance.
	 *
	 * Ensures only one instance of WooCommerce is loaded or can be loaded.
	 *
	 * @since 0.0.1
	 * @static
	 * @see AcrossAI_Abilities_Manager()
	 * @return AcrossAI_Abilities_Manager - Main instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Define WCE Constants
	 */
	private function define_constants() {

		$this->define( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_BASENAME', plugin_basename( \ACROSSAI_ABILITIES_MANAGER_PLUGIN_FILE ) );
		$this->define( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH', plugin_dir_path( \ACROSSAI_ABILITIES_MANAGER_PLUGIN_FILE ) );
		$this->define( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL', plugin_dir_url( \ACROSSAI_ABILITIES_MANAGER_PLUGIN_FILE ) );
		$this->define( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_NAME_SLUG', $this->plugin_name );
		$this->define( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_NAME', 'AcrossAI Abilities Manager' );
		$this->define( 'ACROSSAI_ABILITIES_MANAGER_VERSION', '0.0.45' );
	}

	/**
	 * Define constant if not already set.
	 *
	 * @param  string      $acrossai_name  The constant name.
	 * @param  string|bool $acrossai_value The constant value.
	 */
	private function define( $acrossai_name, $acrossai_value ) {
		if ( ! defined( $acrossai_name ) ) {
			define( $acrossai_name, $acrossai_value );
		}
	}

	/**
	 * Register all the hook once all the active plugins are loaded
	 *
	 * Uses the plugins_loaded to load all the hooks and filters
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	public function load_hooks() {

		/**
		 * Check if plugin can be loaded safely or not
		 *
		 * @since    0.0.1
		 */
		if ( apply_filters( 'acrossai_abilities_manager_load', true ) ) {
			$this->define_admin_hooks();
			$this->define_public_hooks();
		}
	}

	/**
	 * Load the required composer dependencies for this plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function load_composer_dependencies() {

		/**
		 * Add composer file
		 */
		$plugin_path = ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH;

		if ( file_exists( $plugin_path . 'vendor/autoload_packages.php' ) ) {
			require_once $plugin_path . 'vendor/autoload_packages.php';
			return;
		}

		// Feature 038 boot resilience: signal __construct() to short-circuit
		// before load_dependencies() touches the PSR-4 autoloader. Surfaced
		// to admins via the manage_options-gated admin notice in __construct().
		$this->vendor_missing = true;
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - AcrossAI_Abilities_Manager\Admin\Loader. Orchestrates the hooks of the plugin.
	 * - AcrossAI_Abilities_Manager\Admin\Main. Defines all hooks for the admin area.
	 * - AcrossAI_Abilities_Manager_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function load_dependencies() {

		$this->loader = AcrossAI_Loader::instance();
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new \AcrossAI_Abilities_Manager\Admin\Main( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

		$this->loader->add_action( 'plugin_action_links', $plugin_admin, 'plugin_action_links', 1000, 2 );

		/**
		 * Add the Plugin Main Menu
		 */
		$main_menu = new \AcrossAI_Abilities_Manager\Admin\Partials\Menu( $this->get_plugin_name(), $this->get_version() );
		$this->loader->add_action( 'admin_menu', $main_menu, 'register_submenu' );
		// Ordering moved out in main-menu 0.0.38. `MenuRegistrar::enforce_order()`
		// runs at 1100 — after the 999 this used — and states the whole group's
		// order in one place, including the two moves this made. Two passes
		// reordering the same menu is how an order nobody can predict happens;
		// the package that owns the parent owns the answer.
		//
		// `Partials\Menu::reorder_submenu()` is unhooked, not deleted: it is
		// public API that another plugin may call. Its own docblock says so.

		// Quick Connect onboarding wizard (Feature 099). No page of its own — it
		// renders through Partials\Menu when `?quick-connect=1` is present. The
		// page class owns its enqueue per PATTERN-PARTIALS-SELF-ENQUEUE, keeping
		// a page-specific bundle out of Admin\Main::enqueue_scripts().
		$quick_connect_page = \AcrossAI_Abilities_Manager\Admin\Partials\QuickConnect\QuickConnectPage::instance();
		$this->loader->add_action( 'admin_enqueue_scripts', $quick_connect_page, 'enqueue_assets' );
		$this->loader->add_filter( 'admin_body_class', $quick_connect_page, 'add_body_class' );
		$this->loader->add_action( 'in_admin_header', $quick_connect_page, 'suppress_admin_notices', 1000 );

		// Feature 102: the retired Ability Integrations URL. `admin_page_access_denied` is the hook
		// that actually catches it — core denies an unregistered `page` in
		// wp-admin/includes/menu.php:384, reached from admin.php:163, which is BEFORE
		// do_action( 'admin_init' ) at admin.php:180. An admin_init handler at any priority is too
		// late and the visitor gets "Sorry, you are not allowed to access this page". admin_init
		// priority 1 is registered too, for the case where something else still registers the
		// legacy slug and access is therefore never denied.
		$integrations_redirect = \AcrossAI_Abilities_Manager\Admin\Partials\Integrations_Redirect::instance();
		$this->loader->add_action( 'admin_page_access_denied', $integrations_redirect, 'maybe_redirect', 1 );
		$this->loader->add_action( 'admin_init', $integrations_redirect, 'maybe_redirect', 1 );

		// Priority 5: early enough to redirect before other admin_init work
		// renders anything, late enough that the plugin API is available.
		$quick_connect_redirect = \AcrossAI_Abilities_Manager\Admin\Partials\QuickConnect\ActivationRedirect::instance();
		$this->loader->add_action( 'admin_init', $quick_connect_redirect, 'maybe_redirect', 5 );

		// Toolbar chip — the one entry point present on every admin screen, so
		// the wizard stays reachable after its single automatic opening.
		// Priority 100 settles the node to the right of core's own entries.
		$quick_connect_bar = \AcrossAI_Abilities_Manager\Admin\Partials\QuickConnect\AdminBarEntry::instance();
		$this->loader->add_action( 'admin_bar_menu', $quick_connect_bar, 'register_node', 100 );

		// Settings sections (Feature 019; reparented to host page in Feature 038;
		// migrated to instance-method API + tab-scoped option_group in Feature 045).
		// Host page rendering owned by acrossai-co/main-menu — the plugin
		// only contributes (a) an "Abilities" tab via the
		// `acrossai_settings_tabs` filter, and (b) settings sections
		// targeting the per-tab page slug derived by
		// SettingsPage::get_settings_renderer()->tab_page_slug() (main-menu v0.0.14+).
		// option_group is the SAME tab-scoped slug, so each tab has its own
		// whitelist — preventing the cross-tab option-clobber bug that
		// shared-`acrossai-settings` had in 0.0.12.
		// Named variable before Loader call — Boot Flow Rule variable-first pattern (AC-HOOKS-MAIN).
		$settings_menu = \AcrossAI_Abilities_Manager\Admin\Partials\SettingsMenu::instance();
		$this->loader->add_filter( 'acrossai_settings_tabs', $settings_menu, 'register_tab' );
		$this->loader->add_action( 'admin_init', $settings_menu, 'register_settings' );

		// Feature 046 — Absorbed Core Settings field (extra MIME types) renders
		// INSIDE the existing Abilities tab. Core_Settings_Menu does not register
		// its own tab and does not register a second uninstall-opt-in; the
		// manager's SettingsMenu owns both concerns. Variable-first per AC-HOOKS-MAIN.
		$core_settings_menu = \AcrossAI_Abilities_Manager\Admin\Partials\Core_Settings_Menu::instance();
		$this->loader->add_action( 'admin_init', $core_settings_menu, 'register_settings' );

		// Feature 120 — the Integrations directory, a submenu of its own directly below Abilities.
		// `register_submenu` runs at the default priority so it is in place before
		// main-menu's `enforce_order()` at 1100 puts the group in order. Admin-only, so it
		// belongs in define_admin_hooks() (Boot Flow Rule). Variable-first per AC-HOOKS-MAIN.
		$integrations_menu = \AcrossAI_Abilities_Manager\Admin\Partials\Integrations_Menu::instance();
		$this->loader->add_action( 'admin_menu', $integrations_menu, 'register_submenu' );
		$this->loader->add_action( 'admin_init', $integrations_menu, 'register_settings' );
		$this->loader->add_action( 'admin_enqueue_scripts', $integrations_menu, 'enqueue_assets' );

		// The routes behind that screen. Separate controller rather than more routes on the
		// abilities one: this is the only place in the plugin that reaches acrossai.co, and keeping
		// it in its own file keeps that fact findable.
		$integrations_rest = \AcrossAI_Abilities_Manager\Includes\Abilities\Rest\Integrations_Directory_Controller::instance();
		$this->loader->add_action( 'rest_api_init', $integrations_rest, 'register_routes' );

		// Uninstall Settings section is wired LAST — priority 20 pushes it below
		// every default-priority section (Display Settings above, plus
		// Core_Settings_Menu's Upload Media Abilities section). Sections render
		// in the order they were added, so a later priority puts this at the
		// bottom of the Abilities tab.
		$this->loader->add_action( 'admin_init', $settings_menu, 'register_uninstall_settings', 20 );

		// Feature 092 — File Manager settings tab (write allowlist + read
		// allowlist + configurable secret redactor). Registers a new tab on
		// the shared AcrossAI settings page and enqueues the React bundle.
		$file_manager_settings = \AcrossAI_Abilities_Manager\Admin\Partials\File_Manager_Settings_Menu::instance();
		$this->loader->add_filter( 'acrossai_settings_tabs', $file_manager_settings, 'register_tab' );
		$this->loader->add_action( 'admin_init', $file_manager_settings, 'register_settings' );
		$this->loader->add_action( 'admin_enqueue_scripts', $file_manager_settings, 'enqueue_assets' );

		// Feature 092 — REST endpoints backing the File Manager settings tab.
		$file_manager_rest = \AcrossAI_Abilities_Manager\Includes\Abilities\Rest\File_Manager_Settings_Controller::instance();
		$this->loader->add_action( 'rest_api_init', $file_manager_rest, 'register_routes' );

		// Feature 134 — Database settings tab (access mode + table denylist + row/time caps +
		// redaction and mutation logging). Same wiring as the File Manager tab above, deliberately:
		// both tabs register through `acrossai_settings_tabs`, host a React mount in a placeholder
		// Settings API section, and save over REST rather than through the vendor form.
		$database_settings = \AcrossAI_Abilities_Manager\Admin\Partials\Database_Settings_Menu::instance();
		$this->loader->add_filter( 'acrossai_settings_tabs', $database_settings, 'register_tab' );
		$this->loader->add_action( 'admin_init', $database_settings, 'register_settings' );
		$this->loader->add_action( 'admin_enqueue_scripts', $database_settings, 'enqueue_assets' );

		// Feature 134 — REST endpoints backing the Database settings tab.
		$database_rest = \AcrossAI_Abilities_Manager\Includes\Abilities\Rest\Database_Settings_Controller::instance();
		$this->loader->add_action( 'rest_api_init', $database_rest, 'register_routes' );

		// Feature 134 — apply the database policy to every `acrossai-database` ability. Hooked here
		// rather than added to nineteen separate execute() methods, so the policy has one enforcement
		// point and a future database ability inherits it instead of shipping ungoverned.
		\AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Db_Policy_Enforcer::register();

		// Add-ons submenu page (Feature 026 → 030 → 039 → 053).
		// As of acrossai-co/main-menu 0.0.21 the `\AcrossAI_Addon\AddonsPage`
		// entry-class is removed and the Add-ons submenu is registered
		// internally by `\AcrossAI_Main_Menu\MenuRegistrar` when the shared
		// `\AcrossAI_Main_Menu\SettingsPage` bootstrap runs (done at
		// `plugins_loaded @ P0` in this plugin's entry file, see
		// acrossai-abilities-manager.php). Consumers extend the shared list
		// via `add_filter( 'acrossai_addons', … )`; freemius integration was
		// dropped upstream in 0.0.21 so no `fs_*` args are passed anymore.
		// The submenu URL admin.php?page=acrossai-addons is unchanged.

		// Abilities DB table setup — BerlinDB hooks maybe_upgrade() to admin_init.
		// Named variable before Loader call — Boot Flow Rule variable-first pattern (AC-HOOKS-MAIN).
		$abilities_table = \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Table::instance();

		// Register Access Control REST routes and library-missing notice (SAC-01).
		// The library-missing notice is routed through the shared main-menu 0.0.30
		// `acrossai_notices` filter — surfaces on AcrossAI → Notices submenu +
		// the top-of-page summary banner instead of a raw admin_notices banner.
		$abilities_ac = \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\AcrossAI_Abilities_Access_Control::instance();
		$this->loader->add_action( 'rest_api_init', $abilities_ac, 'register_rest_api' );
		$this->loader->add_filter( 'acrossai_notices', $abilities_ac, 'register_library_notice' );

		// Abilities module REST routes (Spec 009).
		// Named variable before Loader call — Boot Flow Rule variable-first pattern.
		$abilities_rest = \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Rest\AcrossAI_Abilities_Rest_Controller::instance();
		$this->loader->add_action( 'rest_api_init', $abilities_rest, 'register_routes' );

		// Feature 101: rewrite stored `acrossai-abilities-manager-*` category
		// slugs to `acrossai-*`. Also called from Activator::activate(), but an
		// in-place upgrade (composer, wp-cli, file replace) never re-activates,
		// so admin_init is the path that actually catches most sites. Guarded by
		// its own completion flag, so this is one option read per admin request
		// once it has run. Priority 1 — the DB-defined abilities it repairs
		// register on wp_abilities_api_init, which is later.
		$category_slug_migration = \AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Category_Slug_Migration::class;
		$this->loader->add_action( 'admin_init', $category_slug_migration, 'maybe_migrate', 1 );

		// Library Registry — collect add-on definitions at init P99 (Feature 027).
		// Named variable before Loader call — Boot Flow Rule variable-first pattern.
		$ability_library_registry = \AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Ability_Library_Registry::instance();
		$this->loader->add_action( 'init', $ability_library_registry, 'collect', 99 );

		// Feature 099: publish the tab-group summary as a filter so consumers
		// outside the Library module (the Quick Connect wizard) can read it
		// without importing Library classes. Constitution Module Contract #3
		// forbids sibling-module reach-through; #4 makes filters the sanctioned
		// integration point. Consumers call:
		// apply_filters( 'acrossai_ability_library_tab_group_summary', array() ).
		$this->loader->add_filter( 'acrossai_ability_library_tab_group_summary', $ability_library_registry, 'get_tab_group_summary' );

		// Feature 102: the same seam for the full definition rows. The gate translation lives in
		// the Abilities module and needs whole definitions, not the tab-group summary — reading
		// the Registry directly would be the sibling-module reach-through Module Contract #3
		// forbids. Consumers call:
		// apply_filters( 'acrossai_ability_library_definitions', array() ).
		$this->loader->add_filter( 'acrossai_ability_library_definitions', $ability_library_registry, 'provide_definitions' );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new \AcrossAI_Abilities_Manager\Front\Main( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

		// Feature 102 — translate the retired library gate into per-ability overrides.
		//
		// init P100, i.e. immediately after AcrossAI_Ability_Library_Registry::collect() at init P99
		// (see below). The translation reads that registry, so anything earlier sees zero
		// definitions.
		//
		// This was originally plugins_loaded P1 and that was wrong in the worst way: the migration
		// ran, found no definitions, translated nothing, and still retired the source option —
		// silently discarding the configuration it existed to preserve. Do not move this earlier
		// than init P100 without also proving get_definitions() is populated at the new point.
		//
		// It is deliberately NOT admin_init: the exposure this closes is reachable via REST and MCP,
		// neither of which loads wp-admin, so an admin-only trigger would leave a site nobody
		// administers serving every previously blocked ability. init fires on every request path.
		//
		// Public hook, so registered here rather than in define_admin_hooks() (Boot Flow Rule).
		$gate_migration = \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\AcrossAI_Library_Gate_Migration::instance();
		$this->loader->add_action( 'init', $gate_migration, 'maybe_migrate', 100 );

		// Adds isGlobalStylesUserThemeJSON + version to wp_global_styles records written before
		// 0.0.41, which WordPress was ignoring outright. Same reasoning as the gate migration for the
		// hook choice: an admin-only trigger would leave an unattended site serving theme defaults
		// while its abilities reported a DB record as effective. It reads no request input and is
		// claimed once per site.
		$global_styles_flags = \AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Flag_Migration::instance();
		$this->loader->add_action( 'init', $global_styles_flags, 'maybe_migrate', 100 );

		// Feature 184: give abilities registered by other plugins a toolset. Hooked to the core
		// wp_register_ability_args filter at P10 so the value is on the WP_Ability itself, which is
		// what makes the tab strip, the counts, the Toolset column and the MCP dispatchers all agree
		// without any of them knowing the tagger exists. Well before the override processor's
		// P100000; the two touch different keys.
		$group_tagger = \AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Group_Tagger::class;
		$this->loader->add_filter( 'wp_register_ability_args', $group_tagger, 'tag', 10, 2 );

		// Ability Override Processor — boot at plugins_loaded P20 and bust cache on override save.
		// Named variable before Loader calls satisfies the Boot Flow Rule (SEC-PLAN-002).
		$override_processor = \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\AcrossAI_Ability_Override_Processor::instance();
		$this->loader->add_action( 'plugins_loaded', $override_processor, 'boot_hook', 20 );
		// CRITICAL-01 fix (sub-change 11d): wire bust_cache_hook to all three Abilities lifecycle hooks.
		$this->loader->add_action( 'acrossai_abilities_after_create', $override_processor, 'bust_cache_hook' );
		$this->loader->add_action( 'acrossai_abilities_after_update', $override_processor, 'bust_cache_hook' );
		$this->loader->add_action( 'acrossai_abilities_after_delete', $override_processor, 'bust_cache_hook' );

		// Abilities Processor — registers published source=db abilities at wp_abilities_api_init P10 (Spec 009).
		// Named variable before Loader call — Boot Flow Rule variable-first pattern.
		$abilities_processor = \AcrossAI_Abilities_Manager\Includes\Modules\Abilities\AcrossAI_Abilities_Processor::instance();
		$this->loader->add_action( 'wp_abilities_api_init', $abilities_processor, 'register_abilities', 10 );

		// Library Processor — registers add-on abilities at wp_abilities_api_init P5 (Feature 027).
		// Runs before the DB processor at P10 so add-on abilities are gated first.
		// Named variable before Loader call — Boot Flow Rule variable-first pattern.
		$ability_library_processor = \AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Ability_Library_Processor::instance();
		$this->loader->add_action( 'wp_abilities_api_init', $ability_library_processor, 'register_abilities', 5 );

		// Feature 046 — Absorbed Core Abilities Bootstrap. Registers 17 category
		// callbacks with the Loader (each is one $this->loader->add_action on
		// wp_abilities_api_categories_init) and hooks the 176-class ability
		// instantiation to plugins_loaded @ P20 matching the companion plugin's
		// original hook point. See specs/046-absorb-core-abilities-into-manager/.
		$core_abilities_bootstrap = \AcrossAI_Abilities_Manager\Includes\Abilities\AcrossAI_Core_Abilities_Bootstrap::instance();
		$core_abilities_bootstrap->register_category_callbacks( $this->loader );
		$this->loader->add_action( 'plugins_loaded', $core_abilities_bootstrap, 'register_abilities', 20 );

		// Feature 060 — Third-party integration bootstraps. Each concrete
		// subclass of AcrossAI_Integration_Ability_Base registers its own
		// hooks from the constructor (plugins_loaded P20 for the enable-filter
		// side-effect + acrossai_abilities_api_init P10 for the synthetic
		// definition row). Both hook callbacks gate on subclass
		// is_plugin_active() so a missing target plugin renders no card, no
		// tab, and attaches no filter. Boot Flow Rule deviation is documented
		// under DEC-ABILITY-DEFINITION-CTOR-HOOKS (Feature 060), sibling to
		// DEC-EXTERNAL-PACKAGE-HOOK-CTOR. See
		// specs/060-library-third-party-integration-toggles/plan.md
		// Complexity Tracking for the justification.
		//
		// Instantiation happens synchronously here (from define_public_hooks(),
		// which itself runs at plugins_loaded @ P0 via the plugin entry file)
		// so the constructor's plugins_loaded P20 add_action registers before
		// P20 fires. Adding another integration is a one-line change here.
		// Feature 117 — seed the opt-ins whose default is ON before any of them is constructed. The
		// base class reads the toggle at plugins_loaded P20 and we are at P0, so seeding here means
		// a default-on integration is correct on the very first request rather than the second.
		\AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Integration_Default_Opt_Ins::maybe_seed();

		// And again once every plugin has loaded. At P0 a host's `is_active()` probe cannot see it
		// yet — `class_exists( '\RankMath\Helper' )` is false this early — so the pass above only
		// ever covers the opt-in slugs it is timed for. The second pass is what seeds the
		// integrations discovered from the registry, and it is safe to run because seeding is
		// idempotent per slug: anything the first pass settled is skipped, and a stored `false` is a
		// decision the seed never overwrites.
		$this->loader->add_action(
			'init',
			\AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Integration_Default_Opt_Ins::class,
			'maybe_seed_discovered',
			1
		);

		new \AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\ACF();
		new \AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\Yoast_Seo_Opt_In();
		new \AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\WPForms();
		// Both host their own gate: Elementor behind an option, Jetpack behind a filter its packages
		// read while they load — which is why Jetpack asks for an earlier enable hook. Neither seeds
		// on, because switching them on switches on a third-party feature as well as our grouping.
		new \AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\Elementor_Opt_In();
		new \AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\Jetpack_Opt_In();

		// Issue #202 — start recording ability-name clashes before anything registers. Abilities
		// register on wp_abilities_api_init (fired from init), so attaching here at plugins_loaded P0
		// is early enough to see every attempt, including plugins that register before we do.
		\AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Ability_Collision_Recorder::listen();
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    0.0.1
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     0.0.1
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     0.0.1
	 * @return    AcrossAI_Abilities_Manager_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * The reference to the autoloader instance.
	 *
	 * @since     0.0.1
	 * @return    Autoloader    The plugin autoloader instance.
	 */
	public function get_autoloader() {
		return $this->autoloader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     0.0.1
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
