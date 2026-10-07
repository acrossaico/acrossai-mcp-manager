<?php
/**
 * F032 — BerlinDB Table subclass for the ConnectorApprovedUsers module.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\ConnectorApprovedUsers
 * @since      0.1.6 (F032)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers;

defined( 'ABSPATH' ) || exit;

// NB: DO NOT `use BerlinDB\Database\Kern\Table` — the local class name here
// is also `Table`, which would collide with the import. Extend via the
// leading-`\` FQN instead (DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION).

/**
 * Manages database table creation for the ConnectorApprovedUsers module.
 *
 * Overrides `maybe_upgrade()` with the F011 phantom-version guard.
 */
class Table extends \BerlinDB\Database\Kern\Table {

	/** @var string */
	protected $name = 'acrossai_mcp_connector_approved_users';

	/** @var string */
	protected $version = '1.0.0';

	/** @var string */
	protected $db_version_key = 'acrossai_mcp_connector_approved_users_db_version';

	/** @var string */
	protected $schema = Schema::class;

	/** @var bool */
	protected $global = false;

	/** @var Table|null */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Table
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * F011 phantom-version guard — clear the version option if the physical
	 * table was manually dropped so a fresh install fires on next call.
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		}
		parent::maybe_upgrade();
	}
}
