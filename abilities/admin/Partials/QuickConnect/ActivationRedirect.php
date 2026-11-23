<?php
/**
 * One-shot redirect into the Quick Connect wizard after activation.
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
 * Consumes the activation flag and opens the wizard once.
 *
 * Hijacking an administrator's screen is intrusive, so this runs a deliberate
 * sequence of guards and bails at the first one that fails. Two of them are
 * easy to get wrong and are worth stating plainly:
 *
 * - The transient is deleted BEFORE anything else is evaluated. If a later
 *   guard bails, or the redirect itself fails, the flag is already gone, so the
 *   wizard can never trap the operator in a loop.
 * - Only an *active* recommended transport suppresses the redirect. A site
 *   running only the alternative transport still gets onboarded (spec FR-002a),
 *   which was an explicit product decision rather than an oversight — and a site
 *   with the recommended transport merely installed but switched off gets
 *   onboarded too, because nothing is connected in that state.
 *
 * @since 0.0.34
 */
class ActivationRedirect {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.34
	 * @var   self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- plugin-wide singleton convention.

	/**
	 * Transient set at activation.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const REDIRECT_TRANSIENT = 'acrossai_abilities_quick_connect_do_redirect';

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.0.34
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Private constructor — use instance().
	 *
	 * @since 0.0.34
	 */
	private function __construct() {}

	/**
	 * Open the wizard once, if every guard passes.
	 *
	 * Hooked to `admin_init` at priority 5.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public function maybe_redirect(): void {
		if ( ! $this->has_pending_redirect() ) {
			return;
		}

		// Consume the flag first — see the class docblock. Everything after this
		// point may bail freely without risking a repeat.
		delete_transient( self::REDIRECT_TRANSIENT );

		if ( ! $this->should_redirect() ) {
			return;
		}

		wp_safe_redirect( $this->wizard_url() );
		exit;
	}

	/**
	 * Whether an activation flag is waiting.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	private function has_pending_redirect(): bool {
		return (bool) get_transient( self::REDIRECT_TRANSIENT );
	}

	/**
	 * Evaluate every condition that must hold before hijacking the screen.
	 *
	 * Extracted from maybe_redirect() so the guard matrix is testable without
	 * triggering a real redirect.
	 *
	 * @since  0.0.34
	 * @return bool True when the wizard should open.
	 */
	public function should_redirect(): bool {
		// Bulk activation: the operator is mid-task on the Plugins screen and
		// did not ask for this plugin specifically.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading WordPress's own bulk-activation marker, no state change.
		if ( ! empty( $_GET['activate-multi'] ) ) {
			return false;
		}

		// Network activation is out of scope: the wizard configures a single
		// site, and a network admin is not necessarily configuring any one of
		// them (documented single-site scope in the spec's Assumptions).
		if ( function_exists( 'is_network_admin' ) && is_network_admin() ) {
			return false;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Only an ACTIVE recommended transport suppresses onboarding, because
		// only an active one is actually connecting anything (spec FR-002:
		// "already installed and active").
		//
		// An installed-but-inactive copy must NOT suppress it. That site's
		// abilities are unreachable, so its administrator needs the wizard more
		// than most — and the transport screen handles them precisely, offering
		// "Continue - Activate the plugin" as a one-click fix. Treating merely
		// installed as "already sorted" hid the wizard from exactly the people
		// it helps most.
		$mcp_manager = AcrossAI_Mcp_Transport_Detector::detect(
			AcrossAI_Mcp_Transport_Detector::TRANSPORT_MCP_MANAGER
		);

		if ( AcrossAI_Mcp_Transport_Detector::STATE_ACTIVE === $mcp_manager ) {
			return false;
		}

		return true;
	}

	/**
	 * Destination for the redirect.
	 *
	 * @since  0.0.34
	 * @return string Admin URL for step 1 of the wizard.
	 */
	public function wizard_url(): string {
		return admin_url(
			'admin.php?page=acrossai-abilities-manager&' . QuickConnectPage::QUERY_ARG . '=1&step=1'
		);
	}
}
