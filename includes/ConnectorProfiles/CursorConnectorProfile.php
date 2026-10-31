<?php
/**
 * Cursor Connector Profile.
 *
 * Registers Cursor (the Anysphere IDE) as an OAuth connector against this
 * plugin's ConnectorProfileRegistry.
 *
 * @package AcrossAI_MCP_Manager
 * @since   0.9.2
 */

namespace AcrossAI_MCP_Manager\Includes\ConnectorProfiles;

defined( 'ABSPATH' ) || exit;

/**
 * Cursor-specific connector profile.
 *
 * Contributes Cursor-branded consent-screen strings, the Anysphere callback
 * whitelist, setup instructions, and the per-server tab section rendered by
 * AIConnectorsTab.
 *
 * Unlike Claude's browser Add-custom-connector dialog, Cursor has no GUI for
 * pasting OAuth credentials. Its two supported paths are:
 *
 *   1. Dynamic Client Registration (the default) — the user pastes only the
 *      MCP URL into `mcp.json` and Cursor self-registers via RFC 7591.
 *   2. Static credentials — the user hand-edits an `auth` block containing
 *      CLIENT_ID / CLIENT_SECRET into `mcp.json`. This is what the whitelist
 *      below (and therefore the admin "Generate credentials" button) serves:
 *      an operator fallback for when DCR is unavailable or fails.
 *
 * Singleton per Architecture Principle A1 (zero add_action/add_filter in
 * constructors). Extends AbstractConnectorProfile via leading-`\` FQN — never
 * `use ...\AbstractConnectorProfile;` — to avoid import-symbol collisions
 * (DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION pattern from base plugin memory).
 *
 * @since 0.9.2
 */
final class CursorConnectorProfile extends \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile {

	/**
	 * Cursor's fixed OAuth callback URLs, documented at
	 * https://cursor.com/docs/mcp § "Static redirect URL". Cursor multiplexes
	 * every MCP server through these two endpoints and disambiguates via the
	 * OAuth `state` parameter, so they are not per-site values.
	 *
	 *   - Web / Cursor Agents → https://www.cursor.com/agents/mcp/oauth/callback
	 *   - Desktop IDE + CLI   → http://localhost:8787/callback
	 *
	 * The desktop entry is deliberately plain `http` on loopback — RFC 8252
	 * §7.3 makes that the correct scheme for a native-app callback, and
	 * ClientRegistrationController::is_valid_redirect_uri() accepts it for
	 * loopback hosts on any port. Do NOT "fix" it to https in a future
	 * cleanup pass; Cursor would then fail with redirect_uri mismatch.
	 *
	 * Port 8787 is hard-coded by Cursor and not configurable.
	 *
	 * Historical note: until ~July 2026 the desktop IDE used the private-use
	 * scheme `cursor://anysphere.cursor-mcp/oauth/callback`. Cursor moved off
	 * it to loopback (staff-confirmed 2026-07-08), so the default-off filter
	 * `acrossai_mcp_manager_native_app_redirect_schemes` added in 0.8.3 is NOT
	 * required for Cursor. Any guide still telling operators to whitelist
	 * `cursor://` is stale.
	 */
	private const REDIRECT_URIS = array(
		'https://www.cursor.com/agents/mcp/oauth/callback',
		'http://localhost:8787/callback',
	);

	/**
	 * Anysphere / Cursor-owned hostnames used for DCR attribution.
	 *
	 * Deliberately does NOT include `localhost`. dcr_matches_by_host() matches
	 * on host alone, so a loopback entry here would claim every CLI client on
	 * the site (Claude Code, Gemini CLI, Codex) as Cursor. The desktop surface
	 * is attributed by the narrower port+path check in
	 * matches_desktop_loopback() instead.
	 */
	private const DCR_HOSTS = array( 'cursor.com', 'www.cursor.com' );

	/**
	 * Loopback port Cursor's desktop IDE and CLI listen on for the OAuth
	 * callback. Fixed by Cursor; see REDIRECT_URIS.
	 */
	private const DESKTOP_LOOPBACK_PORT = 8787;

	/**
	 * Path component of Cursor's desktop loopback callback.
	 */
	private const DESKTOP_LOOPBACK_PATH = '/callback';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 * @since 0.9.2
	 */
	protected static $instance = null;

	/**
	 * Private constructor — enforce singleton and A1 (no hook wiring here).
	 *
	 * @since 0.9.2
	 */
	private function __construct() {}

	/**
	 * Get the shared singleton instance.
	 *
	 * @return self
	 * @since 0.9.2
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
	 * @since 0.9.2
	 */
	public function get_slug(): string {
		return 'cursor';
	}

	/**
	 * User-facing connector name.
	 *
	 * @return string
	 * @since 0.9.2
	 */
	public function get_name(): string {
		return __( 'Cursor', 'acrossai-mcp-manager' );
	}

	/**
	 * @return int
	 */
	public function get_priority(): int {
		return 50;
	}

	/**
	 * URL to the bundled icon shipped with this plugin.
	 *
	 * @return string
	 * @since 0.9.2
	 */
	public function get_icon_url(): string {
		return plugins_url( 'assets/cursor-icon.svg', \ACROSSAI_MCP_MANAGER_PLUGIN_FILE );
	}

	/**
	 * Cursor's two published OAuth callbacks. Returning a non-empty list
	 * keeps the admin card's "Generate credentials" button enabled, which is
	 * what produces the CLIENT_ID / CLIENT_SECRET pair an operator pastes
	 * into the `auth` block of `mcp.json` when Dynamic Client Registration
	 * isn't an option.
	 *
	 * @return string[]
	 * @since 0.9.2
	 */
	public function get_redirect_uri_whitelist(): array {
		return self::REDIRECT_URIS;
	}

	/**
	 * Step-by-step HTML instructions for the operator setting up Cursor with
	 * static OAuth credentials.
	 *
	 * Cursor exposes no dialog for OAuth credentials — the `auth` block is
	 * hand-edited into `mcp.json`, so these steps centre on that file. The
	 * client secret is intentionally NOT interpolated into the snippet: the
	 * card renders it separately behind a Reveal control and we don't want a
	 * second plaintext copy sitting in the instructions.
	 *
	 * @param array<string,mixed> $server         Server row from mcp-manager's
	 *                                            MCP Servers table.
	 * @param string              $client_id      The freshly-generated OAuth client_id.
	 * @param string              $client_secret  The freshly-generated OAuth client_secret.
	 * @return string HTML block, escaped where necessary.
	 * @since 0.9.2
	 */
	public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string {
		unset( $client_secret ); // Rendered separately behind the Reveal control — see docblock.

		$mcp_url = self::mcp_url_for_server( $server );

		// The CLIENT_SECRET placeholder is intentionally an untranslated literal:
		// it sits inside a JSON string, where a translation containing a quote
		// would produce an invalid snippet.
		$snippet = sprintf(
			"{\n  \"mcpServers\": {\n    \"WordPress\": {\n      \"url\": \"%s\",\n      \"auth\": {\n        \"CLIENT_ID\": \"%s\",\n        \"CLIENT_SECRET\": \"PASTE_CLIENT_SECRET_HERE\"\n      }\n    }\n  }\n}",
			$mcp_url,
			$client_id
		);

		ob_start();
		?>
		<ol class="acrossai-ai-setup-steps">
			<li><?php esc_html_e( 'Open your Cursor MCP config: ~/.cursor/mcp.json for every project, or .cursor/mcp.json inside one project.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Add the server entry below, merging it with any servers already listed under "mcpServers".', 'acrossai-mcp-manager' ); ?></li>
			<li>
				<pre><code><?php echo esc_html( $snippet ); ?></code></pre>
			</li>
			<li><?php esc_html_e( 'Click "Reveal" next to the client secret above and paste the real value over PASTE_CLIENT_SECRET_HERE, then save the file.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'In Cursor, open the Customize page, find this server in the MCP list, and start the sign-in.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Approve the consent screen when it opens on this WordPress site.', 'acrossai-mcp-manager' ); ?></li>
			<li><?php esc_html_e( 'Troubleshooting: the Cursor desktop sign-in listens on a fixed port 8787. If the browser returns to a blank page or a 404 and the connection never completes, another process is holding that port — free it and retry.', 'acrossai-mcp-manager' ); ?></li>
		</ol>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Consent-screen branding. AuthorizationController consumes this array
	 * when rendering the consent template.
	 *
	 * @return array{heading:string,subtitle:string,permissions_bullets:string[]}
	 * @since 0.9.2
	 */
	public function get_consent_branding(): array {
		return array(
			'heading'             => __( 'Cursor wants to connect to your site', 'acrossai-mcp-manager' ),
			'subtitle'            => __( 'This will let Cursor access the MCP tools this server exposes, acting as your WordPress user.', 'acrossai-mcp-manager' ),
			'permissions_bullets' => array(
				__( 'Read and act on the tools exposed on this MCP server', 'acrossai-mcp-manager' ),
				__( 'Access the WordPress data your user account has permission to see', 'acrossai-mcp-manager' ),
				__( 'Connect until you revoke access from the Connectors/Integrations tab', 'acrossai-mcp-manager' ),
			),
		);
	}

	/**
	 * Claim DCR-registered clients that are Cursor.
	 *
	 * Three checks, strongest evidence first, per
	 * ARC-DCR-ATTRIBUTION-BY-REDIRECT-HOST:
	 *
	 *   1. Redirect URI host is Cursor-owned — covers the web / Cursor Agents
	 *      surface. Cryptographically meaningful: that host is where the
	 *      authorization code actually lands.
	 *   2. Redirect URI is the desktop loopback callback. A bare `localhost`
	 *      host match would be far too broad, so this matches port AND path
	 *      as well — which makes it *narrower* than a host match, not looser,
	 *      and so consistent with the spirit of that decision.
	 *   3. Whole-word `cursor` in the self-declared client_name. Weakest and
	 *      last, and never `str_contains`.
	 *
	 * If Cursor ever moves off port 8787, check 2 stops firing and attribution
	 * degrades to check 3 rather than breaking outright.
	 *
	 * @param string             $client_name   DCR-submitted client_name.
	 * @param array<int, string> $redirect_uris DCR-submitted redirect_uris.
	 * @return bool
	 * @since 0.9.2
	 */
	public function matches_dcr_client( string $client_name, array $redirect_uris ): bool {
		if ( self::dcr_matches_by_host( $redirect_uris, self::DCR_HOSTS ) ) {
			return true;
		}
		if ( self::matches_desktop_loopback( $redirect_uris ) ) {
			return true;
		}
		return (bool) preg_match( '/\bcursor\b/i', $client_name );
	}

	/**
	 * Whether any redirect URI is Cursor's desktop loopback callback —
	 * loopback host AND port 8787 AND path `/callback`.
	 *
	 * All three components are required. Matching on the loopback host alone
	 * would sweep up every other native OAuth client on the site.
	 *
	 * wp_parse_url returns IPv6 hosts bracketed (`[::1]`); normalize by
	 * stripping the brackets, matching dcr_matches_by_host()'s treatment.
	 *
	 * @param array<int, string> $redirect_uris DCR-submitted redirect_uris.
	 * @return bool
	 * @since 0.9.2
	 */
	private static function matches_desktop_loopback( array $redirect_uris ): bool {
		foreach ( $redirect_uris as $uri ) {
			if ( ! is_string( $uri ) || '' === $uri ) {
				continue;
			}
			$parts = wp_parse_url( $uri );
			if ( ! is_array( $parts ) ) {
				continue;
			}
			$host = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
			if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
				continue;
			}
			if ( self::DESKTOP_LOOPBACK_PORT !== (int) ( $parts['port'] ?? 0 ) ) {
				continue;
			}
			if ( self::DESKTOP_LOOPBACK_PATH === (string) ( $parts['path'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Setup instructions for this connector, as a spec for the shared
	 * renderer.
	 *
	 * The per-surface walkthroughs that used to live here moved to the
	 * documentation site; what stays is the fast path plus anything the
	 * operator cannot get anywhere else, such as a value derived from this
	 * site. See {@see AbstractConnectorProfile::render_setup_panel()} for
	 * the spec keys.
	 *
	 * Cursor uses Dynamic Client Registration, so no credentials need to be
	 * pasted anywhere on the usual path.
	 *
	 * @param string $mcp_url MCP endpoint URL.
	 * @return array<string, mixed> Spec for the shared panel renderer.
	 * @since 0.9.2
	 */
	protected function get_setup_spec( string $mcp_url ): array {
		$spec = array(
			'name'       => 'Cursor',
			'lead'       => __( 'Cursor runs on your machine, so it can reach local sites that hosted assistants cannot.', 'acrossai-mcp-manager' ),
			'open_label' => __( 'Open Cursor settings', 'acrossai-mcp-manager' ),
			'open_url'   => 'https://cursor.com/settings',
			'steps'      => array(
				array( 'text' => __( 'In Cursor, open Settings > MCP & Integrations', 'acrossai-mcp-manager' ) ),
				array( 'text' => __( 'Click New MCP Server to open the "Add a Custom MCP" dialog', 'acrossai-mcp-manager' ) ),
				array(
					'text' => __( 'Fill in Name', 'acrossai-mcp-manager' ),
					'copy' => $this->get_connector_name(),
					'note' => __( 'Anything works — the name is only so you recognise this server in your list. This one matches the site.', 'acrossai-mcp-manager' ),
				),
				array( 'text' => __( 'Leave Type set to URL, not Command', 'acrossai-mcp-manager' ) ),
				array(
					'text' => __( 'Paste the MCP endpoint URL into Server URL', 'acrossai-mcp-manager' ),
					'url'  => true,
				),
				array(
					'text' => __( 'Leave Headers empty, click Add MCP, then approve the consent screen', 'acrossai-mcp-manager' ),
					'note' => __( 'No headers are needed — Cursor registers itself and signs in through your browser.', 'acrossai-mcp-manager' ),
					'open' => true,
				),
			),
			'plan'       => __( 'Custom MCP servers need a paid Cursor plan — the free Hobby plan does not include them.', 'acrossai-mcp-manager' ),
			'docs_url'   => 'https://acrossai.co/docs/connect-cursor-troubleshooting/',
		);

		return $spec;
	}


	// render_tab_section is inherited from
	// AbstractConnectorProfile::render_default_card — the shared CSS + JS
	// bundle handles card layout, Generate/Regenerate buttons, and copy
	// behavior. This class is pure metadata: get_slug + get_name +
	// get_icon_url + get_redirect_uri_whitelist + get_setup_instructions +
	// get_consent_branding + matches_dcr_client + get_mcp_url_setup_html.
}
