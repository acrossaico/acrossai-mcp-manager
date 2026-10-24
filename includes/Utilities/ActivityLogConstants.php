<?php
/**
 * Shared constants for the MCP activity log (Feature 096).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Utilities
 */

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the activity log's option keys, event vocabulary
 * and recorder ignore list.
 *
 * Lives here rather than on a feature class per A9: every value below is read by
 * at least two classes in different modules — `Includes\Database\ActivityLog\*`,
 * `Includes\Observability\*`, `Includes\Abilities\AbilityActivityRecorder`,
 * `Includes\REST\ActivityLogController` and `Admin\Partials\Settings` — and A9
 * requires a constant read by ≥2 classes in different feature modules to live in
 * `includes/Utilities/` as a `final class`.
 *
 * IGNORED_TABLES is deliberately a single constant rather than a literal repeated
 * per recorder. Feature 097 adds a third recorder behind the same re-entry guard,
 * and a second copy of this list is how one recorder ends up recording another's
 * writes.
 *
 * NOTE: no singleton ceremony — only `const` values, no instance state.
 */
final class ActivityLogConstants {

	/** Table name, without the `$wpdb->prefix`. */
	public const TABLE = 'acrossai_mcp_activity_log';

	/**
	 * Schema version. Bump only alongside an `$upgrades` entry — see B34.
	 *
	 * `1.1.0` re-keys four composite indexes to lead with `event_type`. No
	 * column changed, so a reader could mistake it for a no-op bump; it is not.
	 * dbDelta leaves an existing index alone, so the physical keys only change
	 * because `Table::upgrade_to_1_1_0()` drops and rebuilds them.
	 */
	public const TABLE_VERSION = '1.1.0';

	// --- Event vocabulary (the `event_type` discriminator) ---

	/** One MCP JSON-RPC request. `parent_id` is always 0. */
	public const EVENT_REQUEST = 'request';

	/** One ability execution. `parent_id` is its request, or 0 when non-MCP. */
	public const EVENT_ABILITY = 'ability';

	/**
	 * Removal of events by purge, retention or the retained-event ceiling.
	 *
	 * Required by A24: an audit log MUST record its own destruction. The row is
	 * written AFTER the delete, so it survives as the first entry of the
	 * remaining history — the one row a purge cannot erase.
	 */
	public const EVENT_REMOVAL = 'removal';

	/** Every recognised `event_type`, for validation and filter options. */
	public const EVENT_TYPES = array(
		self::EVENT_REQUEST,
		self::EVENT_ABILITY,
		self::EVENT_REMOVAL,
	);

	// --- Reserved `source` values ---

	/** MCP JSON-RPC traffic. The only value written in 1.0.0. */
	public const SOURCE_MCP = 'mcp';

	/**
	 * Reserved, not yet written: WebMCP calls and auth/connection events.
	 *
	 * These exist so those rows can land later as themselves. easy-mcp-ai, which
	 * has no such column, writes auth events into its audit table behind a fake
	 * `tool_name` of `_oauth_refresh` with the event stuffed into `result_status`.
	 * Do not repeat that.
	 *
	 * These are the values of the `event_source` COLUMN. The column is deliberately
	 * not called `source`: the mcp-adapter sends a tag of that name whose value on a
	 * toolset call is `ability`, and a declarative tag-to-column map would silently
	 * write it here. Verified on live traffic 2026-10-16 — see research.md D-11.
	 */
	public const SOURCE_WEBMCP = 'webmcp';
	public const SOURCE_AUTH   = 'auth';

	// --- Option keys ---

	/** Enable flag: one row per MCP request. Read in `MCP\Controller`. */
	public const OPTION_REQUEST_LOGGING = 'acrossai_mcp_activity_request_logging';

	/** Enable flag: one row per ability run. Far noisier; independent switch. */
	public const OPTION_ABILITY_LOGGING = 'acrossai_mcp_activity_ability_logging';

	/** Retention in days for `request` events. 0 keeps them indefinitely. */
	public const OPTION_RETENTION_REQUEST_DAYS = 'acrossai_mcp_activity_retention_request_days';

	/** Retention in days for `ability` events. 0 keeps them indefinitely. */
	public const OPTION_RETENTION_ABILITY_DAYS = 'acrossai_mcp_activity_retention_ability_days';

	/** Maximum retained events; oldest trimmed first. 0 disables the ceiling. */
	public const OPTION_MAX_EVENTS = 'acrossai_mcp_activity_max_events';

	/** Consecutive write failures, so a broken log cannot look like a quiet one. */
	public const OPTION_WRITE_FAILURES = 'acrossai_mcp_activity_write_failures';

	/** Stored schema version, compared against TABLE_VERSION. */
	public const OPTION_DB_VERSION = 'acrossai_mcp_activity_db_version';

	/**
	 * Default retention for `request` events, in days.
	 *
	 * The history an operator actually browses. 30 days covers "what happened
	 * last month" without the operator configuring anything.
	 */
	public const DEFAULT_RETENTION_REQUEST_DAYS = 30;

	/**
	 * Default retention for `ability` events, in days.
	 *
	 * SHORTER than requests on purpose, and this asymmetry is the point. One
	 * tool call can run several abilities, and REST, WP-CLI and admin callers
	 * are recorded too — ability rows were ~68% of volume when this was
	 * benchmarked (6,377 of 9,377). They are also the rows that carry recorded
	 * arguments. Seven days keeps the drill-down useful for the window anyone
	 * actually debugs in — "what ran behind this call yesterday" — while
	 * cutting the dominant growth term by roughly four-fifths.
	 *
	 * Do NOT "tidy" this back to a single shared constant. Both halves having
	 * the same number is what makes the asymmetry look like an oversight.
	 */
	public const DEFAULT_RETENTION_ABILITY_DAYS = 7;

	/**
	 * Default ceiling on retained events, across BOTH halves (FR-035).
	 *
	 * Non-zero because recording is on by default: a plugin that records
	 * without being asked should bound without being asked. Retention bounds
	 * growth in TIME, proportional to traffic; this bounds it independently of
	 * traffic, which is the case retention cannot cover — a site doing 10,000
	 * calls a day would otherwise hold roughly 450,000 rows inside the
	 * retention window alone.
	 *
	 * 50,000 is 5x the benchmarked ~10,000-event design point, so an ordinary
	 * site never reaches it and the ceiling never deletes anything the operator
	 * would miss. 0 remains selectable and still means no limit.
	 */
	public const DEFAULT_MAX_EVENTS = 50000;

	/**
	 * Whether recording is on before anyone chooses.
	 *
	 * Both halves default ON. Safe to introduce as a default rather than an
	 * opt-in ONLY because this feature has never shipped — there is no existing
	 * install whose operator declined it and would be silently switched on by
	 * an update. That reasoning expires the moment 096 is released: changing
	 * this value afterwards IS a retroactive behaviour change on live sites,
	 * and this table records source addresses and ability arguments.
	 */
	public const DEFAULT_REQUEST_LOGGING = true;
	public const DEFAULT_ABILITY_LOGGING = true;

	// --- Hook names ---

	/**
	 * Fired by `OAuth\TokenValidator` after a successful bearer validation,
	 * carrying the resolved connector slug and client name.
	 *
	 * A name, not a literal in two files: the validator fires it and `Main`
	 * registers the listener, so a typo in either copy would fail silently,
	 * leaving `connection_type` resolving to `unknown` for every OAuth client.
	 *
	 * Fire-and-forget per D19; if this ever needs fail-forward semantics, D39
	 * requires per-listener isolation rather than native `do_action()`.
	 */
	public const ACTION_OAUTH_TOKEN_VALIDATED = 'acrossai_mcp_oauth_token_validated';

	/** Filters the bytes-per-token divisor used to derive an approximate count. */
	public const FILTER_BYTES_PER_TOKEN = 'acrossai_mcp_bytes_per_token';

	/** Filters the derived token estimate; return null to render a dash. */
	public const FILTER_ESTIMATED_TOKENS = 'acrossai_mcp_estimated_tokens';

	/** Filters a recorded input preview, so a site can suppress or scrub it. */
	public const FILTER_INPUT_PREVIEW = 'acrossai_mcp_activity_input_preview';

	/** Default divisor for the token estimate. Reads low for JSON; see the spec. */
	public const DEFAULT_BYTES_PER_TOKEN = 4;

	// --- Recursion guard ---

	/**
	 * Tables whose writes MUST NOT be recorded, to stop a recorder observing
	 * itself. Unprefixed; callers compare against `$wpdb->prefix`-stripped names.
	 *
	 * Feature 097's change log is listed here from day one so that adding its
	 * recorder does not require editing this list — the edit that gets forgotten.
	 */
	public const IGNORED_TABLES = array(
		self::TABLE,
		'acrossai_mcp_change_log',
	);

	/** Private, never invoked. This class is constants only. */
	private function __construct() {}
}
