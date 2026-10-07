<?php
/**
 * BerlinDB Row for the OAuthClients module (Feature 021).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthClients
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthClients;

defined( 'ABSPATH' ) || exit;

class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */         public $id                         = 0;
	/** @var string */      public $client_id                  = '';
	/** @var int */         public $server_id                  = 0;
	/** @var string|null */ public $client_secret_hash         = null;
	/** @var string */      public $client_name                = '';
	/** @var string */      public $redirect_uris              = '';
	/** @var string */      public $grant_types                = 'authorization_code refresh_token';
	/** @var string */      public $token_endpoint_auth_method = 'none';
	/** @var string */      public $connector_slug             = '';
	/** @var string */      public $metadata_fingerprint       = '';
	/** @var string */      public $created_at                 = '';

	/**
	 * @param object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );
		$this->id = (int) $this->id;
		// F032 — B18 defensive cast. Post-migration invariant: server_id is NEVER NULL (SQL NOT NULL).
		$this->server_id = (int) $this->server_id;
	}

	/**
	 * Return this row as an array. `redirect_uris` decoded to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                         => $this->id,
			'client_id'                  => $this->client_id,
			'server_id'                  => $this->server_id,
			'client_secret_hash'         => $this->client_secret_hash,
			'client_name'                => $this->client_name,
			'redirect_uris'              => $this->decoded_redirect_uris(),
			'grant_types'                => $this->grant_types,
			'token_endpoint_auth_method' => $this->token_endpoint_auth_method,
			'connector_slug'             => $this->connector_slug,
			'metadata_fingerprint'       => $this->metadata_fingerprint,
			'created_at'                 => $this->created_at,
		);
	}

	/**
	 * Decode the JSON-encoded redirect_uris column into an array. Callers who
	 * need it are the authorize/token endpoints — the byte-match is against
	 * these individual entries.
	 *
	 * @return array<int, string>
	 */
	public function decoded_redirect_uris(): array {
		if ( '' === $this->redirect_uris ) {
			return array();
		}
		$decoded = json_decode( $this->redirect_uris, true );
		return is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_string' ) ) : array();
	}
}
