<?php
/**
 * MCP Controller — boots the WP MCP adapter singleton and registers each
 * enabled MCP server row as an adapter endpoint.
 *
 * Ported from v0.0.4 `src/MCP/Controller.php` (170 LOC) on 2026-07-01 as
 * part of Feature-009 (Phase 4 gap closure). The v0.0.4 file was omitted
 * during Phase 4's PR #6, which shipped only the `MCPClients/*` classes.
 * Without this port, no MCP servers are exposed via the adapter package —
 * a silent functional regression against v0.0.4 behavior.
 *
 * Key differences from v0.0.4:
 *   - Namespace `ACROSSAI_MCP_MANAGER\MCP` → `AcrossAI_MCP_Manager\Includes\MCP`
 *   - Static `MCPServerTable::has_any_enabled()` → instance `MCPServerQuery::query()`
 *   - `add_action('init', ...)` in constructor → wired via Loader in Main.php (A1)
 *   - Singleton pattern with private constructor (A2 / S6 / B5 defense)
 *   - Exception catch broadened to `\Throwable` for PHPStan L8 friendliness
 *
 * State machine (`get_adapter_status()` return values):
 *   'unknown'   — initialize_adapter() not yet called
 *   'running'   — adapter initialised successfully
 *   'disabled'  — no enabled server rows in the DB
 *   'not-found' — \WP\MCP\Plugin class not available (adapter package absent)
 *   'error'     — exception thrown during adapter init
 *
 * @package AcrossAI_MCP_Manager\Includes\MCP
 * @since   0.0.1 (Feature-009 / 2026-07-01)
 */

namespace AcrossAI_MCP_Manager\Includes\MCP;

use AcrossAI_MCP_Manager\Includes\Abilities\ServerGuide;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\AbilityDiscovery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as MCPServerMetaQuery;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;
use WP\MCP\Transport\HttpTransport;

defined( 'ABSPATH' ) || exit;

final class Controller {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Adapter status — one of 'unknown' | 'running' | 'disabled' | 'not-found' | 'error'.
	 * Null until initialize_adapter() runs at least once.
	 *
	 * @var string|null
	 */
	private $adapter_status = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance() instead. Empty body per A1: hook wiring lives
	 * in `Includes\Main::define_admin_hooks()` via Loader, not here.
	 */
	private function __construct() {}

	/**
	 * Boot the MCP Adapter when at least one enabled server row exists.
	 *
	 * Called by the Loader on `rest_api_init`. Idempotent — safe to call
	 * multiple times per request (status is set once, downstream calls
	 * short-circuit on the non-null value).
	 *
	 * Registers `register_database_servers` as an `mcp_adapter_init` handler
	 * at priority 11 (immediately after DefaultServerFactory's priority 10)
	 * BEFORE calling `Plugin::instance()`, so our hook is in place when the
	 * adapter fires its own init chain.
	 */
	public function initialize_adapter(): void {
		if ( null !== $this->adapter_status ) {
			return;
		}

		if ( ! $this->has_any_enabled_server() ) {
			$this->adapter_status = 'disabled';
			return;
		}

		if ( ! class_exists( '\WP\MCP\Plugin' ) ) {
			$this->adapter_status = 'not-found';
			return;
		}

		try {
			add_action( 'mcp_adapter_init', array( $this, 'register_database_servers' ), 11 );

			\WP\MCP\Plugin::instance();
			$this->adapter_status = 'running';
		} catch ( \Throwable $e ) {
			$this->adapter_status = 'error';
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				do_action( 'acrossai_mcp_manager_adapter_init_error', $e );
			}
		}
	}

	/**
	 * Register enabled database-sourced MCP servers with the adapter.
	 *
	 * Hooked on `mcp_adapter_init` (priority 11 — after DefaultServerFactory).
	 * Each enabled row where `registered_from = 'database'` gets its own MCP
	 * server instance via `$adapter->create_server()`.
	 *
	 * @param \WP\MCP\Core\McpAdapter $adapter The MCP Adapter singleton
	 *                                         instance passed by the action.
	 */
	public function register_database_servers( $adapter ): void {
		$servers = $this->get_enabled_database_servers();

		if ( empty( $servers ) ) {
			return;
		}

		foreach ( $servers as $server ) {
			$slug = (string) $server->server_slug;

			if ( '' === $slug ) {
				continue;
			}

			$namespace = '' !== $server->server_route_namespace ? $server->server_route_namespace : 'mcp';
			$route     = '' !== $server->server_route ? $server->server_route : $slug;
			$version   = '' !== $server->server_version ? $server->server_version : 'v1.0.0';

			$tools     = ToolPolicy::compose_effective_tools_for_row( $server );
			$resources = AbilityDiscovery::for_server( (int) $server->id, AbilityDiscovery::TYPE_RESOURCE );
			$prompts   = AbilityDiscovery::for_server( (int) $server->id, AbilityDiscovery::TYPE_PROMPT );

			/**
			 * Filter the tools list a plugin-registered (database) MCP server exposes.
			 *
			 * Fired inside Controller::register_database_servers() per server,
			 * immediately before $adapter->create_server(). The initial list is
			 * the union of TWO sources:
			 *   1. The row's enabled tool_* columns (F025 — three vendor built-in slugs).
			 *   2. Ability slugs saved in wp_acrossai_mcp_server_tools (F020 — operator's
			 *      explicit picks from the Tools tab).
			 * Callbacks may add or remove any slug freely.
			 *
			 * NOT included in the pre-filter list: abilities where
			 * `meta.mcp.public = true` (or `is_exposed = 1` per-server override)
			 * are NOT advertised as tools directly. AI clients access them
			 * through the three built-in meta tools whose callbacks were swapped
			 * to plugin-owned versions (commit 070ffe2) that honor per-server
			 * visibility. Only the Tools tab (F020) controls tool advertisement;
			 * the Abilities tab (F017) controls ability visibility inside the
			 * three meta tools.
			 *
			 * NOT fired for the default server (server_slug =
			 * 'mcp-adapter-default-server'). Hook `mcp_adapter_default_server_config`
			 * for that path.
			 *
			 * @since 0.1.0 (Feature 025)
			 *
			 * @param string[] $tools  Ability slugs to register as MCP tools.
			 * @param \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server The server row being registered.
			 */
			$tools = apply_filters( 'acrossai_mcp_manager_server_tools', $tools, $server );

			/**
			 * Filter the resources list a plugin-registered (database) MCP server exposes.
			 *
			 * Pre-filter list is the F017-effective, resource-typed ability set for this
			 * server — every ability where ExposureResolver::resolve_effective() === true AND
			 * mcp.type === 'resource'. Companion plugins may add or remove any slug freely.
			 *
			 * NOT fired for the default server. Hook `mcp_adapter_default_server_config`
			 * (which sets `$config['resources']`) for that path.
			 *
			 * @since 0.1.0 (Feature 026)
			 *
			 * @param string[] $resources Ability slugs to register as MCP resources.
			 * @param \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server The server row being registered.
			 */
			$resources = apply_filters( 'acrossai_mcp_manager_server_resources', $resources, $server );

			/**
			 * Filter the prompts list a plugin-registered (database) MCP server exposes.
			 *
			 * Pre-filter list is the F017-effective, prompt-typed ability set for this
			 * server — every ability where ExposureResolver::resolve_effective() === true AND
			 * mcp.type === 'prompt'. Companion plugins may add or remove any slug freely.
			 *
			 * NOT fired for the default server. Hook `mcp_adapter_default_server_config`
			 * (which sets `$config['prompts']`) for that path.
			 *
			 * @since 0.1.0 (Feature 026)
			 *
			 * @param string[] $prompts Ability slugs to register as MCP prompts.
			 * @param \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row $server The server row being registered.
			 */
			$prompts = apply_filters( 'acrossai_mcp_manager_server_prompts', $prompts, $server );

			$tools     = array_values( array_unique( array_map( 'strval', (array) $tools ) ) );
			$resources = array_values( array_unique( array_map( 'strval', (array) $resources ) ) );
			$prompts   = array_values( array_unique( array_map( 'strval', (array) $prompts ) ) );

			$result = $adapter->create_server(
				$slug,
				$namespace,
				$route,
				$server->server_name,
				self::instructions_for( $server ),
				$version,
				array( HttpTransport::class ),
				ErrorLogMcpErrorHandler::class,
				NullMcpObservabilityHandler::class,
				$tools,
				$resources,
				$prompts
			);

			if ( is_wp_error( $result ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: 1: server slug, 2: error message, 3: error code */
							'AcrossAI MCP Manager: Failed to create database server "%1$s". Error: %2$s (Code: %3$s)',
							$slug,
							(string) $result->get_error_message(),
							(string) $result->get_error_code()
						)
					),
					'0.0.1'
				);
			}
		}
	}

	/**
	 * Callback for the vendor filter `mcp_adapter_default_server_config`.
	 *
	 * REPLACES three keys on the vendor-supplied config:
	 *   - `tools`     — F025 protocol columns + F020 curated + F026 F017-effective tools.
	 *                   Preserves the F025 empty-set fallback (returns vendor defaults when
	 *                   every source is empty — SEC-025-v2-1).
	 *   - `resources` — F026 F017-effective, resource-typed abilities. REPLACES unconditionally
	 *                   when the default row exists; empty result stays empty (no fallback).
	 *   - `prompts`   — F026 F017-effective, prompt-typed abilities. Same semantic as resources.
	 *
	 * Wired via Loader in `Includes\Main::define_admin_hooks()`. Called once
	 * per `mcp_adapter_init` firing.
	 *
	 * Defensive short-circuits (return input untouched):
	 *  - $config not an array,
	 *  - the default server row cannot be located by slug (unseeded install).
	 *
	 * Per-key guards: each of `tools` / `resources` / `prompts` is REPLACED only when the
	 * vendor set that key to an array. Missing keys pass through unmodified.
	 *
	 * Does NOT fire `acrossai_mcp_manager_server_tools` / `..._server_resources` /
	 * `..._server_prompts` — the vendor filter is the single extension seam for the
	 * default-server path (spec §FR-009).
	 *
	 * @since 0.1.0 (Feature 025)
	 * @since 0.1.0 (Feature 026 — resources + prompts + type filter)
	 *
	 * @param mixed $config The vendor-supplied config array.
	 * @return mixed The config with tools/resources/prompts replaced, or the input untouched.
	 */
	public function filter_default_server_config( $config ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}

		// SEC-025-v2-3: server_slug index is 'key' not 'unique' (F011 baseline);
		// MCPServerQuery::query returns first insertion-order match; safe within
		// manage_options trust boundary — see security-review v2.
		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => DefaultServerSeeder::SLUG,
				'number'      => 1,
			)
		);
		if ( empty( $rows ) ) {
			return $config;
		}

		$row       = $rows[0];
		$server_id = (int) $row->id;

		// F026: tools, resources and prompts ALL replace unconditionally (no
		// empty-set fallback). Rationale: the vendor's DefaultServerFactory sets
		// these via discover_abilities_by_type() with no F017 overlay. If an
		// operator disables a public ability via the Abilities tab (persists
		// is_exposed=0), we MUST remove it from the default server too —
		// otherwise the Abilities-tab control is a no-op for the default server.
		//
		// Tools carried an `if ( ! empty( $tools ) )` fallback until mcp-adapter
		// 0.6.1. That fallback kept the VENDOR list whenever our effective set
		// was empty, and adapter 0.6.0 widened the vendor list —
		// McpAbilityExposure::is_meta_public() now falls back to a bare
		// `meta.public` when `meta.mcp.public` is unset. The combination listed
		// abilities on a server the operator had switched fully off. Execution
		// stayed gated by AbilityExposureGate, so the exposure was name- and
		// schema-level only, but it inverted the operator's intent.
		//
		// Dropping the fallback is safe: the F025 protocol columns are
		// `tinyint(1) NOT NULL DEFAULT 1` (Table::upgrade_to_1_1_1), so MySQL
		// backfilled every pre-F025 row with 1. An empty effective set is only
		// ever a deliberate "expose nothing", never an un-migrated row.
		if ( isset( $config['tools'] ) && is_array( $config['tools'] ) ) {
			$config['tools'] = ToolPolicy::compose_effective_tools_for_row( $row );
		}

		if ( isset( $config['resources'] ) && is_array( $config['resources'] ) ) {
			$config['resources'] = AbilityDiscovery::for_server( $server_id, AbilityDiscovery::TYPE_RESOURCE );
		}

		if ( isset( $config['prompts'] ) && is_array( $config['prompts'] ) ) {
			$config['prompts'] = AbilityDiscovery::for_server( $server_id, AbilityDiscovery::TYPE_PROMPT );
		}

		return $config;
	}

	/**
	 * Return the current adapter status string. If `initialize_adapter()`
	 * hasn't run yet, run it lazily so callers always see the up-to-date
	 * state derived from the current DB contents.
	 */
	public function get_adapter_status(): string {
		if ( null === $this->adapter_status ) {
			$this->initialize_adapter();
		}

		return $this->adapter_status ?? 'unknown';
	}

	/**
	 * Fast-path check for "is at least one server row enabled". Uses the
	 * BerlinDB-style Query with `number => 1` so the SQL uses `LIMIT 1`
	 * rather than scanning the full table.
	 */
	private function has_any_enabled_server(): bool {
		$rows = MCPServerQuery::instance()->query(
			array(
				'is_enabled' => 1,
				'number'     => 1,
			)
		);
		return ! empty( $rows );
	}

	/**
	 * Return all enabled server rows whose `registered_from = 'database'`.
	 * Used by `register_database_servers` — plugin-registered rows are handled
	 * by the adapter's DefaultServerFactory on priority 10.
	 *
	 * @return array<int, \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row>
	 */
	private function get_enabled_database_servers(): array {
		return MCPServerQuery::instance()->query(
			array(
				'is_enabled'      => 1,
				'registered_from' => 'database',
			)
		);
	}

	/**
	 * Per-server meta key holding the operator's replacement connect message.
	 *
	 * ABSENT means "use the system default" — there is no stored copy of the
	 * default to drift from the code when the wording changes. Present means
	 * the operator wrote their own, and an empty string is a legitimate value:
	 * "send my description and nothing else".
	 *
	 * @since 0.3.6
	 * @var string
	 */
	public const INSTRUCTIONS_META_KEY = '_server_instructions';

	/**
	 * What a connecting client is told before it calls anything.
	 *
	 * The adapter passes a server's description straight through as the MCP
	 * `instructions` field (`InitializeHandler.php:81`), which is the ONLY thing
	 * an assistant reads without first deciding to call a tool. Everything else
	 * we publish — the guides, the tool descriptions — depends on it choosing
	 * to look. This is the one place we can be sure lands.
	 *
	 * Until now it carried the operator's one-line label from the servers list
	 * ("Recommended AcrossAI MCP server, managed by the plugin."), written for
	 * an admin screen and useless to an agent.
	 *
	 * The stored description is NOT changed — it is still what the admin shows,
	 * and still the first line here. Guidance is appended for the connection
	 * only.
	 *
	 * @since  0.1.0
	 * @param  Row $server The server row being registered.
	 * @return string
	 */
	private static function instructions_for( Row $server ): string {
		$description = trim( (string) $server->description );
		$guidance    = self::type_guidance( $server );

		// The operator's own wording, if they set one on the Overview tab.
		//
		// Applied AFTER the filter on purpose. A filter is a plugin stating what
		// its server type is for; this is a human overriding that for one
		// server, having read it. When the two disagree the human wins — they
		// can see the result and the plugin cannot.
		$override = self::instructions_override( (int) $server->id );

		if ( null !== $override ) {
			$guidance = $override;
		}

		if ( '' === trim( $guidance ) ) {
			return $description;
		}

		return '' === $description ? trim( $guidance ) : $description . "\n\n" . trim( $guidance );
	}

	/**
	 * The guidance a server type contributes, before any operator override.
	 *
	 * Split out of `instructions_for()` so the Overview tab can show an
	 * operator exactly what they are replacing. One implementation, so the box
	 * they edit cannot describe something other than what the server sends.
	 *
	 * @since  0.3.6
	 * @param  Row $server The server row.
	 * @return string
	 */
	private static function type_guidance( Row $server ): string {
		$type     = (string) $server->server_type;
		$guidance = '';

		if ( ServerTypes::LEGACY === $type ) {
			$guidance = sprintf(
				/* translators: %s: the server guide's ability name. */
				__( 'This server exposes WordPress abilities through three tools: discover-abilities to find them, get-ability-info to read one\'s parameters, and execute-ability to run it. Call %s first — it reports every category, namespace and group on this site with a count for each, so you can narrow on the first attempt instead of guessing a filter value.', 'acrossai-mcp-manager' ),
				ServerGuide::SLUG
			);
		} elseif ( ServerTypes::ACROSSAI === $type ) {
			// A DEFAULT, not a claim of ownership. The add-on still wins through
			// the filter below when it is active — but the type now ships here,
			// so a server of this type must be able to introduce itself before
			// the add-on arrives, or it connects saying nothing at all.
			//
			// The last sentence is the load-bearing one. A client fixes its tool
			// list at connect time and cannot refresh it, so a capability can
			// exist on this site with no tool of its own in the client's list.
			// Saying so is what stops an assistant concluding the site cannot do
			// something it can.
			$guidance = sprintf(
				/* translators: 1: the toolset guide's ability name, 2: the integrations toolset's ability name. */
				__( 'This server exposes Toolsets. Each one takes action=discover to list what it holds, action=info to read an ability\'s parameters, and action=execute to run it — the same three everywhere, so learn them once. Call %1$s first: it names every Toolset on this site with what it covers and how to reach it. Note that your tool list was fixed when you connected and cannot be refreshed, so a capability may exist here without a tool of its own in your list — %2$s reaches those.', 'acrossai-mcp-manager' ),
				'toolset/server-guide',
				'toolset/integrations'
			);
		}

		/**
		 * Filter the instructions a connecting MCP client receives.
		 *
		 * Fired per server, so guidance can be specific to what that server
		 * actually exposes. A companion plugin contributing a server type should
		 * hook this to describe ITS surface — this plugin names only its own
		 * tools, and never another plugin's vocabulary.
		 *
		 * The operator's description is already the first line of `$instructions`;
		 * append rather than replace, or their text is lost from the connection.
		 *
		 * @since 0.1.0
		 *
		 * @param string $instructions Instructions built so far.
		 * @param string $server_type  The server row's type slug.
		 * @param Row    $server       The server row being registered.
		 */
		$guidance = (string) apply_filters( 'acrossai_mcp_server_instructions', $guidance, $type, $server );

		return $guidance;
	}

	/**
	 * The default guidance for a server type, with nothing operator-specific.
	 *
	 * What the Overview tab shows an operator who chooses to write their own —
	 * it prefills the box with this, so "custom" starts from what the server
	 * says today rather than from an empty field.
	 *
	 * Runs the same filter the live path does, so a companion plugin's wording
	 * is what gets offered for editing, not a version only this plugin knows.
	 *
	 * @since  0.3.6
	 * @param  Row $server The server row.
	 * @return string
	 */
	public static function default_instructions_for( Row $server ): string {
		return trim( self::type_guidance( $server ) );
	}

	/**
	 * The operator's replacement guidance for one server, or null for none.
	 *
	 * Absence of the meta row IS "use the system default" — there is no stored
	 * "default" value to drift from the code. An empty string is a real choice
	 * and kept: it means "send my description and nothing else".
	 *
	 * @since  0.3.6
	 * @param  int $server_id Server row id.
	 * @return string|null
	 */
	private static function instructions_override( int $server_id ): ?string {
		if ( $server_id <= 0 ) {
			return null;
		}

		return MCPServerMetaQuery::get_meta( $server_id, self::INSTRUCTIONS_META_KEY );
	}
}
