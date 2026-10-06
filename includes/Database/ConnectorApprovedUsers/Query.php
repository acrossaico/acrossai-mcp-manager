<?php
/**
 * F032 — BerlinDB Query for the ConnectorApprovedUsers module.
 *
 * Bespoke methods:
 *   - find_by_server_and_connector() — enumerate approved users for a (server, connector) pair
 *   - is_user_approved()             — enforcement gate check (single row EXISTS)
 *   - approve()                      — INSERT (idempotent via UNIQUE)
 *   - revoke()                       — DELETE by composite key
 *   - delete_by_user_id()            — site-wide cascade for UserLifecycle FR-042
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\ConnectorApprovedUsers
 * @since      0.1.6 (F032)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers;

use BerlinDB\Database\Kern\Query as BerlinDB_Query;

defined( 'ABSPATH' ) || exit;

class Query extends BerlinDB_Query {

	/** @var string */
	protected $table_name = 'acrossai_mcp_connector_approved_users';

	/** @var string */
	protected $table_alias = 'mcpau';

	/** @var string */
	protected $table_schema = Schema::class;

	/** @var string */
	protected $item_name = 'connector_approved_user';

	/** @var string */
	protected $item_name_plural = 'connector_approved_users';

	/** @var string */
	protected $item_shape = Row::class;

	/** @var Query|null */
	protected static $instance = null;

	/**
	 * Private constructor — enforces singleton pattern (A2/S6).
	 */
	private function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Visibility override enforces singleton per A2/S6.
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
	 * Enumerate every approved user for a (server, connector) pair. Used by
	 * the AI Connectors "Approved Users" panel rendering.
	 *
	 * @param int    $server_id      MCP server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @return array<int, Row>
	 */
	public function find_by_server_and_connector( int $server_id, string $connector_slug ): array {
		if ( $server_id <= 0 || '' === $connector_slug ) {
			return array();
		}

		$rows = $this->query(
			array(
				'server_id'      => $server_id,
				'connector_slug' => $connector_slug,
				'orderby'        => 'approved_at',
				'order'          => 'DESC',
				'number'         => 500,
			)
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * True iff the given user_id has an approval row for this (server, connector).
	 * Used by the OAuth authorize enforcement path.
	 *
	 * @param int    $server_id      MCP server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @param int    $user_id        WP user id.
	 * @return bool
	 */
	public function is_user_approved( int $server_id, string $connector_slug, int $user_id ): bool {
		if ( $server_id <= 0 || '' === $connector_slug || $user_id <= 0 ) {
			return false;
		}

		$rows = $this->query(
			array(
				'server_id'      => $server_id,
				'connector_slug' => $connector_slug,
				'user_id'        => $user_id,
				'number'         => 1,
				'fields'         => 'id',
			)
		);

		return ! empty( $rows );
	}

	/**
	 * Record an approval. Idempotent — the UNIQUE(server_id, connector_slug,
	 * user_id) constraint ensures at most one row per triple, and this method
	 * short-circuits when a row already exists (no wasted INSERT + duplicate-key
	 * error).
	 *
	 * @param int    $server_id      MCP server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @param int    $user_id        WP user id being approved.
	 * @param int    $approved_by    Admin's user id (get_current_user_id() at call site).
	 * @return bool True on success (new row inserted or already existed); false on invalid input.
	 */
	public function approve( int $server_id, string $connector_slug, int $user_id, int $approved_by ): bool {
		if ( $server_id <= 0 || '' === $connector_slug || $user_id <= 0 ) {
			return false;
		}

		if ( $this->is_user_approved( $server_id, $connector_slug, $user_id ) ) {
			return true;
		}

		$new_id = $this->add_item(
			array(
				'server_id'      => $server_id,
				'connector_slug' => $connector_slug,
				'user_id'        => $user_id,
				'approved_by'    => $approved_by,
			)
		);

		return (int) $new_id > 0;
	}

	/**
	 * Delete the approval row for a specific (server, connector, user) triple.
	 * No-op if the row does not exist.
	 *
	 * @param int    $server_id      MCP server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @param int    $user_id        WP user id to revoke.
	 * @return bool True iff a row was deleted.
	 */
	public function revoke( int $server_id, string $connector_slug, int $user_id ): bool {
		if ( $server_id <= 0 || '' === $connector_slug || $user_id <= 0 ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE server_id = %d AND connector_slug = %s AND user_id = %d',
				$wpdb->prefix . $this->table_name,
				$server_id,
				$connector_slug,
				$user_id
			)
		);

		return is_int( $deleted ) && $deleted > 0;
	}

	/**
	 * Server-wide sentinel slug for approvals that apply to any connector on
	 * a given MCP server. Used by the consolidated Approved Users panel
	 * (introduced when settings collapsed from per-connector to server-wide).
	 * `*` is chosen because it's outside the `[a-z0-9-]` slug validation regex
	 * so it cannot collide with a real connector profile slug.
	 */
	public const SERVER_WIDE_SLUG = '*';

	/**
	 * Enumerate every approval row for a server, regardless of connector slug.
	 * Used by the consolidated Approved Users panel. Rows with the
	 * SERVER_WIDE_SLUG sentinel and per-connector legacy rows both appear.
	 *
	 * @param int $server_id MCP server row id.
	 * @return array<int, Row>
	 */
	public function find_all_by_server( int $server_id ): array {
		if ( $server_id <= 0 ) {
			return array();
		}
		$rows = $this->query(
			array(
				'server_id' => $server_id,
				'orderby'   => 'approved_at',
				'order'     => 'DESC',
				'number'    => 500,
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * True iff the user has ANY approval row for this server (any slug —
	 * server-wide sentinel or legacy per-connector). Used by the
	 * authorization gate under the server-wide approval model.
	 *
	 * @param int $server_id MCP server row id.
	 * @param int $user_id   WP user id.
	 * @return bool
	 */
	public function is_user_approved_on_server( int $server_id, int $user_id ): bool {
		if ( $server_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		$rows = $this->query(
			array(
				'server_id' => $server_id,
				'user_id'   => $user_id,
				'number'    => 1,
				'fields'    => 'id',
			)
		);
		return ! empty( $rows );
	}

	/**
	 * Record a server-wide approval (slug = SERVER_WIDE_SLUG). Idempotent
	 * via the UNIQUE(server_id, connector_slug, user_id) constraint.
	 *
	 * @param int $server_id   MCP server row id.
	 * @param int $user_id     WP user id being approved.
	 * @param int $approved_by Admin's user id.
	 * @return bool True on success (new or existing row); false on invalid input.
	 */
	public function approve_server_wide( int $server_id, int $user_id, int $approved_by ): bool {
		return $this->approve( $server_id, self::SERVER_WIDE_SLUG, $user_id, $approved_by );
	}

	/**
	 * Delete every approval row for (server_id, user_id) regardless of slug.
	 * Server-wide revocation counterpart to `approve_server_wide`. Also
	 * cleans up any legacy per-connector rows for the same user.
	 *
	 * @param int $server_id MCP server row id.
	 * @param int $user_id   WP user id to revoke server-wide.
	 * @return int Rows deleted.
	 */
	public function revoke_server_wide( int $server_id, int $user_id ): int {
		if ( $server_id <= 0 || $user_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE server_id = %d AND user_id = %d',
				$wpdb->prefix . $this->table_name,
				$server_id,
				$user_id
			)
		);
		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * FR-042 site-wide cascade — delete every approval row for a WP user across
	 * every (server, connector) pair. Called from UserLifecycle::on_user_deleted.
	 * Site-wide by design: matches the shape of `TokensQuery::revoke_by_user_id`
	 * + `AuthCodesQuery::delete_by_user_id`.
	 *
	 * @param int $user_id WordPress user id being deleted.
	 * @return int Rows deleted.
	 */
	public function delete_by_user_id( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE user_id = %d',
				$wpdb->prefix . $this->table_name,
				$user_id
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}
}
