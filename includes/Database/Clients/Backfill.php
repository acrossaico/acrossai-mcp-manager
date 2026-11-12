<?php
/**
 * Seeds the clients registry from what the site already knows (Feature 104).
 *
 * ## Why this is not an `upgrade_to_1_0_0()`
 *
 * It looks like one, and the plan called for one, but BerlinDB's `$upgrades`
 * map only runs on the way from an OLDER version to a newer one. On a fresh
 * install the table is created at 1.0.0 and the version is stamped directly —
 * so an `upgrade_to_1_0_0()` would never fire on the one install that needs it
 * most. A done-option-gated one-shot is the shape that actually runs, and it
 * is the shape {@see \AcrossAI_MCP_Manager\Includes\Database\OAuthDataMigration}
 * already uses for the same reason.
 *
 * ## Why two sources
 *
 * Measured on this site before any of it was written:
 *
 *  - `activity_log` has 564 request rows covering 16 distinct clients, but
 *    ZERO OAuth requests — so a traffic-only seed shows no connector clients
 *    at all.
 *  - `oauth_clients` holds Claude and ChatGPT, both registered and both with
 *    no tokens: set up, never used. They are precisely the pair an operator
 *    would look for first, and traffic cannot see them.
 *
 * So registered clients are seeded with a NULL `last_seen_at` and traffic is
 * folded in on top of them. `last_seen_at IS NULL` then reads as "registered,
 * never used" — the difference between set up wrong and not set up yet.
 *
 * History reaches back only as far as retention has left, which is the whole
 * argument for the table existing. An empty or disabled log seeds nothing and
 * the registry fills from the next request.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\Clients
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\Clients;

use AcrossAI_MCP_Manager\Includes\Observability\ConnectionContext;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot seed of the registry.
 *
 * @since 0.4.8
 */
final class Backfill {

	/**
	 * Set once the seed has run, successfully or with nothing to do.
	 *
	 * @since 0.4.8
	 * @var   string
	 */
	public const DONE_OPTION = 'acrossai_mcp_clients_backfill_done';

	/**
	 * Most groups read from either source.
	 *
	 * A ceiling, not a page size: this runs once, inside an admin request, and
	 * the grouped queries collapse hundreds of thousands of log rows into a few
	 * dozen groups. The cap exists so a pathological log cannot turn one admin
	 * page load into a timeout, and the registry simply fills from live traffic
	 * for whatever it did not reach.
	 *
	 * @since 0.4.8
	 * @var   int
	 */
	public const MAX_GROUPS = 500;

	/**
	 * Run the seed, once per site.
	 *
	 * Best-effort throughout: this runs on `admin_init`, and a seeding failure
	 * must not break wp-admin. The done-option is set either way — a seed that
	 * could not read its sources will not read them any better next time, and
	 * live traffic covers the gap from here on.
	 *
	 * @since  0.4.8
	 * @return int How many rows were written.
	 */
	public static function maybe_run(): int {
		if ( get_option( self::DONE_OPTION ) ) {
			return 0;
		}

		$written = 0;

		try {
			// Registered first, traffic second. The order is load-bearing: a
			// registered row carries a NULL last-seen, and `touch()` refuses to
			// overwrite a real timestamp with a null, so the reverse order
			// would still be correct — but this way round the "never used"
			// state is established before anything can contradict it, which is
			// easier to reason about than relying on that guard.
			$written += self::seed_registered_clients();
			$written += self::seed_from_activity_log();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Seeding runs on admin_init and must not break wp-admin.
			error_log( '[acrossai-mcp] Clients\\Backfill failed: ' . $e->getMessage() );
		}

		update_option( self::DONE_OPTION, 1, false );

		return $written;
	}

	/**
	 * Seed every registered OAuth client, used or not.
	 *
	 * @since  0.4.8
	 * @return int Rows written.
	 */
	private static function seed_registered_clients(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'acrossai_mcp_oauth_clients';

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		// Assembled before `prepare()` so the table name — built from
		// `$wpdb->prefix`, never from input — is interpolated outside the
		// placeholder machinery, which has no placeholder for an identifier.
		$sql = "SELECT client_id, server_id, client_name, connector_slug, created_at
			FROM `{$table}`
			ORDER BY id ASC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, self::MAX_GROUPS ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$written = 0;

		foreach ( $rows as $row ) {
			// A client row's `connector_slug` is what separates an AI connector
			// from a bare DCR client — the same distinction
			// `ConnectionContext::resolve_type()` makes on a live request, so
			// seeded rows and recorded rows agree on the type.
			$type = '' !== (string) ( $row['connector_slug'] ?? '' )
				? ConnectionContext::TYPE_AI_CONNECTOR
				: ConnectionContext::TYPE_OAUTH;

			$seeded = Recorder::record_registered(
				array(
					'server_id'       => (int) ( $row['server_id'] ?? 0 ),
					'connection_type' => $type,
					'client_id'       => (string) ( $row['client_id'] ?? '' ),
					'client_name'     => (string) ( $row['client_name'] ?? '' ),
					'connector_slug'  => (string) ( $row['connector_slug'] ?? '' ),
					'first_seen_at'   => (string) ( $row['created_at'] ?? '' ),
				)
			);

			if ( $seeded ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Fold in every client the activity log has actually seen.
	 *
	 * Grouped by the same facts {@see Identity} keys on, so the grouping the
	 * SQL does and the identity the recorder resolves cannot disagree. The
	 * user agent is in the GROUP BY because it is the only thing separating the
	 * callers whose `client_name` is empty — on this site, 94 such requests
	 * turned out to be five distinct clients.
	 *
	 * @since  0.4.8
	 * @return int Rows written.
	 */
	private static function seed_from_activity_log(): int {
		global $wpdb;

		$table = $wpdb->prefix . ActivityLogConstants::TABLE;

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		// Assembled before `prepare()`, for the reason given above.
		$sql = "SELECT server_id, connection_type, connector_slug, fleet_peer, client_name,
				client_version, user_id, user_agent, ip_address,
				COUNT(*) AS requests,
				MIN(created_at) AS first_seen,
				MAX(created_at) AS last_seen
			FROM `{$table}`
			WHERE event_type = %s AND server_id > 0
			GROUP BY server_id, connection_type, connector_slug, fleet_peer,
				client_name, client_version, user_id, user_agent, ip_address
			ORDER BY last_seen ASC
			LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$prepared = $wpdb->prepare( $sql, ActivityLogConstants::EVENT_REQUEST, self::MAX_GROUPS );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $prepared, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$written = 0;

		foreach ( $rows as $row ) {
			// The log carries neither a `client_id` nor a fleet host key — it
			// never did, which is why the registry carries both. So a seeded
			// OAuth caller identifies on its name or agent, a seeded fleet
			// caller on its peer LABEL, and the first live request from either
			// supplies the real key under a row of its own.
			//
			// Those rows do not merge, and that is the honest outcome: we
			// cannot prove the historical traffic was the same grant, or the
			// same host. On this site it shows up as one peer appearing twice
			// — `wordpress 7.0` and `wordpress-7-0.local`, the two spellings
			// that motivated keying fleet on the host key in the first place.
			// Both are real history; neither can be promoted to the other.
			$seeded = Recorder::record(
				array(
					'server_id'       => (int) ( $row['server_id'] ?? 0 ),
					'connection_type' => (string) ( $row['connection_type'] ?? '' ),
					'connector_slug'  => (string) ( $row['connector_slug'] ?? '' ),
					'fleet_host_key'  => (string) ( $row['fleet_peer'] ?? '' ),
					'client_name'     => (string) ( $row['client_name'] ?? '' ),
					'client_version'  => (string) ( $row['client_version'] ?? '' ),
					'user_id'         => (int) ( $row['user_id'] ?? 0 ),
					'user_agent'      => (string) ( $row['user_agent'] ?? '' ),
					'ip_address'      => (string) ( $row['ip_address'] ?? '' ),
					'request_count'   => (int) ( $row['requests'] ?? 0 ),
					'first_seen_at'   => (string) ( $row['first_seen'] ?? '' ),
					'last_seen_at'    => (string) ( $row['last_seen'] ?? '' ),
				)
			);

			if ( $seeded ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Does a source table exist?
	 *
	 * Both sources are optional: the activity log may never have been created
	 * on a site that upgraded straight past it, and a fresh install has no
	 * OAuth clients. Neither absence is an error.
	 *
	 * @since  0.4.8
	 * @param  string $table Fully-prefixed table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return is_string( $found ) && '' !== $found;
	}
}
