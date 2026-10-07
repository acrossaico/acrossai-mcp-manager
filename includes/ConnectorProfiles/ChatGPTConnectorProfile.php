<?php
/**
 * ChatGPT Connector Profile.
 *
 * Registers ChatGPT as a browser-flow OAuth connector against the
 * acrossai-mcp-manager base plugin's ConnectorProfileRegistry.
 *
 * @package AcrossAI_MCP_Manager
 * @since   0.1.0
 */

namespace AcrossAI_MCP_Manager\Includes\ConnectorProfiles;

defined( 'ABSPATH' ) || exit;

/**
 * ChatGPT-specific connector profile.
 *
 * Contributes ChatGPT-branded consent-screen strings, the OpenAI
 * callback whitelist, setup instructions, and the per-server tab section
 * rendered by the base plugin's AIConnectorsTab.
 *
 * Singleton per Architecture Principle A1 (zero add_action/add_filter in
 * constructors). Extends the base plugin's AbstractConnectorProfile via
 * leading-`\` FQN — never `use ...\AbstractConnectorProfile;` — to avoid
 * import-symbol collisions (DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION
 * pattern from base plugin memory).
 *
 * At load time, the base plugin's AbstractConnectorProfile class may not
 * exist (base pre-Feature-021, or base absent). Callers MUST guard
 * instantiation with class_exists(); see includes/Main.php.
 *
 * @since 0.1.0
 */
final class ChatGPTConnectorProfile extends \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile {

	/**
	 * OpenAI-owned hostnames used for DCR attribution.
	 * chatgpt.com is the current ChatGPT origin; openai.com and
	 * platform.openai.com cover developer-side callback shapes.
	 */
	private const DCR_HOSTS = array( 'chatgpt.com', 'openai.com', 'platform.openai.com' );

	/**
	 * ChatGPT's stable OAuth callback for MCP connectors.
	 *
	 * OpenAI sends one of two callback shapes, and which one is NOT a
	 * property of the connector — it is a property of the authorization
	 * server it is talking to:
	 *
	 *   • An authorization server that does not meet OpenAI's issuer
	 *     identification requirement gets the callback-ID-specific form,
	 *     `https://chatgpt.com/connector/oauth/{callback_id}`. That id is
	 *     minted per connector and is unknowable at generate time, so it
	 *     cannot be whitelisted in advance.
	 *   • An authorization server that DOES meet it gets this fixed URI.
	 *
	 * The requirement is RFC 9207: advertise
	 * `authorization_response_iss_parameter_supported` and return `iss` on
	 * every authorization response, success and error alike, byte-equal to
	 * the metadata `issuer` (OpenAI compares exactly — no normalization of
	 * trailing slashes, paths, ports or casing). This plugin's OAuth server
	 * meets all three, and has since before this profile existed:
	 * `DiscoveryController::render_authorization_server_metadata()` sets the
	 * flag true, and `AuthorizationController` appends `iss` to the approval
	 * redirect and to `redirect_error()`. Both sides read
	 * `DiscoveryController::issuer()`, so the byte-equality holds by
	 * construction rather than by convention.
	 *
	 * Until 0.9.16 this profile returned an empty whitelist, which hid the
	 * admin "Generate credentials" button and made
	 * `POST /oauth/generate-client` answer 409 — ChatGPT was the one
	 * connector the plugin could not credential manually. The blocking
	 * docblock cited the derived-callback failure reported by Qlik, but read
	 * it as unconditional; it is the fallback branch, and we are not in it.
	 *
	 * The condition is worth re-checking if manual credentials ever fail at
	 * `/authorize` with "Invalid redirect_uri for this client." That is the
	 * signature of ChatGPT having decided this site is in the fallback
	 * branch — which is what happens when another plugin takes over
	 * `.well-known/oauth-authorization-server` (D21/D23) and answers with a
	 * different issuer. DCR is unaffected in that state: ChatGPT writes
	 * whichever URI it derived onto its own client row, so the recovery is
	 * to leave Client ID / Client Secret blank and let it self-register.
	 *
	 * @since 0.9.16
	 */
	private const REDIRECT_URIS = array( 'https://chatgpt.com/connector_platform_oauth_redirect' );

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 * @since 0.1.0
	 */
	protected static $instance = null;

	/**
	 * Private constructor — enforce singleton and A1 (no hook wiring here).
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Get the shared singleton instance.
	 *
	 * @return self
	 * @since 0.1.0
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Connector slug — the registry uses this key to look up the profile.
	 *
	 * @return string
	 * @since 0.1.0
	 */
	public function get_slug(): string {
		return 'chatgpt';
	}

	/**
	 * User-facing connector name.
	 *
	 * @return string
	 * @since 0.1.0
	 */
	public function get_name(): string {
		return __( 'ChatGPT', 'acrossai-mcp-manager' );
	}

	/**
	 * @return int
	 */
	public function get_priority(): int {
		return 20;
	}

	/**
	 * URL to the bundled icon shipped with this plugin.
	 *
	 * @return string
	 * @since 0.1.0
	 */
	public function get_icon_url(): string {
		return plugins_url( 'assets/chatgpt-icon.svg', \ACROSSAI_MCP_MANAGER_PLUGIN_FILE );
	}

	/**
	 * A non-empty whitelist is what keeps the admin card's "Generate
	 * credentials" button on screen and lets
	 * `ClientRegistrationController::handle_admin_generate()` mint a pair —
	 * the credentials an operator pastes into ChatGPT's Client ID / Client
	 * Secret fields when they would rather not rely on self-registration.
	 *
	 * See the REDIRECT_URIS docblock for why exactly one URI is correct
	 * here and what would invalidate it.
	 *
	 * @return string[]
	 */
	public function get_redirect_uri_whitelist(): array {
		return self::REDIRECT_URIS;
	}

	/**
	 * Step-by-step HTML instructions for the operator setting up ChatGPT.
	 *
	 * Written against the same surface the "How to connect" panel below
	 * documents — Settings → Plugins with Developer mode on, which replaced
	 * the "Connectors → Add custom connector" dialog these steps used to
	 * name. Endpoint values are deliberately absent: ChatGPT resolves the
	 * authorize and token endpoints from this site's
	 * `.well-known/oauth-authorization-server` document and offers no field
	 * to override them, so the only values an operator has to carry across
	 * are the two below. (Grok's console.x.ai form is the opposite case and
	 * its instructions render every endpoint literally.)
	 *
	 * @param array<string,mixed> $server         Server row from the base plugin's
	 *                                            MCP Servers table.
	 * @param string              $client_id      The freshly-generated OAuth client_id.
	 * @param string              $client_secret  The freshly-generated OAuth client_secret.
	 * @return string HTML block, escaped where necessary.
	 * @since 0.1.0
	 */
	public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
		unset( $server, $client_secret ); // Server-context + secret are shown separately in render_tab_section.

		ob_start();
		?>
		<p class="acrossai-ai-setup-lead"><?php esc_html_e( 'These credentials are optional. ChatGPT registers itself against this server if you leave the OAuth fields blank — use the pair below when you would rather issue the client yourself, or when self-registration is blocked on your workspace.', 'acrossai-mcp-manager' ); ?></p>
		<ol class="acrossai-ai-setup-steps">
			<li><?php esc_html_e( 'Open ChatGPT at https://chatgpt.com in a signed-in browser tab, with Developer mode already enabled (see Step 1 below).', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Go to Settings → Plugins and click the "+" (New Plugin) button.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Paste the MCP URL shown above into the MCP Server URL field, and set Authentication to OAuth.', 'acrossai-mcp-manager' ); ?></li>
			<li>
			<?php
				printf(
					/* translators: %s: OAuth client identifier value. */
					esc_html__( 'Paste the client_id shown above (%s) into the OAuth Client ID field.', 'acrossai-mcp-manager' ),
					'<code>' . esc_html( $client_id ) . '</code>'
				);
			?>
				</li>
			<li><?php esc_html_e( 'Click "Reveal" next to the client secret, then copy and paste it into the OAuth Client Secret field. It is shown once.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Tick the elevated-risk acknowledgment, then click Create.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Approve the consent screen when it opens on this WordPress site.', 'acrossai-mcp-manager' ); ?></li>
		</ol>
		<p class="acrossai-ai-setup-footnote"><?php esc_html_e( 'If ChatGPT reports an invalid redirect URI, this site is not being recognised as its own OAuth authorization server — usually because another plugin is answering the discovery address. Remove the credentials from the ChatGPT form and let it register itself instead, then check AcrossAI → Notices.', 'acrossai-mcp-manager' ); ?></p>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Consent-screen branding. The base plugin's AuthorizationController
	 * consumes this array when rendering the consent template.
	 *
	 * @return array{heading:string,subtitle:string,permissions_bullets:string[]}
	 * @since 0.1.0
	 */
	public function get_consent_branding(): array {
		return array(
			'heading'             => __( 'ChatGPT wants to connect to your site', 'acrossai-mcp-manager' ),
			'subtitle'            => __( 'This will let ChatGPT access the MCP tools this server exposes, acting as your WordPress user.', 'acrossai-mcp-manager' ),
			'permissions_bullets' => array(
				__( 'Read and act on the tools exposed on this MCP server', 'acrossai-mcp-manager' ),
				__( 'Access the WordPress data your user account has permission to see', 'acrossai-mcp-manager' ),
				__( 'Connect until you revoke access from the Connectors/Integrations tab', 'acrossai-mcp-manager' ),
			),
		);
	}

	/**
	 * Claim DCR-registered clients whose redirect URIs point at an
	 * OpenAI-owned host. Prefer host-based matching over substring name
	 * matching — "OpenAI" and "ChatGPT" appear in third-party client names
	 * frequently enough that name-only matching risked false positives
	 * (e.g., "OpenAI Community Bot" for an unrelated tool).
	 *
	 * @param string             $client_name   DCR-submitted client_name.
	 * @param array<int, string> $redirect_uris DCR-submitted redirect_uris.
	 * @return bool
	 */
	public function matches_dcr_client( string $client_name, array $redirect_uris ): bool {
		if ( self::dcr_matches_by_host( $redirect_uris, self::DCR_HOSTS ) ) {
			return true;
		}
		// Name-only fallback — narrow, whole-word `chatgpt` match. No
		// `openai` fallback because that string is too common in
		// unrelated third-party client names.
		return (bool) preg_match( '/\bchatgpt\b/i', $client_name );
	}

	/**
	 * ChatGPT-branded "How to connect" panel. Verified against
	 * help.openai.com's Plugins + Developer Mode docs (2026): the surface
	 * ChatGPT surfaces custom MCP servers under is now called Plugins (the
	 * old "Connectors" label was retired in July 2026), Developer Mode has
	 * to be flipped on first, and the feature is limited to paid workspaces.
	 *
	 * @param string $mcp_url MCP endpoint URL.
	 * @return string HTML.
	 */
	public function get_mcp_url_setup_html( string $mcp_url ): string {
		$docs_url = 'https://acrossai.co/chatgpt-connectors/';
		ob_start();
		?>
		<div class="acrossai-mcp-connector-panel__setup">
			<h4 class="acrossai-mcp-connector-panel__setup-title"><?php esc_html_e( 'How to connect ChatGPT', 'acrossai-mcp-manager' ); ?></h4>
			<p class="acrossai-mcp-connector-panel__setup-lead"><?php esc_html_e( 'ChatGPT surfaces custom MCP servers under Plugins (renamed from Connectors in mid-2026) with Developer Mode enabled. Available on paid ChatGPT plans.', 'acrossai-mcp-manager' ); ?></p>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Step 1 — Turn on Developer Mode (one-time)', 'acrossai-mcp-manager' ); ?></h5>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li>
					<?php
						printf(
							/* translators: %s: URL to ChatGPT on the web */
							esc_html__( 'Sign in at %s in your browser (Business, Enterprise, Edu, or Pro plan).', 'acrossai-mcp-manager' ),
							'<a href="https://chatgpt.com" target="_blank" rel="noopener noreferrer">chatgpt.com</a>'
						);
					?>
						</li>
					<li><?php esc_html_e( 'Open Settings → Security and login and enable the Developer mode toggle (marked "ELEVATED RISK"). On Business/Enterprise workspaces the admin must first turn it on under Workspace Settings → Permissions & Roles → Connected data → Developer mode.', 'acrossai-mcp-manager' ); ?></li>
				</ol>
			</section>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Step 2 — Add this MCP server as a plugin', 'acrossai-mcp-manager' ); ?></h5>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li><?php esc_html_e( 'Open Settings → Plugins, then click the "+" (New Plugin) button in the top right of the Plugins list.', 'acrossai-mcp-manager' ); ?></li>
					<li>
					<?php
						printf(
							/* translators: %s: MCP URL as inline code */
							esc_html__( 'Paste %s into the MCP Server URL field.', 'acrossai-mcp-manager' ),
							'<code>' . esc_html( $mcp_url ) . '</code>'
						);
					?>
						</li>
					<li><?php esc_html_e( 'Set Authentication to OAuth. Leave Client ID / Client Secret blank and ChatGPT self-registers via Dynamic Client Registration — or click "Generate credentials" above and paste the pair into those two fields if you would rather issue the client yourself.', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'Tick the elevated-risk acknowledgment, then click Create.', 'acrossai-mcp-manager' ); ?></li>
				</ol>
			</section>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Step 3 — Use it in a chat', 'acrossai-mcp-manager' ); ?></h5>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li><?php esc_html_e( 'Open a chat and click the "+" icon in the composer, then search for and select your new plugin.', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'ChatGPT opens the browser OAuth flow the first time — approve the consent screen on this WordPress site.', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'The plugin (labeled "Plugin" in the UI) stays available across future chats — reconnect from Settings → Plugins if the row shows Reconnect.', 'acrossai-mcp-manager' ); ?></li>
				</ol>
			</section>

			<p class="acrossai-mcp-connector-panel__setup-footnote">
				<?php
				printf(
					/* translators: %s: link to the AcrossAI ChatGPT Connectors docs */
					esc_html__( 'Still stuck? Full walkthroughs, screenshots, and troubleshooting live at %s.', 'acrossai-mcp-manager' ),
					'<a href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener noreferrer">acrossai.co/chatgpt-connectors/</a>'
				);
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Override the shared card body so the ChatGPT "How to connect" panel
	 * renders on the tab immediately, without requiring the operator to
	 * click Generate first.
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return void
	 */
	protected function render_card_body( array $server ): void {
		parent::render_card_body( $server );
		parent::print_setup_styles();
		$mcp_url = self::mcp_url_for_server( $server );
		echo wp_kses_post( $this->get_mcp_url_setup_html( $mcp_url ) );
	}

	// F021 Phase 9 (2026-07-11): render_tab_section is now inherited from
	// AbstractConnectorProfile::render_default_card. The base plugin's shared
	// CSS + JS bundle (enqueued via Admin\Main::maybe_enqueue_ai_connectors_app)
	// handles all card layout, Generate/Regenerate buttons, and copy behavior.
	// This class is now pure metadata: get_slug + get_name + get_icon_url +
	// get_redirect_uri_whitelist + get_setup_instructions + get_consent_branding +
	// (F024) matches_dcr_client + get_mcp_url_setup_html.
}
