<?php
/**
 * RefreshTokenRepository — issuance + SEC-021-001 family revocation.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth\Repositories
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth\Repositories;

use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as TokensQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Row as TokenRow;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

defined( 'ABSPATH' ) || exit;

final class RefreshTokenRepository {

	private const TTL_SECONDS = 2592000; // 30 days.

	/**
	 * Issue a refresh token. Carries forward `token_family_id`.
	 *
	 * Shape of $data:
	 *   client_id (string, required)
	 *   server_id (int, required — F032 T041)
	 *   user_id (int, required)
	 *   scope (string, optional)
	 *   resource (string, optional)
	 *   token_family_id (string, required — matches parent access token).
	 *
	 * @param array<string, mixed> $data Refresh token parameters.
	 * @return array{raw: string, id: int, family_id: string, expires_at: string}
	 */
	public static function issue( array $data ): array {
		$raw = SecretsVault::random_token();

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS );

		$id = TokensQuery::instance()->add_item(
			array(
				'token_hash'      => SecretsVault::hash( $raw ),
				'token_type'      => 'refresh',
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
	 * @param string $token_hash
	 * @return TokenRow|null
	 */
	public static function find_by_hash( string $token_hash ): ?TokenRow {
		return TokensQuery::instance()->find_by_hash( $token_hash );
	}

	/**
	 * Single-token revoke.
	 *
	 * @param string $token_hash
	 * @return bool True iff row transitioned 0 → 1 this call.
	 */
	public static function revoke_by_hash( string $token_hash ): bool {
		return TokensQuery::instance()->revoke_by_hash( $token_hash );
	}

	/**
	 * SEC-021-001 family-scoped revoke. Returns list of revoked ids so
	 * TokenController can fire `token_revoked` per row.
	 *
	 * @param string $family_id UUIDv4 (char(36)).
	 * @return array<int, int>
	 */
	public static function revoke_by_family_id( string $family_id ): array {
		return TokensQuery::instance()->revoke_by_family_id( $family_id );
	}

	/**
	 * Refresh-token TTL in seconds (2592000s = 30 days).
	 *
	 * @return int
	 */
	public static function ttl(): int {
		return self::TTL_SECONDS;
	}
}
