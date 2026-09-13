<?php
/**
 * BerlinDB Row for a single MCPServerAbility record.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerAbility
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single row from the MCPServerAbility module's table.
 *
 * NOTE: `$is_exposed` is returned as a string by $wpdb (TINYINT columns
 * always deserialize as strings — bug B18). Downstream consumers MUST cast
 * `(bool) $row->is_exposed` or use `! empty()` for boolean semantics.
 *
 * @since 0.1.0
 */
class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */    public $id           = 0;
	/** @var int */    public $server_id    = 0;
	/** @var string */ public $ability_slug = '';
	/** @var int */    public $is_exposed   = 0;
	/** @var string */ public $created_at   = '';
	/** @var string */ public $updated_at   = '';

	/**
	 * Constructor — casts primitive types.
	 *
	 * @param object|array $item Raw DB row.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );
		$this->id         = (int) $this->id;
		$this->is_exposed = (int) $this->is_exposed;
		$this->server_id  = (int) $this->server_id;
	}

	/**
	 * Return this row as an associative array (external consumers depend on this).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'           => $this->id,
			'server_id'    => $this->server_id,
			'ability_slug' => $this->ability_slug,
			'is_exposed'   => $this->is_exposed,
			'created_at'   => $this->created_at,
			'updated_at'   => $this->updated_at,
		);
	}
}
