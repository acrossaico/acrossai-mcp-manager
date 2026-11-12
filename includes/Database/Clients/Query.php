<?php
/**
 * Reads and writes for the Clients registry.
 *
 * Offset pagination, not the cursor machinery the activity log carries. That
 * exists because a log is large and fast-moving; this list is one row per
 * client per server — tens of rows on a busy site, not tens of thousands —
 * and a cursor would be weight without a reason.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\Clients
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\Clients;

use AcrossAI_MCP_Manager\Includes\Utilities\ClientsConstants;
use BerlinDB\Database\Kern\Query as BerlinDB_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Query service for acrossai_mcp_clients.
 *
 * @since 0.4.8
 */
class Query extends BerlinDB_Query {

	/**
	 * @var string
	 */
	protected $table_name = ClientsConstants::TABLE;

	/**
	 * @var string
	 */
	protected $table_alias = 'amc';

	/**
	 * @var string
	 */
	protected $table_schema = Schema::class;

	/**
	 * @var string
	 */
	protected $item_name = 'mcp_client';

	/**
	 * @var string
	 */
	protected $item_name_plural = 'mcp_clients';

	/**
	 * @var string
	 */
	protected $item_shape = Row::class;

	/**
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Private so the singleton is the only way in (A2/S6).
	 *
	 * @since 0.4.8
	 */
	private function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Visibility override (public → private) enforces the singleton pattern per A2/S6; PHPCS misses the visibility semantics.
		parent::__construct();
	}

	/**
	 * Shared instance.
	 *
	 * @since  0.4.8
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * One client by its identity hash on one server.
	 *
	 * @since  0.4.8
	 * @param  int    $server_id     Server row id.
	 * @param  string $identity_hash From {@see Identity::hash()}.
	 * @return Row|null
	 */
	public function find( int $server_id, string $identity_hash ): ?Row {
		$rows = $this->query(
			array(
				'server_id'     => $server_id,
				'identity_hash' => $identity_hash,
				'number'        => 1,
			)
		);

		$row = is_array( $rows ) ? reset( $rows ) : null;

		return $row instanceof Row ? $row : null;
	}

	/**
	 * Record that a client was seen.
	 *
	 * One statement, by design. A read-then-write would lose a count under
	 * two concurrent requests from the same client, which is exactly the
	 * client busy enough for the count to matter. `ON DUPLICATE KEY UPDATE`
	 * collides on the `server_identity` UNIQUE index and increments in place.
	 *
	 * `first_seen_at` is deliberately absent from the update list — it is
	 * written once, on insert, and must survive every later sighting.
	 *
	 * @since  0.4.8
	 * @param  array<string, mixed> $row Column values; `server_id` and
	 *                                   `identity_hash` are required.
	 * @return bool True when the row was written.
	 */
	public function touch( array $row ): bool {
		global $wpdb;

		$table = $this->get_table_name();

		if ( '' === $table || empty( $row['identity_hash'] ) ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		// `array_key_exists`, not `isset`: a present-but-null `last_seen_at` is
		// how a caller says "registered, never used", and `isset()` cannot tell
		// that apart from absent — it would stamp "now" over a deliberate null.
		$seen = array_key_exists( 'last_seen_at', $row ) ? $row['last_seen_at'] : $now;

		$values = array(
			'server_id'       => (int) ( $row['server_id'] ?? 0 ),
			'identity_hash'   => (string) $row['identity_hash'],
			'identity'        => (string) ( $row['identity'] ?? '' ),
			'connection_type' => (string) ( $row['connection_type'] ?? '' ),
			'route'           => (string) ( $row['route'] ?? '' ),
			'client_name'     => (string) ( $row['client_name'] ?? '' ),
			'client_version'  => (string) ( $row['client_version'] ?? '' ),
			'connector_slug'  => (string) ( $row['connector_slug'] ?? '' ),
			'client_id'       => (string) ( $row['client_id'] ?? '' ),
			'credential_id'   => (string) ( $row['credential_id'] ?? '' ),
			'credential_hint' => (string) ( $row['credential_hint'] ?? '' ),
			'user_id'         => (int) ( $row['user_id'] ?? 0 ),
			'last_ip'         => (string) ( $row['last_ip'] ?? '' ),
			'last_user_agent' => (string) ( $row['last_user_agent'] ?? '' ),
			'request_count'   => (int) ( $row['request_count'] ?? 1 ),
			'first_seen_at'   => (string) ( $row['first_seen_at'] ?? $now ),
		);

		// `$wpdb->prepare()` has no placeholder for NULL: `%s` renders a null as
		// the empty string, which a datetime column rejects outright under
		// strict mode — so the whole upsert would fail and the sighting be
		// lost. The one nullable column therefore takes a literal `NULL` in the
		// statement instead of a bound parameter. Never user input: it is one
		// of exactly two strings chosen here.
		if ( null === $seen ) {
			$seen_placeholder = 'NULL';
		} else {
			$seen_placeholder       = '%s';
			$values['last_seen_at'] = (string) $seen;
		}

		// Only overwrite an identifying field when this sighting actually
		// carried one. A later request without `clientInfo` must not blank a
		// name an earlier `initialize` established, and a "registered, never
		// used" row written after a real sighting must not blank its last-seen.
		$sql = "INSERT INTO `{$table}`
			(server_id, identity_hash, identity, connection_type, route, client_name,
			 client_version, connector_slug, client_id, credential_id, credential_hint,
			 user_id, last_ip, last_user_agent, request_count, first_seen_at, last_seen_at)
			VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %d, %s, {$seen_placeholder})
			ON DUPLICATE KEY UPDATE
				last_seen_at    = IF(VALUES(last_seen_at) IS NULL, last_seen_at, VALUES(last_seen_at)),
				request_count   = request_count + VALUES(request_count),
				route           = IF(VALUES(route) = '', route, VALUES(route)),
				client_name     = IF(VALUES(client_name) = '', client_name, VALUES(client_name)),
				client_version  = IF(VALUES(client_version) = '', client_version, VALUES(client_version)),
				connector_slug  = IF(VALUES(connector_slug) = '', connector_slug, VALUES(connector_slug)),
				client_id       = IF(VALUES(client_id) = '', client_id, VALUES(client_id)),
				credential_id   = IF(VALUES(credential_id) = '', credential_id, VALUES(credential_id)),
				credential_hint = IF(VALUES(credential_hint) = '', credential_hint, VALUES(credential_hint)),
				user_id         = IF(VALUES(user_id) = 0, user_id, VALUES(user_id)),
				last_ip         = IF(VALUES(last_ip) = '', last_ip, VALUES(last_ip)),
				last_user_agent = IF(VALUES(last_user_agent) = '', last_user_agent, VALUES(last_user_agent))";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( $wpdb->prepare( $sql, array_values( $values ) ) );

		if ( false !== $result ) {
			$this->flush_cache();
		}

		return false !== $result;
	}

	/**
	 * Mark a client's access as withdrawn, keeping its history.
	 *
	 * @since  0.4.8
	 * @param  int $id Row id.
	 * @return bool
	 */
	public function revoke( int $id ): bool {
		return (bool) $this->update_item( $id, array( 'revoked_at' => current_time( 'mysql', true ) ) );
	}

	/**
	 * Totals for the stat line.
	 *
	 * `COALESCE(last_seen_at, first_seen_at)` is how "idle" is measured —
	 * a client that registered and never called is idle from the moment it
	 * registered, not excluded for having no last-seen.
	 *
	 * @since  0.4.8
	 * @param  int $server_id Scope to one server, or 0 for every server.
	 * @param  int $idle_days Age beyond which a client counts as idle.
	 * @return array{total: int, active: int, never_used: int, revoked: int, idle: int}
	 */
	public function summary( int $server_id = 0, int $idle_days = 90 ): array {
		global $wpdb;

		$table = $this->get_table_name();

		if ( '' === $table ) {
			return array(
				'total'      => 0,
				'active'     => 0,
				'never_used' => 0,
				'revoked'    => 0,
				'idle'       => 0,
			);
		}

		$where = 'WHERE 1=1';

		// Bound in STATEMENT order, not in the order the values are decided.
		// The idle threshold's placeholder sits in the SELECT list and the
		// server filter's in the WHERE clause, so appending the threshold last
		// fed the server id to the INTERVAL and the day count to `server_id` —
		// which counted rows for server 90 and rendered no summary line at all
		// on every per-server tab. `prepare()` cannot catch this: both are %d
		// and both are integers.
		$params = array( max( 1, $idle_days ) );

		if ( $server_id > 0 ) {
			$where   .= ' AND server_id = %d';
			$params[] = $server_id;
		}

		$sql = "SELECT
				COUNT(*) AS total,
				SUM( CASE WHEN revoked_at IS NULL AND last_seen_at IS NOT NULL THEN 1 ELSE 0 END ) AS active,
				SUM( CASE WHEN revoked_at IS NULL AND last_seen_at IS NULL THEN 1 ELSE 0 END ) AS never_used,
				SUM( CASE WHEN revoked_at IS NOT NULL THEN 1 ELSE 0 END ) AS revoked,
				SUM( CASE WHEN revoked_at IS NULL
					AND COALESCE( last_seen_at, first_seen_at ) < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )
					THEN 1 ELSE 0 END ) AS idle
			FROM `{$table}` {$where}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );

		// Shaped key by key rather than returned raw: every caller is a
		// renderer, and a missing key there is a notice on an admin screen.
		return array(
			'total'      => isset( $found['total'] ) ? (int) $found['total'] : 0,
			'active'     => isset( $found['active'] ) ? (int) $found['active'] : 0,
			'never_used' => isset( $found['never_used'] ) ? (int) $found['never_used'] : 0,
			'revoked'    => isset( $found['revoked'] ) ? (int) $found['revoked'] : 0,
			'idle'       => isset( $found['idle'] ) ? (int) $found['idle'] : 0,
		);
	}

	/**
	 * Drop every row for a server, when the server itself is deleted.
	 *
	 * @since  0.4.8
	 * @param  int $server_id Server row id.
	 * @return int Rows removed.
	 */
	public function delete_items_for_server( int $server_id ): int {
		if ( $server_id <= 0 ) {
			return 0;
		}

		$removed = 0;

		$rows = (array) $this->query(
			array(
				'server_id' => $server_id,
				'number'    => 0,
			)
		);

		foreach ( $rows as $row ) {
			if ( $row instanceof Row && $this->delete_item( $row->id ) ) {
				++$removed;
			}
		}

		if ( $removed > 0 ) {
			$this->flush_cache();
		}

		return $removed;
	}

	/**
	 * Clear the object cache for this table after a raw write.
	 *
	 * BerlinDB invalidates its own cache on `add_item`/`update_item`, but
	 * {@see self::touch()} goes straight to `$wpdb` for the atomic upsert and
	 * therefore has to say so itself.
	 *
	 * @since  0.4.8
	 * @return void
	 */
	public function flush_cache(): void {
		// BerlinDB does not key its query cache by table — it stamps every
		// cached result with a `last_changed` value and discards the result
		// when that value moves. `add_item()`/`update_item()`/`delete_item()`
		// bump it themselves; `touch()` goes straight to `$wpdb` for the atomic
		// upsert, so it has to do the bump by hand.
		//
		// `update_last_changed_cache()` is PRIVATE upstream, so a subclass
		// cannot call it. This is the same write it performs, against the same
		// group (`$cache_group`, which resolves to '' — the default group — for
		// every Query in this plugin).
		//
		// Not a flush of the group: `$cache_group` is '', so flushing it would
		// clear WordPress's entire default cache group, not just this table.
		wp_cache_set( 'last_changed', microtime(), $this->cache_group );
	}
}
