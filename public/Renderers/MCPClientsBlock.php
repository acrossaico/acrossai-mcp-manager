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
		$slug          = $client->get_client_slug();
		$description   = $client->get_description();
		$config_file   = $client->get_config_file();
		$top_level_key = $client->get_top_level_key();
		$restart_step  = $client->get_restart_step_text();

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

		// F077 — numbered STEP 1..5 layout matching Quick Setup wizard Step 11.
		// Same content as the pre-F077 flat rows; reorganized under numbered
		// step headers so both surfaces read identically.

		// ─── STEP 1 — Generate the password ─────────────────────────────────
		$button_context               = $context;
		$button_context['sub_client'] = $slug;
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

		// ─── STEP 2 — Open the config file ──────────────────────────────────
		if ( '' !== $config_file ) {
			echo '<div class="qs-step">';
			printf(
				'<h3 class="qs-step-heading"><span class="qs-step-heading__num">%1$s</span>%2$s</h3>',
				esc_html__( 'Step 2', 'acrossai-mcp-manager' ),
				esc_html__( 'Open the config file', 'acrossai-mcp-manager' )
			);
			printf(
				'<div class="qs-step-body"><input type="text" class="widefat code" readonly value="%s" /></div>',
				esc_attr( $config_file )
			);
			echo '</div>';
		}

		// ─── STEP 3 — Locate the top-level key ──────────────────────────────
		if ( '' !== $top_level_key ) {
			echo '<div class="qs-step">';
			printf(
				'<h3 class="qs-step-heading"><span class="qs-step-heading__num">%1$s</span>%2$s</h3>',
				esc_html__( 'Step 3', 'acrossai-mcp-manager' ),
				esc_html__( 'Locate the top-level key', 'acrossai-mcp-manager' )
			);
			printf(
				'<div class="qs-step-body"><input type="text" class="widefat code" readonly value="&quot;%s&quot;" /></div>',
				esc_attr( $top_level_key )
			);
			echo '</div>';
		}

		// ─── STEP 4 — Copy this config and paste it under the top-level key ─
		$server_url  = rest_url(
			trailingslashit( (string) $server['server_route_namespace'] ) . (string) $server['server_route']
		);
		$snippet     = $client->get_config_snippet( $server_url, '' );
		$textarea_id = 'acrossai-mcp-' . sanitize_key( $slug ) . '-config-' . (int) $server['id'];
		$body        = is_array( $snippet )
			? (string) wp_json_encode( $snippet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			: (string) $snippet;

		echo '<div class="qs-step">';
		printf(
			'<h3 class="qs-step-heading"><span class="qs-step-heading__num">%1$s</span>%2$s</h3>',
			esc_html__( 'Step 4', 'acrossai-mcp-manager' ),
			esc_html__( 'Copy this config and paste it under the top-level key', 'acrossai-mcp-manager' )
		);
		echo '<div class="qs-step-body mcp-config-json">';
		// Feature 075 — small local-dev note above the copied JSON.
		if ( LocalEnvironment::needs_tls_bypass() ) {
			printf(
				'<div class="notice notice-warning inline" style="margin: 8px 0;"><p>%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p></div>',
				sprintf(
					/* translators: %s: the injected env var wrapped in <code>. */
					esc_html__( 'Local dev — added %s for local testing (never use on a live site).', 'acrossai-mcp-manager' ),
					'<code>NODE_TLS_REJECT_UNAUTHORIZED: "0"</code>'
				),
				esc_url( LocalEnvironment::troubleshooting_doc_url() ),
				esc_html__( 'More info', 'acrossai-mcp-manager' )
			);
		}
		printf(
			'<textarea id="%1$s" class="widefat code" readonly rows="12">%2$s</textarea>',
			esc_attr( $textarea_id ),
			esc_textarea( $body )
		);
		printf(
			'<button type="button" class="button copy-to-clipboard" data-field="%1$s">%2$s</button>',
			esc_attr( $textarea_id ),
			esc_html__( 'Copy Configuration', 'acrossai-mcp-manager' )
		);
		echo '</div></div>';

		// ─── STEP 5 — Restart the MCP client ────────────────────────────────
		// Client-specific action from AbstractMCPClient::get_restart_step_text()
		// — see F075 follow-up. Skipped when a subclass returns an empty
		// string (bare / strictly-typed contributions from filter plugins).
		if ( '' !== $restart_step ) {
			echo '<div class="qs-step">';
			printf(
				'<h3 class="qs-step-heading"><span class="qs-step-heading__num">%1$s</span>%2$s</h3>',
				esc_html__( 'Step 5', 'acrossai-mcp-manager' ),
				esc_html__( 'Restart the MCP client', 'acrossai-mcp-manager' )
			);
			printf(
				'<div class="qs-step-body"><p style="margin: 0;">%s</p></div>',
				esc_html( $restart_step )
			);
			echo '</div>';
		}

		// Access Control caveat — rendered AFTER the last qs-step block per
		// FR-004, matching the wizard's Step 11 placement.
		printf(
			'<div class="notice notice-info inline" style="margin-top: 20px;"><p>%s</p></div>',
			esc_html__( 'The generated password belongs to your current WordPress user. Access Control still applies to every MCP request, so a user who is not allowed for this server will receive an access denied response even if they have a saved config.', 'acrossai-mcp-manager' )
		);
	}
}
