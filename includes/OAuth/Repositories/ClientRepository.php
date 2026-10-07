<?php
/**
 * ClientRepository — Controller-facing wrapper for OAuthClients\Query.
 *
 * Owns the boundary where raw client secrets are hashed via SecretsVault
 * before persistence. Controllers MUST NOT touch OAuthClients\Query
 * directly (Refactor Task 2 — Controller/Repository/$wpdb layering).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth\Repositories
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth\Repositories;

use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Query as ClientsQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Row as ClientRow;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

defined( 'ABSPATH' ) || exit;

final class ClientRepository {

	/**
	 * Create a client row. Hashes the raw secret at this boundary.
	 *
	 * Shape of $data (PSR-5 array shape kept for PHPStan level 8):
	 *   client_id (string, required)
	 *   server_id (int, required — F032 T041)
	 *   client_secret (string|null, optional — null → public client)
	 *   client_name (string, optional)
	 *   redirect_uris (array<int, string>, required)
	 *   grant_types (string, optional)
	 *   token_endpoint_auth_method (string, optional)
	 *   connector_slug (string, optional)
	 *   metadata_fingerprint (string, optional).
	 *
	 * @param array<string, mixed> $data Fresh client parameters.
	 * @return int Newly-inserted row id (0 on failure).
	 */
	public static function create( array $data ): int {
		$secret_raw  = isset( $data['client_secret'] ) && '' !== $data['client_secret'] ? (string) $data['client_secret'] : null;
		$secret_hash = null === $secret_raw ? null : SecretsVault::hash( $secret_raw );

		$args = array(
			'client_id'                  => (string) $data['client_id'],
			// F032 (T041) — required server binding. Post-migration NOT NULL invariant.
			'server_id'                  => (int) ( $data['server_id'] ?? 0 ),
			'client_secret_hash'         => $secret_hash,
			'client_name'                => isset( $data['client_name'] ) ? (string) $data['client_name'] : '',
			'redirect_uris'              => wp_json_encode( array_values( $data['redirect_uris'] ) ),
			'grant_types'                => isset( $data['grant_types'] ) ? (string) $data['grant_types'] : 'authorization_code refresh_token',
			'token_endpoint_auth_method' => isset( $data['token_endpoint_auth_method'] ) ? (string) $data['token_endpoint_auth_method'] : 'none',
			'connector_slug'             => isset( $data['connector_slug'] ) ? (string) $data['connector_slug'] : '',
			'metadata_fingerprint'       => isset( $data['metadata_fingerprint'] ) ? (string) $data['metadata_fingerprint'] : '',
		);

		$id = ClientsQuery::instance()->add_item( $args );

		return is_int( $id ) ? $id : (int) $id;
	}

	/**
	 * @param string $client_id
	 * @return ClientRow|null
	 */
	public static function find_by_id( string $client_id ): ?ClientRow {
		return ClientsQuery::instance()->find_by_id( $client_id );
	}

	/**
	 * @param string $fingerprint SHA-256 hex.
	 * @param int    $server_id   Owning MCP server; 0 matches any (D31).
	 * @return ClientRow|null
	 */
	public static function find_by_fingerprint( string $fingerprint, int $server_id = 0 ): ?ClientRow {
		return ClientsQuery::instance()->find_by_fingerprint( $fingerprint, $server_id );
	}

	/**
	 * Q2 lookup — admin-generated client for a (server_id, connector_slug) pair.
	 *
	 * @param int    $server_id      Server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @return ClientRow|null
	 */
	public static function find_admin_client( int $server_id, string $connector_slug ): ?ClientRow {
		return ClientsQuery::instance()->find_admin_client( $server_id, $connector_slug );
	}

	/**
	 * Verify a raw client_secret against the stored hash (client_secret_post).
	 *
	 * @param ClientRow $client     Client row.
	 * @param string    $raw_secret Untrusted input.
	 * @return bool
	 */
	public static function verify_secret( ClientRow $client, string $raw_secret ): bool {
		if ( null === $client->client_secret_hash || '' === $client->client_secret_hash ) {
			return false;
		}
		return SecretsVault::verify( $client->client_secret_hash, $raw_secret );
	}

	/**
	 * F024 Connections panel — every admin-generated client for a
	 * (server_id, connector_slug) pair. Uses the LIKE lookup on the
	 * structured `server-{id}-{slug}-{rand8}` client_id format (spec §Q2).
	 *
	 * @param int    $server_id      Server row id.
	 * @param string $connector_slug Connector profile slug.
	 * @return array<int, ClientRow>
	 */
	public static function find_admin_for_server_connector( int $server_id, string $connector_slug ): array {
		return ClientsQuery::instance()->find_admin_clients_for_server_connector( $server_id, $connector_slug );
	}

	/**
	 * F024 Connections panel — every DCR-registered client (unfiltered).
	 * Callers filter by `$profile->matches_dcr_client(...)` after fetching.
	 *
	 * @return array<int, ClientRow>
	 */
	public static function find_dcr_all(): array {
		return ClientsQuery::instance()->find_dcr_clients();
	}

	/**
	 * Consolidated Connections panel (server-wide view) — every client on a
	 * server, both admin-generated and DCR, regardless of connector slug.
	 *
	 * DCR rows still carry a `connector_slug` that may be empty (unrecognized
	 * OAuth clients like Gemini) or attributed to a registered profile — the
	 * caller renders that via `ConnectorSlugDisplay::display_name`.
	 *
	 * @param int $server_id MCP server row id.
	 * @return array<int, ClientRow>
	 */
	public static function find_all_for_server( int $server_id ): array {
		if ( $server_id <= 0 ) {
			return array();
		}
		$admin = ClientsQuery::instance()->find_admin_clients_for_server( $server_id );
		$dcr   = ClientsQuery::instance()->find_dcr_clients( $server_id );
		return array_merge( $admin, $dcr );
	}
}
