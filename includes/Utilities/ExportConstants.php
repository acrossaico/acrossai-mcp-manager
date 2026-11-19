<?php
/**
 * Single source of truth for the server export format (Feature 105).
 *
 * Mirrors {@see ActivityLogConstants} and {@see ClientsConstants}: a constants
 * holder with a private constructor, so a format name or a block key is
 * written once and every reader names the same string.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Utilities
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Names and shapes for export/import.
 *
 * @since 0.4.9
 */
final class ExportConstants {

	/**
	 * Identifies a file as ours.
	 *
	 * Checked before anything else on import: a JSON file that is valid but
	 * belongs to another plugin must be refused with "this is not an AcrossAI
	 * export", not half-read and reported as corrupt.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const FORMAT = 'acrossai-mcp';

	/**
	 * Format version this build writes, and the highest it can read.
	 *
	 * A file claiming a HIGHER version is refused rather than best-effort
	 * parsed — it may contain blocks whose absence this build would read as
	 * "leave alone" when the newer writer meant something else.
	 *
	 * @since 0.4.9
	 * @var   int
	 */
	public const VERSION = 1;

	/**
	 * The per-server setting groups an operator can include or leave out.
	 *
	 * These are the Edit Server tabs as a person sees them, not the storage
	 * layers underneath — `tools` spans a table and three columns, `abilities`
	 * spans a column and a table. The screen offers what the operator
	 * recognises; the exporter does the mapping.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const BLOCK_STATUS     = 'status';
	public const BLOCK_MESSAGE    = 'message';
	public const BLOCK_TOOLS      = 'tools';
	public const BLOCK_ABILITIES  = 'abilities';
	public const BLOCK_ACCESS     = 'access';
	public const BLOCK_CONNECTORS = 'connectors';

	/**
	 * Every block, in the order the screen lists them.
	 *
	 * @since 0.4.9
	 * @var   array<int, string>
	 */
	public const BLOCKS = array(
		self::BLOCK_STATUS,
		self::BLOCK_MESSAGE,
		self::BLOCK_TOOLS,
		self::BLOCK_ABILITIES,
		self::BLOCK_ACCESS,
		self::BLOCK_CONNECTORS,
	);

	/**
	 * Where a server came from — and therefore whether an import may CREATE it.
	 *
	 * Not whether it may be exported: a plugin-registered server stores its
	 * settings in exactly the same places as any other, and on this codebase's
	 * own dev site it is the only server with all three protocol tools
	 * switched on. Its settings travel; its existence does not.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const ORIGIN_DATABASE = 'database';
	public const ORIGIN_PLUGIN   = 'plugin';

	/**
	 * Tool-state names, mirroring the Tools tab's three bulk buttons.
	 *
	 * The buttons leave no stored mode — only rows — so the exporter works out
	 * which state the rows match and records that, plus any delta. Recording
	 * the absolute slug list instead would pin an imported server to one
	 * release's type defaults, silently undoing a later release's changes.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const TOOLS_TYPE_DEFAULTS = 'type-defaults';
	public const TOOLS_ALL           = 'all';
	public const TOOLS_NONE          = 'none';
	public const TOOLS_CUSTOM        = 'custom';

	/**
	 * Access-control rule key that names individual users.
	 *
	 * Refused by the exporter. A user id means a different person on another
	 * site, so carrying one would silently grant access to whoever happens to
	 * hold that id — the same reason `connector_approved_users` never travels.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const ACCESS_RULE_USER = 'wp_user';

	/**
	 * Not instantiable.
	 *
	 * @since 0.4.9
	 */
	private function __construct() {}
}
