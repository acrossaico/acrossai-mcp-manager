<?php
/**
 * REST endpoints for the Quick Connect onboarding wizard.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities/Rest
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Rest;

use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Protected_Abilities;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Mcp_Transport_Detector;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Serves wizard state and installs the recommended transport.
 *
 * Two routes only. The sibling wizard persists progress in a per-user scratchpad
 * transient and exposes /step and /complete; none of that is ported here because
 * screens 1-4 are read-only and the transport choice lives in the URL. No
 * server-side wizard state means nothing to clean up on uninstall (FR-015).
 *
 * Registers no WordPress hooks itself — the orchestrator calls
 * {@see self::register_routes()} and only the orchestrator is wired in Main.php
 * (Constitution REST Controller Pattern).
 *
 * @since 0.0.34
 */
class AcrossAI_Quick_Connect_Controller {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.34
	 * @var   self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- plugin-wide singleton convention.

	/**
	 * Route prefix under the shared namespace.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const REST_BASE = 'quick-connect';

	/**
	 * Plugin slugs this endpoint may install.
	 *
	 * Exactly one entry, on purpose. Membership is tested with strict
	 * comparison — a loose `in_array()` would let type coercion widen the
	 * allowlist (SEC-04).
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	private const INSTALLABLE_SLUGS = array( 'acrossai-mcp-manager' );

	/**
	 * Transient holding the in-flight install lock.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	private const INSTALL_LOCK_TRANSIENT = 'acrossai_quick_connect_install_lock';

	/**
	 * Lock lifetime, in seconds.
	 *
	 * Long enough to cover a slow download and unzip on a modest host, short
	 * enough that a request killed mid-install (timeout, fatal, closed
	 * connection) does not leave the endpoint bolted shut. The lock is released
	 * explicitly on every normal path; this TTL only matters when the process
	 * dies without unwinding, and there the correct outcome is a short wait
	 * rather than an administrator who can never retry.
	 *
	 * @since 0.0.34
	 * @var   int
	 */
	private const INSTALL_LOCK_TTL = 120;

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
	 * Register the wizard routes.
	 *
	 * Called by the module orchestrator, never hooked directly.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( AcrossAI_Abilities_Rest_Controller::instance(), 'check_permission' );

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/state',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_state' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/install-plugin',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'install_plugin' ),
					'permission_callback' => array( $this, 'check_install_permission' ),
					'args'                => array(
						'slug' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback for the install route.
	 *
	 * Stricter than the shared callback: installing and activating a plugin is
	 * code execution, so both capabilities are required in addition to the nonce.
	 *
	 * MUST return only true, false, or WP_Error. Returning a WP_REST_Response
	 * here would be a critical defect — it is truthy, so WordPress would grant
	 * access regardless of the status code inside it.
	 *
	 * @since  0.0.34
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public function check_install_permission( \WP_REST_Request $request ) {
		$shared = AcrossAI_Abilities_Rest_Controller::instance()->check_permission( $request );

		if ( true !== $shared ) {
			return $shared;
		}

		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to install plugins on this site.', 'acrossai-abilities-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET /quick-connect/state
	 *
	 * Everything the wizard needs to render every screen, in one request.
	 *
	 * @since  0.0.34
	 * @return \WP_REST_Response Wizard state.
	 */
	public function get_state(): \WP_REST_Response {
		$plugins = AcrossAI_Mcp_Transport_Detector::detect_all();

		return rest_ensure_response(
			array(
				'abilities'  => array(
					'total'     => $this->count_abilities(),
					'tabGroups' => $this->tab_groups(),
				),
				'plugins'    => array(
					'mcpManager'          => $plugins[ AcrossAI_Mcp_Transport_Detector::TRANSPORT_MCP_MANAGER ],
					'mcpAdapter'          => $plugins[ AcrossAI_Mcp_Transport_Detector::TRANSPORT_MCP_ADAPTER ],
					// Not something the wizard installs — it arrives with Elementor and is switched
					// on at Elementor's own screen. Reported so the step can say the abilities are
					// already reachable there rather than implying two destinations exist.
					'elementorMcp'        => $plugins[ AcrossAI_Mcp_Transport_Detector::TRANSPORT_ELEMENTOR_MCP ],
					'elementorMcpUrl'     => esc_url_raw( admin_url( 'admin.php?page=elementor-mcp' ) ),
					'mcpManagerWizardUrl' => esc_url_raw(
						admin_url( 'admin.php?page=acrossai_mcp_manager&quick-connect=1&step=1&server=1' )
					),
				),
				// SC-009: the UI must not offer an action the caller cannot perform.
				'canInstall' => current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' ),
			)
		);
	}

	/**
	 * Count abilities available on this site.
	 *
	 * Excludes the protected mcp-adapter slugs so the figure matches what the
	 * plugin's own abilities screen reports (spec SC-004).
	 *
	 * @since  0.0.34
	 * @return int Ability count; zero is a legitimate result.
	 */
	private function count_abilities(): int {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return 0;
		}

		$total = 0;

		foreach ( array_keys( (array) wp_get_abilities() ) as $slug ) {
			if ( ! AcrossAI_Protected_Abilities::is_protected( (string) $slug ) ) {
				++$total;
			}
		}

		return $total;
	}

	/**
	 * Integration groups with per-group ability counts.
	 *
	 * Read through the Library module's published filter rather than by calling
	 * its Registry: this controller lives in the Abilities module, and Module
	 * Contract #3 forbids sibling-module reach-through.
	 *
	 * @since  0.0.34
	 * @return array<int, array{key: string, label: string, count: int}> Tab groups.
	 */
	private function tab_groups(): array {
		$groups = (array) apply_filters( 'acrossai_ability_library_tab_group_summary', array() );
		$clean  = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || empty( $group['key'] ) ) {
				continue;
			}

			$clean[] = array(
				'key'   => (string) $group['key'],
				'label' => (string) ( $group['label'] ?? '' ),
				'count' => (int) ( $group['count'] ?? 0 ),
			);
		}

		return $clean;
	}

	/**
	 * POST /quick-connect/install-plugin
	 *
	 * Installs and activates the recommended transport without the administrator
	 * leaving the wizard. Idempotent: an already-installed-and-active plugin
	 * returns success unchanged.
	 *
	 * Error hygiene: raw upgrader and API messages go to the error log only.
	 * Client-facing strings are hand-authored and contain no filesystem paths
	 * or vendor strings (FR-030).
	 *
	 * @since  0.0.34
	 * @param  \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error Install result or a sanitized error.
	 */
	public function install_plugin( \WP_REST_Request $request ) {
		$slug = sanitize_key( (string) $request->get_param( 'slug' ) );

		// Validated before the lock is taken: a rejected slug does no work, so
		// holding the endpoint shut over it would be pure denial of service.
		if ( ! in_array( $slug, self::INSTALLABLE_SLUGS, true ) ) {
			return new \WP_Error(
				'acrossai_quick_connect_invalid_plugin',
				__( 'That plugin cannot be installed from here.', 'acrossai-abilities-manager' ),
				array( 'status' => 400 )
			);
		}

		// SEC-006 / FR-031. The wizard already prevents a second click, but that
		// rule lived only in the browser: a direct caller could fire the endpoint
		// repeatedly and set several downloads and unzips running over the same
		// directory. Requires install_plugins, so the actor is privileged and the
		// damage is self-inflicted — which is why this is a guard rather than a
		// throttle, and why it answers 409 instead of failing quietly.
		if ( ! $this->acquire_install_lock() ) {
			return new \WP_Error(
				'acrossai_quick_connect_install_in_progress',
				__( 'An installation is already running. Wait for it to finish before trying again.', 'acrossai-abilities-manager' ),
				array( 'status' => 409 )
			);
		}

		try {
			return $this->run_install( $slug );
		} finally {
			// finally, not a release before each return: this method has several
			// exit paths and can also throw out of the upgrader. A lock that
			// leaks on one uncommon path is worse than no lock, because the
			// endpoint then stays shut until the TTL expires.
			$this->release_install_lock();
		}
	}

	/**
	 * Perform the install, assuming the lock is held.
	 *
	 * @since  0.0.34
	 * @param  string $slug Validated plugin slug.
	 * @return \WP_REST_Response|\WP_Error Install result or a sanitized error.
	 */
	private function run_install( string $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$expected_basename = $slug . '/' . $slug . '.php';
		$installed         = get_plugins();

		if ( ! isset( $installed[ $expected_basename ] ) ) {
			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array( 'sections' => false ),
				)
			);

			if ( is_wp_error( $api ) ) {
				$this->log_failure( 'plugins_api', $slug, $api->get_error_message() );

				return new \WP_Error(
					'acrossai_quick_connect_install_failed',
					__( 'Could not find that plugin on WordPress.org. Try installing it manually from Plugins → Add New.', 'acrossai-abilities-manager' ),
					array( 'status' => 502 )
				);
			}

			$upgrader = new \Plugin_Upgrader( new \WP_Ajax_Upgrader_Skin() );
			$result   = $upgrader->install( $api->download_link );

			if ( is_wp_error( $result ) || false === $result || null === $result ) {
				$message = is_wp_error( $result ) ? $result->get_error_message() : 'installer returned false';
				$this->log_failure( 'Plugin_Upgrader::install', $slug, $message );

				return new \WP_Error(
					'acrossai_quick_connect_install_failed',
					__( 'Installation failed. Try installing manually from Plugins → Add New.', 'acrossai-abilities-manager' ),
					array( 'status' => 500 )
				);
			}
		}

		if ( ! is_plugin_active( $expected_basename ) ) {
			$activated = activate_plugin( $expected_basename );

			if ( is_wp_error( $activated ) ) {
				$this->log_failure( 'activate_plugin', $expected_basename, $activated->get_error_message() );

				return new \WP_Error(
					'acrossai_quick_connect_activate_failed',
					__( 'Activation failed. Try activating from Plugins.', 'acrossai-abilities-manager' ),
					array( 'status' => 500 )
				);
			}
		}

		// Confirm what actually became active. plugins_api results and upgrader
		// options are filterable by other plugins, so a redirected package could
		// otherwise be reported as the plugin we promised.
		if ( ! is_plugin_active( $expected_basename ) ) {
			$this->log_failure( 'post_activation_assertion', $expected_basename, 'expected plugin is not active after install' );

			return new \WP_Error(
				'acrossai_quick_connect_activate_failed',
				__( 'The plugin was processed but is not active. Check the Plugins screen.', 'acrossai-abilities-manager' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'installed' => true,
				'active'    => true,
				'plugin'    => $expected_basename,
			)
		);
	}

	/**
	 * Take the in-flight install lock.
	 *
	 * A transient rather than a true mutex. WordPress has no atomic
	 * check-and-set that holds with and without an external object cache, so a
	 * sufficiently precise pair of simultaneous requests can still both pass.
	 * That is an accepted limit: this closes the realistic case — an
	 * administrator double-submitting, or a script looping the endpoint — and
	 * the residual race needs two requests inside the same few milliseconds by
	 * an actor who already holds install_plugins.
	 *
	 * @since  0.0.34
	 * @return bool True when the lock was taken, false when one is already held.
	 */
	private function acquire_install_lock(): bool {
		if ( false !== get_transient( self::INSTALL_LOCK_TRANSIENT ) ) {
			return false;
		}

		set_transient( self::INSTALL_LOCK_TRANSIENT, time(), self::INSTALL_LOCK_TTL );

		return true;
	}

	/**
	 * Release the in-flight install lock.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function release_install_lock(): void {
		delete_transient( self::INSTALL_LOCK_TRANSIENT );
	}

	/**
	 * Record an install failure for site operators.
	 *
	 * Kept out of the HTTP response deliberately: upgrader output routinely
	 * contains filesystem paths. Gated on WP_DEBUG_LOG so a misconfigured host
	 * with a web-readable debug.log does not turn diagnostics into disclosure.
	 *
	 * @since  0.0.34
	 * @param  string $stage   Which step failed.
	 * @param  string $subject Slug or basename involved.
	 * @param  string $message Raw underlying message.
	 * @return void
	 */
	private function log_failure( string $stage, string $subject, string $message ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics; gated on WP_DEBUG_LOG and never returned to the client.
			sprintf(
				'[acrossai-abilities-manager] quick-connect %1$s failed for %2$s: %3$s',
				$stage,
				$subject,
				$message
			)
		);
	}
}
