<?php
/**
 * BerlinDB Row for a single activity-log record (Feature 096).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\ActivityLog
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Represents a single row from the activity log.
 *
 * @property array<string, mixed> $properties
 */
class Row extends \BerlinDB\Database\Kern\Row {

	/** @var int */         public $id                 = 0;
	/** @var int */         public $parent_id          = 0;
	/** @var string */      public $event_type         = 'request';
	/** @var string */      public $event_source       = 'mcp';
	/** @var int */         public $server_id          = 0;
	/** @var string */      public $server_slug        = '';
	/** @var string */      public $connection_type    = 'unknown';
	/** @var string */      public $invoked_via        = '';
	/** @var string */      public $client_name        = '';
	/** @var string */      public $client_version     = '';
	/** @var string */      public $connector_slug     = '';
	/** @var string */      public $transport          = '';
	/** @var string */      public $method             = '';
	/** @var string */      public $protocol_version   = '';
	/** @var string */      public $component_type     = '';
	/** @var string */      public $component_name     = '';
	/** @var string */      public $invoked_name       = '';
	/** @var string */      public $component_category = '';
	/** @var string */      public $status             = '';
	/** @var string */      public $error_code         = '';
	/** @var string */      public $error_message      = '';
	/** @var int|null */    public $duration_ms        = null;
	/** @var int */         public $user_id            = 0;
	/** @var string */      public $ip_address         = '';
	/** @var string */      public $user_agent         = '';
	/** @var string */      public $session_id         = '';
	/** @var string */      public $request_id         = '';
	/** @var string|null */ public $input_preview      = null;
	/** @var string|null */ public $output_preview     = null;
	/** @var int|null */    public $input_bytes        = null;
	/** @var int|null */    public $output_bytes       = null;
	/** @var string */      public $created_at         = '';

	/**
	 * Connector display name, resolved at READ time by Query::label_connectors().
	 *
	 * NOT a column. Declared explicitly because assigning it dynamically is
	 * deprecated from PHP 8.2, and because a reader comparing this class to
	 * Schema.php should be able to see at a glance which fields are persisted
	 * and which are decoration.
	 *
	 * @var string
	 */
	public $connector_label = '';

	/**
	 * Constructor — casts primitive types.
	 *
	 * Integer casts are not cosmetic: B18 records that $wpdb returns numeric
	 * columns as strings, so `1 === $row->parent_id` is false without this.
	 * The three nullable integers stay null when the column is null, because
	 * "no duration recorded" and "zero milliseconds" are different facts.
	 *
	 * @param object|array<string, mixed> $item Raw DB row.
	 */
	public function __construct( $item ) {
		// BerlinDB's Boot trait takes an array of args; a $wpdb row object is
		// the other thing callers have. Normalising here keeps the parent's
		// documented contract honest instead of passing a union it does not accept.
		parent::__construct( is_object( $item ) ? get_object_vars( $item ) : $item );

		$this->id        = (int) $this->id;
		$this->parent_id = (int) $this->parent_id;
		$this->server_id = (int) $this->server_id;
		$this->user_id   = (int) $this->user_id;

		$this->duration_ms  = null === $this->duration_ms ? null : (int) $this->duration_ms;
		$this->input_bytes  = null === $this->input_bytes ? null : (int) $this->input_bytes;
		$this->output_bytes = null === $this->output_bytes ? null : (int) $this->output_bytes;
	}

	/**
	 * Is this event an ability run rather than an MCP request?
	 *
	 * @return bool
	 */
	public function is_ability(): bool {
		return \AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants::EVENT_ABILITY === $this->event_type;
	}

	/**
	 * Did this ability run on behalf of an MCP request?
	 *
	 * @return bool
	 */
	public function has_parent(): bool {
		return $this->parent_id > 0;
	}
}
