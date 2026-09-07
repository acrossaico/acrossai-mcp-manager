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

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\AbilityDiscovery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
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
				$server->description,
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

		if ( isset( $config['tools'] ) && is_array( $config['tools'] ) ) {
			$tools = ToolPolicy::compose_effective_tools_for_row( $row );
			if ( ! empty( $tools ) ) {
				$config['tools'] = $tools;
			}
		}

		// F026: resources and prompts REPLACE unconditionally (no empty-set fallback).
		// Rationale: the vendor's DefaultServerFactory sets these via
		// discover_abilities_by_type() to the mcp.public=true set with no F017 overlay.
		// If an operator disables a public resource/prompt via the Abilities tab
		// (persists is_exposed=0), we MUST remove it from the default server too —
		// otherwise the Abilities-tab control is a no-op for the default server.
		// Unlike tools (which have F025 protocol columns as a "starter set"), there
		// is no equivalent "keep the vendor defaults" fallback for resources/prompts.
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
}
