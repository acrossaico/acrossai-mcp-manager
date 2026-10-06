<?php
/**
 * BerlinDB Row for the OAuthAuthCodes module (Feature 021).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAuthCodes
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes;

defined( 'ABSPATH' ) || exit;

class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */    public $id                    = 0;
	/** @var string */ public $code_hash             = '';
	/** @var string */ public $client_id             = '';
	/** @var int */    public $server_id             = 0;
	/** @var int */    public $user_id               = 0;
	/** @var string */ public $redirect_uri          = '';
	/** @var string */ public $code_challenge        = '';
	/** @var string */ public $code_challenge_method = 'S256';
	/** @var string */ public $scope                 = 'mcp';
	/** @var string */ public $resource              = '';
	/** @var int */    public $used                  = 0;
	/** @var string */ public $expires_at            = '';
	/** @var string */ public $created_at            = '';

	/**
	 * @param object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );
		$this->id = (int) $this->id;
		// F032 — B18 defensive cast. Post-migration invariant: server_id is NEVER NULL.
		$this->server_id = (int) $this->server_id;
		$this->user_id   = (int) $this->user_id;
		$this->used      = (int) $this->used;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                    => $this->id,
			'code_hash'             => $this->code_hash,
			'client_id'             => $this->client_id,
			'server_id'             => $this->server_id,
			'user_id'               => $this->user_id,
			'redirect_uri'          => $this->redirect_uri,
			'code_challenge'        => $this->code_challenge,
			'code_challenge_method' => $this->code_challenge_method,
			'scope'                 => $this->scope,
			'resource'              => $this->resource,
			'used'                  => $this->used,
			'expires_at'            => $this->expires_at,
			'created_at'            => $this->created_at,
		);
	}
}
