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

use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;

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
	 * Claude's undocumented-but-stable deep link into the Add custom
	 * connector modal, with the name and server URL already filled in.
	 *
	 * Anthropic's own documentation describes the manual path only, so this
	 * is a convenience that must never become load-bearing: every step it
	 * saves is still written out below it, and a changed or withdrawn link
	 * costs the operator one extra copy-paste rather than the connection.
	 *
	 * Deliberately scoped to the personal surface. `/customize/connectors`
	 * is a per-account page; Team and Enterprise Owners add connectors in
	 * Organization settings, which no link can target, so the panel points
	 * them at the section below instead of implying one button covers all.
	 *
	 * @param string $mcp_url This server's MCP endpoint.
	 */
	public function get_one_click_connect_url( string $mcp_url ): string {
		return 'https://claude.ai/customize/connectors?modal=add-custom-connector'
			. '&connectorName=' . rawurlencode( $this->get_connector_name() )
			. '&connectorUrl=' . rawurlencode( $mcp_url );
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
	 * @return array<string, mixed> Spec for the shared panel renderer.
	 */
	protected function get_setup_spec( string $mcp_url ): array {
		$reachable = LocalEnvironment::is_publicly_reachable( $mcp_url );
		$host      = (string) wp_parse_url( $mcp_url, PHP_URL_HOST );

		$spec = array(
			'name'            => 'Claude',
			'lead'            => __( 'Claude reaches your site over OAuth. You approve once on a WordPress consent screen — no token to copy.', 'acrossai-mcp-manager' ),
			'one_click_url'   => $this->get_one_click_connect_url( $mcp_url ),
			'one_click_label' => __( 'Connect to Claude (1-click)', 'acrossai-mcp-manager' ),
			'one_click_help'  => __( 'Opens claude.ai with this server pre-filled. Confirm there to add and connect.', 'acrossai-mcp-manager' ),
			'blocked_reason'  => $reachable ? '' : sprintf(
				/* translators: %s: this site's hostname, e.g. "mysite.local". */
				__( 'Claude connects from Anthropic\'s servers, so it needs a public https:// address. %s only resolves on your machine. Expose it with a tunnel (ngrok, Cloudflare Tunnel), or connect once the site is live.', 'acrossai-mcp-manager' ),
				$host
			),
			'open_label'      => __( 'Open Claude settings', 'acrossai-mcp-manager' ),
			'open_url'        => 'https://claude.ai/customize/connectors',
			'steps'           => array(
				array( 'text' => __( 'In claude.ai, go to Settings > Connectors', 'acrossai-mcp-manager' ) ),
				array( 'text' => __( 'Click Add custom connector', 'acrossai-mcp-manager' ) ),
				array(
					'text' => __( 'Give the connector a name', 'acrossai-mcp-manager' ),
					'copy' => $this->get_connector_name(),
					'note' => __( 'Anything works — the name is only so you recognise this connector in your list. This one matches the site.', 'acrossai-mcp-manager' ),
				),
				array(
					'text' => __( 'Paste the MCP endpoint URL as the server URL', 'acrossai-mcp-manager' ),
					'url'  => true,
				),
				array(
					'text' => __( 'Click Add, then Connect, and approve the consent screen', 'acrossai-mcp-manager' ),
					'open' => true,
				),
			),
			'plan'            => __( 'Free, Pro and Max accounts. On Team and Enterprise, only Owners can add connectors.', 'acrossai-mcp-manager' ),
			'other'           => array(
				'label' => __( 'Claude Code (terminal)', 'acrossai-mcp-manager' ),
				'desc'  => __( 'Run this, then finish the browser sign-in Claude Code prints.', 'acrossai-mcp-manager' ),
				'cmd'   => sprintf( 'claude mcp add --transport http acrossai %s', $mcp_url ),
			),
			'docs_url'        => 'https://acrossai.co/docs/connect-claude-troubleshooting/',
		);

		return $spec;
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

	// F021 Phase 9 (2026-07-11): render_tab_section is now inherited from
	// AbstractConnectorProfile::render_default_card. The base plugin's shared
	// CSS + JS bundle (enqueued via Admin\Main::maybe_enqueue_ai_connectors_app)
	// handles all card layout, Generate/Regenerate buttons, and copy behavior.
	// This class is now pure metadata: get_slug + get_name + get_icon_url +
	// get_redirect_uri_whitelist + get_setup_instructions + get_consent_branding +
	// (F024) matches_dcr_client + get_mcp_url_setup_html.
}
