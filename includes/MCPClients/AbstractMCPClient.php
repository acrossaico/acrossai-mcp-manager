<?php
/**
 * Abstract base class for all MCP client definitions.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\MCPClients
 */

namespace AcrossAI_MCP_Manager\Includes\MCPClients;

use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;
use AcrossAI_MCP_Manager\Includes\Utilities\SiteSlug;

defined( 'ABSPATH' ) || exit;

/**
 * Pure service layer — each concrete subclass produces a copy-paste
 * configuration snippet for one AI tool, given a server URL and an
 * Application Password.
 *
 * Constitutional invariants (FR-008, FR-009):
 *   - No WordPress hooks (no add_action / add_filter anywhere in this module).
 *   - No DB / HTTP / cookies / global state.
 *   - No singleton pattern — instances are stateless and interchangeable.
 *   - Tests run WITHOUT WordPress bootstrap (SC-003).
 *
 * The singleton exemption is justified parallel to A10 (WP_List_Table
 * subclasses): different rationale (no instance state to share), same
 * outcome (not every class in the codebase is a singleton).
 * See docs/memory/INDEX.md A2 vs FR-009 soft exemption note.
 */
abstract class AbstractMCPClient {

	/**
	 * Empty-token placeholder. When the caller hasn't yet generated an
	 * Application Password, the snippet renders this text in the token
	 * slot so the user sees a self-documenting gap rather than a
	 * silently-broken config (Q2 clarification 2026-06-17).
	 */
	public const EMPTY_TOKEN_PLACEHOLDER = '(paste generated password here)';

	/**
	 * Fallback server-key when derive_server_key() can't extract a usable
	 * path segment from the URL.
	 */
	public const SERVER_KEY_FALLBACK = 'wordpress-mcp';

	// ─────────────────────────────────────────────────────────────────────────
	// Abstract contract (FR-001).
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Unique machine-readable identifier (kebab-case, lowercase, ASCII).
	 *
	 * @return string e.g. 'claude-desktop'
	 */
	abstract public function get_client_slug(): string;

	/**
	 * Human-readable name as the AI tool markets itself.
	 *
	 * @return string e.g. 'Claude Desktop'
	 */
	abstract public function get_client_name(): string;

	/**
	 * The copy-paste payload the user pastes into their AI tool.
	 *
	 * Return-type union (string|array) reflects per-client format
	 * choices: JSON-config tools return arrays; CLI-install tools
	 * return strings. The consumer differentiates via `is_array()`.
	 *
	 * MUST embed both $server_url and $auth_token; never hardcode URLs;
	 * never read env vars or options for the token. When $auth_token is
	 * empty, the token slot MUST render EMPTY_TOKEN_PLACEHOLDER (via
	 * safe_token()) rather than an empty string.
	 *
	 * @param string $server_url Already-sanitized server URL (caller's responsibility).
	 * @param string $auth_token Already-issued Application Password (caller's responsibility).
	 *
	 * @return string|array
	 */
	abstract public function get_config_snippet( string $server_url, string $auth_token );

	// ─────────────────────────────────────────────────────────────────────────
	// F034 metadata contract (non-abstract, empty-string defaults for
	// backwards-compatibility per FR-002). Concrete subclasses MAY override
	// each; a bare subclass implementing only the three original abstract
	// methods above continues to compile and enumerate correctly.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Icon hint — emoji or short display marker. Rendered next to the client
	 * name in the sub-nav. Empty when unset (no icon glyph rendered).
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '';
	}

	/**
	 * One-line description (translated). Shown below the client name in its
	 * panel. Empty when unset (no description text rendered).
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * Config file path hint (e.g. `~/.claude.json`). Untranslated technical
	 * string. Rendered in the paste instructions. Empty when unset.
	 *
	 * @return string
	 */
	public function get_config_file(): string {
		return '';
	}

	/**
	 * JSON/TOML top-level key the snippet gets pasted under (e.g. `mcpServers`).
	 * Untranslated. Empty when unset.
	 *
	 * @return string
	 */
	public function get_top_level_key(): string {
		return '';
	}

	/**
	 * Setup instructions (translated). Rendered below the config snippet.
	 * Empty when unset (no instructions block).
	 *
	 * @return string
	 */
	public function get_instructions(): string {
		return '';
	}

	/**
	 * Sub-nav slot preference. Lower values sort earlier. WP-idiomatic
	 * (matches `add_action` priority semantics). Default 100 places
	 * third-party contributions AFTER all eight built-ins (which use
	 * 10, 20, 30, ..., 80). Consumed by `get_all_registered_clients()`
	 * during the sort phase; tiebreaker for equal priorities is slug ASC.
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 100;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// F034 canonical filter-aware enumeration. Sole entry point for
	// "which MCP clients are registered on this site." Mirrors the shape of
	// ConnectorProfileRegistry::get_profiles() at
	// includes/Connectors/ConnectorProfileRegistry.php:57-118 (adapted for
	// the FQN-string contribution shape used by acrossai_mcp_client_classes).
	//
	// Seed value for the extension filter — the eight built-in clients in
	// insertion order. Actual runtime sort uses get_priority() per FR-010.
	// ─────────────────────────────────────────────────────────────────────────

	public const DEFAULT_CLIENT_CLASSES = array(
		ClaudeDesktopClient::class,
		ClaudeCodeClient::class,
		VSCodeClient::class,
		GitHubCopilotClient::class,
		CodexClient::class,
		CursorClient::class,
		GeminiClient::class,
		WindsurfClient::class,
		ZedClient::class,
		ClineClient::class,
		RooCodeClient::class,
		KiloCodeClient::class,
		AmazonQClient::class,
		OpenCodeClient::class,
		AntigravityClient::class,
		CustomClient::class,
	);

	/**
	 * Canonical enumeration of every registered MCP client.
	 *
	 * Fires `acrossai_mcp_client_classes` exactly once per call with
	 * `DEFAULT_CLIENT_CLASSES` as the seed. Validates each contributed FQN
	 * (silent-skip on invalid per SEC-013-008) and each contributed subclass
	 * slug (regex `/\A[a-z0-9-]{1,64}\z/` with `_doing_it_wrong` under
	 * `WP_DEBUG` for violators). Dedups by slug (later-wins). Sorts by
	 * `(get_priority() ASC, get_client_slug() ASC)`.
	 *
	 * Returned instances are fresh (no caching). Consumers should call this
	 * method once per admin render, not per lookup.
	 *
	 * @return AbstractMCPClient[]
	 */
	public static function get_all_registered_clients(): array {
		/**
		 * Filter: acrossai_mcp_client_classes
		 *
		 * Companion plugins append their own AbstractMCPClient subclass FQNs.
		 * Invalid FQNs (non-string, missing class, not extending AbstractMCPClient)
		 * are silently skipped per SEC-013-008. Bad slugs and duplicate slugs
		 * fire `_doing_it_wrong` under WP_DEBUG.
		 *
		 * @since 0.0.6
		 * @experimental May change without notice before 1.0.0.
		 *
		 * @param string[] $client_class_fqns Ordered list of AbstractMCPClient subclass FQNs.
		 */
		$class_fqns = (array) apply_filters( 'acrossai_mcp_client_classes', self::DEFAULT_CLIENT_CLASSES );

		$seen = array();
		foreach ( $class_fqns as $fqn ) {
			if ( ! is_string( $fqn ) || ! class_exists( $fqn ) ) {
				continue;
			}
			if ( ! is_subclass_of( $fqn, self::class ) ) {
				continue;
			}
			$instance = new $fqn();
			$slug     = $instance->get_client_slug();
			if ( '' === $slug || ! preg_match( '/\A[a-z0-9-]{1,64}\z/', $slug ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					_doing_it_wrong(
						'AcrossAI_MCP_Manager\\Includes\\MCPClients\\AbstractMCPClient::get_all_registered_clients',
						sprintf(
							/* translators: %s: rejected slug from a third-party MCP client subclass */
							esc_html__( 'Client slug %s does not match /[a-z0-9-]{1,64}/ — subclass discarded.', 'acrossai-mcp-manager' ),
							esc_html( $slug )
						),
						'0.1.7'
					);
				}
				continue;
			}
			if ( isset( $seen[ $slug ] ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				_doing_it_wrong(
					'AcrossAI_MCP_Manager\\Includes\\MCPClients\\AbstractMCPClient::get_all_registered_clients',
					sprintf(
						/* translators: %s: duplicate slug from a third-party MCP client subclass */
						esc_html__( 'Duplicate client slug %s — later contribution wins.', 'acrossai-mcp-manager' ),
						esc_html( $slug )
					),
					'0.1.7'
				);
			}
			$seen[ $slug ] = $instance;
		}

		usort(
			$seen,
			static function ( AbstractMCPClient $a, AbstractMCPClient $b ): int {
				$priority_cmp = $a->get_priority() <=> $b->get_priority();
				if ( 0 !== $priority_cmp ) {
					return $priority_cmp;
				}
				return $a->get_client_slug() <=> $b->get_client_slug();
			}
		);

		return array_values( $seen );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Protected helpers (FR-002).
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Concatenate a base REST URL with a route namespace + route segment.
	 *
	 * Pure string composition — no get_option(), no home_url(), no WP
	 * globals. Caller supplies the base URL (typically `rest_url()` from
	 * the consumer's WP context); this method only joins.
	 *
	 * @param string $base_rest_url   Base URL e.g. 'https://example.com/wp-json/'.
	 * @param string $route_namespace Route namespace, e.g. 'mcp'.
	 * @param string $route           Route path, e.g. 'wordpress-default-server'.
	 *
	 * @return string Composed URL.
	 */
	protected function build_server_url(
		string $base_rest_url,
		string $route_namespace,
		string $route
	): string {
		$base = rtrim( $base_rest_url, '/' );
		$ns   = trim( $route_namespace, '/' );
		$rt   = trim( $route, '/' );
		if ( '' === $ns && '' === $rt ) {
			return $base;
		}
		if ( '' === $ns ) {
			return $base . '/' . $rt;
		}
		if ( '' === $rt ) {
			return $base . '/' . $ns;
		}
		return $base . '/' . $ns . '/' . $rt;
	}

	/**
	 * Extract the inner mcpServers key from a server URL (Q1 2026-06-17).
	 *
	 * Strips query string + trailing slash, takes the last path segment,
	 * then prefixes with the site slug (amended 2026-07-15) so the
	 * admin-UI-rendered `mcpServers` key matches what the CLI
	 * (`@acrossai/mcp-manager` at `configWriter.js` / `configDisplay.js:15`)
	 * writes: `${siteSlug}-${serverId}`. Fixes the historical mismatch
	 * where admin UI showed `mcp-adapter-default-server` but the CLI wrote
	 * `<site>-mcp-adapter-default-server`, causing duplicate/orphaned
	 * entries in `~/.claude.json` when operators copied one and the CLI
	 * generated the other.
	 *
	 * Site slug source: `Utilities\SiteSlug::get()` — the SAME helper
	 * consumed by `CliController::handle_health` for the `/health`
	 * `site_slug` field the CLI reads. Single source of truth per
	 * constitution §VI (DRY).
	 *
	 * Falls back to SERVER_KEY_FALLBACK on empty / unparsable URL inputs.
	 *
	 * Test matrix in research.md R2 + amended 2026-07-15 for the site-slug
	 * prefix in tests/phpunit/MCPClients/AbstractMCPClientTest.php.
	 *
	 * @param string $server_url Full server URL.
	 *
	 * @return string Derived server key of the form `<site-slug>-<url-tail>`.
	 */
	protected function derive_server_key( string $server_url ): string {
		$no_query = (string) strtok( $server_url, '?' );
		$no_slash = rtrim( $no_query, '/' );
		if ( '' === $no_slash ) {
			return self::SERVER_KEY_FALLBACK;
		}
		$parts = explode( '/', $no_slash );
		$last  = end( $parts );
		if ( false === $last || '' === $last ) {
			return self::SERVER_KEY_FALLBACK;
		}

		return SiteSlug::get() . '-' . $last;
	}

	/**
	 * Render the token for snippet output. Empty → placeholder text;
	 * non-empty → verbatim.
	 *
	 * NEVER use this for logs — it returns plaintext. Use redact_token()
	 * for log-safe representation.
	 *
	 * @param string $token Raw Application Password (may be empty).
	 *
	 * @return string Either the token verbatim, or the placeholder.
	 */
	protected function safe_token( string $token ): string {
		return '' === $token ? self::EMPTY_TOKEN_PLACEHOLDER : $token;
	}

	/**
	 * Build the standard env block for a client's `command`/`args`/`env` shape.
	 *
	 * Includes `WP_API_URL` / `WP_API_USERNAME` / `WP_API_PASSWORD` and,
	 * when the current site meets Feature 075's local-HTTPS gate (see
	 * `LocalEnvironment::needs_tls_bypass()`), also `NODE_TLS_REJECT_UNAUTHORIZED = "0"`
	 * — the workaround for Node.js rejecting the self-signed certs Local by
	 * Flywheel, MAMP, DDEV, and wp-env produce. String literal `"0"` (Node
	 * convention), not integer 0.
	 *
	 * `$extra` is merged FIRST so subclass keys (e.g. Claude Code's
	 * `OAUTH_ENABLED => 'false'`) keep their historical position at the
	 * FRONT of the env block — spec FR-006 shape-preservation contract.
	 * The base keys always win on collision (defensive: a subclass cannot
	 * accidentally break `WP_API_URL` / `WP_API_USERNAME` / `WP_API_PASSWORD`).
	 * `NODE_TLS_REJECT_UNAUTHORIZED` is appended last so a diff-viewer sees
	 * it clearly as the new key introduced by Feature 075.
	 *
	 * @param string               $server_url Server URL.
	 * @param string               $auth_token Application Password (may be empty).
	 * @param array<string,string> $extra      Additional env keys to merge in.
	 *
	 * @return array<string,string>
	 */
	protected function build_env( string $server_url, string $auth_token, array $extra = array() ): array {
		$base = array(
			'WP_API_URL'      => $server_url,
			'WP_API_USERNAME' => $this->current_username(),
			'WP_API_PASSWORD' => $this->safe_token( $auth_token ),
		);
		$env  = array_merge( $extra, $base );

		if ( LocalEnvironment::needs_tls_bypass() ) {
			$env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
		}

		return $env;
	}

	/**
	 * Return the current WP user's login for the WP_API_USERNAME env var.
	 *
	 * Application Passwords authenticate via HTTP Basic (username:apppass),
	 * so every generated snippet needs both. Falls back to an empty string
	 * when the request has no authenticated user (defensive — this method
	 * is only called from admin render paths where a user is guaranteed).
	 *
	 * @return string
	 */
	protected function current_username(): string {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return '';
		}
		$user = wp_get_current_user();
		return isset( $user->user_login ) ? (string) $user->user_login : '';
	}

	/**
	 * Log-safe token representation. First 4 chars + ellipsis + last 2
	 * chars, or '(empty)' when input is empty.
	 *
	 * Use this ONLY for log lines / debug strings. NEVER use it as the
	 * actual snippet payload (FR-002 security note).
	 *
	 * @param string $token Raw Application Password (may be empty).
	 *
	 * @return string Log-safe redacted representation.
	 */
	protected function redact_token( string $token ): string {
		if ( '' === $token ) {
			return '(empty)';
		}
		// PHP multibyte-safe substr — Application Passwords are ASCII but
		// belt-and-suspenders for arbitrary token strings.
		$prefix = mb_substr( $token, 0, 4 );
		$suffix = mb_substr( $token, -2 );
		return $prefix . '…' . $suffix;
	}
}
