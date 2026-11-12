<?php
/**
 * Single source of truth for the Clients registry.
 *
 * Mirrors {@see ActivityLogConstants}: a constants holder with a private
 * constructor and no singleton, so a table name or an option key is written
 * once and every reader names the same string.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Utilities
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Names and defaults for the Clients feature.
 *
 * @since 0.4.9
 */
final class ClientsConstants {

	/**
	 * Table stem, unprefixed.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const TABLE = 'acrossai_mcp_clients';

	/**
	 * Schema version. Bumping this without a paired `$upgrades` callback
	 * silently stamps the new version and changes nothing — BerlinDB never
	 * auto-diffs.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const TABLE_VERSION = '1.0.0';

	/**
	 * Option holding the installed schema version.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const OPTION_DB_VERSION = 'acrossai_mcp_clients_db_version';

	/**
	 * Option recording failed registry writes, for Site Health.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const OPTION_WRITE_FAILURES = 'acrossai_mcp_clients_write_failures';

	/**
	 * How a client reached this site. These are the Connect tab's own four
	 * routes plus the three that tab does not cover, so the Clients screen
	 * names a route the same way the screen that set it up did.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const ROUTE_CONNECTOR   = 'connector';
	public const ROUTE_CONFIG_FILE = 'config-file';
	public const ROUTE_NPM         = 'npm';
	public const ROUTE_WP_CLI      = 'wp-cli';
	public const ROUTE_FLEET       = 'fleet';
	public const ROUTE_BROWSER     = 'browser';
	public const ROUTE_DIRECT      = 'direct';

	/**
	 * Every route, for validation and for the filter dropdown.
	 *
	 * @since 0.4.9
	 * @var   array<int, string>
	 */
	public const ROUTES = array(
		self::ROUTE_CONNECTOR,
		self::ROUTE_CONFIG_FILE,
		self::ROUTE_NPM,
		self::ROUTE_WP_CLI,
		self::ROUTE_FLEET,
		self::ROUTE_BROWSER,
		self::ROUTE_DIRECT,
	);

	/**
	 * Seconds between `last_seen_at` writes for one client.
	 *
	 * Zero: every request updates the row. Unlike `Fleet\Hosts::touch()`,
	 * which throttles because its store is a JSON blob needing a
	 * read-modify-write of the whole thing, this is one indexed UPSERT. A
	 * throttle here would also make `request_count` count active minutes
	 * rather than requests — a number that reads like a request count and is
	 * not one. Kept as a constant so the decision is visible rather than
	 * implied by its absence.
	 *
	 * @since 0.4.9
	 * @var   int
	 */
	public const TOUCH_THROTTLE_SECONDS = 0;

	/**
	 * Not instantiable.
	 *
	 * @since 0.4.9
	 */
	private function __construct() {}
}
