<?php
/**
 * BerlinDB Table for the Clients registry.
 *
 * Extends the base via a leading-`\` FQN rather than a `use` import: this
 * class is also called `Table`, and importing one would collide with the
 * other (DEC-BERLINDB-SUBCLASS-NO-USE-COLLISION).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\Clients
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\Clients;

use AcrossAI_MCP_Manager\Includes\Utilities\ClientsConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the DDL and the version stamp for acrossai_mcp_clients.
 *
 * @since 0.4.8
 */
class Table extends \BerlinDB\Database\Kern\Table {

	/**
	 * Table stem; BerlinDB applies the site prefix.
	 *
	 * @var string
	 */
	protected $name = ClientsConstants::TABLE;

	/**
	 * @var string
	 */
	protected $version = ClientsConstants::TABLE_VERSION;

	/**
	 * @var string
	 */
	protected $db_version_key = ClientsConstants::OPTION_DB_VERSION;

	/**
	 * @var string
	 */
	protected $schema = Schema::class;

	/**
	 * Per-site, not network-wide: a client connects to one site's servers.
	 *
	 * @var bool
	 */
	protected $global = false;

	/**
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Shared instance.
	 *
	 * @since  0.4.8
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Install or upgrade, surviving a phantom version.
	 *
	 * Two states BerlinDB does not handle on its own, both reachable by an
	 * operator dropping tables by hand or restoring a partial backup:
	 * the version option present with no table (install never re-runs), and
	 * the inverse. Clearing the option is what makes the install re-run.
	 *
	 * Silent by design — this is a repair, not an incident.
	 *
	 * @since  0.4.8
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		}

		parent::maybe_upgrade();
	}
}
