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

use AcrossAI_MCP_Manager\Includes\Utilities\SiteSlug;

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
	 * The server row currently being rendered.
	 *
	 * `render_setup_panel()` returns a string while `render_credentials_area()`
	 * echoes, and the panel is produced by `get_mcp_url_setup_html( $mcp_url )`
	 * — a public signature that carries no server row. Rather than widen that
	 * signature, the row is parked here for the duration of one card render so
	 * the panel can place the Generate controls inside its own disclosure.
	 *
	 * Set and cleared by `render_default_card()`; never read outside a render.
	 *
	 * @var array<string, mixed>
	 */
	private array $rendering_server = array();

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
		// No card header. The tab strip above already names the connector, so an
		// icon plus the same name repeated under it was pure duplication. The
		// icons still ship and `get_icon_url()` is unchanged — Discovery DTOs
		// carry it to the wizard, which has its own presentation.
		echo '<div class="acrossai-mcp-connector__body">';
		$this->rendering_server = $server;
		$this->render_card_body( $server );
		$this->rendering_server = array();
		echo '</div>';
		echo '</section>';
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
		$spec = $this->get_setup_spec( self::mcp_url_for_server( $server ) );

		if ( array() === $spec ) {
			return;
		}

		// Echoed directly, NOT through wp_kses_post().
		//
		// The panel now carries the Generate controls inside its disclosure,
		// and kses strips `<input>` outright — so a server that already has an
		// OAuth client would lose the field showing its client id. The markup
		// is first-party and every interpolated value is escaped where it is
		// written, so the sanitiser guards nothing here.
		//
		// `get_mcp_url_setup_html()` still returns the same panel WITHOUT the
		// controls, and companions overriding it are still sandboxed by the
		// caller that echoes their string.
		echo $this->render_setup_panel( $spec, self::mcp_url_for_server( $server ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- First-party markup, escaped at each interpolation.
	}

	/**
	 * Per-connector content for the setup panel.
	 *
	 * Returning an empty array — the default — means this profile does not
	 * use the shared panel, and the card body renders nothing.
	 *
	 * @param string $mcp_url This server's MCP endpoint.
	 * @return array<string, mixed> Spec for {@see self::render_setup_panel()}.
	 */
	protected function get_setup_spec( string $mcp_url ): array {
		unset( $mcp_url );
		return array();
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
	 * The name to suggest when a client asks what to call this connection.
	 *
	 * The site title, lowercased with spaces and punctuation collapsed to
	 * hyphens — `sanitize_title()`, via the same `SiteSlug` helper that names
	 * the config-file entry on the Clients tab. One site therefore reads the
	 * same whichever way it was connected.
	 *
	 * Lives here rather than on a profile because more than one client asks
	 * for a name, and they must not disagree about the answer.
	 */
	public function get_connector_name(): string {
		return SiteSlug::get();
	}

	/**
	 * Render a connector setup panel from a declarative spec.
	 *
	 * Every profile emits the same shape — heading, a one-click row or the
	 * note explaining its absence, numbered manual steps, a plan footnote,
	 * two disclosures and a docs link. Only the content differs, so the
	 * markup lives here once instead of being copy-pasted five times and
	 * drifting the way the per-profile walkthroughs did.
	 *
	 * Spec keys, all optional unless noted:
	 *
	 *   name            string  Client name, used in generated sentences. Required.
	 *   lead            string  One paragraph under the heading. Required.
	 *   one_click_url   string  Deep link. Absent means this client has none.
	 *   one_click_label string  Button text.
	 *   one_click_help  string  Sentence beside the button.
	 *   blocked_reason  string  Shown instead of the button when the deep link
	 *                           exists but cannot work here (see $reachable).
	 *   steps           array   [ text, url (bool), copy (string), cmd (string),
	 *                           note (string), open (bool) ]. Required.
	 *   open_label      string  Label for a step's "open settings" button.
	 *   open_url        string  Where that button goes.
	 *   plan            string  Plan-availability footnote.
	 *   other           array   [ label, desc, cmd ] for the first disclosure.
	 *   docs_url        string  The full guide. Required.
	 *
	 * @param array<string, mixed> $spec    Panel content.
	 * @param string               $mcp_url This server's MCP endpoint.
	 * @param bool                 $with_credentials Include the Generate controls. True only
	 *                                                for the admin card; the string form omits them.
	 * @return string HTML.
	 */
	protected function render_setup_panel( array $spec, string $mcp_url, bool $with_credentials = false ): string {
		$name      = (string) ( $spec['name'] ?? '' );
		$steps     = is_array( $spec['steps'] ?? null ) ? $spec['steps'] : array();
		$one_click = (string) ( $spec['one_click_url'] ?? '' );
		$blocked   = (string) ( $spec['blocked_reason'] ?? '' );

		ob_start();
		?>
		<div class="acrossai-mcp-connector-panel__setup">
			<?php // No heading: the tab strip directly above already names the connector. ?>
			<p class="acrossai-mcp-setup__lead"><?php echo esc_html( (string) ( $spec['lead'] ?? '' ) ); ?></p>

			<?php if ( '' !== $one_click && '' === $blocked ) : ?>
				<div class="acrossai-mcp-setup__oneclick">
					<a class="acrossai-mcp-setup__cta" href="<?php echo esc_url( $one_click ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( (string) ( $spec['one_click_label'] ?? '' ) ); ?>
						<span aria-hidden="true">&#8599;</span>
					</a>
					<span class="acrossai-mcp-setup__oneclick-help"><?php echo esc_html( (string) ( $spec['one_click_help'] ?? '' ) ); ?></span>
				</div>
			<?php elseif ( '' !== $one_click ) : ?>
				<div class="acrossai-mcp-setup__oneclick is-blocked">
					<div class="acrossai-mcp-setup__oneclick-row">
						<span class="acrossai-mcp-setup__cta is-disabled" aria-disabled="true"><?php echo esc_html( (string) ( $spec['one_click_label'] ?? '' ) ); ?></span>
						<span class="acrossai-mcp-setup__oneclick-help"><?php esc_html_e( 'Unavailable on this site.', 'acrossai-mcp-manager' ); ?></span>
					</div>
					<p class="acrossai-mcp-setup__warn"><?php echo esc_html( $blocked ); ?></p>
				</div>
			<?php endif; ?>

			<div class="acrossai-mcp-setup__manual">
				<p class="acrossai-mcp-setup__rule">
					<span><?php echo '' !== $one_click ? esc_html__( 'Or add it by hand', 'acrossai-mcp-manager' ) : esc_html__( 'Add it by hand', 'acrossai-mcp-manager' ); ?></span>
				</p>

				<ol class="acrossai-mcp-setup__steps">
					<?php foreach ( $steps as $step ) : ?>
						<li class="acrossai-mcp-setup__step">
							<span class="acrossai-mcp-setup__step-text"><?php echo esc_html( (string) ( $step['text'] ?? '' ) ); ?></span>

							<?php if ( ! empty( $step['url'] ) ) : ?>
								<span class="acrossai-mcp-setup__urlrow">
									<code class="acrossai-mcp-setup__url"><?php echo esc_html( $mcp_url ); ?></code>
									<button type="button" class="acrossai-mcp-setup__copy" data-acrossai-copy="mcp-url" data-acrossai-copy-text="<?php echo esc_attr( $mcp_url ); ?>"><?php esc_html_e( 'Copy URL', 'acrossai-mcp-manager' ); ?></button>
								</span>
							<?php endif; ?>

							<?php if ( '' !== (string) ( $step['copy'] ?? '' ) ) : ?>
								<span class="acrossai-mcp-setup__urlrow">
									<code class="acrossai-mcp-setup__url"><?php echo esc_html( (string) $step['copy'] ); ?></code>
									<button type="button" class="acrossai-mcp-setup__copy" data-acrossai-copy="setup-value" data-acrossai-copy-text="<?php echo esc_attr( (string) $step['copy'] ); ?>"><?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?></button>
								</span>
							<?php endif; ?>

							<?php if ( ! empty( $step['cmd'] ) ) : ?>
								<span class="acrossai-mcp-setup__cmdrow">
									<code class="acrossai-mcp-setup__cmd"><?php echo esc_html( (string) $step['cmd'] ); ?></code>
									<button type="button" class="acrossai-mcp-setup__copy is-dark" data-acrossai-copy="setup-cmd" data-acrossai-copy-text="<?php echo esc_attr( (string) $step['cmd'] ); ?>"><?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?></button>
								</span>
							<?php endif; ?>

							<?php if ( ! empty( $step['open'] ) && '' !== (string) ( $spec['open_url'] ?? '' ) ) : ?>
								<a class="acrossai-mcp-setup__open" href="<?php echo esc_url( (string) $spec['open_url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo esc_html( (string) ( $spec['open_label'] ?? '' ) ); ?>
									<span aria-hidden="true">&#8599;</span>
								</a>
							<?php endif; ?>
							<?php if ( '' !== (string) ( $step['note'] ?? '' ) ) : ?>
								<span class="acrossai-mcp-setup__step-note"><?php echo esc_html( (string) $step['note'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>

				<?php if ( '' !== (string) ( $spec['plan'] ?? '' ) ) : ?>
					<p class="acrossai-mcp-setup__plan"><?php echo esc_html( (string) $spec['plan'] ); ?></p>
				<?php endif; ?>
			</div>

			<div class="acrossai-mcp-setup__alert">
				<strong><?php esc_html_e( 'Connection failing?', 'acrossai-mcp-manager' ); ?></strong>
				<span><?php esc_html_e( 'Disable any other MCP plugins on this site. AI clients may get confused when multiple OAuth endpoints are detected.', 'acrossai-mcp-manager' ); ?></span>
			</div>

			<div class="acrossai-mcp-setup__more">
				<?php if ( is_array( $spec['other'] ?? null ) ) : ?>
					<details class="acrossai-mcp-setup__disclosure">
						<summary><?php echo esc_html( (string) ( $spec['other']['label'] ?? '' ) ); ?></summary>
						<div class="acrossai-mcp-setup__disclosure-body">
							<p><?php echo esc_html( (string) ( $spec['other']['desc'] ?? '' ) ); ?></p>
							<span class="acrossai-mcp-setup__cmdrow">
								<code class="acrossai-mcp-setup__cmd"><?php echo esc_html( (string) ( $spec['other']['cmd'] ?? '' ) ); ?></code>
								<button type="button" class="acrossai-mcp-setup__copy is-dark" data-acrossai-copy="setup-other" data-acrossai-copy-text="<?php echo esc_attr( (string) ( $spec['other']['cmd'] ?? '' ) ); ?>"><?php esc_html_e( 'Copy', 'acrossai-mcp-manager' ); ?></button>
							</span>
						</div>
					</details>
				<?php endif; ?>

				<details class="acrossai-mcp-setup__disclosure">
					<summary><?php esc_html_e( 'Use a manual OAuth client instead', 'acrossai-mcp-manager' ); ?></summary>
					<div class="acrossai-mcp-setup__disclosure-body acrossai-mcp-setup__creds">
						<p>
						<?php
						printf(
							/* translators: %s: the AI client name, e.g. "Claude". */
							esc_html__( 'Only if %s asks for a client ID and secret. Creates a new OAuth client for this server.', 'acrossai-mcp-manager' ),
							esc_html( $name )
						);
						?>
						</p>
						<?php
						// The controls themselves, not a pointer to them. Both
						// echo, so they run inside this buffer.
						if ( $with_credentials && array() !== $this->rendering_server ) {
							$this->render_credentials_area( $this->rendering_server );
							$this->render_result_target( $this->rendering_server );
						}
						?>
					</div>
				</details>
			</div>

			<?php if ( '' !== (string) ( $spec['docs_url'] ?? '' ) ) : ?>
				<p class="acrossai-mcp-setup__docs">
					<a href="<?php echo esc_url( (string) $spec['docs_url'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php
						printf(
							/* translators: %s: the AI client name, e.g. "Claude". */
							esc_html__( 'Read the full %s guide', 'acrossai-mcp-manager' ),
							esc_html( $name )
						);
						?>
						<span aria-hidden="true">&#8599;</span>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
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
		$spec = $this->get_setup_spec( $mcp_url );

		if ( array() !== $spec ) {
			// No credential controls: this string is also consumed by the
			// Quick Connect wizard and the Discovery DTO, neither of which
			// should carry admin-only buttons.
			return $this->render_setup_panel( $spec, $mcp_url );
		}

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
}
