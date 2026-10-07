<?php
/**
 * F032 — BerlinDB Row for a single ConnectorApprovedUsers record.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\ConnectorApprovedUsers
 * @since      0.1.6 (F032)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers;

defined( 'ABSPATH' ) || exit;

class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */    public $id             = 0;
	/** @var int */    public $server_id      = 0;
	/** @var string */ public $connector_slug = '';
	/** @var int */    public $user_id        = 0;
	/** @var int */    public $approved_by    = 0;
	/** @var string */ public $approved_at    = '';

	/**
	 * Constructor — casts primitive types per B18 defense.
	 *
	 * @param object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );
		$this->id          = (int) $this->id;
		$this->server_id   = (int) $this->server_id;
		$this->user_id     = (int) $this->user_id;
		$this->approved_by = (int) $this->approved_by;
	}

	/**
	 * Return this row as an associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'             => $this->id,
			'server_id'      => $this->server_id,
			'connector_slug' => $this->connector_slug,
			'user_id'        => $this->user_id,
			'approved_by'    => $this->approved_by,
			'approved_at'    => $this->approved_at,
		);
	}
}
