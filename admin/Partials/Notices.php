<?php
/**
 * Admin notice renderers — action-result notices + shared-collection filter.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

defined( 'ABSPATH' ) || exit;

/**
 * Centralised admin-notice handlers extracted from Settings (RT-2, 2026-06-17).
 *
 * Two responsibilities today:
 *
 *  1. FR-016 — render the one-shot success/error notice indicated by the
 *     `?notice=<slug>` query var set by `Settings::handle_actions()` after a
 *     form action redirect. This stays on the standard `admin_notices` hook
 *     because it's page-scoped and transient; the shared collection would be
 *     wrong for a "server saved" flash.
 *
 *  2. Push persistent-condition notices (missing MCP adapter, missing
 *     wpb-access-control library) into the cross-plugin `acrossai_notices`
 *     filter introduced in acrossai-co/main-menu 0.0.30. The vendor package
 *     handles rendering (Notices submenu + WP-native dismissible summary)
 *     and per-user fingerprint-based dismissal — this class only supplies
 *     the records.
 *
 * Constitution: singleton + private __construct + zero add_action/add_filter.
 * Hooks wired by Includes\Main::define_admin_hooks().
 */
class Notices {

	/** @var Notices|null */
	protected static $_instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor — enforces the plugin-wide singleton convention.
	 * No add_action / add_filter here; hooks are wired by
	 * Includes\Main::define_admin_hooks().
	 */
	private function __construct() {}

	// ─────────────────────────────────────────────────────────────────────────
	// FR-016 — Action-result notice from `?notice=...` query var.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render success / error notice based on the `notice` query var set by
	 * post-action redirects in Settings::handle_actions(). Wired on `admin_notices`.
	 */
	public function render_action_result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'server_created'   => array( 'success', __( 'Server created.', 'acrossai-mcp-manager' ) ),
			'server_saved'     => array( 'success', __( 'Server saved.', 'acrossai-mcp-manager' ) ),
			'server_deleted'   => array( 'success', __( 'Server deleted.', 'acrossai-mcp-manager' ) ),
			'server_toggled'   => array( 'success', __( 'Server status toggled.', 'acrossai-mcp-manager' ) ),
			'bulk_completed'   => array( 'success', __( 'Bulk action completed.', 'acrossai-mcp-manager' ) ),
			'slug_exists'      => array( 'error', __( 'Slug already in use.', 'acrossai-mcp-manager' ) ),
			'empty_name'       => array( 'error', __( 'Server name is required.', 'acrossai-mcp-manager' ) ),
			'db_error'         => array( 'error', __( 'Database write failed.', 'acrossai-mcp-manager' ) ),
			'server_not_found' => array( 'error', __( 'Server not found.', 'acrossai-mcp-manager' ) ),
			'server_protected' => array( 'error', __( 'This server is managed by the plugin and cannot be edited or deleted.', 'acrossai-mcp-manager' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/*
	 * Shared `acrossai_notices` filter — persistent conditions surfaced via
	 * the cross-plugin Notices submenu and dismissible summary bundled with
	 * acrossai-co/main-menu 0.0.30+.
	 *
	 * Filter contract:
	 *   id      — required, unique per registration (deduped first-wins)
	 *   title   — esc_html
	 *   message — wp_kses_post; inline HTML allowed
	 *   type    — error|warning|info|success (default warning)
	 *   source  — optional label shown on the notice card
	 *   action  — optional { label, url } CTA
	 *
	 * Timing rule: the vendor reads this filter on admin_menu priority 25 and
	 * memoizes the result, so the add_filter() call in Includes\Main runs at
	 * admin bootstrap time — well before the read window.
	 */

	/**
	 * Append MCP-Manager persistent notices to the shared collection.
	 *
	 * @param array<int, array<string, mixed>> $notices Notices accumulated by upstream consumers.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_shared_notices( array $notices ): array {
		if ( ! class_exists( '\WP\MCP\Plugin' ) ) {
			$notices[] = array(
				'id'      => 'acrossai_mcp_manager_adapter_missing',
				'title'   => __( 'WordPress MCP adapter missing', 'acrossai-mcp-manager' ),
				'message' => __( 'MCP servers will not respond until you install the wordpress/mcp-adapter package via composer.', 'acrossai-mcp-manager' ),
				'type'    => 'error',
				'source'  => __( 'MCP Manager', 'acrossai-mcp-manager' ),
			);
		}

		if ( ! class_exists( '\WPBoilerplate\AccessControl\AccessControlManager' ) ) {
			$notices[] = array(
				'id'      => 'acrossai_mcp_manager_wpb_access_control_missing',
				'title'   => __( 'Access control library missing', 'acrossai-mcp-manager' ),
				'message' => sprintf(
					/* translators: %s: library class name, wrapped in <code>. */
					__( 'The wpb-access-control library (%s) is not loaded. Per-server MCP access-control rules are inactive and all tool calls will pass (fail-open). Install or activate the library to enforce saved rules.', 'acrossai-mcp-manager' ),
					'<code>WPBoilerplate\\AccessControl\\AccessControlManager</code>'
				),
				'type'    => 'warning',
				'source'  => __( 'MCP Manager', 'acrossai-mcp-manager' ),
			);
		}

		if ( (bool) get_option( 'acrossai_mcp_npm_login_enabled', false ) ) {
			$auth_url  = \AcrossAI_MCP_Manager\Public\Partials\FrontendAuth::get_base_url();
			$notices[] = array(
				'id'      => 'acrossai_mcp_manager_cli_auth_cache_exclusion',
				'title'   => __( 'Exclude CLI auth URL from page cache', 'acrossai-mcp-manager' ),
				'message' => sprintf(
					/* translators: %s: the frontend CLI authorization URL, wrapped in <code>. */
					__( 'The npm / npx CLI connection flow is enabled. The frontend authorization page at %s contains time-sensitive auth codes and nonces. If your hosting, CDN, or caching plugin caches this URL, authentication will silently fail. Exclude this path from all page-caching rules.', 'acrossai-mcp-manager' ),
					'<code>' . esc_url( $auth_url ) . '</code>'
				),
				'type'    => 'warning',
				'source'  => __( 'MCP Manager', 'acrossai-mcp-manager' ),
			);
		}

		return $notices;
	}
}
