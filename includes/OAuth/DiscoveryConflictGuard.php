<?php
/**
 * Guards the site-wide OAuth discovery documents against competing
 * implementations bundled by other plugins.
 *
 * `wp-media/mcp-oauth` ships inside several unrelated plugins (Rank Math SEO,
 * Enable Abilities for MCP, …) and boots itself with no opt-in. It registers
 * `add_rewrite_rule( '^\.well-known/oauth-protected-resource$', …, 'top' )` and
 * serves a document whose `resource` is hardcoded to its own private server:
 *
 *     {"resource":"https://site/wp-json/mcp/mcp-oauth-server", …}
 *
 * Because both implementations claim the same `.well-known` paths and both
 * register on `init`, whichever added its rule first wins for the whole site.
 * When the bundled copy wins, MCP hosts get an authorization-server document
 * with no `registration_endpoint` (so Dynamic Client Registration is
 * impossible) and a protected-resource document naming a server the operator
 * never created — every AcrossAI connector then fails at the OAuth step while
 * a manually-tokened mcp.json client keeps working.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

final class DiscoveryConflictGuard {

	/**
	 * Bootstrap class shipped by every `wp-media/mcp-oauth` copy.
	 */
	private const WPMEDIA_BOOTSTRAP = '\\WPMedia\\MCP\\OAuth\\Bootstrap';

	/** @var DiscoveryConflictGuard|null */
	private static $instance = null;

	/**
	 * Private constructor enforces singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Stand down the competing implementation so this plugin owns discovery.
	 *
	 * Wired on `plugins_loaded` — early enough, because the bundled library
	 * only calls `Context::is_enabled()` from its `init`/`mcp_adapter_init`
	 * callbacks, never while binding them.
	 *
	 * Operators who would rather keep the other implementation can opt out:
	 *
	 *     add_filter( 'acrossai_mcp_manager_take_over_oauth_discovery', '__return_false' );
	 *
	 * @return void
	 */
	public function take_over_discovery(): void {
		if ( ! self::conflict_detected() ) {
			return;
		}

		/**
		 * Filters whether this plugin claims the site's OAuth discovery documents
		 * when another plugin bundles a competing MCP OAuth server.
		 *
		 * @param bool $take_over Default true.
		 * @since 0.9.12
		 */
		if ( ! (bool) apply_filters( 'acrossai_mcp_manager_take_over_oauth_discovery', true ) ) {
			return;
		}

		// Highest priority so we settle the value last, whatever the other
		// plugin or the site has already filtered it to.
		add_filter( 'wpmedia_mcp_oauth_server_enabled', '__return_false', PHP_INT_MAX );
		add_filter( 'rocket_mcp_oauth_server_enabled', '__return_false', PHP_INT_MAX );
	}

	/**
	 * Is a competing `wp-media/mcp-oauth` copy loaded on this site?
	 *
	 * @return bool
	 */
	public static function conflict_detected(): bool {
		return class_exists( self::WPMEDIA_BOOTSTRAP );
	}

	/**
	 * Which active plugins bundle the competing library?
	 *
	 * Reflection on the loaded class gives the real file path, so this reports
	 * the actual source rather than a hardcoded list of known bundlers.
	 *
	 * @return string Human-readable plugin name, or '' when it cannot be resolved.
	 */
	public static function conflicting_plugin_name(): string {
		if ( ! self::conflict_detected() ) {
			return '';
		}

		try {
			$reflection = new \ReflectionClass( self::WPMEDIA_BOOTSTRAP );
			$file       = (string) $reflection->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}

		if ( '' === $file || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return '';
		}

		$relative = ltrim( str_replace( wp_normalize_path( WP_PLUGIN_DIR ), '', wp_normalize_path( $file ) ), '/' );
		$slug     = strtok( $relative, '/' );

		if ( false === $slug || '' === $slug ) {
			return '';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			if ( 0 === strpos( $plugin_file, $slug . '/' ) && ! empty( $plugin_data['Name'] ) ) {
				return (string) $plugin_data['Name'];
			}
		}

		return $slug;
	}
}
