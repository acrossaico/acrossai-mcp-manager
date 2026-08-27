<?php
/**
 * ActivationRedirect — one-shot redirect from plugin activation to the wizard.
 *
 * Feature 069 T015 — Consumes the site-level `acrossai_mcp_manager_quick_connect_do_redirect`
 * transient set inside `acrossai_mcp_manager_activate()` (T014). On the next
 * `admin_init` cycle after activation, redirects the activating admin
 * directly to Quick Connect via AcrossAI step 1 URL.
 *
 * Guards (per spec FR-004 / research R4):
 *   1. Transient present (else silent no-op).
 *   2. Delete transient FIRST (idempotency — if two admin_init cycles race,
 *      only the first fires the redirect).
 *   3. Bulk-activation skip — WordPress sets ?activate-multi=true in the
 *      Referer when activating multiple plugins in one action. Skip so we
 *      don't steal focus from the operator's multi-plugin flow.
 *   4. Network-activation skip — if we're inside network-wide activation
 *      on multisite, no single activating user to redirect. Skip.
 *   5. Capability gate — only redirect if the current user can manage_options.
 *      Fail-closed (skip) rather than bounce to permission-denied.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/QuickConnect
 * @since      0.2.11
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\QuickConnect;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot activation → wizard redirect.
 *
 * @since 0.2.11
 */
final class ActivationRedirect {

	/**
	 * Transient key holding the redirect signal. MUST match the key set inside
	 * `acrossai_mcp_manager_activate()` (T014).
	 */
	private const REDIRECT_TRANSIENT = 'acrossai_mcp_manager_quick_connect_do_redirect';

	/**
	 * Singleton instance.
	 *
	 * @since 0.2.11
	 * @var self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Singleton accessor.
	 *
	 * @since 0.2.11
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor enforces singleton pattern (S6).
	 *
	 * @since 0.2.11
	 */
	private function __construct() {}

	/**
	 * `admin_init` callback (priority 5). Fires the redirect if all guards pass.
	 *
	 * @since 0.2.11
	 * @return void
	 */
	public function maybe_redirect(): void {
		if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return;
		}

		// Delete first — idempotency + prevents double-redirect on race.
		delete_transient( self::REDIRECT_TRANSIENT );

		// Bulk-activation guard (multiple plugins activated in one action).
		// WordPress passes `?activate-multi=true` on the plugins.php redirect
		// after bulk-activate.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['activate-multi'] ) ) {
			return;
		}

		// Network-activation guard (multisite network-wide plugin activation).
		if ( function_exists( 'is_network_admin' ) && is_network_admin() ) {
			return;
		}

		// Capability gate — fail-closed if the activating user can't
		// manage_options. Better to skip the redirect than to bounce them
		// to a permission-denied screen.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$target = admin_url( 'admin.php?page=acrossai_mcp_manager&quick-connect=1&step=1&server=1' );
		wp_safe_redirect( $target );
		exit;
	}
}
