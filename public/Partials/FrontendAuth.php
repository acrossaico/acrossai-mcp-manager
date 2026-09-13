<?php
/**
 * Browser-mediated CLI authentication approval page (Phase 7).
 *
 * Replaces the prior Phase 6.0 absorbed module. Four intentional changes:
 *   1. QUERY_VAR renamed acrossai_mcp_frontend_auth → acrossai_mcp_auth.
 *   2. Authorization broadened from manage_options → ANY logged-in user
 *      (user consents on own behalf; App Password is scoped to user's caps).
 *      Per FR-007.4 + Constitution §III "Consent-surface exception"
 *      (added 2026-06-30, .specify/memory/constitution.md). This class is
 *      the canonical first instance of that exception. Satisfies all five
 *      conditions: (a) is_user_logged_in() check at maybe_render_page step 3;
 *      (b) approve_auth_code binds credential to get_current_user_id();
 *      (c) operator-gated via acrossai_mcp_npm_login_enabled default-OFF;
 *      (d) this docblock cites the exception with FR identifier;
 *      (e) displayed server slug sourced from transient via S9 below.
 *   3. Inline <style> replaced with externally-enqueued build/css/frontend.css
 *      (versioned via build/css/frontend.asset.php; RTL via wp_style_add_data).
 *   4. Nonce action is PER-CODE — 'cli_auth_approve_' . $code — so a nonce
 *      minted for code A cannot be replayed against code B (SEC-002 / CWE-352).
 *
 * Security hardening baked in (2026-06-30 plan-level security review):
 *   - SEC-001 / S9 (CWE-451/CWE-441): displayed server slug sourced from
 *     CliController::peek_pending_server($code), NOT $_GET['server'].
 *   - SEC-002 (CWE-352): per-code nonce as above.
 *   - SEC-005 (CWE-1004): 503 disabled notice carries Retry-After + noindex.
 *
 * Singleton + private ctor (A2 / S6). Zero hooks in the constructor (A1) —
 * Main::define_public_hooks wires every callback via the Loader. The static
 * get_base_url() helper is the one piece other modules (CliController,
 * Activator) couple to (pending A9 promotion via DEV3 / tasks.md T044).
 *
 * @package AcrossAI_MCP_Manager\Public\Partials
 */

namespace AcrossAI_MCP_Manager\Public\Partials;

use AcrossAI_MCP_Manager\Includes\REST\CliController;

defined( 'ABSPATH' ) || exit;

final class FrontendAuth {

	const PAGE_SLUG = 'acrossai-mcp-manager';
	const QUERY_VAR = 'acrossai_mcp_auth';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance() instead. Prevents B5 double-registration.
	 */
	private function __construct() {}

	/**
	 * Base URL of the approval page — `https://{site}/acrossai-mcp-manager/`.
	 *
	 * Static so callers (CliController::handle_auth_start, Activator) can
	 * reach it without holding a singleton reference. MUST NOT be changed to
	 * admin_url(...) — the page resolves on the front-end (FR-006).
	 */
	public static function get_base_url(): string {
		return home_url( '/' . self::PAGE_SLUG . '/' );
	}

	/**
	 * Register the single rewrite rule. Wired on `init` via Loader.
	 *
	 * Pattern has no `.` to escape — B4 not triggered here.
	 */
	public function register_rewrite_rule(): void {
		add_rewrite_rule(
			'^' . self::PAGE_SLUG . '/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Append the custom query var. Wired on `query_vars` via Loader.
	 *
	 * @param array<int, string> $vars
	 * @return array<int, string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Enqueue the consent-page CSS — ONLY on the consent page. Wired on
	 * `wp_enqueue_scripts` via Loader (FR-013).
	 *
	 * Reads the version from build/css/frontend.asset.php (emitted by the
	 * `wordpress/scripts` build pipeline). Falls back to the plugin version
	 * constant if the manifest is missing — no error_log per research §R2
	 * (silent fallback; deploy-time gate in T037 catches the misconfiguration).
	 */
	public function enqueue_assets(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$plugin_dir  = dirname( \ACROSSAI_MCP_MANAGER_PLUGIN_FILE );
		$plugin_file = \ACROSSAI_MCP_MANAGER_PLUGIN_FILE;
		$asset_path  = $plugin_dir . '/build/css/frontend.asset.php';
		$version     = \ACROSSAI_MCP_MANAGER_VERSION;

		if ( is_readable( $asset_path ) ) {
			$asset = require $asset_path;
			if ( is_array( $asset ) && isset( $asset['version'] ) && is_string( $asset['version'] ) && '' !== $asset['version'] ) {
				$version = $asset['version'];
			}
		}

		wp_enqueue_style(
			'acrossai-mcp-frontend',
			plugins_url( 'build/css/frontend.css', $plugin_file ),
			array(),
			$version
		);
		// RTL variant per FR-013 clarification — WP auto-substitutes
		// build/css/frontend-rtl.css when is_rtl() returns true.
		wp_style_add_data( 'acrossai-mcp-frontend', 'rtl', 'replace' );
	}

	/**
	 * Dispatch on `template_redirect` — branches by `?action=…`.
	 *
	 * Wired via Loader on `template_redirect` priority 10. Implements FR-007
	 * steps 1–7 in exact order. Note: `?server=` is NOT parsed here per the
	 * 2026-06-30 SEC-001 amendment — handle_cli_auth derives the slug from
	 * the transient via CliController::peek_pending_server.
	 */
	public function maybe_render_page(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		nocache_headers();

		if ( ! is_user_logged_in() ) {
			// R3 amendment 2026-07-15: preserve `?action=cli_auth&code=X` in
			// the redirect target so the user lands back on the consent page
			// after login. `code` is validated against hex format
			// (/^[a-f0-9]{32}$/) BEFORE inclusion — any deviation falls
			// through to the base-URL-only path per the original R3
			// conservatism. `server` is intentionally NOT preserved (it's
			// fetched from the transient per SEC-001). `_wpnonce` is
			// generated fresh on return inside handle_cli_auth. Load-bearing
			// invariant: the hex regex mitigates R3's original `//` scheme-
			// relative injection concern — do NOT loosen it without a
			// security-constraints.md update.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only param extraction; the state-mutating cli_auth_approve branch verifies its per-code nonce explicitly inside handle_approve().
			$raw_code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
			$raw_action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$redirect_to = self::get_base_url();
			if ( 'cli_auth' === $raw_action && 1 === preg_match( '/^[a-f0-9]{32}$/', $raw_code ) ) {
				$redirect_to = add_query_arg(
					array(
						'action' => 'cli_auth',
						'code'   => $raw_code,
					),
					$redirect_to
				);
			}

			wp_safe_redirect( wp_login_url( $redirect_to ) );
			$this->terminate();
		}

		// FR-007.4 — NO current_user_can() check. Any logged-in user may
		// consent on their own behalf. Threat-model rationale in spec §Assumptions.

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only render of GET params; the state-mutating cli_auth_approve branch verifies its per-code nonce explicitly inside handle_approve().
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$enabled = (bool) get_option( 'acrossai_mcp_npm_login_enabled', false );
		if ( ! $enabled ) {
			$this->render_disabled_notice();
			$this->terminate();
		}

		switch ( $action ) {
			case 'cli_auth_approve':
				$this->handle_approve();
				break;
			case 'cli_auth_approved':
				$this->handle_approved();
				break;
			case 'cli_auth':
			default:
				$this->handle_cli_auth( $code );
		}
		$this->terminate();
	}

	/**
	 * Render the consent form for `?action=cli_auth`.
	 *
	 * 2026-06-30 SEC-001 fix: the displayed server slug is sourced from
	 * CliController::peek_pending_server($code) — the transient's authoritative
	 * server_id — NOT from $_GET['server']. Rendering URL-supplied context
	 * would be a confused-deputy / UI-misrepresentation attack (CWE-451 /
	 * CWE-441 / S9).
	 *
	 * @param string $code Authorization code from CLI's `/auth/start`.
	 */
	private function handle_cli_auth( string $code ): void {
		$bound_server = ( '' !== $code ) ? CliController::peek_pending_server( $code ) : null;

		if ( '' === $code || null === $bound_server ) {
			$body  = '<p class="acrossai-mcp-frontend__lede">'
				. esc_html__( 'This page must be opened via a link from your CLI tool.', 'acrossai-mcp-manager' )
				. '</p>';
			$body .= '<p class="acrossai-mcp-frontend__hint">'
				. esc_html__( 'Return to your terminal, copy the authentication URL your CLI printed, and open it here.', 'acrossai-mcp-manager' )
				. '</p>';
			$this->render_branded_card(
				'info',
				esc_html__( 'Missing Authentication Parameters', 'acrossai-mcp-manager' ),
				$body
			);
			return;
		}

		// F015 Access Control connection-time gate — mirror the OAuth authorize + App
		// Password gates so unauthorized users see a clear denial page instead of
		// completing consent, receiving a token, and then silently 403-ing on every
		// tool call. `$bound_server` is a slug; resolve to server_id for AC check.
		$server_rows     = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query::instance()->query(
			array(
				'server_slug' => (string) $bound_server,
				'number'      => 1,
			)
		);
		$bound_server_id = ! empty( $server_rows ) ? (int) $server_rows[0]->id : 0;
		$current_user_id = get_current_user_id();
		if ( $bound_server_id > 0 && $current_user_id > 0 ) {
			$ac = \AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control::instance();
			if ( ! $ac->user_has_server_access( $current_user_id, $bound_server_id ) ) {
				do_action(
					'acrossai_mcp_access_control_denied',
					$current_user_id,
					(string) $bound_server,
					null,
					'cli_device_grant'
				);
				$body  = '<p class="acrossai-mcp-frontend__lede">'
					. esc_html__( 'Your account does not have permission to connect a CLI tool to this MCP server.', 'acrossai-mcp-manager' )
					. '</p>';
				$body .= '<p class="acrossai-mcp-frontend__hint">'
					. esc_html__( 'Contact a site administrator to request access. This authorization request has NOT been approved and no credential has been issued.', 'acrossai-mcp-manager' )
					. '</p>';
				$this->render_branded_card(
					'error',
					esc_html__( 'Access Denied', 'acrossai-mcp-manager' ),
					$body
				);
				return;
			}
		}

		$approve_url = add_query_arg(
			array(
				'action'   => 'cli_auth_approve',
				'code'     => $code,
				'_wpnonce' => wp_create_nonce( 'cli_auth_approve_' . $code ),
			),
			self::get_base_url()
		);

		$body = '<p class="acrossai-mcp-frontend__lede">' . sprintf(
			/* translators: 1: server slug from the transient (authoritative source per SEC-001) */
			esc_html__( 'A CLI tool is requesting access to your MCP server %1$s.', 'acrossai-mcp-manager' ),
			'<code class="acrossai-mcp-frontend__code">' . esc_html( $bound_server ) . '</code>'
		) . '</p>';
		$body .= '<p class="acrossai-mcp-frontend__hint">'
			. esc_html__( 'The session is single-use. Click Approve to grant the tool access.', 'acrossai-mcp-manager' )
			. '</p>';

		$this->render_branded_card(
			'consent',
			esc_html__( 'Authorize CLI Access', 'acrossai-mcp-manager' ),
			$body,
			array(
				'url'   => esc_url( $approve_url ),
				'label' => esc_html__( 'Approve', 'acrossai-mcp-manager' ),
				'style' => 'primary',
			)
		);
	}

	/**
	 * Handle Approve click — state-mutating; called only after per-code nonce
	 * verify.
	 *
	 * 2026-06-30 SEC-002 fix: nonce action is 'cli_auth_approve_' . $code —
	 * per-code binding prevents cross-code replay if the rendered HTML leaks.
	 * $code is read+sanitized BEFORE the nonce check (reading $_GET is not
	 * state mutation; nonce-before-mutation still holds). Empty $code → 400
	 * BEFORE nonce check to avoid verifying against trailing-underscore.
	 */
	private function handle_approve(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified below via wp_verify_nonce with per-code action.
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $code ) {
			wp_die(
				esc_html__( 'Missing authorization code.', 'acrossai-mcp-manager' ),
				'',
				array( 'response' => 400 )
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'cli_auth_approve_' . $code ) ) {
			wp_die(
				esc_html__( 'Security check failed.', 'acrossai-mcp-manager' ),
				'',
				array( 'response' => 403 )
			);
		}

		$approved = CliController::approve_auth_code( $code, get_current_user_id() );

		if ( ! $approved ) {
			wp_die(
				esc_html__( 'This authorization code is no longer valid. It may have expired or been used already.', 'acrossai-mcp-manager' ),
				'',
				array( 'response' => 400 )
			);
		}

		wp_safe_redirect( add_query_arg( 'action', 'cli_auth_approved', self::get_base_url() ) );
		$this->terminate();
	}

	/**
	 * Render the success page after `handle_approve` redirect.
	 */
	private function handle_approved(): void {
		$body  = '<p class="acrossai-mcp-frontend__lede">'
			. esc_html__( 'You can now return to your CLI tool — it will detect the approval shortly.', 'acrossai-mcp-manager' )
			. '</p>';
		$body .= '<p class="acrossai-mcp-frontend__hint">'
			. esc_html__( 'This page can be closed.', 'acrossai-mcp-manager' )
			. '</p>';
		$this->render_branded_card(
			'success',
			esc_html__( 'CLI Authorization Approved', 'acrossai-mcp-manager' ),
			$body
		);
	}

	/**
	 * Render the kill-switch notice when the feature flag is OFF.
	 *
	 * 2026-06-30 SEC-005 fix: emits Retry-After header and noindex meta to
	 * signal retry timing and prevent search-engine indexing of the
	 * disabled-notice page (CWE-1004). The noindex meta is prepended to the
	 * body payload (invalid HTML per spec — meta belongs in head — but
	 * browsers ignore it in body while robots/crawlers still respect it).
	 * Preserved from pre-2026-07-15 behavior; retasking `render_page_shell`
	 * to accept a head-injectable robots directive is out of scope here.
	 */
	private function render_disabled_notice(): void {
		status_header( 503 );
		if ( ! headers_sent() ) {
			header( 'Retry-After: 3600' );
		}

		$body  = '<meta name="robots" content="noindex,nofollow">';
		$body .= '<p class="acrossai-mcp-frontend__lede">'
			. esc_html__( 'The CLI login flow is currently disabled on this site. Contact your administrator.', 'acrossai-mcp-manager' )
			. '</p>';
		$this->render_branded_card(
			'warning',
			esc_html__( 'CLI Login Not Enabled', 'acrossai-mcp-manager' ),
			$body
		);
	}

	/**
	 * Wrap body content in a minimal HTML shell — NO `wp_head()` / `wp_footer()`
	 * so themes cannot inject markup into the consent flow.
	 *
	 * The enqueued external CSS (handle `acrossai-mcp-frontend`) is printed
	 * via `wp_print_styles()`. A minimal inline `<style>` safety-net block is
	 * permitted ONLY for layout (max-width, body padding) so the page remains
	 * legible if the external CSS fails to load.
	 *
	 * @param string $title       Pre-escaped page title.
	 * @param string $body_html   Pre-escaped HTML body (caller's responsibility).
	 */
	private function render_page_shell( string $title, string $body_html ): void {
		// Guard like WP core's own nocache_headers(): calling header() after
		// output has begun is a no-op that emits a PHP warning. In production
		// that happens when a theme or plugin echoes early; under PHPUnit it
		// happens on every run, which is what made all 13 render assertions
		// return an empty buffer.
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
		}

		// We exit from template_redirect without calling wp_head(), so the
		// 'wp_enqueue_scripts' action that Main wires enqueue_assets to never
		// fires on this request path. Call it explicitly here — the method
		// has its own query-var guard and wp_enqueue_style is idempotent,
		// so the hook-fired call (if WP ever invokes it on a future code
		// path) would be a safe no-op.
		$this->enqueue_assets();

		echo '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>';
		echo '<meta charset="utf-8">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by caller.
		echo '<title>' . $title . '</title>';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		wp_print_styles( 'acrossai-mcp-frontend' );
		// Inline safety-net — legibility fallback if the external CSS 404s.
		// Deliberately does NOT set `max-width` / `margin: auto` on body,
		// because those constrain the external design and cause overflow
		// (safety-net wins on cascade order — printed AFTER the <link>).
		// The external SCSS handles the full centered-card layout.
		echo '<style>body{font-family:system-ui,sans-serif;margin:0;padding:0;color:#1d2327;background:#f0f0f1;min-height:100vh}</style>';
		echo '</head><body>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller pre-escapes per the method's docblock contract.
		echo $body_html;
		echo '</body></html>';
	}

	/**
	 * Render a branded card wrapper around the caller-supplied body HTML.
	 *
	 * Introduced 2026-07-15 to replace the four sites that previously echoed
	 * bare `<h1>` + `<p>` markup into `render_page_shell`. The card structure
	 * matches the visual language of the admin AI Connectors tab (F021 v1.1.0
	 * constitution exception) — rounded card, subtle shadow, accent-colored
	 * top stripe, primary CTA button — but is scoped under
	 * `.acrossai-mcp-frontend__*` classes so it doesn't collide with admin.
	 *
	 * Variants control the accent color and top stripe:
	 *  - `consent` (blue)   — handle_cli_auth authorize screen
	 *  - `success` (green)  — handle_approved
	 *  - `warning` (amber)  — render_disabled_notice (kill-switch)
	 *  - `info`    (grey)   — missing-params fallback
	 *
	 * Any unknown variant falls through to `info`.
	 *
	 * @param string     $variant   One of 'consent' | 'success' | 'warning' | 'info'.
	 * @param string     $title     Pre-escaped h1 text.
	 * @param string     $body_html Pre-escaped body HTML.
	 * @param array|null $cta       Optional `[ 'url' => string, 'label' => string, 'style' => 'primary' ]`.
	 */
	private function render_branded_card( string $variant, string $title, string $body_html, ?array $cta = null ): void {
		$known_variants = array( 'consent', 'success', 'warning', 'info' );
		if ( ! in_array( $variant, $known_variants, true ) ) {
			$variant = 'info';
		}
		$variant_class = 'acrossai-mcp-frontend--' . $variant;

		$card  = '<main class="acrossai-mcp-frontend ' . esc_attr( $variant_class ) . '">';
		$card .= '<div class="acrossai-mcp-frontend__card">';
		$card .= '<div class="acrossai-mcp-frontend__stripe" aria-hidden="true"></div>';
		$card .= '<header class="acrossai-mcp-frontend__brand">';
		$card .= '<span class="acrossai-mcp-frontend__brand-name">'
			. esc_html__( 'AcrossAI MCP Manager', 'acrossai-mcp-manager' )
			. '</span>';
		$card .= '</header>';
		$card .= '<div class="acrossai-mcp-frontend__body">';
		$card .= '<h1 class="acrossai-mcp-frontend__title">' . $title . '</h1>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller pre-escapes body per the method's docblock contract.
		$card .= $body_html;
		$card .= '</div>';

		if ( is_array( $cta ) && isset( $cta['url'], $cta['label'] ) ) {
			$style        = isset( $cta['style'] ) && is_string( $cta['style'] ) ? $cta['style'] : 'primary';
			$button_class = 'acrossai-mcp-frontend__button acrossai-mcp-frontend__button--' . $style;
			$card        .= '<footer class="acrossai-mcp-frontend__cta">';
			$card        .= '<a class="' . esc_attr( $button_class ) . '" href="' . esc_url( $cta['url'] ) . '">'
				. esc_html( $cta['label'] )
				. '</a>';
			$card        .= '</footer>';
		}

		$card .= '</div>';
		$card .= '</main>';

		$this->render_page_shell(
			esc_html__( 'AcrossAI MCP Manager', 'acrossai-mcp-manager' ),
			$card
		);
	}

	/**
	 * Terminate the CLI-auth request.
	 *
	 * Wraps the bare `exit` so there is a single hookable point before the
	 * request dies. `exit` cannot be caught, which left the render tests with
	 * no way to assert on markup this method had already echoed — they can now
	 * throw from this action, the same throw-from-hook convention the redirect
	 * tests already use against `wp_redirect`.
	 *
	 * @since 0.3.4
	 */
	private function terminate(): void {
		/**
		 * Fires immediately before the CLI-auth page terminates the request.
		 *
		 * Production has no listener; it exists so tests can intercept before
		 * `exit`. Anything hooked here runs after the page markup is echoed.
		 *
		 * @since 0.3.4
		 */
		do_action( 'acrossai_mcp_frontend_auth_before_exit' );
		exit;
	}
}
