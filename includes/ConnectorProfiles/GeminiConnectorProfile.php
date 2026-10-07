<?php
/**
 * Google Gemini Connector Profile.
 *
 * Registers Google Gemini as a browser-flow OAuth connector against the
 * acrossai-mcp-manager base plugin's ConnectorProfileRegistry.
 *
 * @package AcrossAI_MCP_Manager
 * @since   0.6.0
 */

namespace AcrossAI_MCP_Manager\Includes\ConnectorProfiles;

defined( 'ABSPATH' ) || exit;

/**
 * Gemini-specific connector profile.
 *
 * Gemini spans multiple surfaces that each connect to a remote MCP server
 * a bit differently:
 *
 *   1. Gemini web (gemini.google.com) — DCR-registered browser client.
 *   2. Gemini Enterprise (Google Workspace) — pre-registered credentials,
 *      static callback `https://vertexaisearch.cloud.google.com/oauth-redirect`.
 *   3. Antigravity (Google's AI IDE) — pre-registered credentials, static
 *      callback `https://antigravity.google/oauth-callback`.
 *   4. Gemini CLI — DCR + ephemeral-port loopback URIs; handled by
 *      AuthorizationController's RFC 8252 §7.3 loopback matcher.
 *
 * The whitelist below carries the two static callbacks (Enterprise +
 * Antigravity) so admins can generate manual credentials for either
 * surface. The DCR host list attributes the web + CLI + Enterprise +
 * Antigravity origins so dynamically-registered clients still land in
 * the Gemini bucket on the Connections tab.
 *
 * Singleton per Architecture Principle A1 (zero add_action/add_filter in
 * constructors). Extends the base plugin's AbstractConnectorProfile via
 * leading-`\` FQN — never `use ...\AbstractConnectorProfile;` — to avoid
 * import-symbol collisions (DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION
 * pattern from base plugin memory).
 *
 * @since 0.6.0
 */
final class GeminiConnectorProfile extends \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile {

	/**
	 * Google-published callback URLs for Gemini surfaces that support
	 * manual credential generation. Enterprise + Antigravity are pinned
	 * verbatim from Google's docs — no wildcards, not port-flexible.
	 *
	 * The Gemini web app (gemini.google.com) and Gemini CLI do NOT expose
	 * a static callback URL and always use DCR — they don't need to appear
	 * in this whitelist.
	 */
	private const REDIRECT_URIS = array(
		'https://vertexaisearch.cloud.google.com/oauth-redirect',
		'https://antigravity.google/oauth-callback',
	);

	/**
	 * Google-owned hostnames used for DCR attribution. Covers every
	 * surface that might register dynamically:
	 *   - `gemini.google.com` — web app (direct-branded flow).
	 *   - `vertexaisearch.cloud.google.com` — Gemini Enterprise.
	 *   - `antigravity.google` — Antigravity IDE.
	 *   - `oauth-redirect.googleusercontent.com` — Google's generic OAuth
	 *     callback proxy used by the Gemini custom-MCP-connector flow (the
	 *     Google-side connector registers itself with a callback of shape
	 *     `https://oauth-redirect.googleusercontent.com/r/user_bound_custom-mcp-<uid>-<site>`).
	 *   - `oauth-redirect-sandbox.googleusercontent.com` / `oauth-redirect-test.googleusercontent.com`
	 *     — sandbox and test variants of the same proxy; Google always
	 *     registers all three simultaneously.
	 * Gemini CLI uses loopback callbacks — the RFC 8252 §7.3 matcher in
	 * AuthorizationController handles those directly, no host list needed.
	 */
	private const DCR_HOSTS = array(
		'gemini.google.com',
		'vertexaisearch.cloud.google.com',
		'antigravity.google',
		'oauth-redirect.googleusercontent.com',
		'oauth-redirect-sandbox.googleusercontent.com',
		'oauth-redirect-test.googleusercontent.com',
	);

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 * @since 0.6.0
	 */
	protected static $instance = null;

	/**
	 * Private constructor — enforce singleton and A1 (no hook wiring here).
	 *
	 * @since 0.6.0
	 */
	private function __construct() {}

	/**
	 * Get the shared singleton instance.
	 *
	 * @return self
	 * @since 0.6.0
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
	 * @since 0.6.0
	 */
	public function get_slug(): string {
		return 'gemini';
	}

	/**
	 * User-facing connector name.
	 *
	 * @return string
	 * @since 0.6.0
	 */
	public function get_name(): string {
		return __( 'Gemini', 'acrossai-mcp-manager' );
	}

	/**
	 * @return int
	 */
	public function get_priority(): int {
		return 30;
	}

	/**
	 * URL to the bundled icon shipped with this plugin.
	 *
	 * @return string
	 * @since 0.6.0
	 */
	public function get_icon_url(): string {
		return plugins_url( 'assets/gemini-icon.svg', \ACROSSAI_MCP_MANAGER_PLUGIN_FILE );
	}

	/**
	 * Allowed redirect URIs — Gemini Enterprise + Antigravity static
	 * callbacks. Web app + CLI go through DCR and don't need pre-approval.
	 *
	 * @return string[]
	 * @since 0.6.0
	 */
	public function get_redirect_uri_whitelist(): array {
		return self::REDIRECT_URIS;
	}

	/**
	 * Step-by-step HTML instructions for the operator setting up Gemini
	 * manually (Enterprise / Antigravity flow). Web-app users don't hit
	 * this path — their client registers itself via DCR when they paste
	 * the MCP URL.
	 *
	 * @param array<string,mixed> $server         Server row from the base plugin's
	 *                                            MCP Servers table.
	 * @param string              $client_id      The freshly-generated OAuth client_id.
	 * @param string              $client_secret  The freshly-generated OAuth client_secret.
	 * @return string HTML block, escaped where necessary.
	 * @since 0.6.0
	 */
	public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
		unset( $server, $client_secret ); // Server-context + secret are shown separately in render_tab_section.

		ob_start();
		?>
		<ol class="acrossai-ai-setup-steps">
			<li><?php esc_html_e( 'Open your Gemini Enterprise or Antigravity admin console.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Add a new custom MCP server. Paste the MCP URL shown above into the server URL field.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'When prompted for OAuth credentials, choose "Use existing credentials".', 'acrossai-mcp-manager' ); ?></li>
			<li>
			<?php
				printf(
					/* translators: %s: OAuth client identifier value. */
					esc_html__( 'Paste the client_id shown above (%s) into the OAuth Client ID field.', 'acrossai-mcp-manager' ),
					'<code>' . esc_html( $client_id ) . '</code>'
				);
			?>
				</li>
			<li><?php esc_html_e( 'Click "Reveal" next to the client secret, then copy and paste it into the OAuth Client Secret field.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Save the connection, then approve the consent screen when it opens on this WordPress site.', 'acrossai-mcp-manager' ); ?></li>
		</ol>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Consent-screen branding. The base plugin's AuthorizationController
	 * consumes this array when rendering the consent template.
	 *
	 * @return array{heading:string,subtitle:string,permissions_bullets:string[]}
	 * @since 0.6.0
	 */
	public function get_consent_branding(): array {
		return array(
			'heading'             => __( 'Gemini wants to connect to your site', 'acrossai-mcp-manager' ),
			'subtitle'            => __( 'This will let Gemini access the MCP tools this server exposes, acting as your WordPress user.', 'acrossai-mcp-manager' ),
			'permissions_bullets' => array(
				__( 'Read and act on the tools exposed on this MCP server', 'acrossai-mcp-manager' ),
				__( 'Access the WordPress data your user account has permission to see', 'acrossai-mcp-manager' ),
				__( 'Connect until you revoke access from the Connectors/Integrations tab', 'acrossai-mcp-manager' ),
			),
		);
	}

	/**
	 * Claim DCR-registered clients whose redirect URIs point at a
	 * Google-owned Gemini host. Prefer host-based matching over substring
	 * name matching — the name field is arbitrary. Falls back to a
	 * whole-word `gemini` regex match on client_name for edge cases where
	 * a client registers with a proxy callback but declares its identity
	 * via the client_name field.
	 *
	 * @param string             $client_name   DCR-submitted client_name.
	 * @param array<int, string> $redirect_uris DCR-submitted redirect_uris.
	 * @return bool
	 * @since 0.6.0
	 */
	public function matches_dcr_client( string $client_name, array $redirect_uris ): bool {
		if ( self::dcr_matches_by_host( $redirect_uris, self::DCR_HOSTS ) ) {
			return true;
		}
		return (bool) preg_match( '/\bgemini\b/i', $client_name );
	}

	/**
	 * Gemini-branded "How to connect" panel. Covers the surfaces that talk
	 * to a remote MCP server today — Gemini on the web, the Gemini CLI
	 * (@google/gemini-cli), and Gemini Enterprise / Antigravity — then links
	 * to acrossai.co/gemini-connectors/ for the fuller walkthrough. DCR
	 * handles registration for web + CLI; Enterprise / Antigravity use the
	 * pre-generated OAuth credentials from the Generate panel above.
	 *
	 * @param string $mcp_url MCP endpoint URL.
	 * @return string HTML.
	 * @since 0.6.0
	 */
	public function get_mcp_url_setup_html( string $mcp_url ): string {
		$docs_url = 'https://acrossai.co/gemini-connectors/';
		// Gemini CLI's remote HTTP transport field is `httpUrl` (verified against
		// google-gemini/gemini-cli docs) — not `url`.
		$cli_json = '{
  "mcpServers": {
    "acrossai": {
      "httpUrl": "' . $mcp_url . '"
    }
  }
}';
		ob_start();
		?>
		<div class="acrossai-mcp-connector-panel__setup">
			<h4 class="acrossai-mcp-connector-panel__setup-title"><?php esc_html_e( 'How to connect Gemini', 'acrossai-mcp-manager' ); ?></h4>
			<p class="acrossai-mcp-connector-panel__setup-lead"><?php esc_html_e( 'Consumer Gemini (gemini.google.com) does NOT accept custom MCP servers — its Spark connectors are partnership-only. Custom MCP lives in three places: the Gemini CLI, Gemini Enterprise Business Edition (business.gemini.google), and Gemini Enterprise Standard/Plus/Frontline (Google Cloud Console). Every Gemini Enterprise edition requires StreamableHTTP transport — SSE is not supported.', 'acrossai-mcp-manager' ); ?></p>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Gemini CLI (terminal)', 'acrossai-mcp-manager' ); ?></h5>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li>
					<?php
						printf(
							/* translators: %s: install command as inline code */
							esc_html__( 'Install the CLI: %s.', 'acrossai-mcp-manager' ),
							'<code>npm install -g @google/gemini-cli</code>'
						);
					?>
						</li>
					<li>
					<?php
						printf(
							/* translators: 1: global config path as inline code, 2: project config path as inline code */
							esc_html__( 'Open (or create) %1$s (global) or %2$s (this project only).', 'acrossai-mcp-manager' ),
							'<code>~/.gemini/settings.json</code>',
							'<code>.gemini/settings.json</code>'
						);
					?>
						</li>
					<li>
					<?php
						printf(
							/* translators: %s: field name as inline code */
							esc_html__( 'Merge this entry into the mcpServers block (or paste it whole if the file is empty). Note: Gemini CLI uses %s for remote HTTP servers, not "url":', 'acrossai-mcp-manager' ),
							'<code>httpUrl</code>'
						);
					?>
						<pre class="acrossai-mcp-connector-panel__setup-cmd"><code><?php echo esc_html( $cli_json ); ?></code></pre>
					</li>
					<li>
					<?php
						printf(
							/* translators: %s: CLI command as inline code */
							esc_html__( 'Run %s and follow the browser OAuth flow when the CLI prints the authorization URL.', 'acrossai-mcp-manager' ),
							'<code>gemini</code>'
						);
					?>
						</li>
				</ol>
			</section>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Gemini Enterprise — Business Edition (business.gemini.google)', 'acrossai-mcp-manager' ); ?></h5>
				<p><em><?php esc_html_e( 'Admin-only. Business Edition tenants: only Team admins see the Add MCP Server option; every teammate can use the server once it is enabled.', 'acrossai-mcp-manager' ); ?></em></p>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li>
					<?php
						printf(
							/* translators: %s: URL to Gemini Enterprise Business console */
							esc_html__( 'Sign in as a Team admin at %s and click Settings & help → your team → Manage team → Connected apps.', 'acrossai-mcp-manager' ),
							'<a href="https://business.gemini.google/" target="_blank" rel="noopener noreferrer">business.gemini.google</a>'
						);
					?>
						</li>
					<li><?php esc_html_e( 'Click Add MCP Server. In MCP Info, fill Name (e.g. "AcrossAI"), Description, and paste the MCP Server URL below:', 'acrossai-mcp-manager' ); ?>
						<p><code><?php echo esc_html( $mcp_url ); ?></code></p>
					</li>
					<li><?php esc_html_e( 'Under Authentication settings, choose OAuth 2.0. Click Generate credentials above to mint an OAuth client, then paste the client_id, client_secret, authorization URL, token URL, and scopes into the matching Enterprise fields (Enterprise does not use Dynamic Client Registration). Click Verify Auth.', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'Save. New connections are disabled by default — go to the Actions page for this server, click Reload custom actions, tick each action you want to expose (up to 100), then click Enable Actions.', 'acrossai-mcp-manager' ); ?></li>
				</ol>
			</section>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Gemini Enterprise — Standard / Plus / Frontline (Google Cloud Console)', 'acrossai-mcp-manager' ); ?></h5>
				<p><em><?php esc_html_e( 'These editions provision MCP servers as Data Store connectors in the Google Cloud Console. Business Edition uses the separate business.gemini.google flow above.', 'acrossai-mcp-manager' ); ?></em></p>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li><?php esc_html_e( 'Open Google Cloud Console → Gemini Enterprise → Data stores, and click Create data store.', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'Search for and select Custom MCP Server, then choose a multi-region for the data connector and give it a name.', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'Paste the MCP Server URL below (must be HTTPS + StreamableHTTP; SSE is not supported):', 'acrossai-mcp-manager' ); ?>
						<p><code><?php echo esc_html( $mcp_url ); ?></code></p>
					</li>
					<li><?php esc_html_e( 'Choose an authentication mode. For OAuth 2.0, click Generate credentials above to mint an OAuth client, then paste client_id, client_secret, Authorization URL, Token URL, and space-separated scopes; enable PKCE if your policy requires it.', 'acrossai-mcp-manager' ); ?></li>
					<li><?php esc_html_e( 'Create. Wait for the data store status to become Active, then open Actions → Reload custom actions and enable the actions you want to expose. Configure tool annotations (readOnlyHint / destructiveHint) if your team wants extra confirmation before destructive calls.', 'acrossai-mcp-manager' ); ?></li>
				</ol>
			</section>

			<p class="acrossai-mcp-connector-panel__setup-footnote">
				<?php
				printf(
					/* translators: %s: link to the AcrossAI Gemini Connectors docs */
					esc_html__( 'Still stuck? Full walkthroughs, screenshots, and troubleshooting live at %s.', 'acrossai-mcp-manager' ),
					'<a href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener noreferrer">acrossai.co/gemini-connectors/</a>'
				);
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render the Gemini "How to connect" panel below the standard card.
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
}
