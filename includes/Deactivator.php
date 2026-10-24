<?php
namespace AcrossAI_MCP_Manager\Includes;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin deactivation
 *
 * @link       https://github.com/WPBoilerplate/acrossai-mcp-manager
 * @since      0.0.1
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      0.0.1
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes
 * @author     WPBoilerplate <contact@wpboilerplate.com>
 */
class Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    0.0.1
	 */
	public static function deactivate() {
		// Feature 016: no plugin-side deactivation cleanup for the retired
		// Connectors integration. Operator unschedules retired cron events via
		// `wp cron event unschedule` per F016 spec §User Story 2.

		// Feature 021 — clear the daily OAuth cleanup cron.
		// T042a decision: Deactivator class already existed (empty). Single-path
		// deactivation preserved — no inline callback in acrossai-mcp-manager.php
		// beyond the require + delegation.
		wp_clear_scheduled_hook( 'acrossai_mcp_manager_oauth_cleanup' );

		// F096 — the activity log's retention sweep. Cleared by name, matching
		// the OAuth hook above: the constant lives on the Cleanup class but the
		// contract is the string, and deactivation must not depend on that class
		// being loadable.
		wp_clear_scheduled_hook( 'acrossai_mcp_manager_activity_cleanup' );
	}
}
