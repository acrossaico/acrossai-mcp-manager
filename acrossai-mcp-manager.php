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
 * Plugin URI: https://acrossai.co/
 * Description: Enable/Disable MCP Adapter Integration for WordPress
 * Version: 0.3.1
 * Author: raftaar1191
 * Author URI: https://profiles.wordpress.org/raftaar1191/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: acrossai-mcp-manager
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires WP: 7.0
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
