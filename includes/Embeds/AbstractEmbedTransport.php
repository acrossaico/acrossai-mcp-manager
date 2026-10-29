<?php
/**
 * Abstract base class for per-server embed transport categories.
 *
 * Originally the storage + gate layer behind this plugin's own "Embeds" tab.
 * That tab and the frontend renderer moved to acrossai-pro, which reads and
 * writes THE SAME meta keys through its own copy of this subsystem — so the
 * two stay in agreement about what a server exposes.
 *
 * This copy is retained for one reason: `[acrossai_mcp_servers]` composes
 * `get_all_registered_transports()` and `is_enabled_for_server()`, and D40
 * forbids it reading the meta keys directly. Removing this directory means
 * dealing with that shortcode first. Every transport category is represented
 * at runtime as an `AbstractEmbedTransport` subclass with a stable
 * transport_key aligned 1:1 with F035 DTO `category` field values.
 *
 * Direct application of D35 / DEC-F034-SELF-CONTAINED-SUBSYSTEM-CONTRACT:
 * abstract base owns the enumeration + gate lookup + memoization; concrete
 * subclasses declare only identity + display metadata. Third-party plugins
 * extend via the `acrossai_mcp_embed_transports` filter and inherit all
 * shared logic. Every subclass MUST be `final class` per D36.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Embeds
 * @since      0.1.10
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Embeds;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as ServerMetaQuery;

defined( 'ABSPATH' ) || exit;

abstract class AbstractEmbedTransport {

	/**
	 * Built-in transport classes seeded into the enumeration filter.
	 *
	 * @var array<int, class-string>
	 */
	public const DEFAULT_TRANSPORT_CLASSES = array(
		NpmEmbedTransport::class,
		ClientEmbedTransport::class,
		AiConnectorEmbedTransport::class,
	);

	/**
	 * Meta key for the master toggle in `wp_acrossai_mcp_servers_meta`.
	 * Presence + value `'1'` = master ON; absence or any other value = OFF.
	 *
	 * @var string
	 */
	public const META_KEY_MASTER = '_embeds_enabled';

	/**
	 * Meta key for the per-DTO enable state in `wp_acrossai_mcp_servers_meta`.
	 * Single row per server whose meta_value is a JSON-encoded map:
	 *
	 *   {
	 *     "npm": 1,
	 *     "mcp-client": [ "claude-desktop", "vscode" ],
	 *     "connectors": [ "chatgpt", "grok" ]
	 *   }
	 *
	 * Presence semantics: category-key present + non-empty = at least one DTO
	 * in that category enabled; category-key absent OR value empty = category
	 * fully disabled. `npm` is a special-case integer (0/1) because the
	 * built-in NPM category has a single DTO; other categories store the
	 * enabled-slug list.
	 *
	 * @var string
	 */
	public const META_KEY_ITEMS = '_embeds_clients';

	/**
	 * R2 per-request memoization for `is_enabled_for_server()`. Keyed on
	 * `"{server_id}:{transport_key}:{dto_slug}"`. Reset via `flush_cache()`.
	 *
	 * @var array<string, bool>
	 */
	private static array $enabled_cache = array();

	/**
	 * Memoized map:
	 *   transport_key → [ 'storage_key' => string, 'is_single' => bool ]
	 * Built once per request from `get_all_registered_transports()` so
	 * static callers (public renderers, `is_enabled_for_server()`) can
	 * resolve per-transport specifics without an instance. Reset via
	 * `flush_cache()`.
	 *
	 * @var array<string, array{storage_key: string, is_single: bool}>|null
	 */
	private static ?array $meta_map = null;

	/**
	 * Stable machine identifier for this transport. MUST match
	 * `/\A[a-z0-9_-]{1,64}\z/` (underscore allowed to accommodate F035 DTO
	 * `category` field values like `ai_connector`). Built-in values:
	 * `'npm'`, `'client'`, `'ai_connector'` — align 1:1 with F035 DTO
	 * `category` field.
	 *
	 * @return string
	 */
	abstract public function get_transport_key(): string;

	/**
	 * Translated checkbox label for the Embeds admin UI, which acrossai-pro
	 * now owns.
	 *
	 * @return string
	 */
	abstract public function get_checkbox_label(): string;

	/**
	 * Display sort order in the Embeds admin UI. Lower runs earlier.
	 * Built-ins: 10 (npm), 20 (client), 30 (ai_connector). Third-party
	 * default 100.
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 100;
	}

	/**
	 * Optional translated description (unused by the current render).
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * JSON category-key used inside the `_embeds_clients` meta blob for
	 * this transport. Defaults to `get_transport_key()`. Override to pick
	 * a storage-facing alias (e.g. built-in `client` transport stores its
	 * enabled slugs under `mcp-client`; `ai_connector` under `connectors`).
	 *
	 * @return string
	 */
	public function get_storage_key(): string {
		return $this->get_transport_key();
	}

	/**
	 * Whether this transport's storage entry is a single scalar (0/1)
	 * rather than an array of enabled DTO slugs. Applies to transports
	 * that logically carry a single DTO — e.g. built-in NPM (npx bridge)
	 * only ever has one item. Default `false` — array-of-slugs shape.
	 *
	 * @return bool
	 */
	public function is_single_item(): bool {
		return false;
	}

	/**
	 * DTOs this transport gates. Callers iterate the returned list and
	 * check `is_enabled_for_server( server, transport_key, dto['slug'] )`.
	 * Default `[]` — companion-plugin transports with no known DTOs
	 * render as an empty category (harmless). Built-in transports
	 * override to delegate to `ConnectionMethodRegistry`.
	 *
	 * DTO shape (F035 contract): each item is an assoc array with at
	 * least `slug`, `name`, `icon`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_dtos(): array {
		return array();
	}

	/**
	 * Enumerate every registered transport. Direct application of D35 /
	 * DEC-F034-SELF-CONTAINED-SUBSYSTEM-CONTRACT. Mirrors
	 * `AbstractMCPClient::get_all_registered_clients()` line-for-line.
	 *
	 * Fires `acrossai_mcp_embed_transports` filter exactly once per call,
	 * seeded with `DEFAULT_TRANSPORT_CLASSES`. Silent-skips invalid FQNs
	 * (SEC-013-008), validates transport_key regex, dedups by key
	 * later-wins with `_doing_it_wrong` under `WP_DEBUG`, sorts by
	 * `(priority ASC, transport_key ASC)`. Priority comparator uses `(int)`
	 * coercion per SEC-037-002 — a companion plugin returning non-int from
	 * `get_priority()` MUST NOT fatal the admin render.
	 *
	 * @return array<int, self>
	 */
	public static function get_all_registered_transports(): array {
		/**
		 * Filter the registered embed transport class list.
		 *
		 * @param array<int, class-string> $fqns Seed with built-in transports.
		 */
		$contributed = (array) apply_filters( 'acrossai_mcp_embed_transports', self::DEFAULT_TRANSPORT_CLASSES );

		$seen = array();
		foreach ( $contributed as $fqn ) {
			if ( ! is_string( $fqn ) || ! class_exists( $fqn ) || ! is_subclass_of( $fqn, self::class ) ) {
				continue;
			}

			$instance = new $fqn();
			$key      = $instance->get_transport_key();

			// Regex includes underscore to accommodate F035 DTO `category`
			// field values (Q1 alignment) — `ai_connector` uses underscore.
			// Diverges from F034's hyphens-only regex by design.
			if ( ! is_string( $key ) || ! preg_match( '/\A[a-z0-9_-]{1,64}\z/', $key ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					_doing_it_wrong(
						'AbstractEmbedTransport::get_all_registered_transports',
						sprintf(
							/* translators: %s: FQN of the transport class with an invalid transport_key */
							esc_html__( 'Transport %s returned a transport_key not matching /[a-z0-9_-]{1,64}/ — discarded.', 'acrossai-mcp-manager' ),
							esc_html( $fqn )
						),
						'0.1.10'
					);
				}
				continue;
			}

			if ( isset( $seen[ $key ] ) && ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				_doing_it_wrong(
					'AbstractEmbedTransport::get_all_registered_transports',
					sprintf(
						/* translators: %s: duplicate transport_key */
						esc_html__( 'Duplicate embed transport key %s — later contribution wins.', 'acrossai-mcp-manager' ),
						esc_html( $key )
					),
					'0.1.10'
				);
			}

			$seen[ $key ] = $instance;
		}

		// SEC-037-002 — coerce `(int)` before comparison so a non-int
		// return from a companion plugin's get_priority() does NOT fatal
		// the admin render.
		$instances = array_values( $seen );
		usort(
			$instances,
			static function ( self $a, self $b ): int {
				$pa  = (int) $a->get_priority();
				$pb  = (int) $b->get_priority();
				$cmp = $pa <=> $pb;
				return 0 !== $cmp ? $cmp : strcmp( $a->get_transport_key(), $b->get_transport_key() );
			}
		);

		return $instances;
	}

	/**
	 * Two-check gate: (1) master toggle ON for the server, (2) the DTO
	 * `$dto_slug` appears in the `_embeds_clients` JSON blob under its
	 * category. Memoized per-request per R2.
	 *
	 * Per the per-DTO redesign, callers MUST identify BOTH the transport
	 * category (`transport_key`) and the specific DTO (`dto_slug`) —
	 * e.g. `is_enabled_for_server( 1, 'client', 'claude-desktop' )`.
	 * F035 DTO consumers pass `$dto['slug']` (NOT the parent array key).
	 *
	 * @param int    $server_id     Target server's primary key.
	 * @param string $transport_key Category machine identifier (e.g. 'client', 'ai_connector', 'npm').
	 * @param string $dto_slug      Specific F035 DTO slug (e.g. 'claude-desktop', 'chatgpt', 'npx-acrossai-mcp-manager').
	 * @return bool
	 */
	public static function is_enabled_for_server( int $server_id, string $transport_key, string $dto_slug ): bool {
		$cache_key = $server_id . ':' . $transport_key . ':' . $dto_slug;
		if ( isset( self::$enabled_cache[ $cache_key ] ) ) {
			return self::$enabled_cache[ $cache_key ];
		}

		// Gate 1 — master toggle read from meta table (`_embeds_enabled`).
		// Presence + value '1' = ON; absence or any other value = OFF.
		$master_raw = ServerMetaQuery::get_meta( $server_id, self::META_KEY_MASTER );
		if ( null === $master_raw || '1' !== (string) $master_raw ) {
			self::$enabled_cache[ $cache_key ] = false;
			return false;
		}

		// Gate 2 — look up the DTO inside the `_embeds_clients` JSON blob
		// under this transport's storage_key.
		$meta   = self::meta_for( $transport_key );
		$items  = self::get_items_for_server( $server_id );
		$entry  = $items[ $meta['storage_key'] ] ?? null;
		$result = self::entry_enables_slug( $entry, $dto_slug, $meta['is_single'] );

		self::$enabled_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Whether a decoded storage entry enables the given DTO slug. Handles
	 * the single-item (int 0/1) shape and the array-of-slugs shape
	 * uniformly for both the runtime gate + observability diffing.
	 *
	 * @param mixed  $entry     Decoded value from the items blob for this
	 *                          category (may be null, int, or array).
	 * @param string $dto_slug  DTO slug to check.
	 * @param bool   $is_single Whether the transport uses the int shape.
	 * @return bool
	 */
	public static function entry_enables_slug( $entry, string $dto_slug, bool $is_single ): bool {
		if ( $is_single ) {
			// Single-item transports (e.g. NPM) have one logical DTO per
			// category — the int shorthand covers every slug in the group.
			return null !== $entry && 1 === (int) $entry;
		}
		return is_array( $entry ) && in_array( $dto_slug, $entry, true );
	}

	/**
	 * Resolve a transport_key to its runtime metadata (storage_key +
	 * is_single flag). Built lazily from `get_all_registered_transports()`
	 * so companion-plugin transports are honoured without knowing them
	 * up-front. Falls back to `[storage_key => $key, is_single => false]`
	 * for unknown keys so a mid-request unregister does not fatal.
	 *
	 * @param string $transport_key Transport identifier.
	 * @return array{storage_key: string, is_single: bool}
	 */
	public static function meta_for( string $transport_key ): array {
		if ( null === self::$meta_map ) {
			$map = array();
			foreach ( self::get_all_registered_transports() as $t ) {
				$map[ $t->get_transport_key() ] = array(
					'storage_key' => $t->get_storage_key(),
					'is_single'   => $t->is_single_item(),
				);
			}
			self::$meta_map = $map;
		}
		return self::$meta_map[ $transport_key ] ?? array(
			'storage_key' => $transport_key,
			'is_single'   => false,
		);
	}

	/**
	 * Fetch + decode the `_embeds_clients` JSON blob for a server. Returns
	 * an empty array when the row is missing OR the payload fails to decode
	 * (defensive against manual DB edits + corruption).
	 *
	 * Shape:
	 *   [
	 *     'npm'        => 1,
	 *     'mcp-client' => [ 'claude-desktop', 'vscode', ... ],
	 *     'connectors' => [ 'chatgpt', 'grok', ... ],
	 *   ]
	 *
	 * @param int $server_id Server PK.
	 * @return array<string, mixed>
	 */
	public static function get_items_for_server( int $server_id ): array {
		$raw = ServerMetaQuery::get_meta( $server_id, self::META_KEY_ITEMS );
		if ( null === $raw || '' === (string) $raw ) {
			return array();
		}
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Persist the `_embeds_clients` JSON blob for a server. Deletes the
	 * meta row entirely when the array is empty (presence-model — absence
	 * = every category fully disabled).
	 *
	 * @param int                  $server_id Server PK.
	 * @param array<string, mixed> $items     Assembled JSON payload.
	 * @return void
	 */
	public static function save_items_for_server( int $server_id, array $items ): void {
		if ( empty( $items ) ) {
			ServerMetaQuery::delete_meta( $server_id, self::META_KEY_ITEMS );
			return;
		}
		$encoded = wp_json_encode( $items );
		if ( false === $encoded ) {
			return; // json_encode failed — refuse to persist garbage.
		}
		ServerMetaQuery::update_meta( $server_id, self::META_KEY_ITEMS, $encoded );
	}

	/**
	 * Garbage-collect category keys from every server's `_embeds_clients`
	 * blob whose transport_key is no longer registered (companion plugin
	 * uninstalled per Clarifications Q2). F037 itself NEVER invokes this —
	 * it's a supported opt-in surface for companion plugins' `uninstall.php`
	 * + future WP-CLI commands. Idempotent: second call returns 0.
	 *
	 * @return int Count of pruned category keys across all servers.
	 */
	public static function garbage_collect_orphans(): int {
		global $wpdb;

		$known_json_keys = array();
		foreach ( self::get_all_registered_transports() as $transport ) {
			$known_json_keys[] = $transport->get_storage_key();
		}
		if ( empty( $known_json_keys ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT meta_id, server_id, meta_value FROM %i WHERE meta_key = %s',
				$wpdb->prefix . 'acrossai_mcp_servers_meta',
				self::META_KEY_ITEMS
			)
		);
		if ( empty( $rows ) ) {
			return 0;
		}

		$pruned = 0;
		foreach ( $rows as $row ) {
			$decoded = json_decode( (string) $row->meta_value, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$kept    = array();
			$removed = 0;
			foreach ( $decoded as $json_key => $val ) {
				if ( in_array( $json_key, $known_json_keys, true ) ) {
					$kept[ $json_key ] = $val;
				} else {
					++$removed;
				}
			}
			if ( 0 === $removed ) {
				continue;
			}
			$pruned += $removed;
			self::save_items_for_server( (int) $row->server_id, $kept );
		}
		return $pruned;
	}

	/**
	 * Reset the R2 per-request memoization cache. Documented supported
	 * surface — production-shape name per B23 (never `_reset_for_tests`).
	 * Callers: PHPUnit `setUp()` for test isolation; companion plugins
	 * that mutate state mid-request.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$enabled_cache = array();
		self::$meta_map      = null;
	}

	/**
	 * Fire an action with per-listener isolation — a listener that throws
	 * MUST NOT abort subsequent listeners in the same hook. Full R3
	 * compliance (research.md R3: "fire AFTER the DB commit, INSIDE a
	 * try/catch **per-listener** — one broken listener MUST NOT block
	 * others OR roll back the DB write").
	 *
	 * WordPress's native `do_action()` iterates listeners and re-raises
	 * the first thrown exception, causing subsequent listeners to be
	 * silently skipped. This helper iterates `$wp_filter[$hook]->callbacks`
	 * manually and wraps each `call_user_func_array()` in try/catch, so
	 * one broken audit-log listener cannot mask a second listener's
	 * observation of the same event.
	 *
	 * Callers pass the same args they'd pass to `do_action()`; helper
	 * respects listener priority ordering. If no listeners are registered
	 * for the hook, this is a no-op (matches `do_action()`'s behavior).
	 *
	 * @param string $hook  Action hook name.
	 * @param mixed  ...$args Arguments to pass to each listener.
	 * @return void
	 */
	public static function fire_action_isolated( string $hook, ...$args ): void {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return;
		}
		$callbacks = $wp_filter[ $hook ]->callbacks;
		if ( empty( $callbacks ) ) {
			return;
		}
		// Priority-ordered iteration (matches do_action semantics).
		ksort( $callbacks, SORT_NUMERIC );
		foreach ( $callbacks as $callback_group ) {
			foreach ( $callback_group as $callback ) {
				if ( ! isset( $callback['function'] ) || ! is_callable( $callback['function'] ) ) {
					continue;
				}
				try {
					call_user_func_array( $callback['function'], $args );
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fail-forward per R3.
					error_log( sprintf( 'F037 %s listener threw: %s', $hook, $e->getMessage() ) );
				}
			}
		}
	}
}
