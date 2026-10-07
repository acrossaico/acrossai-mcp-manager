<?php
/**
 * Claude Connector Profile.
 *
 * Registers Claude as a browser-flow OAuth connector against the
 * acrossai-mcp-manager base plugin's ConnectorProfileRegistry.
 *
 * @package AcrossAI_MCP_Manager
 * @since   0.1.0
 */

namespace AcrossAI_MCP_Manager\Includes\ConnectorProfiles;

defined( 'ABSPATH' ) || exit;

/**
 * Claude-specific connector profile.
 *
 * Contributes Claude-branded consent-screen strings, the Anthropic
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
final class ClaudeConnectorProfile extends \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile {

	/**
	 * Anthropic's fixed OAuth callback URLs for MCP browser connectors.
	 * Both hosts are pre-registered: `claude.ai` is the current hosted
	 * surface, `claude.com` is Anthropic's stated forward-compat migration
	 * target — the official custom-connector docs explicitly ask operators
	 * to allowlist both.
	 *
	 * Claude Code CLI uses ephemeral-port loopback callbacks
	 * (http://127.0.0.1:{port}/callback) and always registers via DCR, so
	 * those URIs never need to appear in this static whitelist.
	 *
	 * @since 0.1.0
	 */
	private const REDIRECT_URIS = array(
		'https://claude.ai/api/mcp/auth_callback',
		'https://claude.com/api/mcp/auth_callback',
	);

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
		return 'claude';
	}

	/**
	 * User-facing connector name.
	 *
	 * @return string
	 * @since 0.1.0
	 */
	public function get_name(): string {
		return __( 'Claude', 'acrossai-mcp-manager' );
	}

	/**
	 * First in every connector list — the flagship connector.
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 10;
	}

	/**
	 * URL to the bundled icon shipped with this plugin.
	 *
	 * @return string
	 * @since 0.1.0
	 */
	public function get_icon_url(): string {
		return plugins_url( 'assets/claude-icon.svg', \ACROSSAI_MCP_MANAGER_PLUGIN_FILE );
	}

	/**
	 * Allowed redirect URIs — the current `claude.ai` callback plus its
	 * announced `claude.com` migration target. Neither is a wildcard, not
	 * port-flexible.
	 *
	 * @return string[]
	 * @since 0.1.0
	 */
	public function get_redirect_uri_whitelist(): array {
		return self::REDIRECT_URIS;
	}

	/**
	 * Step-by-step HTML instructions for the operator setting up Claude.
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
		<ol class="acrossai-ai-setup-steps">
			<li><?php esc_html_e( 'Open Claude at https://claude.ai in a signed-in browser tab.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Go to Settings → Connectors → Add custom connector.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Paste the MCP URL shown above into the URL field.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Expand "Advanced settings".', 'acrossai-mcp-manager' ); ?></li>
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
			<li><?php esc_html_e( 'Click Add.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Approve the consent screen when it opens on this WordPress site.', 'acrossai-mcp-manager' ); ?></li>
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
			'heading'             => __( 'Claude wants to connect to your site', 'acrossai-mcp-manager' ),
			'subtitle'            => __( 'This will let Claude access the MCP tools this server exposes, acting as your WordPress user.', 'acrossai-mcp-manager' ),
			'permissions_bullets' => array(
				__( 'Read and act on the tools exposed on this MCP server', 'acrossai-mcp-manager' ),
				__( 'Access the WordPress data your user account has permission to see', 'acrossai-mcp-manager' ),
				__( 'Connect until you revoke access from the Connectors/Integrations tab', 'acrossai-mcp-manager' ),
			),
		);
	}

	/**
	 * Anthropic-owned hostnames used for DCR attribution. Includes both the
	 * current `claude.ai` origin and the forward-compat `claude.com` target
	 * Anthropic has announced. Kept in sync with `get_redirect_uri_whitelist()`.
	 */
	private const DCR_HOSTS = array( 'claude.ai', 'claude.com', 'anthropic.com' );

	/**
	 * Claim DCR-registered clients whose redirect URIs point at an
	 * Anthropic-owned host. Host matching is preferred over the previous
	 * substring-on-client-name check because the client_name is
	 * self-declared free text — a redirect URI at `claude.ai` is a much
	 * stronger identity signal.
	 *
	 * As a fallback, we still substring-match the client_name for the edge
	 * case where a DCR registration ships without an explicit host we
	 * recognize (e.g., a wrapper client using a proxy callback). Kept
	 * intentionally narrow — no `anthropic` fallback since that string
	 * could show up in a third-party OpenAI-competitor's client_name.
	 *
	 * @param string             $client_name   DCR-submitted client_name.
	 * @param array<int, string> $redirect_uris DCR-submitted redirect_uris.
	 * @return bool
	 */
	public function matches_dcr_client( string $client_name, array $redirect_uris ): bool {
		if ( self::dcr_matches_by_host( $redirect_uris, self::DCR_HOSTS ) ) {
			return true;
		}
		// Name-only fallback — trimmed regex ensures 'claude' appears as a
		// standalone token, not embedded in another brand's name.
		return (bool) preg_match( '/\bclaude\b/i', $client_name );
	}

	/**
	 * F024 (2026-07-11): Claude-branded MCP URL setup instructions.
	 * Replaces the AbstractConnectorProfile default with step-by-step
	 * guides covering every Claude surface — the web app at claude.ai, the
	 * Claude for Desktop native app, and the Claude Code CLI — plus a link
	 * to acrossai.co/claude-connectors/ for anything not covered here.
	 *
	 * Rendered as an always-visible panel inside the connector card via
	 * {@see self::render_card_body()}, and also returned to the JS layer
	 * after "Generate credentials" so the same steps appear in the result
	 * area. Both surfaces pass this HTML through wp_kses_post at the render
	 * boundary — inline HTML is safe; scripts are stripped.
	 *
	 * @param string $mcp_url MCP endpoint URL.
	 * @return string HTML.
	 */
	public function get_mcp_url_setup_html( string $mcp_url ): string {
		$docs_url = 'https://acrossai.co/claude-connectors/';
		$cli_cmd  = sprintf( 'claude mcp add --transport http acrossai %s', $mcp_url );

		ob_start();
		?>
		<div class="acrossai-mcp-connector-panel__setup">
			<h4 class="acrossai-mcp-connector-panel__setup-title"><?php esc_html_e( 'How to connect Claude', 'acrossai-mcp-manager' ); ?></h4>
			<p class="acrossai-mcp-connector-panel__setup-lead"><?php esc_html_e( 'Pick the Claude surface you use. Every path ends with an OAuth consent screen on this WordPress site.', 'acrossai-mcp-manager' ); ?></p>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Claude on the web — individual (Pro/Max)', 'acrossai-mcp-manager' ); ?></h5>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li>
					<?php
						printf(
							/* translators: %s: URL to Claude on the web */
							esc_html__( 'Sign in at %s in your browser.', 'acrossai-mcp-manager' ),
							'<a href="https://claude.ai" target="_blank" rel="noopener noreferrer">claude.ai</a>'
						);
					?>
						</li>
					<li><?php esc_html_e( 'Open Customize → Connectors, click "+" and choose Add custom connector.', 'acrossai-mcp-manager' ); ?></li>
					<li>
					<?php
						printf(
							/* translators: %s: MCP URL as inline code */
							esc_html__( 'Paste %s into the remote MCP server URL field. Leave Advanced settings blank — no Client ID / Client Secret needed.', 'acrossai-mcp-manager' ),
							'<code>' . esc_html( $mcp_url ) . '</code>'
						);
					?>
						</li>
					<li><?php esc_html_e( 'Click Add, then Connect, and approve the consent screen when it opens on this WordPress site.', 'acrossai-mcp-manager' ); ?></li>
				</ol>
			</section>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Claude Team / Enterprise (Owner adds once, teammates connect)', 'acrossai-mcp-manager' ); ?></h5>
				<p><em><?php esc_html_e( 'Regular team members do NOT see an "Add custom connector" option on Team or Enterprise plans. Only Owners and Primary Owners can add a new connector; once they save it, every teammate sees an AcrossAI row they can Connect from Customize → Connectors.', 'acrossai-mcp-manager' ); ?></em></p>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li><?php esc_html_e( 'Owner-only: open Organization settings → Connectors, hover over Custom, and select Web.', 'acrossai-mcp-manager' ); ?></li>
					<li>
					<?php
						printf(
							/* translators: %s: MCP URL as inline code */
							esc_html__( 'Owner pastes %s as the remote MCP server URL and saves. (Anthropic’s servers must be able to reach this URL over HTTPS — private-network URLs will not work.)', 'acrossai-mcp-manager' ),
							'<code>' . esc_html( $mcp_url ) . '</code>'
						);
					?>
						</li>
					<li><?php esc_html_e( 'Every teammate — Owner included — then opens Customize → Connectors, finds the AcrossAI row, clicks Connect, and approves the OAuth consent screen on this WordPress site. Each user’s Claude account is authorized individually; the Owner’s save only makes the connector available.', 'acrossai-mcp-manager' ); ?></li>
				</ol>
			</section>

			<section class="acrossai-mcp-connector-panel__setup-section">
				<h5><?php esc_html_e( 'Claude Code (terminal / CLI)', 'acrossai-mcp-manager' ); ?></h5>
				<ol class="acrossai-mcp-connector-panel__setup-steps">
					<li>
					<?php
						printf(
							/* translators: %s: install command as inline code */
							esc_html__( 'Install Claude Code if you haven’t yet: %s.', 'acrossai-mcp-manager' ),
							'<code>npm install -g @anthropic-ai/claude-code</code>'
						);
					?>
						</li>
					<li><?php esc_html_e( 'Open a terminal and run:', 'acrossai-mcp-manager' ); ?>
						<pre class="acrossai-mcp-connector-panel__setup-cmd"><code><?php echo esc_html( $cli_cmd ); ?></code></pre>
					</li>
					<li><?php esc_html_e( 'Follow the browser OAuth flow when Claude Code prints the authorization URL.', 'acrossai-mcp-manager' ); ?></li>
					<li>
					<?php
						printf(
							/* translators: %s: inline code for the verify command */
							esc_html__( 'Verify with %s — the acrossai server should list as connected.', 'acrossai-mcp-manager' ),
							'<code>claude mcp list</code>'
						);
					?>
						</li>
				</ol>
			</section>

			<p class="acrossai-mcp-connector-panel__setup-footnote">
				<?php
				printf(
					/* translators: %s: link to the AcrossAI Claude Connectors docs */
					esc_html__( 'Still stuck? Full walkthroughs, screenshots, and troubleshooting live at %s.', 'acrossai-mcp-manager' ),
					'<a href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener noreferrer">acrossai.co/claude-connectors/</a>'
				);
				?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Override the shared card body so the Claude "How to connect" panel
	 * renders on the tab immediately, without requiring the operator to
	 * click Generate first. The base implementation only fires the setup
	 * HTML through JS after a Generate response; that hides the onboarding
	 * steps behind an action step and confused operators evaluating whether
	 * to connect at all. Rendering here keeps the URL + Generate row on top
	 * (single most important action) and appends the guide underneath.
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
