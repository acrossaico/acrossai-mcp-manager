<?php
/**
 * Detects which MCP transport plugins are present on the site.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Reports the presence of the two supported MCP transports.
 *
 * Abilities registered by this plugin do nothing until a transport exposes them to an AI client.
 * Three are reported, and the question each answers is the same: can the operator reach this site's
 * abilities through it, and if not, what is the next action.
 *
 * - **AcrossAI MCP Manager** and the **WordPress MCP Adapter** are plugins, so the plugin file is
 *   authoritative for both. The adapter was once detected by probing for its class, on the
 *   reasoning that it is often vendored inside another plugin — but a vendored copy is not an
 *   install an operator can activate, and since Elementor began shipping one the probe reported
 *   "already active" on sites where the plugin was switched off. See `detect_mcp_adapter()`.
 * - **Elementor MCP** is not installed for us at all: it arrives with Elementor and is switched on
 *   at Elementor's own screen, so it has no "install" state worth reporting, only on or off.
 *
 * @since 0.0.34
 */
class AcrossAI_Mcp_Transport_Detector {

	/**
	 * Identifier for the recommended transport.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TRANSPORT_MCP_MANAGER = 'mcp-manager';

	/**
	 * Identifier for the alternative transport.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TRANSPORT_MCP_ADAPTER = 'mcp-adapter';

	/**
	 * Identifier for Elementor's own MCP server.
	 *
	 * Unlike the other two this is not a transport an operator installs for us — it arrives with
	 * Elementor and is switched on at Elementor's own screen. It is listed here because the
	 * question this class answers is "where can this site's abilities already be reached from",
	 * and since 0.0.42 that includes Elementor's server.
	 *
	 * @since 0.0.42
	 * @var   string
	 */
	public const TRANSPORT_ELEMENTOR_MCP = 'elementor-mcp';

	/**
	 * Plugin basename of the recommended transport.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const MCP_MANAGER_BASENAME = 'acrossai-mcp-manager/acrossai-mcp-manager.php';

	/**
	 * State returned when the transport is not installed at all.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const STATE_MISSING = 'missing';

	/**
	 * State returned when the transport is installed but not active.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const STATE_INACTIVE = 'inactive';

	/**
	 * State returned when the transport is available and running.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const STATE_ACTIVE = 'active';

	/**
	 * Detect the state of a supported transport.
	 *
	 * @since  0.0.34
	 * @param  string $transport One of the TRANSPORT_* constants.
	 * @return string One of the STATE_* constants. Unknown transports report missing.
	 */
	public static function detect( string $transport ): string {
		if ( self::TRANSPORT_MCP_MANAGER === $transport ) {
			return self::detect_mcp_manager();
		}

		if ( self::TRANSPORT_MCP_ADAPTER === $transport ) {
			return self::detect_mcp_adapter();
		}

		if ( self::TRANSPORT_ELEMENTOR_MCP === $transport ) {
			return self::detect_elementor_mcp();
		}

		return self::STATE_MISSING;
	}

	/**
	 * Detect Elementor's own MCP server.
	 *
	 * Three states, and the middle one is the reason this is not a boolean. Elementor ships its
	 * MCP module switched off and puts the switch on its own screen, so "Elementor is here but its
	 * server is not running" is a real and common position — and it is the one an operator can fix
	 * in a click, which is worth saying rather than reporting it as absent.
	 *
	 * Checks the module rather than just `\Elementor\Plugin`: an Elementor old enough to predate
	 * `modules/mcp/` has no server to reach, and reporting one would be wrong.
	 *
	 * @since  0.0.42
	 * @return string One of the STATE_* constants.
	 */
	private static function detect_elementor_mcp(): string {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! class_exists( '\Elementor\Modules\Mcp\Module' ) ) {
			return self::STATE_MISSING;
		}

		// Asking Elementor rather than reading its option: the option is absent by default and the
		// answer also depends on our own integration switch, which filters the read.
		$enabled = is_callable( array( '\Elementor\Modules\Mcp\Module', 'is_site_mcp_exposure_enabled' ) )
			&& \Elementor\Modules\Mcp\Module::is_site_mcp_exposure_enabled();

		return $enabled ? self::STATE_ACTIVE : self::STATE_INACTIVE;
	}

	/**
	 * Detect every supported transport at once.
	 *
	 * @since  0.0.34
	 * @return array<string, string> Transport identifier => STATE_* constant.
	 */
	public static function detect_all(): array {
		return array(
			self::TRANSPORT_MCP_MANAGER   => self::detect( self::TRANSPORT_MCP_MANAGER ),
			self::TRANSPORT_MCP_ADAPTER   => self::detect( self::TRANSPORT_MCP_ADAPTER ),
			self::TRANSPORT_ELEMENTOR_MCP => self::detect( self::TRANSPORT_ELEMENTOR_MCP ),
		);
	}

	/**
	 * Detect the recommended transport by plugin file.
	 *
	 * @since  0.0.34
	 * @return string One of the STATE_* constants.
	 */
	private static function detect_mcp_manager(): string {
		self::require_plugin_api();

		if ( is_plugin_active( self::MCP_MANAGER_BASENAME ) ) {
			return self::STATE_ACTIVE;
		}

		if ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( self::MCP_MANAGER_BASENAME ) ) {
			return self::STATE_ACTIVE;
		}

		$installed = function_exists( 'get_plugins' ) ? get_plugins() : array();

		return array_key_exists( self::MCP_MANAGER_BASENAME, $installed )
			? self::STATE_INACTIVE
			: self::STATE_MISSING;
	}

	/**
	 * Detect the standalone MCP Adapter plugin.
	 *
	 * The plugin file is authoritative. A loaded `WP\MCP\Core\*` class is NOT evidence of it —
	 * see the note in the body for what that cost.
	 *
	 * @since  0.0.34
	 * @return string One of the STATE_* constants.
	 */
	private static function detect_mcp_adapter(): string {
		self::require_plugin_api();

		$installed = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$found     = false;

		foreach ( self::mcp_adapter_basename_candidates() as $basename ) {
			if ( ! array_key_exists( $basename, $installed ) ) {
				continue;
			}

			$found = true;

			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $basename ) ) {
				return self::STATE_ACTIVE;
			}
		}

		if ( $found ) {
			return self::STATE_INACTIVE;
		}

		/*
		 * The adapter LIBRARY may still be loaded at this point, and until 0.0.42 that was read as
		 * "active". It is not the same thing, and the difference is now common rather than
		 * theoretical: Elementor ships `wordpress/mcp-adapter` inside its own vendor directory, as
		 * does AcrossAI MCP Manager. On this site `WP\MCP\Core\McpAdapter` resolved out of
		 * Elementor while `mcp-adapter/mcp-adapter.php` sat installed and switched off — and the
		 * Quick Connect card happily reported "Already active" for a plugin the operator had never
		 * turned on, hiding the Activate button that would have fixed it.
		 *
		 * So the plugin is the authority here, because the plugin is what this answer is about: the
		 * card offers to install or activate one, and neither action exists for a library vendored
		 * inside somebody else's plugin.
		 */
		return self::STATE_MISSING;
	}

	/**
	 * Plugin basenames that may hold a standalone MCP Adapter install.
	 *
	 * @since  0.0.34
	 * @return string[] Plugin basenames, filterable.
	 */
	private static function mcp_adapter_basename_candidates(): array {
		$candidates = array(
			'mcp-adapter/mcp-adapter.php',
			'wordpress-mcp-adapter/mcp-adapter.php',
		);

		/**
		 * Filters the plugin basenames checked for a standalone MCP Adapter.
		 *
		 * @since 0.0.34
		 * @param string[] $candidates Plugin basenames.
		 */
		return (array) apply_filters( 'acrossai_mcp_adapter_basename_candidates', $candidates );
	}

	/**
	 * Ensure the WordPress plugin API is loaded.
	 *
	 * Detection runs on front-end and REST requests too, where
	 * wp-admin/includes/plugin.php is not loaded by default.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private static function require_plugin_api(): void {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
