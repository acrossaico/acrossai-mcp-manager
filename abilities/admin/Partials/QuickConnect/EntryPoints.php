<?php
/**
 * Shared rules for every Quick Connect entry point.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/Admin/Partials/QuickConnect
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Admin\Partials\QuickConnect;

use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Mcp_Transport_Detector;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Where the wizard lives, and whether to offer it at all.
 *
 * Four surfaces link into Quick Connect — the sidebar submenu, the toolbar chip,
 * the plugins-row action link, and the settings-tab button. They share this class
 * so the destination and the visibility rule are decided once. Four copies of
 * either would be four chances to drift.
 *
 * @since 0.0.34
 */
class EntryPoints {

	/**
	 * Per-request memo for is_available().
	 *
	 * The toolbar chip asks on every admin page load, and a negative answer costs
	 * a get_plugins() directory scan. One answer per request is plenty — plugin
	 * activation state cannot change midway through one.
	 *
	 * @since 0.0.34
	 * @var   bool|null
	 */
	private static $available = null;

	/**
	 * Canonical entry URL for the wizard.
	 *
	 * @since  0.0.34
	 * @return string Admin URL for step 1.
	 */
	public static function wizard_url(): string {
		return admin_url(
			'admin.php?page=acrossai-abilities-manager&' . QuickConnectPage::QUERY_ARG . '=1&step=1'
		);
	}

	/**
	 * Whether Quick Connect should be advertised anywhere in wp-admin.
	 *
	 * AcrossAI MCP Manager ships its own Quick Connect and registers it on these
	 * same surfaces. A site running it does not need a second wizard competing
	 * for the same sidebar slot and the same toolbar strip, and the abilities
	 * wizard's whole purpose — getting a transport connected — is already served.
	 *
	 * The rule is deliberately the same one ActivationRedirect uses to decide
	 * whether to open the wizard on activation: one predicate, so the plugin
	 * never auto-opens a wizard it then hides every route back to.
	 *
	 * Note this hides the *advertising*, not the wizard. A saved link still
	 * works, which is what keeps support able to walk someone through it.
	 *
	 * @since  0.0.34
	 * @return bool True when no recommended transport is active.
	 */
	public static function is_available(): bool {
		if ( null === self::$available ) {
			self::$available = AcrossAI_Mcp_Transport_Detector::STATE_ACTIVE !== AcrossAI_Mcp_Transport_Detector::detect(
				AcrossAI_Mcp_Transport_Detector::TRANSPORT_MCP_MANAGER
			);
		}

		return self::$available;
	}

	/**
	 * Discard the memoised availability answer.
	 *
	 * Exists for tests, which activate and deactivate the sibling plugin within
	 * a single process.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function reset_availability_cache(): void {
		self::$available = null;
	}
}
