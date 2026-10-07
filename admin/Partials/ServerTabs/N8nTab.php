<?php
/**
 * The n8n per-server tab (Feature 013).
 *
 * Priority 36 places this tab immediately after AIConnectorsTab (35) in the
 * per-server tab strip. Visibility is gated on the `acrossai_n8n_enabled`
 * site option, wrapped in the `acrossai_mcp_manager_n8n_enabled` filter (D6 hybrid
 * — operator UI + developer override). BOTH the tab-registration filter
 * callback (Main::register_n8n_tab) AND this class's visible_for() call the
 * same is_enabled() helper — belt-and-braces double gate per FR-003.
 *
 * Two sub-panels dispatched via ?panel=:
 *   - bearer-auth (default) — admin-issued OAuth access token with 1/7/30/90-
 *     day TTL, minted via POST /wp-json/acrossai-mcp-manager/v1/servers/{id}/
 *     n8n/bearer/token.
 *   - header-auth — WordPress Application Password + n8n Header Auth guide.
 *
 * Panel dispatch shape mirrors AIConnectorsTab.php:100-131 byte-for-byte.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/ServerTabs
 * @since      0.10.0
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractServerTab;
use AcrossAI_MCP_Manager\Includes\Integrations\GlobalIntegration;

defined( 'ABSPATH' ) || exit;

final class N8nTab extends AbstractServerTab {

	private const N8N_PANELS     = array( 'bearer-auth', 'header-auth', 'connections' );
	private const DEFAULT_PANEL  = 'bearer-auth';
	private const CONNECTOR_SLUG = 'n8n';

	/**
	 * Tab slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'n8n';
	}

	/**
	 * Tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'n8n', 'acrossai-mcp-manager' );
	}

	/**
	 * Sort position — 36 places this immediately after AIConnectorsTab (35).
	 *
	 * @return int
	 */
	public function priority(): int {
		// 40 — the slot MethodRegistry's scale already reserved for n8n. The
		// companion returned 36 because it registered on the top-level tab
		// filter, a different scale.
		return 40;
	}

	/**
	 * Whether the tab renders for a given server.
	 *
	 * @param  array<string, mixed> $server Server row.
	 * @return bool
	 */
	public function visible_for( array $server ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature required by base class.
		return self::is_enabled();
	}

	/**
	 * Composite enablement check (D6 hybrid). The `acrossai_mcp_manager_n8n_enabled`
	 * filter defaults to the `acrossai_n8n_enabled` option value, so operators
	 * flip the UI checkbox and developers override programmatically.
	 *
	 * Feature 024 moved the option/filter pair into a GlobalIntegration
	 * descriptor registered on `acrossai_mcp_manager_global_integrations` (see
	 * Main.php), so the Pro settings page can render every site-wide toggle
	 * from one loop. Both public strings are unchanged and still resolved in
	 * the same order — this method is kept, with its signature, because it is
	 * the name every caller already uses and because F013 FR-003 requires the
	 * check to exist independently of the tab-registration filter so that
	 * instantiating the tab directly cannot bypass the toggle.
	 *
	 * Callers: this class's visible_for(), Main::register_n8n_tab() and
	 * Main::register_n8n_method() (both the entry guard and its
	 * visible_callback), AdminTokenController::permission_callback(), and
	 * Admin\Main::maybe_enqueue_n8n_admin_app().
	 *
	 * @since 0.10.0
	 */
	/**
	 * Contributes the n8n descriptor to the global-integration registry.
	 *
	 * Loader-wired on `acrossai_mcp_manager_global_integrations` rather than
	 * registered from a closure, so A1 holds: the hook is declared in Main.php
	 * and the descriptor lives with the feature it describes.
	 *
	 * The descriptor only tells the settings page what to render and which
	 * option to read. Whether the TAB appears is still decided by
	 * `is_enabled()` plus the capability checks on the method registration.
	 *
	 * `option` is passed verbatim and MUST NOT be derived from the slug:
	 * `acrossai_n8n_enabled` is a frozen public string (memory A3), and
	 * deriving it would orphan every existing install's stored value.
	 *
	 * @param  mixed $integrations Contributed descriptors.
	 * @return array<int, GlobalIntegration>
	 */
	public static function register_global_integration( $integrations ): array {
		if ( ! is_array( $integrations ) ) {
			$integrations = array();
		}

		$integrations[] = new GlobalIntegration(
			array(
				'slug'               => self::CONNECTOR_SLUG,
				'option'             => 'acrossai_n8n_enabled',
				'filter'             => 'acrossai_mcp_manager_n8n_enabled',
				'default'            => false,
				'priority'           => 10,
				'section_title'      => __( 'n8n Integration', 'acrossai-mcp-manager' ),
				'beta'               => true,
				'section_intro'      => __( 'The n8n integration is currently in Beta — the OAuth token issuance and audit-log paths are fully tested, but real-world workflow integrations across self-hosted / cloud / team n8n deployments are still being validated. Feature complete, not yet SLA-covered.', 'acrossai-mcp-manager' ),
				'field_label'        => __( 'Enable n8n Tab', 'acrossai-mcp-manager' ),
				'toggle_label'       => __( 'Show the n8n tab on every MCP server admin screen.', 'acrossai-mcp-manager' ),

				// Load-bearing copy (C8 / SEC-002): without it a rushed operator
				// responding to an incident may leave up to 90 days of live
				// tokens in the field believing "toggle off = feature off =
				// tokens dead."
				'toggle_description' => __( 'Disabling hides the tab and blocks new token generation but does NOT revoke previously-minted tokens. Existing tokens remain valid until their expiry (max 90 days). To revoke immediately, regenerate the token from each admin\'s account, then disable.', 'acrossai-mcp-manager' ),
			)
		);

		return $integrations;
	}

	/**
	 * Whether the operator has switched the n8n integration on.
	 *
	 * Default OFF — the descriptor registered above ships `'default' => false`,
	 * so a fresh install shows no n8n tab until the settings toggle is set. The
	 * REST permission gate reads this too, which is why it is static: a 403
	 * must be decidable without instantiating the tab.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return \AcrossAI_MCP_Manager\Includes\Integrations\GlobalIntegrationRegistry::is_enabled( self::CONNECTOR_SLUG );
	}

	/**
	 * Renders the tab body.
	 *
	 * @param array<string, mixed> $server Server row.
	 */
	protected function render_body( array $server ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$requested_panel = isset( $_GET['panel'] ) ? sanitize_key( wp_unslash( (string) $_GET['panel'] ) ) : '';
		// phpcs:enable

		if ( ! in_array( $requested_panel, self::N8N_PANELS, true ) ) {
			$requested_panel = self::DEFAULT_PANEL;
		}

		$server_id = (int) ( $server['id'] ?? 0 );

		echo '<div class="acrossai-mcp-n8n" data-server-id="' . esc_attr( (string) $server_id ) . '" data-wp-rest-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">';

		$this->render_top_tabs( $server, $requested_panel );

		echo '<div class="acrossai-mcp-n8n__panel acrossai-mcp-n8n__panel--' . esc_attr( $requested_panel ) . '">';

		$this->render_panel_dispatch( $server, $requested_panel );

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Renders the Level 3 panel nav.
	 *
	 * @param array<string, mixed> $server         Server row.
	 * @param string               $selected_panel Active panel slug.
	 */
	private function render_top_tabs( array $server, string $selected_panel ): void {
		$labels = array(
			'bearer-auth' => __( 'Bearer Auth', 'acrossai-mcp-manager' ),
			'header-auth' => __( 'Header Auth', 'acrossai-mcp-manager' ),
			'connections' => __( 'Connections', 'acrossai-mcp-manager' ),
		);

		echo '<nav class="nav-tab-wrapper acrossai-mcp-n8n__tabs">';
		foreach ( $labels as $panel_id => $label ) {
			$active = $panel_id === $selected_panel ? ' nav-tab-active' : '';
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $this->panel_url( $server, $panel_id ) ),
				esc_attr( $active ),
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Admin URL for one panel of this tab.
	 *
	 * @param  array<string, mixed> $server Server row.
	 * @param  string               $panel  Panel slug.
	 * @return string
	 */
	private function panel_url( array $server, string $panel ): string {
		// Feature 020 — see AIConnectorsTab::panel_url(). Builds the address
		// through ConnectTab rather than hard-coding `?tab=n8n`.
		return add_query_arg(
			'panel',
			sanitize_key( $panel ),
			ConnectTab::method_url( $server, 'n8n' )
		);
	}

	/**
	 * Dispatches to the renderer for the selected panel.
	 *
	 * @param array<string, mixed> $server Server row.
	 * @param string               $panel  Panel slug.
	 */
	private function render_panel_dispatch( array $server, string $panel ): void {
		switch ( $panel ) {
			case 'header-auth':
				$this->render_header_auth_panel( $server );
				return;
			case 'connections':
				$this->render_connections_panel( $server );
				return;
			case 'bearer-auth':
			default:
				$this->render_bearer_auth_panel( $server );
				return;
		}
	}

	/**
	 * Bearer Auth sub-panel (FR-013c). Amber callout + MCP URL copy row + TTL
	 * select + Generate Token button (admin-gated) + result region + step-by-
	 * step n8n MCP Client Tool node config.
	 *
	 * @param array<string, mixed> $server Server row.
	 *
	 * Inline <style> for the amber callout is emitted OUTSIDE any wp_kses_post
	 * wrapper (B9 memory) with a static print-once guard (mirror of
	 * AbstractConnectorProfile::print_setup_styles(), A8).
	 *
	 * Every dynamic output routes through esc_html / esc_attr / esc_url (C7).
	 */
	private function render_bearer_auth_panel( array $server ): void {
		self::print_bearer_auth_styles();
		// Reuse the connector-panel setup card CSS block for the "How to
		// connect n8n" section (A8 static print-once guard).
		AbstractConnectorProfile::print_setup_styles();

		$server_id = (int) ( $server['id'] ?? 0 );
		$mcp_url   = AbstractConnectorProfile::mcp_url_for_server( (array) $server );
		$can_gen   = current_user_can( 'manage_options' );
		$icon_url  = defined( '\\ACROSSAI_MCP_MANAGER_PLUGIN_URL' )
			? \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'assets/n8n-icon.svg'
			: '';

		// Outer connector-card wrapper (matches Claude/ChatGPT/… panel shape).
		echo '<section class="acrossai-mcp-connector acrossai-mcp-connector--n8n" data-acrossai-connector-slug="n8n">';

		// Card header — icon + "n8n" title.
		echo '<header class="acrossai-mcp-connector__header">';
		if ( '' !== $icon_url ) {
			printf(
				'<img class="acrossai-mcp-connector__icon" src="%s" alt="" width="32" height="32">',
				esc_url( $icon_url )
			);
		}
		echo '<h3 class="acrossai-mcp-connector__title">' . esc_html__( 'n8n', 'acrossai-mcp-manager' ) . '</h3>';
		echo '</header>';

		// Card body.
		echo '<div class="acrossai-mcp-connector__body">';

		// Amber callout — "Why not n8n's built-in OAuth?"
		echo '<div class="acrossai-mcp-n8n__callout acrossai-mcp-n8n__callout--warn" role="note">';
		echo '<strong>' . esc_html__( 'Why not n8n\'s built-in OAuth?', 'acrossai-mcp-manager' ) . '</strong>';
		echo '<p>' . esc_html__( 'n8n\'s MCP OAuth2 credential violates OAuth 2.1 today — it omits code_challenge in Dynamic Client Registration and mismatches the DCR-registered redirect_uri at authorize time. acrossai-mcp-manager enforces both requirements. This page will grow an OAuth panel once the upstream fix ships.', 'acrossai-mcp-manager' ) . '</p>';
		echo '<ul>';
		printf(
			'<li><a href="%1$s" target="_blank" rel="noopener">%2$s</a></li>',
			esc_url( 'https://github.com/n8n-io/n8n/issues/22103' ),
			esc_html__( 'n8n#22103 — missing code_challenge at DCR', 'acrossai-mcp-manager' )
		);
		printf(
			'<li><a href="%1$s" target="_blank" rel="noopener">%2$s</a></li>',
			esc_url( 'https://github.com/n8n-io/n8n/issues/23712' ),
			esc_html__( 'n8n#23712 — redirect_uri mismatch at authorize', 'acrossai-mcp-manager' )
		);
		printf(
			'<li><a href="%1$s" target="_blank" rel="noopener">%2$s</a></li>',
			esc_url( 'https://github.com/n8n-io/n8n/pull/22405' ),
			esc_html__( 'n8n#22405 — upstream fix (not yet released)', 'acrossai-mcp-manager' )
		);
		echo '</ul>';
		echo '</div>';

		// MCP URL row — matches Claude's "MCP URL to paste into Claude:" shape.
		echo '<div class="acrossai-mcp-n8n__field">';
		echo '<p class="acrossai-mcp-connector__label">' . esc_html__( 'MCP URL to paste into n8n:', 'acrossai-mcp-manager' ) . '</p>';
		echo '<div class="acrossai-mcp-connector__copy-row">';
		printf(
			'<input type="text" readonly class="acrossai-mcp-connector__input regular-text code" value="%s" data-acrossai-n8n-mcp-url />',
			esc_attr( $mcp_url )
		);
		printf(
			'<button type="button" class="button acrossai-mcp-connector__copy-btn acrossai-mcp-n8n__copy-btn" data-acrossai-n8n-copy-target="%s">%s</button>',
			esc_attr( $mcp_url ),
			esc_html__( 'Copy', 'acrossai-mcp-manager' )
		);
		echo '</div>';
		echo '</div>';

		// TTL selector + Generate Token button (admin-gated) with side hint.
		echo '<div class="acrossai-mcp-n8n__field">';
		printf(
			'<label for="acrossai-mcp-n8n__ttl-%1$s" class="acrossai-mcp-connector__label">%2$s</label>',
			esc_attr( (string) $server_id ),
			esc_html__( 'Token expiry', 'acrossai-mcp-manager' )
		);
		echo '<div class="acrossai-mcp-n8n__generate-row">';
		printf(
			'<select id="acrossai-mcp-n8n__ttl-%s" class="acrossai-mcp-n8n__ttl-select" data-acrossai-n8n-ttl>',
			esc_attr( (string) $server_id )
		);
		$ttl_options = array(
			86400   => __( '1 day', 'acrossai-mcp-manager' ),
			604800  => __( '7 days', 'acrossai-mcp-manager' ),
			2592000 => __( '30 days (default)', 'acrossai-mcp-manager' ),
			7776000 => __( '90 days', 'acrossai-mcp-manager' ),
		);
		foreach ( $ttl_options as $seconds => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $seconds ),
				selected( $seconds, 2592000, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		if ( $can_gen ) {
			// Full endpoint URL is rendered server-side with the numeric
			// server_id already substituted. Previously the JS built the
			// URL from a `{server_id}` template threaded through
			// `wp_localize_script`, but esc_url_raw() strips `{` and `}`
			// as invalid URL characters — the placeholder collapsed to
			// literal `server_id` and the REST server returned
			// `rest_no_route` because `server_id` isn't `\d+`.
			$token_endpoint = rest_url( 'acrossai-mcp-manager/v1/servers/' . $server_id . '/n8n/bearer/token' );
			$rest_nonce     = wp_create_nonce( 'wp_rest' );
			printf(
				'<button type="button" class="button button-primary acrossai-mcp-n8n__generate-token-btn" data-server-id="%1$s" data-endpoint="%2$s" data-nonce="%3$s">%4$s</button>',
				esc_attr( (string) $server_id ),
				esc_url( $token_endpoint ),
				esc_attr( $rest_nonce ),
				esc_html__( 'Generate Token', 'acrossai-mcp-manager' )
			);
			echo '<span class="acrossai-mcp-connector__description">' . esc_html__( 'Mints an admin-issued OAuth access token bound to this server.', 'acrossai-mcp-manager' ) . '</span>';
		} else {
			echo '<span class="acrossai-mcp-connector__description">' . esc_html__( 'Token generation requires the manage_options capability. Ask a site administrator to generate a token, or use the Header Auth path instead.', 'acrossai-mcp-manager' ) . '</span>';
		}
		echo '</div>';
		echo '</div>';

		// Result target — populated by n8n-admin.js with the masked token widget.
		echo '<div class="acrossai-mcp-n8n__result" data-acrossai-n8n-result aria-live="polite"></div>';

		// "How to connect n8n" — nested setup card mirroring the Claude
		// connector's setup-card pattern (see AbstractConnectorProfile::
		// print_setup_styles for the shared CSS).
		echo '<div class="acrossai-mcp-connector-panel__setup">';
		echo '<h4 class="acrossai-mcp-connector-panel__setup-title">' . esc_html__( 'How to connect n8n', 'acrossai-mcp-manager' ) . '</h4>';
		echo '<p class="acrossai-mcp-connector-panel__setup-lead">' . esc_html__( 'Configure the MCP Client Tool node in n8n with the credentials generated above.', 'acrossai-mcp-manager' ) . '</p>';

		echo '<div class="acrossai-mcp-connector-panel__setup-section">';
		echo '<h5>' . esc_html__( 'MCP CLIENT TOOL NODE — BEARER AUTH', 'acrossai-mcp-manager' ) . '</h5>';
		echo '<ol class="acrossai-mcp-connector-panel__setup-steps">';
		printf(
			/* translators: %s: <code> element wrapping the field label */
			'<li>%s</li>',
			sprintf(
				/* translators: %s: the MCP server URL copied from the row above. */
				esc_html__( 'Endpoint URL: paste the %s from above.', 'acrossai-mcp-manager' ),
				'<code>' . esc_html__( 'MCP URL to paste into n8n', 'acrossai-mcp-manager' ) . '</code>'
			)
		);
		printf(
			/* translators: %s: <code> element wrapping the transport name */
			'<li>%s</li>',
			sprintf(
				/* translators: %s: transport name, e.g. HTTP Streamable. */
				esc_html__( 'Transport: %s.', 'acrossai-mcp-manager' ),
				'<code>HTTP Streamable</code>'
			)
		);
		printf(
			/* translators: 1: <code> Header Auth 2: <code> Authorization 3: <code> Bearer <token> */
			'<li>%s</li>',
			sprintf(
				/* translators: 1: auth scheme name. 2: HTTP header name. 3: header value. */
				esc_html__( 'Auth: %1$s. Create a new credential with Name = %2$s and Value = %3$s.', 'acrossai-mcp-manager' ),
				'<code>Header Auth</code>',
				'<code>Authorization</code>',
				'<code>Bearer &lt;paste-the-token-here&gt;</code>'
			)
		);
		echo '<li>' . esc_html__( 'Save the workflow and run it — the node lists your MCP server\'s tools.', 'acrossai-mcp-manager' ) . '</li>';
		echo '</ol>';
		echo '</div>';

		echo '</div>'; // .acrossai-mcp-connector-panel__setup

		echo '</div>'; // .acrossai-mcp-connector__body
		echo '</section>';
	}

	/**
	 * Header Auth sub-panel (FR-013d). Step-by-step Application Password
	 * guide reusing $this->passwords_notice() from the base class.
	 *
	 * @param array<string, mixed> $server Server row.
	 *
	 * No manage_options gate — every signed-in user can follow this path
	 * for their own account.
	 */
	private function render_header_auth_panel( array $server ): void {
		self::print_bearer_auth_styles();
		AbstractConnectorProfile::print_setup_styles();

		$mcp_url  = AbstractConnectorProfile::mcp_url_for_server( (array) $server );
		$icon_url = defined( '\\ACROSSAI_MCP_MANAGER_PLUGIN_URL' )
			? \ACROSSAI_MCP_MANAGER_PLUGIN_URL . 'assets/n8n-icon.svg'
			: '';

		// Outer connector-card wrapper (matches Claude/ChatGPT/… panel shape).
		echo '<section class="acrossai-mcp-connector acrossai-mcp-connector--n8n" data-acrossai-connector-slug="n8n">';

		// Card header — icon + "n8n" title.
		echo '<header class="acrossai-mcp-connector__header">';
		if ( '' !== $icon_url ) {
			printf(
				'<img class="acrossai-mcp-connector__icon" src="%s" alt="" width="32" height="32">',
				esc_url( $icon_url )
			);
		}
		echo '<h3 class="acrossai-mcp-connector__title">' . esc_html__( 'n8n', 'acrossai-mcp-manager' ) . '</h3>';
		echo '</header>';

		// Card body.
		echo '<div class="acrossai-mcp-connector__body">';

		// MCP URL row — matches Claude's "MCP URL to paste into Claude:" shape.
		echo '<div class="acrossai-mcp-n8n__field">';
		echo '<p class="acrossai-mcp-connector__label">' . esc_html__( 'MCP URL to paste into n8n:', 'acrossai-mcp-manager' ) . '</p>';
		echo '<div class="acrossai-mcp-connector__copy-row">';
		printf(
			'<input type="text" readonly class="acrossai-mcp-connector__input regular-text code" value="%s" />',
			esc_attr( $mcp_url )
		);
		printf(
			'<button type="button" class="button acrossai-mcp-connector__copy-btn acrossai-mcp-n8n__copy-btn" data-acrossai-n8n-copy-target="%s">%s</button>',
			esc_attr( $mcp_url ),
			esc_html__( 'Copy', 'acrossai-mcp-manager' )
		);
		echo '</div>';
		echo '</div>';

		// "How to connect n8n via Header Auth" — nested setup card.
		echo '<div class="acrossai-mcp-connector-panel__setup">';
		echo '<h4 class="acrossai-mcp-connector-panel__setup-title">' . esc_html__( 'How to connect n8n via Header Auth', 'acrossai-mcp-manager' ) . '</h4>';
		echo '<p class="acrossai-mcp-connector-panel__setup-lead">' . esc_html__( 'Use a WordPress Application Password so you can revoke n8n\'s access without touching any other login. Every signed-in WordPress user can create their own — no admin capability required.', 'acrossai-mcp-manager' ) . '</p>';

		// Step 1 — Application Password.
		//
		// mcp-manager's ApplicationPasswords.php exposes
		// POST /wp-json/acrossai-mcp-manager/v1/generate-app-password with
		// standard `wp_rest` nonce + `manage_options` permission + optional
		// `name` body param. mcp-manager's own `.generate-app-password`
		// button handler in `src/js/backend.js` only sends `server_id`, so
		// we own the click handler here to also send `name=n8n` — Application
		// Passwords generated from this panel are labelled "n8n" (not the
		// default `build_app_name($server_id)` label).
		$server_id_int = (int) ( $server['id'] ?? 0 );

		echo '<div class="acrossai-mcp-connector-panel__setup-section">';
		echo '<h5>' . esc_html__( 'STEP 1 — CREATE AN APPLICATION PASSWORD', 'acrossai-mcp-manager' ) . '</h5>';
		echo '<div class="acrossai-mcp-n8n__generate-app-password-row">';
		printf(
			'<button type="button" class="button button-primary acrossai-mcp-n8n__generate-app-password-btn" data-server-id="%1$s" data-name="%2$s" data-endpoint="%3$s" data-nonce="%4$s">%5$s</button>'
			. '<span class="acrossai-mcp-n8n__app-password-status" aria-live="polite"></span>',
			esc_attr( (string) $server_id_int ),
			esc_attr( 'n8n' ),
			esc_url( rest_url( 'acrossai-mcp-manager/v1/generate-app-password' ) ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
			esc_html__( 'Generate New Application Password', 'acrossai-mcp-manager' )
		);
		echo '</div>';
		printf(
			'<p class="description acrossai-mcp-n8n__generate-app-password-hint">%s</p>',
			esc_html__( 'Creates a one-time password via WordPress Application Passwords labelled "n8n". Shown only once — store it safely.', 'acrossai-mcp-manager' )
		);
		// Rendered by JS after success — masked input + Reveal + Copy widget.
		echo '<div class="acrossai-mcp-n8n__app-password-result" data-acrossai-n8n-app-password-result aria-live="polite"></div>';
		// Supplementary link for users who want to view / revoke existing
		// Application Passwords on their profile.
		$this->passwords_notice();
		echo '</div>';

		// Step 2 — n8n node config.
		echo '<div class="acrossai-mcp-connector-panel__setup-section">';
		echo '<h5>' . esc_html__( 'STEP 2 — CONFIGURE THE n8n MCP CLIENT TOOL NODE', 'acrossai-mcp-manager' ) . '</h5>';
		echo '<ol class="acrossai-mcp-connector-panel__setup-steps">';
		printf(
			'<li>%s</li>',
			sprintf(
				/* translators: %s: the MCP server URL copied from the row above. */
				esc_html__( 'Endpoint URL: paste the %s from above.', 'acrossai-mcp-manager' ),
				'<code>' . esc_html__( 'MCP endpoint URL', 'acrossai-mcp-manager' ) . '</code>'
			)
		);
		printf(
			'<li>%s</li>',
			sprintf(
				/* translators: %s: transport name, e.g. HTTP Streamable. */
				esc_html__( 'Transport: %s.', 'acrossai-mcp-manager' ),
				'<code>HTTP Streamable</code>'
			)
		);
		printf(
			'<li>%s</li>',
			sprintf(
				/* translators: %s: auth scheme name. */
				esc_html__( 'Auth: %s.', 'acrossai-mcp-manager' ),
				'<code>Header Auth</code>'
			)
		);
		echo '</ol>';
		echo '</div>';

		// Step 3 — Base64 encoding.
		echo '<div class="acrossai-mcp-connector-panel__setup-section">';
		echo '<h5>' . esc_html__( 'STEP 3 — ENCODE USERNAME + APPLICATION PASSWORD', 'acrossai-mcp-manager' ) . '</h5>';
		printf(
			'<p>%s</p>',
			sprintf(
				/* translators: 1: HTTP header name. 2: header value format. */
				esc_html__( 'In n8n\'s Header Auth credential, set Name = %1$s and Value = %2$s. The credential is base64(username:app_password).', 'acrossai-mcp-manager' ),
				'<code>Authorization</code>',
				'<code>Basic &lt;base64-encoded credential&gt;</code>'
			)
		);
		echo '<p>' . esc_html__( 'Example — keeps the password out of your shell history:', 'acrossai-mcp-manager' ) . '</p>';
		echo '<pre class="acrossai-mcp-connector-panel__setup-cmd"><code>IFS= read -rs -p "Paste Application Password: " APP_PW &amp;&amp; \\' . "\n";
		echo '  printf \'%s:%s\' "$USER" "$APP_PW" | base64 &amp;&amp; \\' . "\n";
		echo '  unset APP_PW</code></pre>';
		echo '</div>';

		echo '</div>'; // .acrossai-mcp-connector-panel__setup

		echo '</div>'; // .acrossai-mcp-connector__body
		echo '</section>';
	}

	/**
	 * Connections sub-panel — read-out of every non-revoked, non-expired
	 * admin-issued n8n token for this server, grouped by owning user.
	 *
	 * @param array<string, mixed> $server Server row.
	 *
	 * Mirrors AIConnectorsTab::render_connections_all() but filters the
	 * server-wide `group_by_user_and_connector_for_server()` result to
	 * `connector_slug === 'n8n'` — so operators see only the n8n tokens
	 * on this specific server, one row per user.
	 *
	 * Revoke / Delete-client button markup is byte-identical to the
	 * AIConnectorsTab version — the AI Connectors JS bundle's delegated
	 * click handlers (`.acrossai-mcp-connector-panel__revoke-user-btn` +
	 * `.acrossai-mcp-connector-panel__delete-user-btn`) pick them up.
	 * `Admin\Main::maybe_enqueue_n8n_admin_app` conditionally enqueues
	 * that bundle when `?panel=connections` is active.
	 */
	private function render_connections_panel( array $server ): void {
		self::print_bearer_auth_styles();
		self::print_connections_panel_styles();

		$server_id = (int) ( $server['id'] ?? 0 );
		$all       = AccessTokenRepository::group_by_user_and_connector_for_server( $server_id );
		$groups    = array_values(
			array_filter(
				$all,
				static function ( $g ) {
					return isset( $g['connector_slug'] ) && self::CONNECTOR_SLUG === $g['connector_slug'];
				}
			)
		);

		echo '<div class="acrossai-mcp-connector-panel">';
		printf(
			'<h3 class="acrossai-mcp-connector-panel__title">%s</h3>',
			esc_html(
				sprintf(
					/* translators: %d: number of admins with active n8n tokens on this server */
					_n( '%d active n8n connection', '%d active n8n connections', count( $groups ), 'acrossai-mcp-manager' ),
					count( $groups )
				)
			)
		);

		if ( empty( $groups ) ) {
			printf(
				'<p class="acrossai-mcp-connector-panel__empty description">%s</p>',
				esc_html__( 'No admins have generated an n8n bearer token for this server yet.', 'acrossai-mcp-manager' )
			);
			echo '</div>';
			return;
		}

		echo '<div class="acrossai-mcp-connector-panel__actions-legend notice notice-info inline"><p><strong>' . esc_html__( 'What each action button does', 'acrossai-mcp-manager' ) . ':</strong></p><ul style="margin-left:1.5em;list-style:disc;">';
		printf(
			'<li><strong>%s</strong> — %s</li>',
			esc_html__( 'Revoke', 'acrossai-mcp-manager' ),
			esc_html__( 'Marks every non-revoked n8n token issued to this admin on THIS server as revoked. Other admins are unaffected. The shared n8n OAuth client row stays intact; the admin can regenerate a fresh token from the Bearer Auth tab.', 'acrossai-mcp-manager' )
		);
		printf(
			'<li><strong>%s</strong> — %s</li>',
			esc_html__( 'Delete client', 'acrossai-mcp-manager' ),
			esc_html__( 'Revokes this admin\'s tokens AND deletes the underlying n8n OAuth client row(s) if no other admin still has active tokens on them. If the client is shared with other admins, only this admin\'s tokens are revoked and the client survives.', 'acrossai-mcp-manager' )
		);
		echo '</ul></div>';

		echo '<table class="widefat striped acrossai-mcp-connector-panel__table">';
		echo '<thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Admin', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Active tokens', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Latest issued', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Actions', 'acrossai-mcp-manager' ) );
		echo '</tr></thead><tbody>';

		$now = time();
		foreach ( $groups as $group ) {
			$uid  = (int) $group['user_id'];
			$user = get_userdata( $uid );
			if ( $user instanceof \WP_User ) {
				$user_label = sprintf( '%s (#%d)', $user->user_login, $uid );
			} else {
				$user_label = sprintf(
					/* translators: %d: WordPress user id whose row has been deleted */
					__( '(deleted user #%d)', 'acrossai-mcp-manager' ),
					$uid
				);
			}

			$latest_iso = (string) $group['latest_created_at'];
			$latest_ts  = '' !== $latest_iso ? (int) strtotime( $latest_iso . ' UTC' ) : 0;
			if ( $latest_ts > 0 ) {
				$time_ago = sprintf(
					/* translators: %s: human-readable time difference like "5 minutes" */
					__( '%s ago', 'acrossai-mcp-manager' ),
					human_time_diff( $latest_ts, $now )
				);
			} else {
				$time_ago = '—';
			}

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $user_label ) );
			printf(
				'<td>%d <span class="description">(%s)</span></td>',
				(int) $group['token_count'],
				esc_html(
					sprintf(
						/* translators: 1: access token count, 2: refresh token count */
						__( '%1$d access · %2$d refresh', 'acrossai-mcp-manager' ),
						(int) $group['access_count'],
						(int) $group['refresh_count']
					)
				)
			);
			printf(
				'<td><span title="%s">%s</span></td>',
				esc_attr( $latest_iso ),
				esc_html( $time_ago )
			);

			$client_ids_json = wp_json_encode( array_values( $group['client_ids'] ) );
			printf(
				'<td>' .
					'<button type="button" class="button-link acrossai-mcp-connector-panel__revoke-user-btn" ' .
						'data-acrossai-user-id="%1$d" ' .
						'data-acrossai-connector-slug="%2$s" ' .
						'data-acrossai-server-id="%3$d" ' .
						'data-acrossai-client-ids="%4$s">%5$s</button>' .
					' | <button type="button" class="button-link-delete acrossai-mcp-connector-panel__delete-user-btn" ' .
						'data-acrossai-user-id="%1$d" ' .
						'data-acrossai-connector-slug="%2$s" ' .
						'data-acrossai-server-id="%3$d" ' .
						'data-acrossai-client-ids="%4$s">%6$s</button>' .
				'</td>',
				(int) $uid,
				esc_attr( self::CONNECTOR_SLUG ),
				(int) $server_id,
				esc_attr( (string) $client_ids_json ),
				esc_html__( 'Revoke', 'acrossai-mcp-manager' ),
				esc_html__( 'Delete client', 'acrossai-mcp-manager' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Inline CSS for the Connections panel table — mirrors the essentials
	 * from src/scss/ai-connectors.scss (.acrossai-mcp-connector-panel*) so
	 * the panel renders correctly on the n8n tab even when the AI Connectors
	 * SCSS bundle isn't loaded (e.g., fresh checkout without webpack build).
	 * Print-once guard (B9 / A8 pattern).
	 */
	private static function print_connections_panel_styles(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style id="acrossai-mcp-n8n-connections-styles">
			.acrossai-mcp-connector-panel {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				padding: 24px 28px;
				margin: 0;
				box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
			}
			.acrossai-mcp-connector-panel__title {
				margin: 0 0 16px;
				font-size: 16px;
				font-weight: 600;
				color: #1d2327;
			}
			.acrossai-mcp-connector-panel__empty {
				margin: 8px 0 0;
				color: #50575e;
			}
			.acrossai-mcp-connector-panel__actions-legend {
				margin: 0 0 16px !important;
				padding: 12px 14px !important;
				background: #f0f6fc !important;
				border-left: 4px solid #2271b1 !important;
			}
			.acrossai-mcp-connector-panel__actions-legend p {
				margin: 0 0 6px;
			}
			.acrossai-mcp-connector-panel__actions-legend ul {
				margin: 6px 0 0 22px !important;
				list-style: disc;
			}
			.acrossai-mcp-connector-panel__actions-legend li {
				margin: 4px 0;
				font-size: 13px;
				line-height: 1.5;
			}
			.acrossai-mcp-connector-panel__table {
				width: 100%;
				border-collapse: collapse;
			}
			.acrossai-mcp-connector-panel__table th,
			.acrossai-mcp-connector-panel__table td {
				padding: 10px 12px;
				vertical-align: middle;
			}
			.acrossai-mcp-connector-panel__table th {
				font-weight: 600;
				color: #1d2327;
			}
			.acrossai-mcp-connector-panel__table .description {
				color: #50575e;
				font-size: 12px;
			}
			.acrossai-mcp-connector-panel__revoke-user-btn {
				color: #2271b1;
			}
			.acrossai-mcp-connector-panel__delete-user-btn {
				color: #b32d2e;
			}
		</style>
		<?php
	}

	/**
	 * Print the amber-callout CSS once per request. Emitted OUTSIDE any
	 * wp_kses_post() wrapper (B9). Mirror of
	 * AbstractConnectorProfile::print_setup_styles() (A8).
	 */
	private static function print_bearer_auth_styles(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style id="acrossai-mcp-n8n-callout-styles">
			/* Widen the n8n panel — the mcp-manager tab wrapper caps content
				at 720px for non-connections panels; we want the full width to
				match the Claude/ChatGPT/… panels' setup-card presentation. */
			.acrossai-mcp-ai-connectors__panel--n8n,
			.acrossai-mcp-n8n__panel {
				max-width: none !important;
			}

			.acrossai-mcp-n8n {
				max-width: none;
			}

			/* Sub-tab strip (Bearer Auth | Header Auth) — mirror of
				.acrossai-mcp-ai-connectors__tabs at src/scss/ai-connectors.scss:275
				so the n8n sub-tabs get the same 8px breathing room ABOVE (separating
				them from the primary tabs) and 16px BELOW (separating them from
				the content card). Without these rules the sub-tabs sit flush
				against both edges and the panel feels cramped. */
			.acrossai-mcp-n8n__tabs {
				margin: 0 0 16px;
				padding-top: 8px;
				display: flex;
				align-items: flex-end;
				flex-wrap: wrap;
				gap: 0;
			}

			/* Panel container — top margin so the connector card doesn't butt
				directly against the sub-tab strip's bottom margin (the two
				margins collapse otherwise, giving 16px total — this pushes
				the card down a hair further to match the AI Connectors panel
				feel from image #12). */
			.acrossai-mcp-n8n__panel {
				padding-top: 4px;
			}

			/* Connector-card shell — mirror of ai-connectors.scss
				.acrossai-mcp-connector so the n8n Bearer Auth panel gets the
				same white card + icon-header + body layout as Claude / ChatGPT
				/ … without depending on the AI Connectors bundle being
				enqueued on this tab. */
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				padding: 28px 32px;
				margin: 0 0 20px;
				box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-connector__header {
				display: flex;
				align-items: center;
				gap: 12px;
				margin: 0 0 20px;
				padding: 0 0 16px;
				border-bottom: 1px solid #f0f0f1;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-connector__icon {
				display: block;
				width: 32px;
				height: 32px;
				flex-shrink: 0;
				object-fit: contain;
				color: #1d2327;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-connector__title {
				margin: 0;
				font-size: 16px;
				font-weight: 600;
				line-height: 1.3;
				color: #1d2327;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-connector__body {
				display: flex;
				flex-direction: column;
				gap: 20px;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-connector__label {
				display: block;
				margin: 0 0 6px;
				font-size: 13px;
				font-weight: 500;
				color: #1d2327;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-connector__copy-row {
				display: flex;
				align-items: stretch;
				gap: 8px;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-connector__input {
				flex: 1;
				min-width: 0;
				padding: 8px 12px;
				font-family: Consolas, Monaco, "Andale Mono", "DejaVu Sans Mono", monospace;
				font-size: 13px;
				line-height: 1.4;
				background: #f6f7f7;
				border: 1px solid #dcdcde;
				border-radius: 4px;
				color: #1d2327;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-connector__copy-btn {
				flex-shrink: 0;
				min-width: 64px;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-connector__description {
				margin: 0;
				font-size: 13px;
				line-height: 1.5;
				color: #50575e;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-n8n__field {
				margin: 0;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-n8n__generate-row {
				display: flex;
				gap: 12px;
				align-items: center;
				flex-wrap: wrap;
				margin: 0;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-n8n__ttl-select {
				padding: 4px 24px 4px 8px;
				font-size: 13px;
				min-height: 34px;
			}
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-n8n__callout--warn {
				margin: 0;
			}
			/* The nested "How to connect" setup card sits at the bottom of
				the connector body — no top margin so the flex-column gap owns
				the spacing. */
			.acrossai-mcp-connector.acrossai-mcp-connector--n8n .acrossai-mcp-connector-panel__setup {
				margin: 0;
			}

			/* Amber callout — "Why not n8n's built-in OAuth?" */
			.acrossai-mcp-n8n__callout--warn {
				background: #fff8e1;
				border-left: 4px solid #f0b429;
				padding: 14px 18px;
				margin: 12px 0 20px;
				border-radius: 4px;
			}
			.acrossai-mcp-n8n__callout--warn strong {
				display: block;
				margin-bottom: 6px;
				color: #7c5e00;
				font-size: 14px;
			}
			.acrossai-mcp-n8n__callout--warn p {
				margin: 0 0 8px;
				color: #4b3d00;
			}
			.acrossai-mcp-n8n__callout--warn ul {
				margin: 8px 0 0 22px;
				list-style: disc;
			}
			.acrossai-mcp-n8n__callout--warn li {
				margin: 4px 0;
			}

			/* Field label above the input / select row. */
			.acrossai-mcp-n8n__field-label {
				display: block;
				margin: 0 0 6px;
				font-size: 13px;
				font-weight: 600;
				color: #1d2327;
			}

			/* MCP URL copy row — mirror of .acrossai-mcp-connector__copy-row
				(defined in ai-connectors.scss) so the n8n panel gets the same
				full-width input + button pairing without needing the AI
				Connectors bundle enqueued. */
			.acrossai-mcp-n8n__mcp-url-row {
				display: flex;
				align-items: stretch;
				gap: 8px;
				margin: 0 0 20px;
			}
			.acrossai-mcp-n8n__mcp-url-row .acrossai-mcp-connector__input {
				flex: 1;
				min-width: 0;
				padding: 8px 12px;
				font-family: Consolas, Monaco, "Andale Mono", "DejaVu Sans Mono", monospace;
				font-size: 13px;
				line-height: 1.4;
				background: #f6f7f7;
				border: 1px solid #dcdcde;
				border-radius: 4px;
				color: #1d2327;
			}
			.acrossai-mcp-n8n__mcp-url-row .acrossai-mcp-n8n__copy-btn {
				min-width: 80px;
			}

			/* Generate row — TTL select + Generate Token button. */
			.acrossai-mcp-n8n__generate-row {
				display: flex;
				gap: 12px;
				align-items: center;
				flex-wrap: wrap;
				margin: 0 0 20px;
			}
			.acrossai-mcp-n8n__generate-row .acrossai-mcp-n8n__field-label {
				margin: 0;
			}
			.acrossai-mcp-n8n__ttl-select {
				padding: 4px 24px 4px 8px;
				font-size: 13px;
				min-height: 32px;
			}

			/* Result target — populated by n8n-admin.js on Generate Token. */
			.acrossai-mcp-n8n__result {
				margin: 0 0 8px;
			}
			.acrossai-mcp-n8n__result:empty {
				margin: 0;
			}
			.acrossai-mcp-n8n__token-row {
				display: flex;
				align-items: stretch;
				gap: 8px;
				margin: 12px 0 0;
				padding: 12px 14px;
				background: #f6f7f7;
				border: 1px solid #dcdcde;
				border-radius: 4px;
			}
			.acrossai-mcp-n8n__token-row .acrossai-mcp-connector__input {
				flex: 1;
				min-width: 0;
				padding: 8px 12px;
				font-family: Consolas, Monaco, "Andale Mono", "DejaVu Sans Mono", monospace;
				font-size: 13px;
				background: #ffffff;
				border: 1px solid #dcdcde;
				border-radius: 4px;
			}
			.acrossai-mcp-n8n__token-expiry {
				margin: 6px 0 0;
				font-size: 12px;
				color: #50575e;
			}

			/* Generate Application Password button row inside STEP 1 of the
				Header Auth sub-panel — matches mcp-manager's MCPClientsBlock
				Step 1 layout (button + inline status span). */
			.acrossai-mcp-n8n__generate-app-password-row {
				display: flex;
				align-items: center;
				gap: 12px;
				margin: 0 0 10px;
				flex-wrap: wrap;
			}
			.acrossai-mcp-n8n__generate-app-password-hint {
				margin: 0 0 12px;
				color: #50575e;
			}
			.acrossai-mcp-n8n__app-password-status,
			.acrossai-generate-app-password-status {
				color: #1e3a8a;
				font-weight: 500;
			}
			.acrossai-mcp-n8n__app-password-status:empty,
			.acrossai-generate-app-password-status:empty {
				display: none;
			}
			.acrossai-mcp-n8n__app-password-status--error {
				color: #b32d2e;
			}

			/* App-password result block (populated by n8n-admin.js after
				Generate succeeds). Card layout: field label, wide input +
				Reveal + Copy in a row, username hint below. */
			.acrossai-mcp-n8n__app-password-block {
				margin: 8px 0 16px;
				padding: 14px 16px;
				background: #ffffff;
				border: 1px solid #dcdcde;
				border-radius: 4px;
			}
			.acrossai-mcp-n8n__app-password-block .acrossai-mcp-n8n__field-label {
				margin: 0 0 8px;
			}
			.acrossai-mcp-n8n__app-password-row {
				display: flex;
				align-items: stretch;
				gap: 8px;
				margin: 0;
			}
			.acrossai-mcp-n8n__app-password-row .acrossai-mcp-connector__input {
				flex: 1;
				min-width: 0;
				padding: 8px 12px;
				font-family: Consolas, Monaco, "Andale Mono", "DejaVu Sans Mono", monospace;
				font-size: 13px;
				background: #f6f7f7;
				border: 1px solid #dcdcde;
				border-radius: 4px;
			}
			.acrossai-mcp-n8n__app-password-meta {
				margin: 10px 0 0;
				font-size: 12px;
				color: #50575e;
			}

			/* Header Auth STEP 1 — space out the trailing "Passwords generated
				… profile page" notice from the app-password block above so
				they don't merge visually. AbstractServerTab::passwords_notice()
				emits <p class="description">…</p> — target that as an adjacent
				sibling of the app-password block. */
			.acrossai-mcp-n8n__app-password-block + .description {
				margin-top: 14px;
			}
			/* Same treatment when there's no app-password block yet — space
				from the description hint above. */
			.acrossai-mcp-n8n__generate-app-password-hint + .description {
				margin-top: 12px;
			}

			/* Top-of-panel MCP endpoint URL row — bump the label, tighten
				the input group, add breathing room below so the Header/Bearer
				sub-panels' setup card doesn't feel crammed against it. */
			.acrossai-mcp-n8n > .acrossai-mcp-n8n__field-label,
			.acrossai-mcp-n8n > .acrossai-mcp-connector__label {
				margin-top: 4px;
				font-size: 13px;
				font-weight: 600;
			}
			.acrossai-mcp-n8n__panel > .acrossai-mcp-n8n__field-label,
			.acrossai-mcp-n8n__panel > .acrossai-mcp-connector__label {
				margin-top: 4px;
				font-size: 13px;
				font-weight: 600;
			}
		</style>
		<?php
	}
}
