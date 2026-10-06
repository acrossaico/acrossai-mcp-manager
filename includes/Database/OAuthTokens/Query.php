<?php
/**
 * BerlinDB Query for the OAuthTokens module (Feature 021).
 *
 * Bespoke methods that MUST be preserved:
 *   - find_by_hash()          — TokenValidator bearer lookup
 *   - revoke_by_hash()        — single-token revoke
 *   - revoke_by_user_id()     — FR-042 user-deletion cascade
 *   - revoke_by_client_id()   — FR-036 admin regenerate
 *   - revoke_by_family_id()   — SEC-021-001 RFC 9700 family revocation
 *   - delete_expired()        — daily cron cleanup
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthTokens
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthTokens;

defined( 'ABSPATH' ) || exit;

class Query extends \BerlinDB\Database\Kern\Query {

	/** @var string */
	protected $table_name = 'acrossai_mcp_oauth_tokens';

	/** @var string */
	protected $table_alias = 'oat';

	/** @var string */
	protected $table_schema = Schema::class;

	/** @var string */
	protected $item_name = 'oauth_token';

	/** @var string */
	protected $item_name_plural = 'oauth_tokens';

	/** @var string */
	protected $item_shape = Row::class;

	/** @var Query|null */
	protected static $instance = null;

	/**
	 * Private constructor enforces singleton pattern (A2/S6).
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
	 * TokenValidator lookup by SHA-256 hex hash.
	 *
	 * @param string $token_hash SHA-256 hex, char(64).
	 * @return Row|null
	 */
	public function find_by_hash( string $token_hash ): ?Row {
		if ( '' === $token_hash ) {
			return null;
		}

		$rows = $this->query(
			array(
				'token_hash' => $token_hash,
				'number'     => 1,
			)
		);

		return isset( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * Atomically flip revoked 0 → 1 for a single token by hash. Returns true
	 * iff the row transitioned this call (rows_affected === 1).
	 *
	 * @param string $token_hash SHA-256 hex.
	 * @return bool
	 */
	public function revoke_by_hash( string $token_hash ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE token_hash = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$token_hash
			)
		);

		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * Bulk revoke every non-revoked token for a user (FR-042 cascade).
	 *
	 * Returns caller-usable list of token ids so `token_revoked` can fire per
	 * row (F020 pattern — observers get per-row events, not silent bulk).
	 *
	 * @param int $user_id WordPress user id.
	 * @return array<int, int> List of token row ids that were revoked this call.
	 */
	public function revoke_by_user_id( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE user_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$user_id
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE user_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$user_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Bulk revoke every non-revoked token for a client on a specific server.
	 *
	 * F032 (T033) BREAKING — gains required `int $server_id` param. The
	 * pre-F032 1-arg shape would revoke tokens across every server that
	 * happens to share a `client_id` (impossible after F032's composite
	 * UNIQUE, but possible during migration mid-state) — see
	 * B-CROSS-SERVER-BYPASS-VIA-CLIENT-ID-ONLY.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $server_id MCP server row id (per-server scope).
	 * @return array<int, int> Revoked token row ids.
	 */
	public function revoke_by_client_id( string $client_id, int $server_id ): array {
		global $wpdb;

		if ( '' === $client_id || $server_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE client_id = %s AND server_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id,
				$server_id
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE client_id = %s AND server_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id,
				$server_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Server-neutral bulk revoke — every non-revoked token for a client_id
	 * across EVERY server on this site. Intentional carve-out from F032's
	 * per-server invariant (D31): operator-visible "Revoke from all servers"
	 * button uses this to nuke a client's presence everywhere at once.
	 *
	 * Returns the list of revoked row ids so the caller can fire per-row
	 * observability actions. Distinct signal from the per-server revoke path
	 * so operators can differentiate scoped vs global revoke in their logs.
	 *
	 * @param string $client_id Client identifier.
	 * @return array<int, int> Revoked token row ids.
	 */
	public function revoke_by_client_id_across_all_servers( string $client_id ): array {
		global $wpdb;

		if ( '' === $client_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE client_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE client_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Bulk revoke every non-revoked token for a user across a set of client_ids
	 * on a specific server. Used by the ConnectorApprovedUsers cascade — when
	 * an admin clicks "Revoke approval" for (server, connector, user), the
	 * approval-revoke listener enumerates all clients matching the connector
	 * profile and calls this method to nuke the user's active tokens for that
	 * exact (server × connector × user) intersection.
	 *
	 * Distinct from `revoke_by_client_id_across_all_servers` (site-wide by
	 * client_id) and from `revoke_by_user_id` (site-wide by user).
	 *
	 * @param int                $user_id    WP user id.
	 * @param int                $server_id  MCP server row id.
	 * @param array<int, string> $client_ids List of client_ids to filter by (typically
	 *                                       the admin + DCR clients matching the connector profile).
	 * @return array<int, int> Revoked token row ids.
	 */
	public function revoke_by_user_and_server_and_client_ids( int $user_id, int $server_id, array $client_ids ): array {
		if ( $user_id <= 0 || $server_id <= 0 || empty( $client_ids ) ) {
			return array();
		}

		$client_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $client_ids ),
					static fn( string $cid ): bool => '' !== $cid
				)
			)
		);

		$all_revoked = array();
		foreach ( $client_ids as $client_id ) {
			$revoked = $this->revoke_by_client_id_and_user_id( $client_id, $server_id, $user_id );
			if ( ! empty( $revoked ) ) {
				$all_revoked = array_merge( $all_revoked, $revoked );
			}
		}

		return $all_revoked;
	}

	/**
	 * Bulk revoke every non-revoked token for a specific (client_id, server_id, user_id)
	 * triple. Building block for `revoke_by_user_and_server_and_client_ids()` —
	 * loops over client_ids and calls this per iteration. Kept as its own public
	 * helper so any caller that already knows the exact triple can skip the loop.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $server_id MCP server row id.
	 * @param int    $user_id   WP user id.
	 * @return array<int, int> Revoked token row ids.
	 */
	public function revoke_by_client_id_and_user_id( string $client_id, int $server_id, int $user_id ): array {
		if ( '' === $client_id || $server_id <= 0 || $user_id <= 0 ) {
			return array();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE client_id = %s AND server_id = %d AND user_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id,
				$server_id,
				$user_id
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE client_id = %s AND server_id = %d AND user_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id,
				$server_id,
				$user_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * SEC-021-001 — Bulk revoke every non-revoked token in a family.
	 *
	 * Called by TokenController on refresh-reuse detection per RFC 9700 §2.2.2.
	 * Returns the list of revoked row ids so `token_revoked` can fire per row
	 * with the caller-supplied reason (typically 'family_reuse_detected').
	 *
	 * @param string $family_id UUIDv4 (char(36)).
	 * @return array<int, int> Revoked token row ids.
	 */
	public function revoke_by_family_id( string $family_id ): array {
		global $wpdb;

		// Guard against '' — an empty family_id would revoke every legacy row.
		if ( '' === $family_id || 36 !== strlen( $family_id ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE token_family_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$family_id
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE token_family_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$family_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Server-scoped counterpart of `revoke_by_family_id()` — bulk revoke every
	 * non-revoked token in a family on ONE server. Drives the per-grant admin
	 * "Revoke" action on the Connections panel, where a family that does not
	 * belong to the given server must revoke 0 rows (idempotent no-op, no
	 * cross-server oracle).
	 *
	 * `revoke_by_family_id()` stays untouched for TokenController's
	 * refresh-reuse detection, which is deliberately server-agnostic.
	 *
	 * @param string $family_id UUIDv4 (char(36)).
	 * @param int    $server_id MCP server row id.
	 * @return array<int, int> Revoked token row ids.
	 */
	public function revoke_by_family_id_and_server_id( string $family_id, int $server_id ): array {
		global $wpdb;

		// Guard against '' — an empty family_id would revoke every legacy row.
		if ( '' === $family_id || 36 !== strlen( $family_id ) || $server_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE token_family_id = %s AND server_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$family_id,
				$server_id
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked = 1 WHERE token_family_id = %s AND server_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$family_id,
				$server_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Count non-revoked tokens for a (client_id, server_id) pair, grouped by token_type.
	 *
	 * Returns the split of active access + refresh tokens so the Connections panel
	 * can render "2 (1 access · 1 refresh)" instead of an ambiguous total. Also
	 * closes a F032 read-side leak: the older `count_active_by_client_id` is
	 * server-agnostic and would over-count if the same client_id existed on
	 * multiple servers post-migration.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $server_id MCP server row id.
	 * @return array{access:int, refresh:int, total:int}
	 */
	public function count_active_by_client_id_and_server_id_grouped( string $client_id, int $server_id ): array {
		$empty = array(
			'access'  => 0,
			'refresh' => 0,
			'total'   => 0,
		);

		if ( '' === $client_id || $server_id <= 0 ) {
			return $empty;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT token_type, COUNT(*) AS n FROM %i WHERE client_id = %s AND server_id = %d AND revoked = 0 GROUP BY token_type',
				$wpdb->prefix . $this->table_name,
				$client_id,
				$server_id
			)
		);

		if ( ! is_array( $rows ) ) {
			return $empty;
		}

		$out = $empty;
		foreach ( $rows as $r ) {
			$type = (string) $r->token_type;
			$n    = (int) $r->n;
			if ( 'access' === $type ) {
				$out['access'] = $n;
			} elseif ( 'refresh' === $type ) {
				$out['refresh'] = $n;
			}
			$out['total'] += $n;
		}

		return $out;
	}

	/**
	 * Count non-revoked tokens for a client (F024 Connections panel).
	 *
	 * @param string $client_id Client identifier.
	 * @return int
	 */
	public function count_active_by_client_id( string $client_id ): int {
		global $wpdb;

		if ( '' === $client_id ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE client_id = %s AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id
			)
		);
	}

	/**
	 * List distinct user_ids with a non-revoked token for a (client, server)
	 * pair (F024 Connections panel — "Users" column).
	 *
	 * F032 (T033) BREAKING — renamed from `get_active_user_ids_by_client_id`
	 * + gains required `int $server_id` param. Closes the read-side
	 * "authorized users" cross-server display leak surfaced in the F032 audit
	 * where Server A's Connectors tab listed every user holding a token for
	 * the same client_id across every server.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $server_id MCP server row id (per-server scope).
	 * @return array<int, int>
	 */
	public function get_active_user_ids_by_client_id_and_server_id( string $client_id, int $server_id ): array {
		global $wpdb;

		if ( '' === $client_id || $server_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT user_id FROM %i WHERE client_id = %s AND server_id = %d AND revoked = 0',
				$wpdb->prefix . $this->table_name,
				$client_id,
				$server_id
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * List every non-revoked token for a server together with its owning
	 * client's metadata (name, redirect URIs, stored connector_slug). Drives
	 * Feature 010's per-(user × connector) Connections panel.
	 *
	 * Kept pure-SQL — no dependency on the Connectors namespace. Callers
	 * (AccessTokenRepository::group_by_user_and_connector_for_server and
	 * AccessTokenRepository::group_by_grant_for_server) do the grouping +
	 * DCR-slug fallback in PHP, because the fallback needs
	 * ConnectorProfileRegistry::matches_dcr_client() which can't be expressed
	 * in SQL.
	 *
	 * @param int $server_id MCP server row id.
	 * @return array<int, array{
	 *   user_id: int,
	 *   client_id: string,
	 *   token_type: string,
	 *   created_at: string,
	 *   token_family_id: string,
	 *   client_name: string,
	 *   redirect_uris: string,
	 *   connector_slug: string
	 * }>
	 */
	public function list_active_tokens_with_client_meta_for_server( int $server_id ): array {
		global $wpdb;

		if ( $server_id <= 0 ) {
			return array();
		}

		$tokens_table  = $wpdb->prefix . $this->table_name;
		$clients_table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.user_id, t.client_id, t.token_type, t.created_at, t.token_family_id,
					COALESCE(c.client_name, \'\')    AS client_name,
					COALESCE(c.redirect_uris, \'\')  AS redirect_uris,
					COALESCE(c.connector_slug, \'\') AS connector_slug
				FROM %i AS t
				LEFT JOIN %i AS c ON c.client_id = t.client_id
				WHERE t.server_id = %d AND t.revoked = 0',
				$tokens_table,
				$clients_table,
				$server_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Daily cron cleanup: delete rows that are (expired AND revoked) OR older
	 * than 30 days past their expiry (defensive: covers case where an admin
	 * unset revoked=1 to reactivate a token — the row still ages out).
	 *
	 * @param string $now Current GMT time in mysql format.
	 * @return int Rows deleted.
	 */
	public function delete_expired( string $now ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE ( expires_at < %s AND revoked = 1 ) OR expires_at < ( %s - INTERVAL 30 DAY )',
				$wpdb->prefix . $this->table_name,
				$now,
				$now
			)
		);

		return is_int( $result ) ? $result : 0;
	}
}
