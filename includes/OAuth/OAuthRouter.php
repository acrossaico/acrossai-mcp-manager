<?php
/**
 * OAuth rewrite-rule router (Feature 021).
 *
 * Registers rewrite rules for the four domain-root OAuth endpoints and
 * dispatches parse_request to the appropriate Controller. Follows the
 * F007 FrontendAuth pattern — all wiring lives in Main.php per Principle A1;
 * this class only owns rule shape + dispatch.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

defined( 'ABSPATH' ) || exit;

final class OAuthRouter {

	private const QUERY_VAR = 'acrossai_mcp_oauth';

	/**
	 * Query var carrying the RFC 9728 §3.1 path-inserted resource suffix,
	 * e.g. `wp-json/mcp/testing-servers` for a request to
	 * `/.well-known/oauth-protected-resource/wp-json/mcp/testing-servers`.
	 */
	public const RESOURCE_VAR = 'acrossai_mcp_oauth_resource';

	/**
	 * Stores the plugin version whose rewrite rules are currently flushed.
	 *
	 * Autoloaded `false` — it is read once per admin request and never on the
	 * front end.
	 *
	 * @var string
	 */
	private const FLUSH_OPTION = 'acrossai_mcp_oauth_rewrite_version';

	/** @var OAuthRouter|null */
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
	 * Flush rewrite rules once per plugin version.
	 *
	 * `Activator::activate()` flushes, and that covers a fresh install — but
	 * WordPress does NOT run activation hooks on plugin UPDATE. Without this,
	 * every site upgrading into a release that adds or changes a rewrite rule
	 * keeps serving the stored `rewrite_rules` option, and all five OAuth
	 * routes 404 until someone re-saves Settings → Permalinks. F095 shipped
	 * exactly that: `.well-known/oauth-authorization-server`,
	 * `.well-known/oauth-protected-resource`, `/authorize` and `/token` were
	 * all registered correctly on `init` and all 404ed, so discovery and the
	 * whole authorization flow were unreachable after upgrade. Verified on a
	 * real site: 404 before the flush, 200 after, with no code change.
	 *
	 * Keyed on the plugin version rather than a boolean, so a future release
	 * that changes the rules re-flushes without needing a new option. Called
	 * from `Main::reconcile_database_schemas()` on `admin_init` priority 3 —
	 * the same one-shot lane as the other upgrade routines, and after `init`
	 * has registered the rules, which is what makes the flush meaningful.
	 *
	 * Admin-side only, matching the house pattern: a front-end request never
	 * pays for it.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrites(): void {
		$current = defined( 'ACROSSAI_MCP_MANAGER_VERSION' ) ? (string) \ACROSSAI_MCP_MANAGER_VERSION : '';
		if ( '' === $current ) {
			return;
		}

		if ( get_option( self::FLUSH_OPTION ) === $current ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::FLUSH_OPTION, $current, false );
	}

	/**
	 * Register the OAuth rewrite rules. Wired by Main.php on `init`.
	 *
	 * The path-inserted `.well-known` rule implements RFC 9728 §3.1: a client
	 * that wants metadata for the resource
	 * `https://site/wp-json/mcp/testing-servers` requests
	 * `https://site/.well-known/oauth-protected-resource/wp-json/mcp/testing-servers`.
	 * It MUST be registered before the bare rule so the more specific pattern
	 * is matched first. Claude.ai and other MCP hosts probe the path-inserted
	 * form; without it they got a WordPress 404 and fell back to the bare
	 * document, which advertised the wrong server on multi-server installs.
	 *
	 * There is deliberately NO path-inserted `oauth-authorization-server`
	 * rule. RFC 8414's path-insertion form applies only to issuers that HAVE
	 * a path component, and ours never does — `DiscoveryController::issuer()`
	 * returns `untrailingslashit( home_url() )`, and even on a subdirectory
	 * install the well-known segment goes at the host root BEFORE the path,
	 * never after it. 0.9.12 registered such a rule for symmetry with the
	 * resource rule; because it discarded its own capture, it answered with
	 * THIS site's metadata for every issuer path on the domain, squatting on
	 * other plugins' AS discovery (observed against Bit CRM). Removed in
	 * 0.9.13 — see DECISIONS.md DEC-NO-PATH-INSERTED-AS-METADATA.
	 */
	public function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/(.+?)/?$',
			'index.php?' . self::QUERY_VAR . '=pr-metadata&' . self::RESOURCE_VAR . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server/?$',
			'index.php?' . self::QUERY_VAR . '=as-metadata',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/?$',
			'index.php?' . self::QUERY_VAR . '=pr-metadata',
			'top'
		);
		add_rewrite_rule(
			'^authorize/?$',
			'index.php?' . self::QUERY_VAR . '=authorize',
			'top'
		);
		add_rewrite_rule(
			'^token/?$',
			'index.php?' . self::QUERY_VAR . '=token',
			'top'
		);
	}

	/**
	 * Whitelist the query var. Wired by Main.php on `query_vars` filter.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 * @return array<int, string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::RESOURCE_VAR;
		return $vars;
	}

	/**
	 * Dispatcher for the WP `parse_request` action.
	 *
	 * Wired by Main.php on `parse_request`. Reads the OAuth query var and
	 * delegates to the appropriate controller. `exit`s on match.
	 *
	 * @param \WP $wp WP object holding query_vars.
	 * @return void
	 */
	public function parse_request( \WP $wp ): void {
		if ( ! isset( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$route = (string) $wp->query_vars[ self::QUERY_VAR ];

		switch ( $route ) {
			case 'as-metadata':
				DiscoveryController::instance()->render_authorization_server_metadata();
				break;
			case 'pr-metadata':
				$suffix = isset( $wp->query_vars[ self::RESOURCE_VAR ] )
					? (string) $wp->query_vars[ self::RESOURCE_VAR ]
					: '';
				DiscoveryController::instance()->render_protected_resource_metadata( $suffix );
				break;
			case 'authorize':
				if ( 'POST' === self::request_method() ) {
					AuthorizationController::instance()->handle_post();
				} else {
					AuthorizationController::instance()->handle_get();
				}
				break;
			case 'token':
				TokenController::instance()->handle();
				break;
			default:
				// Unknown route via our query var: styled 404 (was a blank body).
				MessagePage::render(
					404,
					__( 'Page not found', 'acrossai-mcp-manager' ),
					array(
						__( 'This address is not a valid OAuth endpoint on this site.', 'acrossai-mcp-manager' ),
					)
				);
		}
	}

	/**
	 * @return string HTTP method (uppercased).
	 */
	private static function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( (string) $_SERVER['REQUEST_METHOD'] )
			: 'GET';
	}
}
