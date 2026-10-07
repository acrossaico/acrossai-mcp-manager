<?php
/**
 * Grok Connector Profile.
 *
 * Registers Grok as a browser-flow OAuth connector against the
 * acrossai-mcp-manager base plugin's ConnectorProfileRegistry.
 *
 * @package AcrossAI_MCP_Manager
 * @since   0.1.0
 */

namespace AcrossAI_MCP_Manager\Includes\ConnectorProfiles;

defined( 'ABSPATH' ) || exit;

/**
 * Grok-specific connector profile.
 *
 * Contributes Grok-branded consent-screen strings, the xAI
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
final class GrokConnectorProfile extends \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile {

	/**
	 * Hostnames owned by xAI / Grok, used for DCR attribution.
	 *
	 * Already covers `grok.com`, so the callback added to REDIRECT_URIS below
	 * needs no change here — a Grok client that self-registers is still
	 * attributed by host exactly as before.
	 */
	private const DCR_HOSTS = array( 'grok.com', 'x.ai', 'grok.x.ai' );

	/**
	 * Grok's OAuth callback for custom MCP connectors.
	 *
	 * PROVENANCE: observed, then confirmed end-to-end. xAI publishes no callback
	 * URL, so this value was first taken from a real Dynamic Client
	 * Registration Grok performed against a production site (acrossai.co,
	 * 2026-08-10) — the `redirect_uris` it submitted for itself. On 2026-09-10
	 * it was verified the other way too: an admin-generated client using this
	 * URI completed a full console.x.ai connect, so authorize's byte-exact
	 * comparison passes against Grok's real callback. Re-verify before changing
	 * it.
	 *
	 * The trailing slash is byte-significant:
	 * `AuthorizationController::assert_redirect_uri_or_die()` compares with
	 * `hash_equals` against the URI snapshotted onto the client row, and the
	 * RFC 8252 §7.3 loopback exception does not apply to a non-loopback host.
	 *
	 * ChatGPT appends a per-connector id to its base callback only when the
	 * authorization server fails RFC 9207 issuer identification; ours passes,
	 * so that profile whitelists its stable URI too (see
	 * ChatGPTConnectorProfile::REDIRECT_URIS). Grok has no such conditional
	 * branch — every registration observed so far uses this fixed URI with no
	 * per-connector segment, and a console.x.ai connect has been completed
	 * against it. If that ever stops being true,
	 * admin-generated Grok clients will fail authorize with "Invalid
	 * redirect_uri for this client." and this profile should go back to being
	 * DCR-only.
	 *
	 * @since 0.9.13
	 */
	private const REDIRECT_URIS = array( 'https://grok.com/connectors-oauth-exchange-code/' );

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
		return 'grok';
	}

	/**
	 * User-facing connector name.
	 *
	 * @return string
	 * @since 0.1.0
	 */
	public function get_name(): string {
		return __( 'Grok', 'acrossai-mcp-manager' );
	}

	/**
	 * @return int
	 */
	public function get_priority(): int {
		return 40;
	}

	/**
	 * URL to the bundled icon shipped with this plugin.
	 *
	 * @return string
	 * @since 0.1.0
	 */
	public function get_icon_url(): string {
		return plugins_url( 'assets/grok-icon.svg', \ACROSSAI_MCP_MANAGER_PLUGIN_FILE );
	}

	/**
	 * Allowed redirect URIs. Non-empty since 0.9.13, which switches on the
	 * manual "Generate credentials" button in the admin card and stops
	 * `POST /oauth/generate-client` refusing with 409 `dcr_only_connector`.
	 *
	 * That matters because Grok has two connector surfaces and only one of
	 * them registers itself: `grok.com/connectors` performs DCR, while the
	 * Business / Enterprise surface at `console.x.ai` is admin-only and
	 * demands manual OAuth credentials. Until this returned a URI, the plugin
	 * could not serve that second surface at all.
	 *
	 * @return string[]
	 */
	public function get_redirect_uri_whitelist(): array {
		return self::REDIRECT_URIS;
	}

	/**
	 * Step-by-step HTML instructions for the operator setting up Grok.
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

		// Same values the site publishes at /.well-known/oauth-authorization-server.
		// Rendered literally rather than named, because an operator filling in
		// console.x.ai has no other way to discover them — omitting them is
		// what left Business/Enterprise accounts stuck with credentials that
		// could not be used.
		$issuer        = \AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryController::issuer();
		$authorize_url = $issuer . '/authorize';
		$token_url     = $issuer . '/token';

		ob_start();
		?>
		<p class="acrossai-ai-setup-lead"><?php esc_html_e( 'These credentials are for the Grok Business / Enterprise surface at console.x.ai, which is admin-only and requires manual OAuth details. On an individual grok.com account you do not need them — leave the Advanced OAuth fields empty and Grok will register itself.', 'acrossai-mcp-manager' ); ?></p>
		<ol class="acrossai-ai-setup-steps">
			<li><?php esc_html_e( 'Sign in at console.x.ai with Team Read-Write permissions and select your team.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Open Grok Business → Connectors, click + Add Connector, and choose Other.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Paste the MCP URL shown above into the MCP Server URL field.', 'acrossai-mcp-manager' ); ?></li>
			<li>
			<?php
				printf(
					/* translators: %s: OAuth client identifier value. */
					esc_html__( 'Client ID — paste %s', 'acrossai-mcp-manager' ),
					'<code>' . esc_html( $client_id ) . '</code>'
				);
			?>
				</li>
			<li><?php esc_html_e( 'Client Secret — click "Reveal" above, then copy and paste it.', 'acrossai-mcp-manager' ); ?></li>
			<li>
			<?php
				printf(
					/* translators: %s: OAuth authorization endpoint URL. */
					esc_html__( 'Authorization Endpoint — paste %s', 'acrossai-mcp-manager' ),
					'<code>' . esc_html( $authorize_url ) . '</code>'
				);
			?>
				</li>
			<li>
			<?php
				printf(
					/* translators: %s: OAuth token endpoint URL. */
					esc_html__( 'Token Endpoint — paste %s', 'acrossai-mcp-manager' ),
					'<code>' . esc_html( $token_url ) . '</code>'
				);
			?>
				</li>
			<li>
			<?php
				printf(
					/* translators: %s: the OAuth scope value. */
					esc_html__( 'Scopes — enter %s', 'acrossai-mcp-manager' ),
					'<code>mcp</code>'
				);
			?>
				</li>
			<li>
			<?php
				printf(
					/* translators: %s: the token endpoint authentication method value. */
					esc_html__( 'Token Auth Method — choose %s, because the credentials above include a secret.', 'acrossai-mcp-manager' ),
					'<code>client_secret_post</code>'
				);
			?>
				</li>
			<li><?php esc_html_e( 'Save the connector. Each teammate then opens grok.com/connectors, clicks Connect on it, and approves the consent screen on this WordPress site.', 'acrossai-mcp-manager' ); ?></li>
		</ol>
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
			'heading'             => __( 'Grok wants to connect to your site', 'acrossai-mcp-manager' ),
			'subtitle'            => __( 'This will let Grok access the MCP tools this server exposes, acting as your WordPress user.', 'acrossai-mcp-manager' ),
			'permissions_bullets' => array(
				__( 'Read and act on the tools exposed on this MCP server', 'acrossai-mcp-manager' ),
				__( 'Access the WordPress data your user account has permission to see', 'acrossai-mcp-manager' ),
				__( 'Connect until you revoke access from the Connectors/Integrations tab', 'acrossai-mcp-manager' ),
			),
		);
	}

	/**
	 * Claim DCR-registered clients whose redirect URIs point at a
	 * Grok / xAI-owned host. Prefer host-based matching over substring
	 * name matching so short brand words don't false-positive against
	 * unrelated clients ("xai" is a two-letter root that could appear in
	 * random client names or paths).
	 *
	 * @param string             $client_name   DCR-submitted client_name.
	 * @param array<int, string> $redirect_uris DCR-submitted redirect_uris.
	 * @return bool
	 */
	public function matches_dcr_client( string $client_name, array $redirect_uris ): bool {
		if ( self::dcr_matches_by_host( $redirect_uris, self::DCR_HOSTS ) ) {
			return true;
		}
		// Name-only fallback — whole-word `grok` match. No `xai` fallback
		// since the two-letter root is too fragile.
		return (bool) preg_match( '/\bgrok\b/i', $client_name );
	}

	/**
	 * Grok-branded "How to connect" panel. Covers the two Grok surfaces
	 * that accept a custom MCP server today — Grok on the web (grok.com)
	 * and the Grok mobile / X app — and links to
	 * acrossai.co/grok-connectors/ for the fuller walkthrough. Grok uses
	 * Dynamic Client Registration, so no credentials need to be pasted.
	 *
	 * @param string $mcp_url MCP endpoint URL.
	 * @return string HTML.
	 */
	public function get_mcp_url_setup_html( string $mcp_url ): string {
		$docs_url = 'https://acrossai.co/grok-connectors/';
		ob_start();
		?>
		<div class="acrossai-mcp-connector-panel__setup">
			<h4 class="acrossai-mcp-connector-panel__setup-title"><?php esc_html_e( 'How to connect Grok', 'acrossai-mcp-manager' ); ?></h4>
			<p class="acrossai-mcp-connector-panel__setup-lead"><?php esc_html_e( 'Grok exposes custom MCP connectors at grok.com/connectors for individual accounts (feature availability depends on your current xAI plan — SuperGrok / X Premium+ / free where enabled), and at the console.x.ai admin surface for Business and Enterprise teams. Both paths end with an OAuth consent screen on this WordPress site.', 'acrossai-mcp-manager' ); ?></p>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Individual account (grok.com)', 'acrossai-mcp-manager' ); ?></h5>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li>
					<?php
						printf(
							/* translators: %s: URL to grok.com/connectors */
							esc_html__( 'Sign in and open %s in your browser.', 'acrossai-mcp-manager' ),
							'<a href="https://grok.com/connectors" target="_blank" rel="noopener noreferrer">grok.com/connectors</a>'
						);
					?>
						</li>
					<li><?php esc_html_e( 'Click New Connector, then choose Custom.', 'acrossai-mcp-manager' ); ?></li>
					<li>
					<?php
						printf(
							/* translators: %s: MCP URL as inline code */
							esc_html__( 'Paste %s as the MCP server URL and give the connector a name (e.g. AcrossAI). Leave the Advanced OAuth fields empty — on grok.com Grok registers itself via Dynamic Client Registration. The console.x.ai admin surface below is different: it requires the manual credentials.', 'acrossai-mcp-manager' ),
							'<code>' . esc_html( $mcp_url ) . '</code>'
						);
					?>
						</li>
					<li><?php esc_html_e( 'Click Add, then Connect, and approve the OAuth consent screen when it opens on this WordPress site.', 'acrossai-mcp-manager' ); ?></li>
				</ol>
			</section>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Team / Enterprise (Admin adds once, teammates authorize)', 'acrossai-mcp-manager' ); ?></h5>
				<p><em><?php esc_html_e( 'Regular team members can’t add a connector on Grok Business or Enterprise — the "Add Connector" surface is admin-only. Once the admin saves the connector, every teammate sees it on grok.com/connectors and authorizes it themselves.', 'acrossai-mcp-manager' ); ?></em></p>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li>
					<?php
						printf(
							/* translators: %s: URL to the xAI console */
							esc_html__( 'Admin-only: sign in with Team Read-Write permissions at %s and select your team.', 'acrossai-mcp-manager' ),
							'<a href="https://console.x.ai" target="_blank" rel="noopener noreferrer">console.x.ai</a>'
						);
					?>
						</li>
					<li><?php esc_html_e( 'Open Grok Business → Connectors and click + Add Connector.', 'acrossai-mcp-manager' ); ?></li>
					<li>
					<?php
						printf(
							/* translators: %s: MCP URL as inline code */
							esc_html__( 'Select Other, name the connector (e.g. AcrossAI), and paste %s as the MCP server URL. This surface does NOT self-register — use Generate credentials above, then fill in the Client ID, Client Secret, Authorization Endpoint, Token Endpoint, Scopes and Token Auth Method exactly as the panel that appears lists them, and save.', 'acrossai-mcp-manager' ),
							'<code>' . esc_html( $mcp_url ) . '</code>'
						);
					?>
						</li>
					<li>
					<?php
						printf(
							/* translators: %s: URL to grok.com/connectors */
							esc_html__( 'Each teammate then opens %s, clicks Connect on the AcrossAI row, and approves the OAuth consent screen on this WordPress site.', 'acrossai-mcp-manager' ),
							'<a href="https://grok.com/connectors" target="_blank" rel="noopener noreferrer">grok.com/connectors</a>'
						);
					?>
						</li>
				</ol>
			</section>

			<p class="acrossai-mcp-connector-panel__setup-footnote">
				<?php
				printf(
					/* translators: %s: link to the AcrossAI Grok Connectors docs */
					esc_html__( 'Still stuck? Full walkthroughs, screenshots, and troubleshooting live at %s.', 'acrossai-mcp-manager' ),
					'<a href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener noreferrer">acrossai.co/grok-connectors/</a>'
				);
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the Grok "How to connect" panel below the standard card.
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return void
	 */
	protected function render_card_body( array $server ): void {
		parent::render_card_body( $server );
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
