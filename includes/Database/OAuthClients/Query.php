<?php
/**
 * BerlinDB Query for the OAuthClients module (Feature 021).
 *
 * Bespoke methods that MUST be preserved:
 *   - find_by_id()         — client_id lookup for authorize/token flows
 *   - find_by_fingerprint() — DCR idempotent dedup (FR-022)
 *   - find_by_id_like()    — AIConnectorsTab existence check (Q2)
 *   - revoke_by_client_id() — client-scoped bulk revoke (fires via Repository)
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthClients
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthClients;

defined( 'ABSPATH' ) || exit;

class Query extends \BerlinDB\Database\Kern\Query {

	/** @var string */
	protected $table_name = 'acrossai_mcp_oauth_clients';

	/** @var string */
	protected $table_alias = 'oac';

	/** @var string */
	protected $table_schema = Schema::class;

	/** @var string */
	protected $item_name = 'oauth_client';

	/** @var string */
	protected $item_name_plural = 'oauth_clients';

	/** @var string */
	protected $item_shape = Row::class;

	/** @var Query|null */
	protected static $instance = null;

	/**
	 * F032 — per-request cache for find_by_client_id_and_server_id().
	 * Mirrors F017 ExposureResolver::resolve() shape. Key: "<server_id>:<client_id>".
	 *
	 * @var array<string, Row|null>
	 */
	private array $find_by_composite_cache = array();

	/**
	 * F032 (T049) — per-request cache for `server_id_column_exists()` INFORMATION_SCHEMA lookup.
	 *
	 * @var bool|null
	 */
	private ?bool $server_id_column_exists_cache = null;

	/**
	 * Private constructor enforces singleton (A2/S6).
	 */
	private function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- visibility override enforces singleton.
		parent::__construct();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Look up a single client by its `client_id` value.
	 *
	 * @param string $client_id Opaque client identifier.
	 * @return Row|null
	 * @deprecated F032 — DO NOT use for authorization/mutation decisions.
	 *   This method is cross-server-unaware and will return the first matching row
	 *   regardless of server_id. Callers on the mutation path MUST use
	 *   find_by_client_id_and_server_id() so cross-server bypass is prevented.
	 *   Retained for legacy read paths that have their own server-scoping upstream.
	 */
	public function find_by_id( string $client_id ): ?Row {
		if ( '' === $client_id ) {
			return null;
		}

		$rows = $this->query(
			array(
				'client_id' => $client_id,
				'number'    => 1,
			)
		);

		return isset( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * F032 (FR-016 / SC-001) — cross-server-safe composite lookup.
	 *
	 * Look up a single client by the composite (client_id, server_id) key. This is the
	 * canonical helper for every mutating REST endpoint that receives both values from
	 * the request body: a null return signals cross-server mismatch (or missing client)
	 * and callers MUST return a 403 acrossai_mcp_oauth_cross_server response paired
	 * with an acrossai_mcp_oauth_cross_server_attempted observability action fire.
	 *
	 * Per-request cache mirrors F017 ExposureResolver::resolve() shape — repeated
	 * lookups within the same request (e.g. authorization check + delete) share one query.
	 *
	 * @param string $client_id Opaque client identifier as submitted in the REST body.
	 * @param int    $server_id MCP server row id as submitted in the REST body.
	 * @return Row|null Row iff the (client_id, server_id) pair matches an existing row.
	 */
	public function find_by_client_id_and_server_id( string $client_id, int $server_id ): ?Row {
		if ( '' === $client_id || $server_id <= 0 ) {
			return null;
		}

		$cache_key = $server_id . ':' . $client_id;
		if ( array_key_exists( $cache_key, $this->find_by_composite_cache ) ) {
			return $this->find_by_composite_cache[ $cache_key ];
		}

		$rows = $this->query(
			array(
				'client_id' => $client_id,
				'server_id' => $server_id,
				'number'    => 1,
			)
		);

		$row = isset( $rows[0] ) ? $rows[0] : null;

		$this->find_by_composite_cache[ $cache_key ] = $row;

		return $row;
	}

	/**
	 * Clear the per-request cache. Called from tests and (defensively) after any
	 * INSERT/UPDATE/DELETE that could invalidate cached rows.
	 *
	 * @internal
	 */
	public function clear_request_cache(): void {
		$this->find_by_composite_cache = array();
	}

	/**
	 * F032 (T049 / SEC-032-005 remediation) — Per-request cached INFORMATION_SCHEMA
	 * lookup for the `oauth_clients.server_id` column existence.
	 *
	 * Used by `ClientRegistrationController::handle_register` +
	 * `handle_admin_generate` as the FR-028 race guard: if the plugin was just
	 * upgraded and `Main::reconcile_database_schemas()` has not yet fired, the
	 * column is absent — refusing to INSERT prevents silent destruction by the
	 * auto-purge step on the subsequent admin request.
	 *
	 * Lives on Query (not Controller) per T118c layering — Controllers MUST NOT
	 * touch `$wpdb` directly.
	 *
	 * @since 0.1.6 (F032)
	 * @return bool True iff the `server_id` column exists on `oauth_clients`.
	 */
	public function server_id_column_exists(): bool {
		if ( null !== $this->server_id_column_exists_cache ) {
			return $this->server_id_column_exists_cache;
		}

		global $wpdb;
		$table = $wpdb->prefix . $this->table_name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$col = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'server_id'",
				DB_NAME,
				$table
			)
		);

		$this->server_id_column_exists_cache = ! empty( $col );
		return $this->server_id_column_exists_cache;
	}

	/**
	 * FR-022 — DCR idempotent dedup lookup by canonical metadata fingerprint.
	 *
	 * Scoped by `server_id` when one is supplied. Every MCP host registers with
	 * the same canonical metadata (Claude.ai always sends the same single
	 * redirect_uri), so a fingerprint-only lookup returned the client row from
	 * whichever server was connected FIRST. Connecting a second MCP server then
	 * reused that row — permanently bound to server #1 — and every token minted
	 * for it was audience-bound to the wrong route, so TokenValidator rejected
	 * it at the second server and the connector never finished authenticating.
	 *
	 * @param string $fingerprint SHA-256 hex.
	 * @param int    $server_id   MCP server row id, or 0 to search across servers.
	 * @return Row|null
	 */
	public function find_by_fingerprint( string $fingerprint, int $server_id = 0 ): ?Row {
		if ( '' === $fingerprint ) {
			return null;
		}

		$args = array(
			'metadata_fingerprint' => $fingerprint,
			'number'               => 1,
		);

		if ( $server_id > 0 && $this->server_id_column_exists() ) {
			$args['server_id'] = $server_id;
		}

		$rows = $this->query( $args );

		return isset( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * F024 — List every client whose client_id is prefixed for this
	 * (server, connector) pair — includes admin-generated clients only.
	 * DCR-registered clients (connector_slug = '') are matched separately
	 * via AbstractConnectorProfile::matches_dcr_client in the caller.
	 *
	 * @param int    $server_id      MCP server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @return array<int, Row>
	 */
	public function find_admin_clients_for_server_connector( int $server_id, string $connector_slug ): array {
		global $wpdb;

		if ( $server_id <= 0 || '' === $connector_slug ) {
			return array();
		}

		$prefix = 'server-' . $server_id . '-' . $connector_slug . '-';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE connector_slug = %s AND client_id LIKE %s ORDER BY id DESC',
				$wpdb->prefix . $this->table_name,
				$connector_slug,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( $r ): Row => new Row( $r ), $rows );
	}

	/**
	 * List every admin-generated client on a server, regardless of connector
	 * slug. Prefix-scoped (`server-{server_id}-%`) mirror of
	 * `find_admin_clients_for_server_connector` for the consolidated
	 * Connections panel (server-wide view, all connectors).
	 *
	 * @param int $server_id MCP server row id.
	 * @return array<int, Row>
	 */
	public function find_admin_clients_for_server( int $server_id ): array {
		global $wpdb;

		if ( $server_id <= 0 ) {
			return array();
		}

		$prefix = 'server-' . $server_id . '-';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE server_id = %d AND client_id LIKE %s ORDER BY id DESC',
				$wpdb->prefix . $this->table_name,
				$server_id,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( $r ): Row => new Row( $r ), $rows );
	}

	/**
	 * List every DCR-registered client — i.e., any client whose `client_id` does NOT
	 * carry the admin-generated `server-{id}-` prefix. Caller filters by profile via
	 * `matches_dcr_client`.
	 *
	 * F029 attribution note: DCR clients can carry a non-empty `connector_slug` if
	 * `handle_register` matched them to a registered profile (Claude etc.). The
	 * original filter `WHERE connector_slug = ''` excluded these attributed rows
	 * from the Connections panel — bug fix 2026-07-21: filter by
	 * `client_id NOT LIKE 'server-%'` instead, which is what "DCR" semantically means.
	 *
	 * F032 (T010) — gains optional `$server_id` filter for per-server enumeration.
	 *
	 * @param int $server_id Optional MCP server row id (0 = all servers).
	 * @return array<int, Row>
	 */
	public function find_dcr_clients( int $server_id = 0 ): array {
		global $wpdb;

		if ( $server_id > 0 ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- The LIKE pattern is a hardcoded literal prefix ('server-'), never user input; esc_like() would escape the intended wildcard. Block-scoped: the pattern sits inside a multi-line prepare() call.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE client_id NOT LIKE 'server-%%' AND server_id = %d ORDER BY id DESC",
					$wpdb->prefix . $this->table_name,
					$server_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- The LIKE pattern is a hardcoded literal prefix ('server-'), never user input; esc_like() would escape the intended wildcard. Block-scoped: the pattern sits inside a multi-line prepare() call.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE client_id NOT LIKE 'server-%%' ORDER BY id DESC",
					$wpdb->prefix . $this->table_name
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( static fn( $r ): Row => new Row( $r ), $rows );
	}

	/**
	 * F024 — Delete a client row by id. Caller is responsible for having
	 * revoked its tokens first.
	 *
	 * @param int $client_pk_id Client row primary key id.
	 * @return bool True iff a row was deleted.
	 */
	public function delete_by_id( int $client_pk_id ): bool {
		global $wpdb;

		if ( $client_pk_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$wpdb->prefix . $this->table_name,
			array( 'id' => $client_pk_id ),
			array( '%d' )
		);

		return 1 === (int) $result;
	}

	/**
	 * Q2 — Look up an admin-generated client by structured prefix.
	 *
	 * Used by AIConnectorsTab to check whether a `(server_id, connector_slug)`
	 * pair already has an issued client. Prefix format is
	 * `server-{server_id}-{connector_slug}-`; the KEY(connector_slug) index
	 * makes this O(k) where k = number of clients for the connector.
	 *
	 * @param int    $server_id      MCP server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @return Row|null
	 */
	public function find_admin_client( int $server_id, string $connector_slug ): ?Row {
		global $wpdb;

		if ( $server_id <= 0 || '' === $connector_slug ) {
			return null;
		}

		$prefix = 'server-' . $server_id . '-' . $connector_slug . '-';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE connector_slug = %s AND client_id LIKE %s ORDER BY id DESC LIMIT 1',
				$wpdb->prefix . $this->table_name,
				$connector_slug,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return $row ? new Row( $row ) : null;
	}
}
