<?php
/**
 * Instantiates the AcrossAI MCP Manager plugin
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager;

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://github.com/WPBoilerplate/acrossai-mcp-manager
 * @since             0.0.1
 * @package           AcrossAI_MCP_Manager
 *
 * @wordpress-plugin
 * Plugin Name: AcrossAI MCP Manager
 * Plugin URI: https://acrossai.co/mcp-manager/
 * Description: WordPress MCP server and admin for MCP Adapter: multiple servers, access control and one-click connectors for Claude, ChatGPT, Gemini, Grok and Cursor.
 * Version: 0.4.9
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: AcrossAI
 * Author URI: https://acrossai.co/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: acrossai-mcp-manager
 * Domain Path: /languages
 *
 * @package AcrossAI_MCP_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 0.0.1 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'ACROSSAI_MCP_MANAGER_PLUGIN_FILE', __FILE__ );

/**
 * Tell the standalone AcrossAI Abilities Manager plugin (0.0.46+) to stand
 * itself down.
 *
 * Feature 106 absorbed that plugin's entire tree into this one. On a site
 * running both, letting the standalone plugin load its own copy too would
 * register every ability, Toolset and admin screen twice. 0.0.46 added
 * `acrossai_abilities_manager_load_plugin` — a filter it consults on
 * `plugins_loaded`, before requiring a single file of its own tree — so a
 * plugin that carries that tree itself, like this one, can answer it and
 * make the standalone plugin inert: no constants, no autoloader, nothing.
 *
 * Registered here at file scope, not inside a hook callback, because it has
 * to be in place before `plugins_loaded` fires — that is when the standalone
 * plugin reads it. WordPress loads active plugins in `active_plugins` order,
 * and `activate_plugin()` sorts that array (wp-admin/includes/plugin.php:719)
 * — which puts `acrossai-abilities-manager` before `acrossai-mcp-manager` — so
 * by the time this file is read, the standalone plugin's file has already run
 * and registered its own `plugins_loaded` callback, but that action has not
 * fired yet. Any plugin file can still add this filter in time, whatever
 * order it loads in; file scope is simply the earliest point this plugin
 * itself gets a turn.
 *
 * This only helps against a standalone plugin at 0.0.46 or later. An older
 * copy has no gate to consult and will define
 * `ACROSSAI_ABILITIES_MANAGER_PLUGIN_FILE` at file scope regardless — see
 * that constant below for how the rare case is still handled.
 *
 * @since 0.5.0
 */
add_filter( 'acrossai_abilities_manager_load_plugin', '__return_false' );

/**
 * Root file of the absorbed Abilities Manager tree (Feature 106).
 *
 * `abilities/` arrived from acrossai-abilities-manager, where its own entry
 * file defined this. That file did not move, so this define is what makes the
 * absorbed tree resolve against THIS plugin: `abilities/includes/Main.php`
 * derives PLUGIN_PATH, PLUGIN_URL and PLUGIN_BASENAME from it, and every
 * `build/js/abilities/…` and `assets/abilities/…` path in that tree is written
 * relative to the result.
 *
 * It is deliberately the same `__FILE__` rather than a path inside
 * `abilities/`: there is one plugin now, with one basename WordPress knows it
 * by, and the absorbed admin notices and action links have to name that one.
 *
 * Guarded, and the guard is not theoretical — though with the filter above in
 * place, it is no longer the expected path. A standalone plugin at 0.0.46+
 * never reaches the `define()` that would trip this guard; its whole
 * bootstrap, including that line, is deferred behind the gate it just lost.
 * So on a current install this plugin always wins the race below, whether or
 * not the standalone plugin is also installed and active.
 *
 * What the guard still protects against: a standalone plugin OLDER than
 * 0.0.46, which has no gate to answer and defines this constant at file
 * scope unconditionally. WordPress loads active plugins in alphabetical
 * order, and `acrossai-abilities-manager` sorts BEFORE `acrossai-mcp-manager`
 * — so on a site where an old copy is still active, it has already defined
 * this constant by the time we get here, and an unguarded `define()` would
 * emit "Constant already defined" on every request. That is now the rare
 * path rather than the expected one — see acrossai_mcp_manager_run_abilities()
 * below for what we do about it — but it is not unreachable, so the guard
 * stays.
 *
 * Whether we won that race is also the only reliable thing to branch the boot
 * on, so it is recorded rather than recomputed: `is_plugin_active()` is not
 * loaded this early, and the class-level check cannot work because both trees
 * declare the same namespace at the same version, leaving jetpack-autoloader to
 * break the tie by load order.
 */
if ( defined( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_FILE' ) ) {
	define( 'ACROSSAI_MCP_MANAGER_OWNS_ABILITIES', false );
} else {
	define( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_FILE', __FILE__ );
	define( 'ACROSSAI_MCP_MANAGER_OWNS_ABILITIES', true );
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/activator.php
 */
function acrossai_mcp_manager_activate() {
	// FR-011: Load the vendor autoloader before the Activator so BerlinDB Kern
	// base classes and the four Database\<Module>\Query FQNs autoload cleanly
	// during activation — `plugins_loaded` (where Main::__construct() normally
	// registers the vendor autoloader) has not yet fired at this point.
	// The priority-1 pre-guard on `activate_<plugin>` (below) provides the
	// fail-early wp_die() on a missing vendor install (DEV4 / D15 / B14).
	require_once __DIR__ . '/vendor/autoload_packages.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/Activator.php';
	Includes\Activator::activate();

	// F106 — the absorbed Abilities Manager half activates itself too: two
	// tables plus seven option migrations. Every step is individually guarded
	// by its own claim option, so this is safe to call on a site that already
	// ran it under the old plugin — which is the normal case, not the edge
	// one. It creates nothing that exists and loses nothing that does.
	//
	// Only when we own the namespace. With the old plugin still active, its
	// own activation hook covers this and the classes resolved here might be
	// its copy rather than ours.
	if ( ACROSSAI_MCP_MANAGER_OWNS_ABILITIES ) {
		\AcrossAI_Abilities_Manager\Includes\AcrossAI_Activator::activate();
	}

	// F069 T014 — Set the one-shot redirect signal so the very next admin
	// page load lands the activating admin on the Quick Connect via AcrossAI wizard step 1.
	// Consumed by ActivationRedirect::maybe_redirect() on `admin_init` @ 5.
	// Short TTL (30s) so a WP-CLI activation without a subsequent admin
	// request lets the signal expire naturally instead of ambushing the
	// operator on their next unrelated admin visit hours later.
	// function_exists() guard is defensive: WordPress guarantees set_transient
	// is loaded here (we're running under the WP activation lifecycle), but a
	// broken WP core install would fatal before this — cheap belt-and-suspenders.
	if ( function_exists( 'set_transient' ) ) {
		set_transient( 'acrossai_mcp_manager_quick_connect_do_redirect', '1', 30 );
	}
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/deactivator.php
 */
function acrossai_mcp_manager_deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/Deactivator.php';
	Includes\Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'AcrossAI_MCP_Manager\acrossai_mcp_manager_activate' );
register_deactivation_hook( __FILE__, 'AcrossAI_MCP_Manager\acrossai_mcp_manager_deactivate' );

/**
 * Pre-activation vendor autoload guard (FR-030).
 *
 * Registered at priority 1 on the WordPress-internal `activate_<plugin>` action
 * so it runs BEFORE the default-priority-10 callback registered by the
 * register_activation_hook above. Without this priority shift the existing
 * callback would fatal on a missing-vendor install and never reach this guard.
 *
 * Mirrors the reference pattern from `acrossai-abilities-manager` Feature 038.
 */
add_action(
	'activate_' . plugin_basename( __FILE__ ),
	static function () {
		if ( ! file_exists( __DIR__ . '/vendor/autoload_packages.php' ) ) {
			wp_die(
				esc_html__(
					'AcrossAI MCP Manager cannot activate: the Composer autoloader is missing. Run "composer install" inside the plugin directory and try again.',
					'acrossai-mcp-manager'
				)
			);
		}
	},
	1
);

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/Main.php';

use AcrossAI_MCP_Manager\Includes\Main;

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    0.0.1
 */
function acrossai_mcp_manager_run() {

	$plugin = Main::instance();

	/**
	 * Run this plugin on the plugins_loaded functions
	 */
	add_action( 'plugins_loaded', array( $plugin, 'run' ), 0 );

	acrossai_mcp_manager_run_abilities();
}

/**
 * Boot the absorbed Abilities Manager half (Feature 106).
 *
 * Its `Includes\Main` is a singleton with the same shape as ours — it builds
 * its own Loader, registers its own hooks, and `run()` fires them — so the two
 * halves boot side by side rather than one wrapping the other. Nothing is
 * shared but the constants above and the shared `acrossai-co/main-menu` parent,
 * which is already idempotent via `did_action( 'acrossai_main_menu_bootstrapped' )`.
 *
 * Checked before writing this: the two halves do not collide on the settings
 * screen. Tab slugs are `abilities`, `mcp`, `file-manager`, `import-export` and
 * `database`, all distinct, and no admin page slug appears on both sides.
 * (`import-export` and `file-manager` both ask for priority 30, so their order
 * is decided by registration order rather than by intent — cosmetic, and noted
 * rather than silently relied on.)
 *
 * `load_composer_dependencies()` in the absorbed Main `require_once`s
 * `vendor/autoload_packages.php`, which now resolves to this plugin's own
 * autoloader that our Main has already loaded — a no-op, not a second
 * autoloader.
 *
 * @since 0.5.0
 */
function acrossai_mcp_manager_run_abilities(): void {

	// Reached only on the rare fallback path: a standalone plugin OLDER than
	// 0.0.46, which has no `acrossai_abilities_manager_load_plugin` filter to
	// consult and so already defined ACROSSAI_ABILITIES_MANAGER_PLUGIN_FILE at
	// file scope by the time we got here — see that constant's docblock
	// above. A current (0.0.46+) standalone install answers the filter
	// registered near the top of this file and never reaches this branch.
	//
	// Either way, its copy of the tree got here first and owns the
	// namespace. Booting ours too would register every hook twice. Defer to
	// it and say so, rather than racing it.
	if ( ! ACROSSAI_MCP_MANAGER_OWNS_ABILITIES ) {
		add_action(
			'admin_notices',
			static function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'AcrossAI MCP Manager:', 'acrossai-mcp-manager' ),
					esc_html__( 'The separate AcrossAI Abilities Manager plugin is running a version old enough that it is still loading its own code. Its abilities are now built into MCP Manager, so the old plugin is running the same code twice. Update it to version 0.0.46 or later and it will stand itself down automatically — or deactivate it now if you would rather not wait. Either way nothing is lost: your abilities and access rules stay exactly as they are.', 'acrossai-mcp-manager' )
				);
			}
		);

		return;
	}

	$abilities = \AcrossAI_Abilities_Manager\Includes\Main::instance();

	add_action( 'plugins_loaded', array( $abilities, 'run' ), 0 );
}

/**
 * Bootstrap the shared `acrossai-co/main-menu` top-level menu host (FR-029).
 *
 * Registered at plugins_loaded priority 0 so the shared parent menu exists
 * before any plugin's admin_menu hooks fire on default priority 10. The
 * `did_action()` guard makes the bootstrap idempotent across multiple
 * AcrossAI plugins consuming the same shared menu. The `class_exists()` guard
 * provides Constitution §V Integration Resilience graceful degradation when
 * the package is absent — submenus simply won't have a parent rather than
 * fataling.
 *
 * Accepted deviation from architecture constraint A1 per FR-031 / D15 / DEV4:
 * the bootstrap lives in the plugin entry file rather than in includes/Main.php
 * because the host menu must be the canonical owner of the shared parent menu,
 * independent of any single consuming plugin's internal Loader. Mirrors
 * `acrossai-abilities-manager` Feature 038 `DEC-EXTERNAL-PACKAGE-HOOK-CTOR`.
 * See docs/memory/DECISIONS.md D15 for the full pattern rationale.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( did_action( 'acrossai_main_menu_bootstrapped' ) ) {
			return;
		}
		if ( class_exists( \AcrossAI_Main_Menu\SettingsPage::class ) ) {
			new \AcrossAI_Main_Menu\SettingsPage();
			do_action( 'acrossai_main_menu_bootstrapped' );
		}
	},
	0
);

acrossai_mcp_manager_run();
