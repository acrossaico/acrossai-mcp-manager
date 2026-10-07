<?php
/**
 * Contract for AI connector profiles (Feature 021).
 *
 * Companion plugins subclass this and contribute an instance via the
 * `acrossai_mcp_manager_connector_profiles` filter. See
 * contracts/connector-profile.md for the full contract.
 *
 * ---------------------------------------------------------------------------
 * Card Shell (F021 Phase 9 — 2026-07-11)
 * ---------------------------------------------------------------------------
 * This class ships a concrete card renderer so companion plugins get a
 * uniform look + behavior for free. Subclasses only need to implement the
 * 5 abstract metadata methods (get_slug, get_name, get_icon_url,
 * get_redirect_uri_whitelist, get_setup_instructions) — the tab section
 * is rendered by `render_tab_section` calling `render_default_card`.
 *
 * Companions can override at any granularity:
 *   • `render_tab_section` — full replacement, opts out of the shell.
 *   • `render_card_body` — replace only the body (keep header + card frame).
 *   • `render_url_row`, `render_credentials_area`, `render_result_target`
 *     — replace individual sections.
 *
 * The shell's CSS lives in `src/scss/ai-connectors.scss` (base plugin) and
 * its JS event handlers in `src/js/ai-connectors.js`. Both are enqueued by
 * `Admin\Main::maybe_enqueue_ai_connectors_app()` on `?tab=ai-connectors`.
 *
 * Every render helper name + CSS class name + data-attribute name below is
 * `@experimental May change without notice before 1.0.0` — companion
 * plugins depending on them accept minor-version drift until then.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Connectors
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Connectors;

use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\ClientRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for connector profiles.
 *
 * See docs/extending-connector-profiles.md for the author guide.
 */
abstract class AbstractConnectorProfile {

	/**
	 * Unique lowercase-kebab identifier ([a-z0-9-]{1,64}).
	 *
	 * Stable across plugin versions — this is the join key to the
	 * `OAuthClients.connector_slug` column.
	 *
	 * @return string
	 */
	abstract public function get_slug(): string;

	/**
	 * Human-readable display name (translated inside the profile).
	 *
	 * @return string
	 */
	abstract public function get_name(): string;

	/**
	 * Display weight for every surface that lists connectors.
	 *
	 * Lower values sort earlier — WP-idiomatic, matching `add_action`
	 * priority semantics. The default of 100 places third-party connectors
	 * contributed through `acrossai_mcp_manager_connector_profiles` AFTER the
	 * built-ins, while
	 * still letting them claim any position by overriding this method. Ties
	 * are broken by slug ascending, so equal priorities stay deterministic.
	 *
	 * Built-ins occupy a 10-step spine (10, 20, 30 …) so a connector can be
	 * inserted later without renumbering its neighbours. Mirrors the host
	 * plugin's convention for MCP clients, embed transports, server tabs and
	 * connect methods.
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 100;
	}

	/**
	 * Absolute URL to a square icon (64×64 SVG or PNG recommended).
	 *
	 * @return string
	 */
	abstract public function get_icon_url(): string;

	/**
	 * List of exact redirect URIs the connector may use.
	 *
	 * HTTPS or loopback only — FR-021 strict scheme validation applies at
	 * both the DCR + authorize boundaries.
	 *
	 * @return array<int, string>
	 */
	abstract public function get_redirect_uri_whitelist(): array;

	/**
	 * HTML instructions rendered after credentials are generated. Raw
	 * secret is passed once; ClientRegistrationController runs the output
	 * through `wp_kses_post` before returning (SEC-021-T02).
	 *
	 * @param array<string, mixed> $server        Server row as array.
	 * @param string               $client_id     Just-issued client_id.
	 * @param string               $client_secret Just-issued raw secret (visible ONCE).
	 * @return string
	 */
	abstract public function get_setup_instructions( array $server, string $client_id, string $client_secret ): string;

	/**
	 * Directly echoes HTML for the tab card.
	 *
	 * Default implementation calls `render_default_card` for the shared
	 * card shell. Companions can override for a fully custom UI (e.g., a
	 * device-flow connector that doesn't fit the credentials-copy pattern).
	 *
	 * @experimental Delegates to render_default_card until 1.0.0.
	 *
	 * @param array<string, mixed> $server Server row as array.
	 * @return void
	 */
	public function render_tab_section( array $server ): void {
		$this->render_default_card( $server );
	}

	/**
	 * Optional consent-screen branding override. Default returns a
	 * neutral heading + subtitle from the connector name.
	 *
	 * @return array{heading: string, subtitle: string, permissions_bullets: array<int, string>}
	 */
	public function get_consent_branding(): array {
		return array(
			'heading'             => sprintf(
				/* translators: %s: connector name */
				__( '%s wants to connect to your site', 'acrossai-mcp-manager' ),
				$this->get_name()
			),
			'subtitle'            => __(
				'This will allow the application to access the MCP tools you have exposed on this server.',
				'acrossai-mcp-manager'
			),
			'permissions_bullets' => array(),
		);
	}

	// -------------------------------------------------------------------------
	// Card Shell — concrete renderers. Every method below is @experimental.
	// -------------------------------------------------------------------------

	/**
	 * Render the full connector card — outer frame, header, body, result target.
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (Phase 9)
	 *
	 * @param array<string, mixed> $server Server row as array.
	 * @return void
	 */
	protected function render_default_card( array $server ): void {
		$slug = $this->get_slug();
		printf(
			'<section class="acrossai-mcp-connector acrossai-mcp-connector--%1$s" data-acrossai-connector-slug="%1$s">',
			esc_attr( $slug )
		);
		$this->render_card_header( $server );
		echo '<div class="acrossai-mcp-connector__body">';
		$this->render_card_body( $server );
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Render the card header — icon + connector name. Companions can
	 * override for a differently-shaped header (e.g., adding a status pill).
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (Phase 9)
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return void
	 */
	protected function render_card_header( array $server ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Subclass overrides may consume $server.
		echo '<header class="acrossai-mcp-connector__header">';
		printf(
			'<img class="acrossai-mcp-connector__icon" src="%1$s" alt="" width="32" height="32">',
			esc_url( $this->get_icon_url() )
		);
		printf(
			'<h3 class="acrossai-mcp-connector__title">%s</h3>',
			esc_html( $this->get_name() )
		);
		echo '</header>';
	}

	/**
	 * Render the card body — orchestrates URL row, credentials area,
	 * setup instructions. Companions can override for a completely
	 * different body content.
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (Phase 9)
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return void
	 */
	protected function render_card_body( array $server ): void {
		$this->render_url_row( $server );
		$this->render_credentials_area( $server );
		// Immediately after the Generate button, NOT at the end of the card.
		// Every connector profile overrides this method as
		// `parent::render_card_body(); <how-to panel>`, so emitting the target
		// here puts the generated credentials between the button that produced
		// them and the instructions that consume them. Rendering it after the
		// parent call — which is what happened until 0.9.13 — pushed
		// "Credentials generated" below a full page of setup steps, so the
		// once-visible client secret appeared far from the button just clicked.
		$this->render_result_target( $server );
	}

	/**
	 * Render the "MCP URL to paste" row with an inline copy button.
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (Phase 9)
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return void
	 */
	protected function render_url_row( array $server ): void {
		$mcp_url = self::mcp_url_for_server( $server );

		echo '<p class="acrossai-mcp-connector__label">';
		printf(
			/* translators: %s: connector display name */
			esc_html__( 'MCP URL to paste into %s:', 'acrossai-mcp-manager' ),
			esc_html( $this->get_name() )
		);
		echo '</p>';
		echo '<div class="acrossai-mcp-connector__copy-row">';
		printf(
			'<input type="text" class="acrossai-mcp-connector__input regular-text code" value="%s" readonly>',
			esc_attr( $mcp_url )
		);
		printf(
			'<button type="button" class="button acrossai-mcp-connector__copy-btn" data-acrossai-copy="mcp-url">%s</button>',
			esc_html__( 'Copy', 'acrossai-mcp-manager' )
		);
		echo '</div>';
	}

	/**
	 * Render the "credentials + action button" area. Reads existing
	 * client via `find_existing_client_id` and picks Generate or
	 * Regenerate accordingly.
	 *
	 * Gated on `manage_options` — subscribers see a static description.
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (Phase 9)
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return void
	 */
	protected function render_credentials_area( array $server ): void {
		$server_id  = isset( $server['id'] ) ? (int) $server['id'] : 0;
		$client_id  = $this->find_existing_client_id( $server_id );
		$can_manage = current_user_can( 'manage_options' );

		if ( null !== $client_id ) {
			$this->render_regenerate_area( $client_id, $can_manage );
			return;
		}

		// FR-005 — don't offer a control the endpoint will refuse. A connector
		// disabled on this server is rejected by
		// ClientRegistrationController::handle_generate() (FR-004), so a
		// Generate button here could only ever produce an error. The tab's own
		// disabled-state notice (AIConnectorsTab) already explains the state and
		// links to Settings, so this stays silent rather than repeating it.
		//
		// Placed AFTER the existing-client branch on purpose: an operator who
		// disables a connector that already has credentials should still see
		// those credentials and the Regenerate/Revoke controls, so they can
		// clean up rather than being locked out of their own leftovers.
		if ( $server_id > 0 && ! ConnectorSettings::is_slug_enabled_on_server( $server_id, $this->get_slug() ) ) {
			return;
		}

		// A profile that returns an empty redirect_uri whitelist has opted
		// out of manual credential generation entirely — it only supports
		// DCR. Show a note explaining that instead of a Generate button
		// that would fail admin-generate downstream.
		if ( empty( $this->get_redirect_uri_whitelist() ) ) {
			printf(
				'<p class="acrossai-mcp-connector__description description">%s</p>',
				esc_html__( 'This connector supports Dynamic Client Registration only — paste the MCP URL above into your AI client and it will register itself. No manual credentials to generate.', 'acrossai-mcp-manager' )
			);
			return;
		}

		if ( ! $can_manage ) {
			printf(
				'<p class="acrossai-mcp-connector__description description">%s</p>',
				esc_html__( 'No credentials have been generated for this server yet. An administrator can generate them here.', 'acrossai-mcp-manager' )
			);
			return;
		}

		echo '<p class="acrossai-mcp-connector__generate-row">';
		printf(
			'<button type="button" class="button button-primary acrossai-mcp-connector__generate-btn">%s</button>',
			esc_html__( 'Generate credentials', 'acrossai-mcp-manager' )
		);
		echo '<span class="description">';
		printf(
			/* translators: %s: connector display name */
			esc_html__( 'Creates a new OAuth client for this server that %s can connect against.', 'acrossai-mcp-manager' ),
			esc_html( $this->get_name() )
		);
		echo '</span>';
		echo '</p>';
	}

	/**
	 * Render the "credentials already exist" area — display the
	 * client_id + a Regenerate button (admin only).
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (Phase 9)
	 *
	 * @param string $client_id  Existing client_id.
	 * @param bool   $can_manage True if the current user can regenerate.
	 * @return void
	 */
	protected function render_regenerate_area( string $client_id, bool $can_manage ): void {
		echo '<div class="acrossai-mcp-connector__credentials">';
		echo '<p class="acrossai-mcp-connector__label">';
		esc_html_e( 'OAuth Client ID (already generated):', 'acrossai-mcp-manager' );
		echo '</p>';
		echo '<div class="acrossai-mcp-connector__copy-row">';
		printf(
			'<input type="text" class="acrossai-mcp-connector__input regular-text code" value="%s" readonly>',
			esc_attr( $client_id )
		);
		printf(
			'<button type="button" class="button acrossai-mcp-connector__copy-btn" data-acrossai-copy="client-id">%s</button>',
			esc_html__( 'Copy', 'acrossai-mcp-manager' )
		);
		echo '</div>';
		echo '<p class="acrossai-mcp-connector__hint description">';
		esc_html_e(
			'The Client Secret was displayed once at generation time. If you no longer have it, click Regenerate below to issue a new pair — this revokes every outstanding token.',
			'acrossai-mcp-manager'
		);
		echo '</p>';

		if ( $can_manage ) {
			$confirm_msg = __(
				'Regenerating will revoke every outstanding token for this connector. You will need to re-run the setup with the new credentials. Continue?',
				'acrossai-mcp-manager'
			);
			echo '<p class="acrossai-mcp-connector__actions">';
			printf(
				'<button type="button" class="button button-secondary acrossai-mcp-connector__regenerate-btn" data-acrossai-confirm="%1$s">%2$s</button>',
				esc_attr( $confirm_msg ),
				esc_html__( 'Regenerate credentials', 'acrossai-mcp-manager' )
			);
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Render the AJAX result target — the shared JS injects Generate /
	 * Regenerate response HTML into this element.
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (Phase 9)
	 *
	 * @param array<string, mixed> $server Server row. Currently unused but reserved.
	 * @return void
	 */
	protected function render_result_target( array $server ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Reserved for subclass overrides.
		echo '<div class="acrossai-mcp-connector__result" data-acrossai-result aria-live="polite"></div>';
	}

	/**
	 * Look up the existing OAuth client_id for this (server_id, slug) pair.
	 *
	 * Encapsulates the base-plugin ClientRepository lookup so companion
	 * subclasses don't need to import the Repository FQN. Returns null when
	 * no client exists OR when the base plugin's F021 Repository is absent
	 * (e.g., during test bootstrap before the DB is set up).
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (Phase 9)
	 *
	 * @param int $server_id MCP server row id.
	 * @return string|null
	 */
	protected function find_existing_client_id( int $server_id ): ?string {
		if ( $server_id <= 0 ) {
			return null;
		}
		if ( ! class_exists( ClientRepository::class ) ) {
			return null;
		}
		$existing = ClientRepository::find_admin_client( $server_id, $this->get_slug() );
		return null === $existing ? null : (string) $existing->client_id;
	}

	// -------------------------------------------------------------------------
	// F024 additions — DCR client matching + MCP URL setup instructions.
	// -------------------------------------------------------------------------

	/**
	 * F024 FR-024-018 — claim ownership of a DCR-registered client whose
	 * metadata identifies it as belonging to this profile's brand.
	 *
	 * Default returns false. Companion plugins override to inspect the DCR
	 * client_name + redirect_uris and return true when they match. Called
	 * by AIConnectorsTab's Connections panel to attribute DCR clients
	 * (which have connector_slug = '') to a specific profile.
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (F024)
	 *
	 * @param string             $client_name   DCR-submitted client_name.
	 * @param array<int, string> $redirect_uris DCR-submitted redirect_uris.
	 * @return bool
	 */
	public function matches_dcr_client( string $client_name, array $redirect_uris ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Subclass overrides may consume both.
		return false;
	}

	/**
	 * Shared host-based DCR attribution helper for subclasses. Returns true
	 * iff any redirect_uri's host exactly matches one of `$allowed_hosts`.
	 *
	 * Host matching is strictly preferable to substring matching against the
	 * client_name — the name field is arbitrary and easy to spoof (a client
	 * called "OpenAI Community Tool" isn't necessarily OpenAI), whereas the
	 * OAuth redirect URI is the actual callback endpoint the AI host must
	 * reach. If a client registers with a redirect URI pointing at
	 * `chatgpt.com`, we can treat that as declared identity for attribution.
	 *
	 * Case-insensitive on host per DNS spec; empty entries are ignored.
	 *
	 * @since 0.6.0
	 *
	 * @param array<int, string> $redirect_uris DCR-submitted redirect_uris.
	 * @param array<int, string> $allowed_hosts Brand hostnames (exact match).
	 * @return bool
	 */
	protected static function dcr_matches_by_host( array $redirect_uris, array $allowed_hosts ): bool {
		if ( empty( $redirect_uris ) || empty( $allowed_hosts ) ) {
			return false;
		}
		$needles = array();
		foreach ( $allowed_hosts as $h ) {
			$h = strtolower( trim( (string) $h ) );
			if ( '' !== $h ) {
				$needles[ $h ] = true;
			}
		}
		foreach ( $redirect_uris as $uri ) {
			if ( ! is_string( $uri ) || '' === $uri ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $uri, PHP_URL_HOST ) );
			if ( '' !== $host && isset( $needles[ $host ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * F024 FR-024-019 — HTML setup instructions for pasting the MCP URL
	 * into this connector's AI client. Rendered inside the Generate panel.
	 *
	 * Default returns a generic message. Companion plugins override for
	 * connector-specific step-by-step instructions.
	 *
	 * Output is passed through wp_kses_post at the render boundary — so
	 * subclass output MAY use inline HTML but MUST NOT rely on script tags.
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (F024)
	 *
	 * @param string $mcp_url The MCP endpoint URL to paste.
	 * @return string HTML.
	 */
	public function get_mcp_url_setup_html( string $mcp_url ): string {
		return sprintf(
			'<p>%s</p><p><code>%s</code></p>',
			esc_html__( 'Copy the URL above and paste it into your AI client&#8217;s connector settings. The AI client will handle credential registration automatically.', 'acrossai-mcp-manager' ),
			esc_html( $mcp_url )
		);
	}

	/**
	 * Compute the canonical MCP endpoint URL for a server row.
	 *
	 * Joins `server_route_namespace` + `server_route` into
	 * `rest_url( ns . '/' . route )`. Matches how F011 CliController and
	 * F013 MCPClientsBlock derive the same URL — tokens issued for the
	 * value returned here pass TokenValidator's audience-binding check.
	 *
	 * @experimental May change without notice before 1.0.0.
	 * @since 0.1.0 (F024)
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return string
	 */
	public static function mcp_url_for_server( array $server ): string {
		$namespace = isset( $server['server_route_namespace'] ) && '' !== $server['server_route_namespace']
			? (string) $server['server_route_namespace']
			: 'mcp';
		$route     = isset( $server['server_route'] ) ? (string) $server['server_route'] : '';
		if ( '' === $route ) {
			return rest_url( $namespace );
		}
		return rest_url( trailingslashit( $namespace ) . $route );
	}

	/**
	 * Shared scoped CSS for connector "How to connect …" setup panels.
	 *
	 * Emitted once per request (`$printed` guard) so multiple connector cards
	 * on the same page reuse a single block. Printed directly by callers
	 * (typically from a `render_card_body()` override) so the `<style>` tag
	 * survives — running the whole panel HTML through `wp_kses_post()`
	 * would strip it.
	 *
	 * Also widens every per-connector panel (`--chatgpt / --claude / --cursor
	 * / --gemini / --grok`), overriding the 720px cap the base bundle
	 * applies at `src/scss/ai-connectors.scss:36-40`. The full-width layout
	 * matches the Connections / Approved Users tabs so the setup guide has
	 * room to breathe.
	 *
	 * @return void
	 */
	public static function print_setup_styles(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style id="acrossai-mcp-manager-connector-setup-styles">
			.acrossai-mcp-ai-connectors__panel--chatgpt,
			.acrossai-mcp-ai-connectors__panel--claude,
			.acrossai-mcp-ai-connectors__panel--cursor,
			.acrossai-mcp-ai-connectors__panel--gemini,
			.acrossai-mcp-ai-connectors__panel--grok {
				max-width: none !important;
			}
			.acrossai-mcp-connector-panel__setup {
				margin: 24px 0 0;
				padding: 24px 28px;
				background: #ffffff;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
				color: #1e1e1e;
				font-size: 14px;
				line-height: 1.55;
			}
			.acrossai-mcp-connector-panel__setup-title {
				margin: 0 0 6px;
				font-size: 18px;
				font-weight: 600;
				color: #1e3a8a;
				letter-spacing: -0.01em;
			}
			.acrossai-mcp-connector-panel__setup-lead {
				margin: 0 0 20px;
				color: #4b5563;
				font-size: 14px;
			}
			.acrossai-mcp-connector-panel__setup-section {
				margin: 0 0 18px;
				padding: 16px 18px;
				background: #f8f9fc;
				border: 1px solid #e5e7eb;
				border-left: 3px solid #3858e9;
				border-radius: 6px;
			}
			.acrossai-mcp-connector-panel__setup-section:last-of-type {
				margin-bottom: 16px;
			}
			.acrossai-mcp-connector-panel__setup-section h5 {
				margin: 0 0 12px;
				font-size: 12px;
				font-weight: 700;
				text-transform: uppercase;
				letter-spacing: 0.06em;
				color: #3858e9;
			}
			.acrossai-mcp-connector-panel__setup-steps {
				margin: 0;
				padding: 0 0 0 22px;
				list-style: decimal;
			}
			.acrossai-mcp-connector-panel__setup-steps > li {
				margin: 0 0 8px;
				padding-left: 4px;
				line-height: 1.6;
			}
			.acrossai-mcp-connector-panel__setup-steps > li:last-child {
				margin-bottom: 0;
			}
			.acrossai-mcp-connector-panel__setup code {
				display: inline-block;
				padding: 1px 6px;
				background: #eef2ff;
				color: #1e3a8a;
				border: 1px solid #e0e7ff;
				border-radius: 3px;
				font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
				font-size: 12.5px;
				line-height: 1.5;
				word-break: break-all;
			}
			.acrossai-mcp-connector-panel__setup a {
				color: #3858e9;
				text-decoration: underline;
				text-underline-offset: 2px;
			}
			.acrossai-mcp-connector-panel__setup a:hover,
			.acrossai-mcp-connector-panel__setup a:focus-visible {
				color: #2f4dc7;
			}
			.acrossai-mcp-connector-panel__setup-cmd {
				display: block;
				margin: 10px 0 0;
				padding: 12px 14px;
				background: #1f2937;
				border: 0;
				border-radius: 5px;
				overflow-x: auto;
				line-height: 1.5;
				box-shadow: none;
			}
			.acrossai-mcp-connector-panel__setup-cmd code {
				display: block;
				background: transparent;
				border: 0;
				padding: 0;
				color: #e5e7eb;
				font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
				font-size: 12.5px;
				white-space: pre;
				word-break: normal;
			}
			.acrossai-mcp-connector-panel__setup-footnote {
				margin: 20px 0 0;
				padding: 12px 16px;
				background: #f5f3ff;
				border-left: 3px solid #7c3aed;
				border-radius: 4px;
				font-size: 13.5px;
				color: #1e1e1e;
			}
			.acrossai-mcp-connector-panel__setup-footnote a {
				font-weight: 600;
			}
		</style>
		<?php
	}
}
