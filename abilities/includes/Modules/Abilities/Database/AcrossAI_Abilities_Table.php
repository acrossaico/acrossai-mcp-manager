<?php
/**
 * Database table definition for the unified abilities table.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities/Database
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database;

use BerlinDB\Database\Kern\Table;
use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Schema;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Manages database table creation and upgrades for the unified abilities table.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Table extends Table {

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $name = 'acrossai_abilities';

	/**
	 * Table version used to trigger maybe_upgrade().
	 *
	 * @var string
	 */
	protected $version = '1.0.0';

	/**
	 * WordPress option key used to track the installed schema version.
	 * Must match what uninstall.php deletes.
	 *
	 * @var string
	 */
	protected $db_version_key = 'acrossai_abilities_db_version';

	/**
	 * Schema class for this table.
	 *
	 * @var string
	 */
	protected $schema = AcrossAI_Abilities_Schema::class;

	/**
	 * Use per-site table prefix ($wpdb->prefix), not the network base prefix.
	 * Explicitly set to false so multisite intent is declared in code, not
	 * inherited from BerlinDB library defaults (SAC-02 / Constitution §II).
	 *
	 * @var bool
	 */
	protected $global = false;

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Abilities_Table|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance of this table.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Abilities_Table
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Create or upgrade the table.
	 *
	 * Overrides the parent to handle the "phantom version" case: if the stored
	 * db_version_key option exists but the physical table was manually dropped,
	 * BerlinDB's needs_upgrade() would return false and skip installation.
	 * Clearing the option first forces a fresh install on the next run.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		}
		parent::maybe_upgrade();
	}
}
