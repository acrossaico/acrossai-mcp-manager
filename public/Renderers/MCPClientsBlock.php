<?php
/**
 * The MCP Clients configuration block — sub-nav + per-client config.
 *
 * Feature 013 — dispatches per-client configuration rendering across the
 * 8 built-in MCPClients (Claude Desktop, Claude Code, VS Code, GitHub Copilot,
 * Codex, Cursor, Gemini, Custom) plus any third-party subclass contributed
 * via the `acrossai_mcp_client_classes` filter.
 *
 * Post-F034: enumeration + validation + sort happen inside
 * `AbstractMCPClient::get_all_registered_clients()` (single canonical entry
 * point). Per-client display metadata (icon, description, config file,
 * top-level key, instructions, priority) is read via method calls on each
 * client instance — no private const on this class, no direct filter loop.
 *
 * NOT gated by any F012 toggle (FR-019).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 *
 * See DEC-CLIENT-RENDERER-PUBLIC-API.
 */

namespace AcrossAI_MCP_Manager\Public\Renderers;

use AcrossAI_MCP_Manager\Includes\Utilities\CredentialNaming;
use AcrossAI_MCP_Manager\Includes\Utilities\OperatingSystemLabels;
use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The MCP Clients block — sub-nav + selected client's config. Singleton.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 */
final class MCPClientsBlock extends AbstractClientRenderer {

	/** Route that hands the whole job to the AI client. */
	private const PATH_PROMPT = 'prompt';

	/** Route where the operator edits the config file themselves. */
	private const PATH_MANUAL = 'manual';


	/**
	 * Singleton instance.
	 *
	 * @since 0.0.6
	 * @var MCPClientsBlock|null
	 */
	protected static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @since 0.0.6
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.0.6
	 */
	private function __construct() {}

	/**
	 * Returns the block slug.
	 *
	 * @since 0.0.6
	 * @return string
	 */
	public function slug(): string {
		return 'clients';
	}

	/**
	 * Renders the block body — sub-nav pills + selected client's config.
	 *
	 * NOT gated by any F012 toggle (FR-019). Client enumeration is delegated
	 * to `AbstractMCPClient::get_all_registered_clients()` post-F034 — that
	 * method fires the `acrossai_mcp_client_classes` filter, validates FQNs
	 * per SEC-013-008 (silent-skip on invalid), validates slugs, dedups, and
	 * sorts by `(get_priority() ASC, get_client_slug() ASC)`.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @param array $server  Server row data.
	 * @param array $context Resolved context array.
	 * @return void
	 */
	protected function render_body( array $server, array $context ): void {
		// F034: single canonical enumeration path. The `acrossai_mcp_client_classes`
		// filter is fired inside `AbstractMCPClient::get_all_registered_clients()`
		// with the eight-class default seed. Validation (FQN + slug regex + dedup)
		// and sort (priority ASC, slug ASC) happen there — this method is a pure
		// consumer, no metadata lookup by slug, no inline default array.
		$clients = AbstractMCPClient::get_all_registered_clients();

		if ( empty( $clients ) ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'No MCP client integrations available.', 'acrossai-mcp-manager' )
			);
			return;
		}

		$sub_client_slug = isset( $context['sub_client'] ) ? sanitize_key( (string) $context['sub_client'] ) : '';
		$active_client   = null;
		foreach ( $clients as $client ) {
			if ( $client->get_client_slug() === $sub_client_slug ) {
				$active_client = $client;
				break;
			}
		}
		if ( null === $active_client ) {
			$active_client = reset( $clients );
		}

		echo '<div class="mcp-tab-panel acrossai-clients-panel">';
		$this->render_subnav( $clients, $active_client, $context );
		$this->render_client_details( $server, $context, $active_client );
		echo '</div>';
	}

	/**
	 * Renders the horizontal pill sub-nav (Claude Desktop, Claude Code, VS Code, ...).
	 *
	 * @since 0.0.6
	 * @param AbstractMCPClient[] $clients       Ordered client instances.
	 * @param AbstractMCPClient   $active_client Currently-active client.
	 * @param array               $context       Resolved context array.
	 * @return void
	 */
	private function render_subnav( array $clients, AbstractMCPClient $active_client, array $context ): void {
		echo '<div class="acrossai-client-tabs-nav">';
		foreach ( $clients as $client ) {
			$slug      = $client->get_client_slug();
			$is_active = ( $client === $active_client );
			$url       = add_query_arg( 'client', $slug, (string) $context['submit_target_url'] );
			$css_class = $is_active ? 'acrossai-client-tab acrossai-client-tab-active' : 'acrossai-client-tab';

			// F076 — pill body renders the client name only. The abstract
			// client method that returns the per-client emoji stays
			// defined (companion plugins may still consume its return
			// value via ConnectionMethodRegistry's DTO), but the picker
			// no longer surfaces the emoji.
			printf(
				'<a href="%1$s" class="%2$s"><span>%3$s</span></a>',
				esc_url( $url ),
				esc_attr( $css_class ),
				esc_html( $client->get_client_name() )
			);
		}
		echo '</div>';
	}

	/**
	 * State what the generated prompt actually contains, and how to undo it.
	 *
	 * An Application Password is NOT scoped to this plugin or this server: it
	 * authenticates as the user across the whole REST API. Because generation
	 * is administrator-only (FR-024), every credential this produces is an
	 * administrator credential — and the operator is about to paste it into a
	 * third party's chat history.
	 *
	 * "Contains a live password" would be true and useless: an operator can
	 * reasonably read that as a password scoped to this connection. The copy
	 * says what it can do and where it goes (FR-020, SC-C13), and the revoke
	 * link makes it reversible from the same panel (SC-C14).
	 *
	 * The link points at core's own Application Passwords UI rather than a tab
	 * of ours. `ApplicationPasswords::render_for_server()` exists but has never
	 * had a call site, so there is no Tokens tab to link to; core's screen
	 * always exists, lists every credential with a revoke button, and the names
	 * this plugin now writes identify which is which.
	 *
	 * Two printf calls, not one: B16 records that mixing positional and
	 * numbered placeholders in a single format string silently mislabels
	 * output, and the inner string needs numbered ones for the anchor pair.
	 *
	 * @return void
	 */
	private function render_prompt_credential_disclosure(): void {
		printf(
			'<div class="acrossai-mcp-callout acrossai-mcp-callout--warn"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'This prompt contains a working password for your site.', 'acrossai-mcp-manager' ),
			esc_html__( 'It signs in as you across the whole site, not just this MCP server. Pasting it into a hosted assistant sends it to that provider, where it may be kept in your conversation history.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Which route the operator picked.
	 *
	 * A navigation parameter, read the same way the client pill is: this
	 * screen already changes selection by full page load, so the choice
	 * survives without JavaScript and the server renders exactly one route.
	 *
	 * Defaults to the prompt because it is the shorter path and the one most
	 * operators want; an unrecognised value falls back to it rather than
	 * rendering nothing.
	 *
	 * @return string One of self::PATH_PROMPT or self::PATH_MANUAL.
	 */
	private function resolve_setup_path(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation parameter, changes no state.
		$raw = isset( $_GET['setup'] ) ? sanitize_key( wp_unslash( $_GET['setup'] ) ) : '';

		return self::PATH_MANUAL === $raw ? self::PATH_MANUAL : self::PATH_PROMPT;
	}

	/**
	 * STEP 2 — the choice between the two routes.
	 *
	 * Rendered as links, not form controls: selection is navigation here, so
	 * it works without JavaScript, is bookmarkable, and leaves the server as
	 * the single thing deciding what gets rendered. `aria-current` rather than
	 * `role="radio"` — these are links that move you somewhere, and announcing
	 * them as radios would promise behaviour they do not have.
	 *
	 * @param array<string, mixed> $server  Resolved server row.
	 * @param array<string, mixed> $context Resolved render context.
	 * @param AbstractMCPClient    $client  Selected client.
	 * @param string               $active  Currently selected route.
	 * @return void
	 */
	private function render_path_picker( array $server, array $context, AbstractMCPClient $client, string $active ): void {
		$base = add_query_arg(
			'client',
			$client->get_client_slug(),
			(string) ( $context['submit_target_url'] ?? '' )
		);

		$options = array(
			array(
				'key'         => self::PATH_PROMPT,
				'title'       => __( 'Let your AI client do it', 'acrossai-mcp-manager' ),
				'desc'        => sprintf(
					/* translators: %s: MCP client display name. */
					__( 'Copy one prompt and paste it into %s. It edits its own config file.', 'acrossai-mcp-manager' ),
					$client->get_client_name()
				),
				'meta'        => __( '1 step · about 30 seconds', 'acrossai-mcp-manager' ),
				'recommended' => true,
			),
			array(
				'key'         => self::PATH_MANUAL,
				'title'       => __( 'Set it up yourself', 'acrossai-mcp-manager' ),
				'desc'        => __( 'Open the config file, find the key, and paste the configuration yourself.', 'acrossai-mcp-manager' ),
				'meta'        => __( '3 steps · no password sent to a chat', 'acrossai-mcp-manager' ),
				'recommended' => false,
			),
		);

		echo '<div class="qs-step">';
		printf(
			'<h3 class="qs-step-heading"><span class="qs-step-heading__num">%1$s</span>%2$s</h3>',
			esc_html__( 'Step 2', 'acrossai-mcp-manager' ),
			esc_html(
				sprintf(
					/* translators: %s: MCP client display name. */
					__( 'Choose how to add it to %s', 'acrossai-mcp-manager' ),
					$client->get_client_name()
				)
			)
		);

		echo '<div class="qs-step-body"><div class="acrossai-mcp-route-picker">';

		foreach ( $options as $option ) {
			$on = $option['key'] === $active;

			printf(
				'<a class="acrossai-mcp-route-card%1$s" href="%2$s" data-acrossai-route-target="%3$s"%4$s>',
				$on ? ' is-selected' : '',
				esc_url( add_query_arg( 'setup', $option['key'], $base ) ),
				esc_attr( (string) $option['key'] ),
				$on ? ' aria-current="true"' : ''
			);

			echo '<span class="acrossai-mcp-route-card__mark" aria-hidden="true"></span>';
			echo '<span class="acrossai-mcp-route-card__body">';

			printf(
				'<span class="acrossai-mcp-route-card__title">%1$s%2$s</span>',
				esc_html( (string) $option['title'] ),
				$option['recommended']
					? '<span class="acrossai-mcp-route-card__badge">' . esc_html__( 'Recommended', 'acrossai-mcp-manager' ) . '</span>'
					: ''
			);
			printf(
				'<span class="acrossai-mcp-route-card__desc">%s</span>',
				esc_html( (string) $option['desc'] )
			);
			printf(
				'<span class="acrossai-mcp-route-card__meta">%s</span>',
				esc_html( (string) $option['meta'] )
			);

			echo '</span></a>';
		}

		echo '</div></div></div>';
	}

	/**
	 * PATH A — hand the whole job to the AI client.
	 *
	 * @param array<string, mixed> $server     Resolved server row.
	 * @param AbstractMCPClient    $client     Selected client.
	 * @param string               $server_url Fully-resolved MCP endpoint.
	 * @param bool                 $visible    Whether this route starts visible.
	 * @return void
	 */
	private function render_prompt_path( array $server, AbstractMCPClient $client, string $server_url, bool $visible ): void {
		$prompt = $client->get_setup_prompt( $server_url, '' );

		if ( '' === trim( $prompt ) ) {
			return;
		}

		$field_id = 'acrossai-mcp-' . sanitize_key( $client->get_client_slug() ) . '-prompt-' . (int) $server['id'];

		printf(
			'<div class="acrossai-mcp-route-panel" data-acrossai-route="%1$s"%2$s>',
			esc_attr( self::PATH_PROMPT ),
			$visible ? '' : ' hidden'
		);

		printf(
			'<div class="acrossai-mcp-route-panel__intro"><span class="acrossai-mcp-route-panel__title">%1$s</span><span class="acrossai-mcp-route-panel__sub">%2$s</span></div>',
			esc_html(
				sprintf(
					/* translators: %s: MCP client display name. */
					__( 'Paste this prompt into %s', 'acrossai-mcp-manager' ),
					$client->get_client_name()
				)
			),
			esc_html__( 'It edits its own config file. You do not need to find or open anything.', 'acrossai-mcp-manager' )
		);

		$this->render_prompt_credential_disclosure();

		printf(
			'<textarea id="%1$s" class="widefat code acrossai-mcp-route-panel__code" data-acrossai-password-target="1" readonly rows="14">%2$s</textarea>',
			esc_attr( $field_id ),
			esc_textarea( $prompt )
		);

		echo '<div class="acrossai-mcp-route-panel__actions">';
		printf(
			'<button type="button" class="button button-primary copy-to-clipboard" data-field="%1$s">%2$s</button>',
			esc_attr( $field_id ),
			esc_html__( 'Copy prompt', 'acrossai-mcp-manager' )
		);
		$this->render_credential_footnote( (string) ( $server['server_name'] ?? '' ) );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * PATH B — the operator edits the file.
	 *
	 * Lettered a/b/c rather than numbered: these are sub-steps of Step 2, and
	 * numbering them 2-5 was what made the screen read as one long sequence.
	 * The restart instruction folds into the final sub-step, because it is
	 * part of "paste it and make it take effect", not a step of its own.
	 *
	 * @param array<string, mixed> $server     Resolved server row.
	 * @param AbstractMCPClient    $client     Selected client.
	 * @param string               $server_url Fully-resolved MCP endpoint.
	 * @param bool                 $visible    Whether this route starts visible.
	 * @return void
	 */
	private function render_manual_path( array $server, AbstractMCPClient $client, string $server_url, bool $visible = true ): void {
		$slug          = $client->get_client_slug();
		$top_level_key = $client->get_top_level_key();
		$restart_step  = $client->get_restart_step_text();
		$config_files  = $client->get_config_files();
		$config_file   = $client->get_config_file();

		$snippet     = $client->get_config_snippet( $server_url, '' );
		$textarea_id = 'acrossai-mcp-' . sanitize_key( $slug ) . '-config-' . (int) $server['id'];
		$body        = is_array( $snippet )
			? (string) wp_json_encode( $snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			: (string) $snippet;

		printf(
			'<div class="acrossai-mcp-route-panel" data-acrossai-route="%1$s"%2$s>',
			esc_attr( self::PATH_MANUAL ),
			$visible ? '' : ' hidden'
		);

		// (a) Open the config file.
		if ( array() !== $config_files || '' !== $config_file ) {
			$this->open_substep( 'a', __( 'Open the config file', 'acrossai-mcp-manager' ) );
			if ( array() !== $config_files ) {
				$this->render_os_config_paths(
					$config_files,
					'acrossai-mcp-' . sanitize_key( $slug ) . '-' . (int) $server['id']
				);
			} else {
				printf(
					'<input type="text" class="widefat code" readonly value="%s" />',
					esc_attr( $config_file )
				);
			}
			echo '</div></div>';
		}

		// (b) Find the top-level key.
		if ( '' !== $top_level_key ) {
			$this->open_substep( 'b', __( 'Find the top-level key', 'acrossai-mcp-manager' ) );
			printf(
				'<div class="acrossai-mcp-substep__inline"><code class="acrossai-mcp-key">%1$s</code><span class="acrossai-mcp-substep__hint">%2$s</span></div>',
				esc_html( '"' . $top_level_key . '"' ),
				esc_html__( 'Create it if the file is empty.', 'acrossai-mcp-manager' )
			);
			echo '</div></div>';
		}

		// (c) Paste, save, restart.
		$this->open_substep(
			'c',
			'' === $restart_step
				? __( 'Paste this configuration under the key and save', 'acrossai-mcp-manager' )
				: sprintf(
					/* translators: %s: client-specific reload instruction, e.g. "Restart Claude Desktop to load the new MCP server." */
					__( 'Paste this configuration under the key, save, then: %s', 'acrossai-mcp-manager' ),
					$restart_step
				)
		);

		if ( LocalEnvironment::needs_tls_bypass() ) {
			printf(
				'<div class="acrossai-mcp-callout acrossai-mcp-callout--warn"><p>%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p></div>',
				wp_kses(
					sprintf(
						/* translators: %s: the injected env var wrapped in <code>. */
						__( 'Local dev — added %s for local testing. Never use on a live site.', 'acrossai-mcp-manager' ),
						'<code>NODE_TLS_REJECT_UNAUTHORIZED: "0"</code>'
					),
					array( 'code' => array() )
				),
				esc_url( LocalEnvironment::troubleshooting_doc_url() ),
				esc_html__( 'More info', 'acrossai-mcp-manager' )
			);
		}

		printf(
			'<textarea id="%1$s" class="widefat code acrossai-mcp-route-panel__code" data-acrossai-password-target="1" readonly rows="12">%2$s</textarea>',
			esc_attr( $textarea_id ),
			esc_textarea( $body )
		);
		printf(
			'<div class="acrossai-mcp-route-panel__actions"><button type="button" class="button button-primary copy-to-clipboard" data-field="%1$s">%2$s</button></div>',
			esc_attr( $textarea_id ),
			esc_html__( 'Copy config', 'acrossai-mcp-manager' )
		);

		echo '</div></div>';
		echo '</div>';
	}

	/**
	 * Open one lettered sub-step. Caller closes with `</div></div>`.
	 *
	 * @param string $letter Sub-step letter.
	 * @param string $title  Sub-step title.
	 * @return void
	 */
	private function open_substep( string $letter, string $title ): void {
		printf(
			'<div class="acrossai-mcp-substep"><span class="acrossai-mcp-substep__mark">%1$s</span><div class="acrossai-mcp-substep__body"><span class="acrossai-mcp-substep__title">%2$s</span>',
			esc_html( $letter ),
			esc_html( $title )
		);
	}

	/**
	 * Credential count plus the revoke link, beside the Copy button.
	 *
	 * The revoke link shows unconditionally — SC-C14 requires revocation to be
	 * one step from where the credential was made, and an operator who has
	 * just generated their first one needs it as much as anybody. The count
	 * prefixes it only once there is something to count.
	 *
	 * @param string $server_name Server display name.
	 * @return void
	 */
	private function render_credential_footnote( string $server_name ): void {
		$count = '' === $server_name
			? 0
			: CredentialNaming::count_for_server( get_current_user_id(), $server_name );

		$prefix = $count > 0
			? sprintf(
				/* translators: %d: number of Application Passwords already issued for this server. */
				_n(
					'%d password already exists for this server — generating another leaves it working.',
					'%d passwords already exist for this server — generating another leaves them working.',
					$count,
					'acrossai-mcp-manager'
				),
				$count
			) . ' '
			: '';

		printf(
			'<span class="acrossai-mcp-route-panel__footnote">%1$s<a href="%2$s">%3$s</a></span>',
			esc_html( $prefix ),
			esc_url( admin_url( 'profile.php#application-passwords' ) ),
			esc_html__( 'Revoke a password', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * STEP 2's per-OS path list (issue #159).
	 *
	 * Every supported OS is shown at once, labelled. An earlier revision used
	 * a tabbed switcher with the visitor's OS auto-selected; it was rejected on
	 * review and the simpler form is better: with two or three short paths
	 * there is nothing to gain by hiding all but one, and a Windows user should
	 * not have to click anything to see a Windows path. The issue sanctions
	 * this form directly ("listing all three labelled paths is acceptable if a
	 * switcher is overkill").
	 *
	 * Consequently there is no OS detection here at all. Nothing is hidden, so
	 * nothing needs guessing — and a User-Agent sniff that can be wrong is not
	 * worth carrying for a layout that does not depend on it.
	 *
	 * Only the OSes the client actually ships for are listed. A client omits an
	 * OS it has no build for; the absence of a Linux row IS the answer for
	 * Claude Desktop, which ships for macOS and Windows only.
	 *
	 * @since 0.3.9
	 * @param array<string, string> $config_files OS slug => path. Non-empty.
	 * @param string                $id_prefix    Deterministic field-id prefix.
	 * @return void
	 */
	private function render_os_config_paths( array $config_files, string $id_prefix ): void {
		// Ordering and labels come from the shared utility rather than a local
		// copy: F099's prompt builder needs the identical mapping, and §VI
		// requires extraction before the second use, not after it.
		$ordered = OperatingSystemLabels::order( $config_files );

		if ( array() === $ordered ) {
			return;
		}

		echo '<div class="acrossai-mcp-paths">';

		foreach ( $ordered as $os => $path ) {
			// Deterministic, derived from client + server + OS. An earlier
			// revision used wp_rand() for uniqueness, which made the rendered
			// markup differ between two renders of the same screen — that
			// breaks any byte-identity assertion, and ConnectTab has one as a
			// security invariant (an unknown method and a capability-excluded
			// one must be indistinguishable, or the difference enumerates
			// withheld methods).
			$field = $id_prefix . '-path-' . sanitize_key( $os );

			printf(
				'<span class="acrossai-mcp-paths__os">%1$s</span><input id="%2$s" type="text" class="acrossai-mcp-paths__value code" readonly value="%3$s" aria-label="%4$s" /><button type="button" class="button copy-to-clipboard acrossai-mcp-paths__copy" data-field="%2$s">%5$s</button>',
				esc_html( OperatingSystemLabels::label( $os ) ),
				esc_attr( $field ),
				esc_attr( $path ),
				esc_attr(
					sprintf(
						/* translators: %s: operating system label, e.g. "macOS". */
						__( '%s config file path', 'acrossai-mcp-manager' ),
						OperatingSystemLabels::label( $os )
					)
				),
				esc_html__( 'Copy', 'acrossai-mcp-manager' )
			);
		}

		echo '</div>';
	}

	/**
	 * Renders the selected client's details: heading + description + generate
	 * button + Config File row + Top-Level Key row + Configuration JSON block +
	 * Copy button + instructions callout.
	 *
	 * @since 0.0.6
	 * @param array             $server  Server row data.
	 * @param array             $context Resolved context array.
	 * @param AbstractMCPClient $client  Selected client instance.
	 * @return void
	 */
	private function render_client_details( array $server, array $context, AbstractMCPClient $client ): void {
		$description = $client->get_description();

		// Hoisted from STEP 4: the setup-prompt panel renders before it and
		// needs the same URL. One resolution, so the prompt and the manual
		// block can never disagree about which server they describe.
		$server_url = rest_url(
			trailingslashit( (string) $server['server_route_namespace'] ) . (string) $server['server_route']
		);

		// Heading + subtitle. F076 — no emoji prefix on the client name.
		printf(
			'<h2>%s</h2>',
			esc_html( $client->get_client_name() )
		);
		if ( '' !== $description ) {
			printf(
				'<p class="description">%s</p>',
				esc_html( $description )
			);
		}

		// ─── STEP 1 — Generate the password ─────────────────────────────────
		// Step 1 is unchanged by F099. What follows it is now a choice rather
		// than a continuation: see render_path_picker().
		$button_context               = $context;
		$button_context['sub_client'] = $client->get_client_slug();
		echo '<div class="qs-step">';
		printf(
			'<h3 class="qs-step-heading"><span class="qs-step-heading__num">%1$s</span>%2$s</h3>',
			esc_html__( 'Step 1', 'acrossai-mcp-manager' ),
			esc_html__( 'Generate the password', 'acrossai-mcp-manager' )
		);
		echo '<div class="qs-step-body password-actions">';
		$this->passwords_generate_button( $server, $button_context );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Creates a one-time password via WordPress Application Passwords. Shown only once — store it safely.', 'acrossai-mcp-manager' )
		);
		echo '</div></div>';

		// ─── STEP 2 — choose a route, then render only that route ──────────
		// Before F099 this screen rendered the prompt AND steps 2-5 stacked, so
		// the two routes read as one long sequence and an operator could not
		// tell where the first ended. Step 2 is now the choice itself, and only
		// the chosen path is shown.
		$is_admin_user = current_user_can( 'manage_options' );
		$path          = $is_admin_user ? $this->resolve_setup_path() : self::PATH_MANUAL;

		if ( $is_admin_user ) {
			$this->render_path_picker( $server, $context, $client, $path );
		}

		// BOTH routes are rendered; the inactive one is hidden. Switching is
		// then instant and keeps any password already spliced into the other
		// route's text — a reload would discard it, because the credential is
		// shown once and never re-fetchable.
		//
		// The picker is still links with real hrefs, so with JavaScript off the
		// server honours ?setup= and marks the right panel visible. Progressive
		// enhancement, not a JS requirement.
		if ( $is_admin_user ) {
			$this->render_prompt_path( $server, $client, $server_url, self::PATH_PROMPT === $path );
		}

		$this->render_manual_path( $server, $client, $server_url, self::PATH_MANUAL === $path );

		// Access Control caveat — rendered AFTER the last qs-step block per
		// FR-004, matching the wizard's Step 11 placement.
		printf(
			'<div class="notice notice-info inline" style="margin-top: 20px;"><p>%s</p></div>',
			esc_html__( 'The generated password belongs to your current WordPress user. Access Control still applies to every MCP request, so a user who is not allowed for this server will receive an access denied response even if they have a saved config.', 'acrossai-mcp-manager' )
		);
	}
}
