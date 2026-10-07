<?php
/**
 * AccessTokenRepository — issuance surface for OAuth access tokens.
 *
 * SEC-021-001: caller MUST supply `token_family_id` (either fresh UUIDv4 on
 * initial code→token exchange, or the parent refresh's family_id on rotation).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth\Repositories
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth\Repositories;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSlugDisplay;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as TokensQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Row as TokenRow;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

defined( 'ABSPATH' ) || exit;

final class AccessTokenRepository {

	private const TTL_SECONDS = 3600;

	/**
	 * Hard cap on admin-issued token TTL (90 days). Enforced at the repository
	 * layer as defense-in-depth beyond any caller's args validation, so a
	 * bypass of that validation cannot mint a longer-lived token.
	 *
	 * The only admin-issued flow today is the acrossai-pro companion's n8n
	 * tab, whose controller does NOT live here — so this cap is the last line
	 * of defence rather than a second one, and must not be relaxed because
	 * "the controller already checks". That controller is in another plugin.
	 */
	public const MAX_ADMIN_TTL_SECONDS = 7776000;

	/**
	 * Issue a fresh access token. Hashes at boundary.
	 *
	 * Shape of $data:
	 *   client_id (string, required)
	 *   server_id (int, required — F032 T041)
	 *   user_id (int, required)
	 *   scope (string, optional — default 'mcp')
	 *   resource (string, optional)
	 *   token_family_id (string, required — UUIDv4 char(36))
	 *   ttl_seconds (int, optional — Feature 013). When present + positive int,
	 *     clamped to MAX_ADMIN_TTL_SECONDS and used as the TTL. When absent or
	 *     non-positive, falls back to TTL_SECONDS (3600s). Existing callers
	 *     that omit the key see identical behavior.
	 *
	 * @param array<string, mixed> $data Access token parameters.
	 * @return array{raw: string, id: int, family_id: string, expires_at: string}
	 */
	public static function issue( array $data ): array {
		$raw = SecretsVault::random_token();

		$ttl_override = isset( $data['ttl_seconds'] ) ? (int) $data['ttl_seconds'] : 0;
		$ttl          = $ttl_override > 0
			? min( $ttl_override, self::MAX_ADMIN_TTL_SECONDS )
			: self::TTL_SECONDS;
		$expires_at   = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		$id = TokensQuery::instance()->add_item(
			array(
				'token_hash'      => SecretsVault::hash( $raw ),
				'token_type'      => 'access',
				'client_id'       => (string) $data['client_id'],
				// F032 (T041) — required server binding. Post-migration NOT NULL invariant.
				'server_id'       => (int) ( $data['server_id'] ?? 0 ),
				'user_id'         => (int) $data['user_id'],
				'scope'           => isset( $data['scope'] ) && '' !== $data['scope'] ? (string) $data['scope'] : 'mcp',
				'resource'        => isset( $data['resource'] ) ? (string) $data['resource'] : '',
				'expires_at'      => $expires_at,
				'revoked'         => 0,
				'token_family_id' => (string) $data['token_family_id'],
			)
		);

		return array(
			'raw'        => $raw,
			'id'         => is_int( $id ) ? $id : (int) $id,
			'family_id'  => (string) $data['token_family_id'],
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Look up a token row by SHA-256 hex hash.
	 *
	 * @param string $token_hash
	 * @return TokenRow|null
	 */
	public static function find_by_hash( string $token_hash ): ?TokenRow {
		return TokensQuery::instance()->find_by_hash( $token_hash );
	}

	/**
	 * Access-token TTL in seconds (3600s = 1h).
	 *
	 * @return int
	 */
	public static function ttl(): int {
		return self::TTL_SECONDS;
	}

	/**
	 * Count non-revoked tokens for a client (F024 Connections panel).
	 *
	 * @param string $client_id Client identifier.
	 * @return int
	 */
	public static function count_active_by_client_id( string $client_id ): int {
		return TokensQuery::instance()->count_active_by_client_id( $client_id );
	}

	/**
	 * Count non-revoked tokens for a (client, server) pair, grouped by token_type.
	 *
	 * Returns `array{access:int, refresh:int, total:int}` — enables the Connections
	 * panel to render the "2 (1 access · 1 refresh)" annotated total.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $server_id MCP server row id.
	 * @return array{access:int, refresh:int, total:int}
	 */
	public static function count_active_by_client_id_and_server_id_grouped( string $client_id, int $server_id ): array {
		return TokensQuery::instance()->count_active_by_client_id_and_server_id_grouped( $client_id, $server_id );
	}

	/**
	 * Distinct user_ids holding a non-revoked token for a (client, server) pair
	 * (F024 Connections panel).
	 *
	 * F032 (T042) BREAKING — renamed from `get_active_user_ids_by_client_id`
	 * + gains required `int $server_id`. Closes cross-server read leak.
	 *
	 * @param string $client_id Client identifier.
	 * @param int    $server_id MCP server row id.
	 * @return array<int, int>
	 */
	public static function get_active_user_ids_by_client_id_and_server_id( string $client_id, int $server_id ): array {
		return TokensQuery::instance()->get_active_user_ids_by_client_id_and_server_id( $client_id, $server_id );
	}

	/**
	 * Aggregate every active (user × connector) authorization on a server —
	 * one entry per pairing, sorted by most recent `created_at` DESC. Powers
	 * the Feature 010 Connections panel.
	 *
	 * Grouping is done in PHP (not SQL) because DCR rows with an empty stored
	 * `connector_slug` need `AbstractConnectorProfile::matches_dcr_client()`
	 * inference — a PHP-only path via the ConnectorProfileRegistry. Rows that
	 * still don't resolve fall back to `ConnectorSlugDisplay::OTHER_SLUG`.
	 *
	 * @param int $server_id MCP server row id.
	 * @return array<int, array{
	 *   user_id: int,
	 *   connector_slug: string,
	 *   client_ids: array<int, string>,
	 *   token_count: int,
	 *   access_count: int,
	 *   refresh_count: int,
	 *   latest_created_at: string
	 * }>
	 */
	public static function group_by_user_and_connector_for_server( int $server_id ): array {
		$rows = TokensQuery::instance()->list_active_tokens_with_client_meta_for_server( $server_id );
		if ( empty( $rows ) ) {
			return array();
		}

		$profiles = ConnectorProfileRegistry::instance()->get_profiles();

		// Cache slug-inference per (client_id) since one client may back many
		// token rows and matches_dcr_client() calls are non-trivial.
		$slug_cache = array();

		$groups = array();
		foreach ( $rows as $row ) {
			$user_id   = (int) ( $row['user_id'] ?? 0 );
			$client_id = (string) ( $row['client_id'] ?? '' );
			if ( $user_id <= 0 || '' === $client_id ) {
				continue;
			}

			$slug = self::resolve_connector_slug( $row, $profiles, $slug_cache );

			$key = $user_id . '|' . $slug;
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'user_id'           => $user_id,
					'connector_slug'    => $slug,
					'client_ids'        => array(),
					'token_count'       => 0,
					'access_count'      => 0,
					'refresh_count'     => 0,
					'latest_created_at' => '',
				);
			}

			++$groups[ $key ]['token_count'];
			if ( 'refresh' === ( $row['token_type'] ?? '' ) ) {
				++$groups[ $key ]['refresh_count'];
			} else {
				++$groups[ $key ]['access_count'];
			}

			if ( ! in_array( $client_id, $groups[ $key ]['client_ids'], true ) ) {
				$groups[ $key ]['client_ids'][] = $client_id;
			}

			$created = (string) ( $row['created_at'] ?? '' );
			if ( '' !== $created && $created > $groups[ $key ]['latest_created_at'] ) {
				$groups[ $key ]['latest_created_at'] = $created;
			}
		}

		$out = array_values( $groups );
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( (string) $b['latest_created_at'], (string) $a['latest_created_at'] );
			}
		);

		return $out;
	}

	/**
	 * Aggregate every active authorization GRANT on a server — one entry per
	 * `token_family_id` (a family is minted once per consent flow and carried
	 * forward across refresh rotation, so access+refresh pairs collapse into
	 * their grant). Two claude.ai accounts connecting as the same WP user share
	 * one DCR client_id (metadata-fingerprint dedup) but never a family, so
	 * this view renders them as two independent rows.
	 *
	 * Legacy rows minted before SEC-021-001 carry an empty family_id; a family
	 * revoke cannot address them (Query guards reject '' to avoid matching
	 * every legacy row), so they fall back to the old (user × connector)
	 * grouping with `is_legacy => true` and keep the client-scoped revoke path.
	 *
	 * `first_created_at` is the earliest row in the family — stable across
	 * rotation, i.e. when the connection was first authorized. Sorted by
	 * `latest_created_at` DESC like the per-user view.
	 *
	 * @param int $server_id MCP server row id.
	 * @return array<int, array{
	 *   token_family_id: string,
	 *   user_id: int,
	 *   connector_slug: string,
	 *   client_ids: array<int, string>,
	 *   token_count: int,
	 *   access_count: int,
	 *   refresh_count: int,
	 *   first_created_at: string,
	 *   latest_created_at: string,
	 *   is_legacy: bool
	 * }>
	 */
	public static function group_by_grant_for_server( int $server_id ): array {
		$rows = TokensQuery::instance()->list_active_tokens_with_client_meta_for_server( $server_id );
		if ( empty( $rows ) ) {
			return array();
		}

		$profiles   = ConnectorProfileRegistry::instance()->get_profiles();
		$slug_cache = array();

		$groups = array();
		foreach ( $rows as $row ) {
			$user_id   = (int) ( $row['user_id'] ?? 0 );
			$client_id = (string) ( $row['client_id'] ?? '' );
			if ( $user_id <= 0 || '' === $client_id ) {
				continue;
			}

			$slug = self::resolve_connector_slug( $row, $profiles, $slug_cache );

			$family    = (string) ( $row['token_family_id'] ?? '' );
			$is_legacy = 36 !== strlen( $family );
			$key       = $is_legacy
				? 'legacy|' . $user_id . '|' . $slug
				: 'fam|' . $family;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'token_family_id'   => $is_legacy ? '' : $family,
					'user_id'           => $user_id,
					'connector_slug'    => $slug,
					'client_ids'        => array(),
					'token_count'       => 0,
					'access_count'      => 0,
					'refresh_count'     => 0,
					'first_created_at'  => '',
					'latest_created_at' => '',
					'is_legacy'         => $is_legacy,
				);
			}

			++$groups[ $key ]['token_count'];
			if ( 'refresh' === ( $row['token_type'] ?? '' ) ) {
				++$groups[ $key ]['refresh_count'];
			} else {
				++$groups[ $key ]['access_count'];
			}

			if ( ! in_array( $client_id, $groups[ $key ]['client_ids'], true ) ) {
				$groups[ $key ]['client_ids'][] = $client_id;
			}

			$created = (string) ( $row['created_at'] ?? '' );
			if ( '' !== $created ) {
				if ( '' === $groups[ $key ]['first_created_at'] || $created < $groups[ $key ]['first_created_at'] ) {
					$groups[ $key ]['first_created_at'] = $created;
				}
				if ( $created > $groups[ $key ]['latest_created_at'] ) {
					$groups[ $key ]['latest_created_at'] = $created;
				}
			}
		}

		$out = array_values( $groups );
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( (string) $b['latest_created_at'], (string) $a['latest_created_at'] );
			}
		);

		return $out;
	}

	/**
	 * Effective connector slug for a token row: stored value, else DCR
	 * inference via `AbstractConnectorProfile::matches_dcr_client()`, else
	 * `ConnectorSlugDisplay::OTHER_SLUG`. Inference is cached per client_id
	 * since one client may back many token rows.
	 *
	 * @param array<string, mixed>                                                           $row        Token row with client meta.
	 * @param array<int, \AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile> $profiles   Registered connector profiles.
	 * @param array<string, string>                                                          $slug_cache Per-client_id inference cache (by reference).
	 * @return string
	 */
	private static function resolve_connector_slug( array $row, array $profiles, array &$slug_cache ): string {
		$client_id = (string) ( $row['client_id'] ?? '' );

		$slug = (string) ( $row['connector_slug'] ?? '' );
		if ( '' === $slug ) {
			if ( ! array_key_exists( $client_id, $slug_cache ) ) {
				$client_name   = (string) ( $row['client_name'] ?? '' );
				$decoded_uris  = array();
				$redirect_json = (string) ( $row['redirect_uris'] ?? '' );
				if ( '' !== $redirect_json ) {
					$decoded = json_decode( $redirect_json, true );
					if ( is_array( $decoded ) ) {
						$decoded_uris = array_values( array_filter( array_map( 'strval', $decoded ) ) );
					}
				}
				$inferred = '';
				foreach ( $profiles as $profile ) {
					if ( $profile->matches_dcr_client( $client_name, $decoded_uris ) ) {
						$inferred = $profile->get_slug();
						break;
					}
				}
				$slug_cache[ $client_id ] = $inferred;
			}
			$slug = $slug_cache[ $client_id ];
		}
		if ( '' === $slug ) {
			$slug = ConnectorSlugDisplay::OTHER_SLUG;
		}

		return $slug;
	}
}
