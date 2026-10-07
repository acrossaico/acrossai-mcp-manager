<?php
/**
 * AuthCodeRepository — issuance + atomic single-use consumption.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth\Repositories
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth\Repositories;

use AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Query as AuthCodesQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Row as AuthCodeRow;
use AcrossAI_MCP_Manager\Includes\OAuth\Security\SecretsVault;

defined( 'ABSPATH' ) || exit;

final class AuthCodeRepository {

	private const TTL_SECONDS = 600;

	/**
	 * Issue a raw auth code, persist its SHA-256 hash + PKCE + resource.
	 *
	 * Shape of $data:
	 *   client_id (string, required)
	 *   server_id (int, required — F032 T041)
	 *   user_id (int, required)
	 *   redirect_uri (string, required)
	 *   code_challenge (string, required — 43 chars)
	 *   code_challenge_method (string, optional — default 'S256')
	 *   scope (string, optional — default 'mcp')
	 *   resource (string, required — RFC 8707 audience).
	 *
	 * @param array<string, mixed> $data Auth code parameters.
	 * @return array{raw: string, id: int, expires_at: string}
	 */
	public static function create( array $data ): array {
		$raw        = SecretsVault::random_token();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS );

		$id = AuthCodesQuery::instance()->add_item(
			array(
				'code_hash'             => SecretsVault::hash( $raw ),
				'client_id'             => (string) $data['client_id'],
				// F032 (T041) — required server binding. Post-migration NOT NULL invariant.
				'server_id'             => (int) ( $data['server_id'] ?? 0 ),
				'user_id'               => (int) $data['user_id'],
				'redirect_uri'          => (string) $data['redirect_uri'],
				'code_challenge'        => (string) $data['code_challenge'],
				'code_challenge_method' => isset( $data['code_challenge_method'] ) && '' !== $data['code_challenge_method']
					? (string) $data['code_challenge_method']
					: 'S256',
				'scope'                 => isset( $data['scope'] ) && '' !== $data['scope'] ? (string) $data['scope'] : 'mcp',
				'resource'              => (string) $data['resource'],
				'used'                  => 0,
				'expires_at'            => $expires_at,
			)
		);

		return array(
			'raw'        => $raw,
			'id'         => is_int( $id ) ? $id : (int) $id,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * B10 atomic single-use consume by raw code (not hash — Repository
	 * hashes internally so Controllers stay hash-agnostic).
	 *
	 * @param string $raw_code Raw auth code presented by client.
	 * @return AuthCodeRow|null
	 */
	public static function consume_atomic( string $raw_code ): ?AuthCodeRow {
		if ( '' === $raw_code ) {
			return null;
		}
		return AuthCodesQuery::instance()->consume_atomic(
			SecretsVault::hash( $raw_code ),
			gmdate( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * Auth-code TTL in seconds (600s = 10min).
	 *
	 * @return int
	 */
	public static function ttl(): int {
		return self::TTL_SECONDS;
	}
}
