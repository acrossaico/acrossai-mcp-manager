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
			// F090 — a refused enable MUST say what is missing (FR-015).
			// Silently doing nothing is the failure mode this feature exists to
			// remove, so the refusal gets its own notice rather than sharing a
			// generic one.
			'type_unavailable' => array(
				'error',
				__( 'That server could not be enabled: its server type requires the AcrossAI Abilities Manager plugin to be installed and activated. Install it, or change the server type on the Tools tab.', 'acrossai-mcp-manager' ),
			),
			'bulk_partial'     => array(
				'warning',
				__( 'Some servers were skipped: their server type requires the AcrossAI Abilities Manager plugin to be installed and activated.', 'acrossai-mcp-manager' ),
			),
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

		// ── F095: OAuth operational warnings ────────────────────────────────
		// Ported from the companion. Each is a soft warning about a hosting
		// condition that silently degrades OAuth rather than breaking it
		// loudly — which is exactly the kind of failure an operator will not
		// otherwise connect back to this plugin.

		if ( ! is_ssl() && ! ( defined( 'FORCE_SSL_ADMIN' ) && \FORCE_SSL_ADMIN ) ) {
			$notices[] = array(
				'id'      => 'acrossai_mcp_manager_oauth_https_missing',
				'title'   => __( 'HTTPS is not configured', 'acrossai-mcp-manager' ),
				'message' => __( 'OAuth tokens will be issued over plaintext HTTP — passive network attackers can intercept them. Configure SSL / FORCE_SSL_ADMIN before exposing the OAuth endpoints to production traffic.', 'acrossai-mcp-manager' ),
				'type'    => 'warning',
				'source'  => __( 'Connectors/Integrations', 'acrossai-mcp-manager' ),
			);
		}

		if ( defined( 'DISABLE_WP_CRON' ) && true === \DISABLE_WP_CRON ) {
			$notices[] = array(
				'id'      => 'acrossai_mcp_manager_wp_cron_disabled',
				'title'   => __( 'WP-Cron is disabled', 'acrossai-mcp-manager' ),
				'message' => __( 'DISABLE_WP_CRON=true prevents the daily OAuth cleanup from running automatically — expired access tokens and auth codes will accumulate. Either remove the DISABLE_WP_CRON constant, or configure a real cron entry to run <code>wp cron event run acrossai_mcp_manager_oauth_cleanup</code> daily.', 'acrossai-mcp-manager' ),
				'type'    => 'warning',
				'source'  => __( 'Connectors/Integrations', 'acrossai-mcp-manager' ),
			);
		}

		// Wordfence per-IP throttling silently rate-limits bursty MCP polling
		// and OAuth token exchanges. Any of the three knobs set to something
		// other than DISABLED is enough.
		if ( class_exists( '\\wfConfig' ) ) {
			foreach ( array( 'maxGlobalRequests', 'maxRequestsHumans', 'maxRequestsCrawlers' ) as $wf_key ) {
				$wf_val = \wfConfig::get( $wf_key, 'DISABLED' );
				if ( 'DISABLED' !== $wf_val && '' !== $wf_val ) {
					$notices[] = array(
						'id'      => 'acrossai_mcp_manager_wordfence_rate_limit_enabled',
						'title'   => __( 'Wordfence rate limiting may throttle Connectors traffic', 'acrossai-mcp-manager' ),
						'message' => __( 'Wordfence per-IP rate limiting is enabled and can silently throttle bursty MCP polling from AI assistants plus OAuth token exchanges. Add <code>/wp-json/mcp/*</code> and <code>/wp-json/acrossai-mcp-manager/v1/oauth/*</code> under <strong>Wordfence → Firewall → Rate Limiting → Whitelisted URLs</strong>. Dismiss this notice once configured.', 'acrossai-mcp-manager' ),
						'type'    => 'warning',
						'source'  => __( 'Connectors/Integrations', 'acrossai-mcp-manager' ),
					);
					break;
				}
			}
		}

		// Full-page caches serve stale OAuth responses, which carry
		// per-request nonces, client_ids and bearer tokens. CacheHeaders sends
		// no-store on those responses and most caches respect it; excluding
		// the paths outright is the belt-and-braces posture. WP_CACHE is the
		// reliable signal — core only loads advanced-cache.php when it is
		// truthy, so every plugin-based cache sets it. Edge and host caches
		// cannot be detected this way and are covered in the docs instead.
		if ( defined( 'WP_CACHE' ) && true === \WP_CACHE ) {
			$notices[] = array(
				'id'      => 'acrossai_mcp_manager_page_cache_exclusions_required',
				'title'   => __( 'Page cache detected — exclude OAuth + REST URLs', 'acrossai-mcp-manager' ),
				'message' => __( 'A full-page cache is enabled on this site (<code>WP_CACHE=true</code>). MCP and OAuth responses carry per-request state (nonces, client_ids, bearer tokens) and MUST NOT be served from cache. Add <code>/wp-json/*</code> and <code>/.well-known/*</code> to your cache plugin\'s do-not-cache list. Dismiss this notice once configured.', 'acrossai-mcp-manager' ),
				'type'    => 'warning',
				'source'  => __( 'Connectors/Integrations', 'acrossai-mcp-manager' ),
			);
		}

		// A competing OAuth discovery implementation claims the same
		// .well-known URLs. DiscoveryConflictGuard disables it; this explains
		// why, because the symptom otherwise looks like our bug.
		if ( \AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryConflictGuard::conflict_detected() ) {
			$conflict_source = \AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryConflictGuard::conflicting_plugin_name();
			if ( '' === $conflict_source ) {
				$conflict_source = __( 'Another active plugin', 'acrossai-mcp-manager' );
			}

			$notices[] = array(
				'id'      => 'acrossai_mcp_manager_oauth_discovery_conflict',
				'title'   => __( 'Another plugin also serves OAuth discovery', 'acrossai-mcp-manager' ),
				'message' => sprintf(
					/* translators: %s: name of the conflicting plugin. */
					__( '<strong>%s</strong> bundles its own MCP OAuth server, which claims the same <code>/.well-known/oauth-protected-resource</code> and <code>/.well-known/oauth-authorization-server</code> URLs. Its documents advertise a single hardcoded server and no <code>registration_endpoint</code>, so AI assistants could not register against your MCP servers. This plugin has taken discovery back. To opt out, add <code>add_filter( \'acrossai_mcp_manager_take_over_oauth_discovery\', \'__return_false\' );</code>.', 'acrossai-mcp-manager' ),
					$conflict_source
				),
				'type'    => 'warning',
				'source'  => __( 'Connectors/Integrations', 'acrossai-mcp-manager' ),
			);
		}

		// Firefox Enhanced Tracking Protection. Confirmed in the field
		// (salon.hvacb.com, 2026-09-11): adding a custom connector in Claude
		// failed repeatedly in Firefox and succeeded immediately once
		// protections were switched off for the site. ETP partitions and
		// blocks cross-site state, and the connector handshake crosses
		// between the assistant's origin and this one, so the sign-in can be
		// dropped part-way with no error either side can show.
		//
		// Registered only for the browser affected, and keyed off the request
		// rather than anything stored: the same administrator switching to
		// Chrome should not keep seeing it. The shared renderer dismisses by
		// a fingerprint of the registered ids, so a Firefox-only entry never
		// disturbs what another browser's session has already dismissed.
		//
		// Adopted from acrossai-pro 0.9.16 when the connector stack moved
		// here: the condition it reports is a property of connecting an
		// assistant, which is this plugin's feature now.
		if ( self::is_firefox() ) {
			$notices[] = array(
				'id'      => 'acrossai_mcp_manager_firefox_tracking_protection',
				'title'   => __( 'Firefox tracking protection can block AI assistants from connecting', 'acrossai-mcp-manager' ),
				'message' => __( "You are viewing this page in Firefox. Its <strong>Enhanced Tracking Protection</strong> can stop an AI assistant part-way through connecting to this site — the connector is added, sign-in opens, and then nothing completes, usually with no error to explain it.<br><br>If a connection fails: click the <strong>shield icon</strong> to the left of the address bar, then switch <strong>Enhanced Tracking Protection</strong> off for this site and connect again. Firefox remembers the choice per site and it does not affect any other site you visit. You can switch it back on afterwards — the protection only interferes while the connection is being set up, not once it is working.", 'acrossai-mcp-manager' ),
				'type'    => 'info',
				'source'  => __( 'Connectors/Integrations', 'acrossai-mcp-manager' ),
			);
		}

		return $notices;
	}

	/**
	 * Whether the CURRENT REQUEST comes from Firefox or a Firefox-derived
	 * browser.
	 *
	 * Deliberately a request-scoped check on the User-Agent rather than
	 * anything persisted. The condition being reported is a property of the
	 * browser reading the page, not of the site, so it has to be re-evaluated
	 * per request — the same administrator on Chrome must not see it.
	 *
	 * Matches Firefox on desktop and Android (`Firefox/`), Firefox on iOS
	 * (`FxiOS/`, a WebKit shell that still ships ETP), and the Gecko forks
	 * that inherit the same protection stack — LibreWolf and Waterfox both
	 * keep `Firefox/` in their User-Agent. SeaMonkey carries `Firefox/` in
	 * some builds without shipping ETP, so it is excluded.
	 *
	 * A spoofed or absent User-Agent simply means no notice. That is the
	 * right failure direction: this is advisory, it costs nothing to miss,
	 * and showing it to someone who is not in Firefox would be confusing.
	 *
	 * @param string|null $user_agent Override for tests. Defaults to the
	 *                                current request's User-Agent.
	 * @return bool
	 */
	public static function is_firefox( ?string $user_agent = null ): bool {
		if ( null === $user_agent ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only browser sniff for an advisory notice.
			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
				: '';
		}

		if ( '' === $user_agent ) {
			return false;
		}

		if ( false !== stripos( $user_agent, 'Seamonkey/' ) ) {
			return false;
		}

		return ( false !== stripos( $user_agent, 'Firefox/' ) )
			|| ( false !== stripos( $user_agent, 'FxiOS/' ) );
	}
}
