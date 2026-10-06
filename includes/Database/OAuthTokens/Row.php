<?php
/**
 * BerlinDB Row for the OAuthTokens module (Feature 021).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthTokens
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthTokens;

defined( 'ABSPATH' ) || exit;

class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */    public $id              = 0;
	/** @var string */ public $token_hash      = '';
	/** @var string */ public $token_type      = 'access';
	/** @var string */ public $client_id       = '';
	/** @var int */    public $server_id       = 0;
	/** @var int */    public $user_id         = 0;
	/** @var string */ public $scope           = 'mcp';
	/** @var string */ public $resource        = '';
	/** @var string */ public $expires_at      = '';
	/** @var int */    public $revoked         = 0;
	/** @var string */ public $token_family_id = '';
	/** @var string */ public $created_at      = '';

	/**
	 * @param object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );
		$this->id = (int) $this->id;
		// F032 — B18 defensive cast. Post-migration invariant: server_id is NEVER NULL.
		$this->server_id = (int) $this->server_id;
		$this->user_id   = (int) $this->user_id;
		// B18: $wpdb returns tinyint as string. Cast at boundary.
		$this->revoked = (int) $this->revoked;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'token_hash'      => $this->token_hash,
			'token_type'      => $this->token_type,
			'client_id'       => $this->client_id,
			'server_id'       => $this->server_id,
			'user_id'         => $this->user_id,
			'scope'           => $this->scope,
			'resource'        => $this->resource,
			'expires_at'      => $this->expires_at,
			'revoked'         => $this->revoked,
			'token_family_id' => $this->token_family_id,
			'created_at'      => $this->created_at,
		);
	}

	/**
	 * True if the token is non-revoked AND not expired.
	 *
	 * @param string $now Current GMT time in mysql format.
	 * @return bool
	 */
	public function is_active( string $now ): bool {
		return 0 === $this->revoked && $this->expires_at > $now;
	}
}
