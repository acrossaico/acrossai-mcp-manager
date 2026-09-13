<?php
/**
 * BerlinDB Row for a single CliAuthLog record.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\CliAuthLog
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\CliAuthLog;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single row from the CliAuthLog module's table.
 *
 * @property array $properties
 */
class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */         public $id                    = 0;
	/** @var int */         public $server_id             = 0;
	/** @var string */      public $server_slug           = '';
	/** @var int */         public $user_id               = 0;
	/** @var string */      public $status                = 'pending';
	/** @var string */      public $failure_code          = '';
	/** @var string */      public $auth_code_hash        = '';
	/** @var string */      public $app_password_uuid     = '';
	/** @var string */      public $redirect_uri          = '';
	/** @var string */      public $code_challenge        = '';
	/** @var string */      public $code_challenge_method = 'S256';
	/** @var string */      public $scope                 = 'mcp';
	/** @var string|null */ public $approved_at           = null;
	/** @var string|null */ public $completed_at          = null;
	/** @var string */      public $created_at            = '';

	/**
	 * Constructor — casts primitive types.
	 *
	 * @param object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );
		$this->id        = (int) $this->id;
		$this->server_id = (int) $this->server_id;
		$this->user_id   = (int) $this->user_id;
	}

	/**
	 * Return this row as an associative array (external consumers depend on this).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                    => $this->id,
			'server_id'             => $this->server_id,
			'server_slug'           => $this->server_slug,
			'user_id'               => $this->user_id,
			'status'                => $this->status,
			'failure_code'          => $this->failure_code,
			'auth_code_hash'        => $this->auth_code_hash,
			'app_password_uuid'     => $this->app_password_uuid,
			'redirect_uri'          => $this->redirect_uri,
			'code_challenge'        => $this->code_challenge,
			'code_challenge_method' => $this->code_challenge_method,
			'scope'                 => $this->scope,
			'approved_at'           => $this->approved_at,
			'completed_at'          => $this->completed_at,
			'created_at'            => $this->created_at,
		);
	}
}
