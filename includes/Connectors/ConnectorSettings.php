<?php
/**
 * Server-wide settings + pending-approval storage backed by mcp-manager's
 * per-server meta table (wp_acrossai_mcp_servers_meta).
 *
 * Two meta rows per server:
 *   meta_key = '_acrossai_mcp_server_settings' →
 *     JSON { enabled_slugs: string[], allow_other: bool, require_admin_approval: bool }
 *   meta_key = '_acrossai_mcp_pending_users'   →
 *     JSON int[] (user_ids awaiting admin approval)
 *
 * Meta rows are cascade-cleaned when the server row itself is deleted (per
 * MCPServerMeta::delete_by_server_id), so no bespoke uninstall hook is
 * needed.
 *
 * Legacy per-slug wp_options (`acrossai_mcp_connector_settings_*`,
 * `acrossai_mcp_connector_pending_approvals_*`) are still read on the
 * first migration so an upgrade is a runtime no-op; they are never
 * written by new code.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Connectors
 * @since 0.1.0 (F024)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Connectors;

use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Query as ConnectorApprovedUsersQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as MCPServerMetaQuery;

defined( 'ABSPATH' ) || exit;

final class ConnectorSettings {

	/**
	 * Meta key on `wp_acrossai_mcp_servers_meta` that holds the JSON blob
	 * `{ enabled_slugs: string[], allow_other: bool, require_admin_approval: bool }`.
	 * Underscore prefix matches mcp-manager's private-meta convention
	 * (mirrors F037's `_embeds_*` keys).
	 */
	private const META_SERVER_SETTINGS = '_acrossai_mcp_server_settings';

	/**
	 * Meta key holding a JSON array of user_ids awaiting server-wide
	 * admin approval.
	 */
	private const META_SERVER_PENDING = '_acrossai_mcp_pending_users';

	// ---------------------------------------------------------------------
	// Server-wide facade — introduced when the AI Connectors UI moved from
	// per-connector sub-tabs to a single top-level Settings/Connections/
	// Approved Users layout. Backed by mcp-manager's per-server meta table
	// (wp_acrossai_mcp_servers_meta) so rows are cascade-deleted with the
	// server row itself. Legacy per-slug wp_options are still read on first
	// migration for a runtime-no-op upgrade path.
	// ---------------------------------------------------------------------

	/**
	 * Read the server-wide settings record. Seeds from legacy per-slug
	 * wp_options on first read (soft migration — no data-migration script,
	 * per the cross-plugin migration style: read-path bridges old to new so
	 * an upgrade is a runtime no-op for existing installs).
	 *
	 * @param int $server_id MCP server row id.
	 * @return array{enabled_slugs: array<int, string>, allow_other: bool, require_admin_approval: bool}
	 */
	public static function get_server_settings( int $server_id ): array {
		$raw = MCPServerMetaQuery::get_meta( $server_id, self::META_SERVER_SETTINGS );
		if ( null !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return self::normalize_server_settings( $decoded );
			}
		}

		// First-read seed. Enumerate every registered profile; each is
		// enabled by default unless the legacy per-slug key explicitly
		// disabled it. `require_admin_approval` collapses to OR across the
		// legacy per-slug values so an admin who had approval on for one
		// connector keeps it enforced server-wide.
		$enabled_slugs = array();
		$require       = false;
		foreach ( ConnectorProfileRegistry::instance()->get_profiles() as $profile ) {
			$slug   = $profile->get_slug();
			$legacy = self::get( $server_id, $slug );
			if ( $legacy['enabled'] ) {
				$enabled_slugs[] = $slug;
			}
			if ( $legacy['require_admin_approval'] ) {
				$require = true;
			}
		}

		$seed = array(
			'enabled_slugs'          => $enabled_slugs,
			'allow_other'            => false,
			'require_admin_approval' => $require,
		);
		self::write_server_settings( $server_id, $seed );
		return $seed;
	}

	/**
	 * Persist the server-wide settings record.
	 *
	 * @param int                                                                                          $server_id MCP server row id.
	 * @param array{enabled_slugs?: array<int, string>, allow_other?: bool, require_admin_approval?: bool} $settings Fields to save (merged with current).
	 * @return void
	 */
	public static function save_server_settings( int $server_id, array $settings ): void {
		$current = self::get_server_settings( $server_id );
		$merged  = array(
			'enabled_slugs'          => isset( $settings['enabled_slugs'] )
				? array_values( array_unique( array_map( 'strval', $settings['enabled_slugs'] ) ) )
				: $current['enabled_slugs'],
			'allow_other'            => isset( $settings['allow_other'] )
				? (bool) $settings['allow_other']
				: $current['allow_other'],
			'require_admin_approval' => isset( $settings['require_admin_approval'] )
				? (bool) $settings['require_admin_approval']
				: $current['require_admin_approval'],
		);
		self::write_server_settings( $server_id, $merged );
	}

	/**
	 * Serialize + persist the canonical settings shape. Extracted so the
	 * seed and the save paths both go through one JSON encoder + meta
	 * writer.
	 *
	 * @param int                                                                                       $server_id Server PK.
	 * @param array{enabled_slugs: array<int, string>, allow_other: bool, require_admin_approval: bool} $settings  Normalized settings.
	 * @return void
	 */
	private static function write_server_settings( int $server_id, array $settings ): void {
		$json = wp_json_encode( $settings );
		if ( ! is_string( $json ) ) {
			return;
		}
		MCPServerMetaQuery::update_meta( $server_id, self::META_SERVER_SETTINGS, $json );
	}

	/**
	 * True iff the slug is enabled on this server. Empty slug and the
	 * "other" sentinel both check the `allow_other` toggle.
	 *
	 * @param int    $server_id MCP server row id.
	 * @param string $slug      Effective connector slug (see ConnectorSlugDisplay::bucket).
	 * @return bool
	 */
	public static function is_slug_enabled_on_server( int $server_id, string $slug ): bool {
		if ( $server_id <= 0 ) {
			return false;
		}
		$s = self::get_server_settings( $server_id );
		if ( '' === $slug || ConnectorSlugDisplay::OTHER_SLUG === $slug ) {
			return (bool) $s['allow_other'];
		}
		return in_array( $slug, $s['enabled_slugs'], true );
	}

	/**
	 * True iff the server-wide admin-approval gate is on.
	 *
	 * @param int $server_id MCP server row id.
	 * @return bool
	 */
	public static function is_admin_approval_required( int $server_id ): bool {
		return (bool) self::get_server_settings( $server_id )['require_admin_approval'];
	}

	/**
	 * Server-wide list of pending user ids.
	 *
	 * On first read, seeds from every registered profile's legacy per-slug
	 * `acrossai_mcp_connector_pending_approvals_{server_id}_{slug}` option
	 * so an admin who had pending users before the refactor sees them in
	 * the consolidated panel.
	 *
	 * @param int $server_id MCP server row id.
	 * @return array<int, int>
	 */
	public static function server_pending_user_ids( int $server_id ): array {
		$raw = MCPServerMetaQuery::get_meta( $server_id, self::META_SERVER_PENDING );
		if ( null !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map( 'intval', $decoded ), static fn( int $id ): bool => $id > 0 ) );
			}
		}

		$seed = array();
		foreach ( ConnectorProfileRegistry::instance()->get_profiles() as $profile ) {
			foreach ( self::pending_user_ids( $server_id, $profile->get_slug() ) as $uid ) {
				if ( ! in_array( $uid, $seed, true ) ) {
					$seed[] = $uid;
				}
			}
		}
		self::write_server_pending( $server_id, $seed );
		return $seed;
	}

	/**
	 * Add a user id to the server-wide pending list. No-op if already
	 * present or already approved server-wide.
	 *
	 * @param int $server_id MCP server row id.
	 * @param int $user_id   WP user id.
	 * @return void
	 */
	public static function add_server_pending_user( int $server_id, int $user_id ): void {
		if ( $server_id <= 0 || $user_id <= 0 ) {
			return;
		}
		if ( self::is_user_approved_on_server( $server_id, $user_id ) ) {
			return;
		}
		$list = self::server_pending_user_ids( $server_id );
		if ( in_array( $user_id, $list, true ) ) {
			return;
		}
		$list[] = $user_id;
		self::write_server_pending( $server_id, array_values( $list ) );
	}

	/**
	 * Remove a user id from the server-wide pending list.
	 *
	 * @param int $server_id MCP server row id.
	 * @param int $user_id   WP user id.
	 * @return void
	 */
	public static function remove_server_pending_user( int $server_id, int $user_id ): void {
		if ( $server_id <= 0 || $user_id <= 0 ) {
			return;
		}
		$list = self::server_pending_user_ids( $server_id );
		$next = array_values( array_filter( $list, static fn( int $id ): bool => $id !== $user_id ) );
		self::write_server_pending( $server_id, $next );
	}

	/**
	 * Persist the server-wide pending list as a JSON array on the meta row.
	 *
	 * @param int             $server_id Server PK.
	 * @param array<int, int> $ids       Distinct positive user ids.
	 * @return void
	 */
	private static function write_server_pending( int $server_id, array $ids ): void {
		$json = wp_json_encode( array_values( $ids ) );
		if ( ! is_string( $json ) ) {
			return;
		}
		MCPServerMetaQuery::update_meta( $server_id, self::META_SERVER_PENDING, $json );
	}

	/**
	 * True iff the user has ANY approval row for this server, regardless of
	 * slug. New approvals are inserted with slug='*'; legacy per-slug rows
	 * also grant server-wide approval (once approved for any connector on a
	 * server, the user is considered approved for the whole server under
	 * the new model).
	 *
	 * @param int $server_id MCP server row id.
	 * @param int $user_id   WP user id.
	 * @return bool
	 */
	public static function is_user_approved_on_server( int $server_id, int $user_id ): bool {
		if ( $server_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		return ConnectorApprovedUsersQuery::instance()->is_user_approved_on_server( $server_id, $user_id );
	}

	/**
	 * Record a server-wide approval (slug='*').
	 *
	 * @param int $server_id MCP server row id.
	 * @param int $user_id   WP user id being approved.
	 * @return void
	 */
	public static function add_server_approved_user( int $server_id, int $user_id ): void {
		if ( $server_id <= 0 || $user_id <= 0 ) {
			return;
		}
		ConnectorApprovedUsersQuery::instance()->approve_server_wide(
			$server_id,
			$user_id,
			(int) get_current_user_id()
		);
	}

	/**
	 * Normalize an on-disk server-settings array into the canonical shape.
	 *
	 * @param array<string, mixed> $raw Raw option value.
	 * @return array{enabled_slugs: array<int, string>, allow_other: bool, require_admin_approval: bool}
	 */
	private static function normalize_server_settings( array $raw ): array {
		$enabled_slugs = array();
		if ( isset( $raw['enabled_slugs'] ) && is_array( $raw['enabled_slugs'] ) ) {
			foreach ( $raw['enabled_slugs'] as $slug ) {
				if ( is_string( $slug ) && '' !== $slug ) {
					$enabled_slugs[] = $slug;
				}
			}
		}
		return array(
			'enabled_slugs'          => array_values( array_unique( $enabled_slugs ) ),
			'allow_other'            => isset( $raw['allow_other'] ) ? (bool) $raw['allow_other'] : false,
			'require_admin_approval' => isset( $raw['require_admin_approval'] ) ? (bool) $raw['require_admin_approval'] : false,
		);
	}

	// ---------------------------------------------------------------------
	// Legacy per-slug facade — retained for backward compat with existing
	// callers (AuthorizationController's legacy branch, tests, third parties
	// that hooked into ConnectorSettings directly).
	// ---------------------------------------------------------------------

	/**
	 * Read the settings row for (server_id, slug). Defaults on miss.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return array{enabled: bool, require_admin_approval: bool}
	 */
	public static function get( int $server_id, string $slug ): array {
		$raw = get_option( self::settings_key( $server_id, $slug ), null );
		if ( ! is_array( $raw ) ) {
			return array(
				'enabled'                => true,
				'require_admin_approval' => false,
			);
		}
		return array(
			'enabled'                => isset( $raw['enabled'] ) ? (bool) $raw['enabled'] : true,
			'require_admin_approval' => isset( $raw['require_admin_approval'] ) ? (bool) $raw['require_admin_approval'] : false,
		);
	}

	/**
	 * Save the settings row for (server_id, slug).
	 *
	 * @param int                                                  $server_id Server row id.
	 * @param string                                               $slug      Connector slug.
	 * @param array{enabled?: bool, require_admin_approval?: bool} $settings  Fields to save.
	 * @return void
	 */
	public static function save( int $server_id, string $slug, array $settings ): void {
		$current = self::get( $server_id, $slug );
		$merged  = array(
			'enabled'                => isset( $settings['enabled'] ) ? (bool) $settings['enabled'] : $current['enabled'],
			'require_admin_approval' => isset( $settings['require_admin_approval'] ) ? (bool) $settings['require_admin_approval'] : $current['require_admin_approval'],
		);
		update_option( self::settings_key( $server_id, $slug ), $merged, false );
	}

	/**
	 * Return true iff the connector is enabled on this server.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return bool
	 */
	public static function is_enabled( int $server_id, string $slug ): bool {
		return self::get( $server_id, $slug )['enabled'];
	}

	/**
	 * List of user_ids pre-approved to authorize this connector.
	 *
	 * Backed by the ConnectorApprovedUsers BerlinDB table since the introduction
	 * of the "Approved Users" panel. Signature preserved byte-for-byte for
	 * backward compatibility with all existing callers (AuthorizationController,
	 * ConnectorAdminController, AIConnectorsTab).
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return array<int, int>
	 */
	public static function approved_user_ids( int $server_id, string $slug ): array {
		$rows = ConnectorApprovedUsersQuery::instance()->find_by_server_and_connector( $server_id, $slug );
		return array_values( array_map( static fn( $r ): int => (int) $r->user_id, $rows ) );
	}

	/**
	 * Add a user_id to the pre-approved list. No-op if already present.
	 *
	 * Backed by the ConnectorApprovedUsers BerlinDB table. Signature preserved
	 * for backward compatibility. Records the current user_id as `approved_by`
	 * for audit purposes.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @param int    $user_id   User id to approve.
	 * @return void
	 */
	public static function add_approved_user( int $server_id, string $slug, int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		ConnectorApprovedUsersQuery::instance()->approve(
			$server_id,
			$slug,
			$user_id,
			(int) get_current_user_id()
		);
	}

	/**
	 * True iff the user is pre-approved for this connector on this server.
	 *
	 * Backed by the ConnectorApprovedUsers BerlinDB table. Signature preserved
	 * for backward compatibility.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @param int    $user_id   User id.
	 * @return bool
	 */
	public static function is_user_approved( int $server_id, string $slug, int $user_id ): bool {
		return ConnectorApprovedUsersQuery::instance()->is_user_approved( $server_id, $slug, $user_id );
	}

	/**
	 * List of user_ids awaiting admin approval for this connector.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return array<int, int>
	 */
	public static function pending_user_ids( int $server_id, string $slug ): array {
		$raw = get_option( self::pending_key( $server_id, $slug ), array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $raw ), static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * Add a user_id to the pending list. No-op if already present or already approved.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @param int    $user_id   User id.
	 * @return void
	 */
	public static function add_pending_user( int $server_id, string $slug, int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		if ( self::is_user_approved( $server_id, $slug, $user_id ) ) {
			return;
		}
		$list = self::pending_user_ids( $server_id, $slug );
		if ( in_array( $user_id, $list, true ) ) {
			return;
		}
		$list[] = $user_id;
		update_option( self::pending_key( $server_id, $slug ), array_values( $list ), false );
	}

	/**
	 * Remove a user_id from the pending list.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @param int    $user_id   User id.
	 * @return void
	 */
	public static function remove_pending_user( int $server_id, string $slug, int $user_id ): void {
		$list = self::pending_user_ids( $server_id, $slug );
		$next = array_values( array_filter( $list, static fn( int $id ): bool => $id !== $user_id ) );
		update_option( self::pending_key( $server_id, $slug ), $next, false );
	}

	/**
	 * Delete every wp_option row associated with this (server_id, slug).
	 * Called from the F024 disable-connector nuclear path.
	 *
	 * Note: the approved-users list migrated from wp_options to the
	 * ConnectorApprovedUsers BerlinDB table — the legacy `approved_key()`
	 * delete is retained as an idempotent cleanup for any leftover legacy
	 * option row on pre-migration installs (no-op if the option doesn't exist).
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return void
	 */
	public static function delete_all( int $server_id, string $slug ): void {
		delete_option( self::settings_key( $server_id, $slug ) );
		delete_option( self::pending_key( $server_id, $slug ) );
		// Legacy wp_options cleanup (pre-migration installs).
		delete_option( sprintf( 'acrossai_mcp_connector_approved_users_%d_%s', $server_id, sanitize_key( $slug ) ) );
	}

	/**
	 * Build the wp_options key for the settings row.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return string
	 */
	private static function settings_key( int $server_id, string $slug ): string {
		return sprintf( 'acrossai_mcp_connector_settings_%d_%s', $server_id, sanitize_key( $slug ) );
	}

	/**
	 * Build the wp_options key for the pending-approvals list.
	 *
	 * @param int    $server_id Server row id.
	 * @param string $slug      Connector slug.
	 * @return string
	 */
	private static function pending_key( int $server_id, string $slug ): string {
		return sprintf( 'acrossai_mcp_connector_pending_approvals_%d_%s', $server_id, sanitize_key( $slug ) );
	}
}
