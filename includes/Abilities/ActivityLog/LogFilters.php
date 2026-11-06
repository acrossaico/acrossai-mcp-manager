<?php
/**
 * `mcp-manager/get-log-filters` — what values this site's log actually holds.
 *
 * Every filter {@see QueryLogs} accepts is an exact match. A caller that guesses
 * `client_name = "Claude"` on a site that records `"claude-ai"` gets an empty
 * result and no hint that the filter, not the log, was wrong — so this returns
 * the distinct values present, and the servers they belong to.
 *
 * Call it before filtering, not after an empty answer.
 *
 * The server list is included even though servers are not a log column in the
 * useful sense: `server_id` is what `query-logs` filters on, and an id is not
 * something a caller can guess. Pairing each id with its slug and label is what
 * makes "show me the Fleet server's traffic" answerable in two calls.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities\ActivityLog
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities\ActivityLog;

use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\McpManager;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;

defined( 'ABSPATH' ) || exit;

/**
 * The distinct filter values present in the log.
 *
 * @since 0.4.8
 */
final class LogFilters {

	/**
	 * Ability name.
	 *
	 * @var string
	 */
	public const SLUG = 'mcp-manager/get-log-filters';

	/**
	 * Period scanned when the caller names none.
	 *
	 * @var int
	 */
	public const DEFAULT_DAYS = 30;

	/**
	 * Register the ability. Loader-wired from Main.php (A1).
	 *
	 * @since 0.4.8
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( self::SLUG ) ) {
			return;
		}

		wp_register_ability(
			self::SLUG,
			array(
				'label'               => __( 'List activity log filter values', 'acrossai-mcp-manager' ),
				'description'         => __(
					'The values this site\'s activity log actually contains for the columns it indexes — event type, status, connection type, how it was invoked, method, component category and fleet peer — plus every MCP server with its id, slug and label. Call this before filtering with mcp-manager/query-logs: those filters are exact matches, so a guessed value returns an empty result that looks like a quiet site rather than a wrong filter. Filters this does not enumerate (client_name, connector_slug, component_name, invoked_name, server_slug) are readable off the events themselves. Reads only; changes nothing.',
					'acrossai-mcp-manager'
				),
				'category'            => QueryLogs::CATEGORY,
				'meta'                => array(
					'acrossai' => array( 'tab_group' => McpManager::GROUP ),
					'mcp'      => array(
						// public:true is required — ToolsetExposureBridge
						// defaults a member's visibility to this flag, so a
						// private member is invisible to its own dispatcher.
						'public' => true,
						// 'tool', not 'resource'. A resource-typed ability is
						// injected into every server's resources list by
						// AbilityDiscovery::for_server(), which would advertise
						// these directly to clients and bypass the dispatcher
						// entirely. Tools come from the curated per-server set
						// instead, and these are not in that pool, so the type
						// leaves them reachable only through toolset/mcp-manager.
						'type'   => 'tool',
					),
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'days' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => self::DEFAULT_DAYS,
							/* translators: %d: default number of days. */
							'description' => sprintf( __( 'How far back to look for distinct values. Defaults to %d days. A value present only in older events will not be listed.', 'acrossai-mcp-manager' ), self::DEFAULT_DAYS ),
						),
					),
					'additionalProperties' => false,
					// See B59 — without this a zero-argument call is rejected
					// before the callback runs.
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'days'    => array( 'type' => 'integer' ),
						'filters' => array( 'type' => 'object' ),
						'servers' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'   => array( 'filters', 'servers' ),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Collect the values.
	 *
	 * @since  0.4.8
	 * @param  mixed $input Validated input; untyped because core may pass
	 *                      anything and the guard below is what makes it safe.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();
		$days  = isset( $input['days'] ) && is_numeric( $input['days'] )
			? max( 1, (int) $input['days'] )
			: self::DEFAULT_DAYS;

		return array(
			'days'    => $days,
			'filters' => Query::instance()->filter_options( $days ),
			'servers' => self::servers(),
			'note'    => __( 'Every filter on mcp-manager/query-logs is an exact match — pass one of these values verbatim rather than a fragment. This lists the columns the log indexes for filtering; client_name, connector_slug, component_name, invoked_name and server_slug are also filterable, and their values are visible on the events mcp-manager/query-logs returns.', 'acrossai-mcp-manager' ),
		);
	}

	/**
	 * Every MCP server, as id plus the names a person would recognise.
	 *
	 * Read from the server table rather than from distinct `server_id` values in
	 * the log, so a server that exists but has not been called yet is still
	 * offered. "Why is there no traffic for this server?" is a real question,
	 * and it cannot be asked about a server the caller was never told about.
	 *
	 * @since  0.4.8
	 * @return array<int, array<string, mixed>>
	 */
	private static function servers(): array {
		// 100, matching every other caller in this plugin. BerlinDB treats
		// `number` as a real LIMIT and 0 is not reliably "all", so this follows
		// the established convention rather than inventing an unbounded read.
		$rows = MCPServerQuery::instance()->query( array( 'number' => 100 ) );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();

		foreach ( $rows as $row ) {
			$id = (int) ( $row->id ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			$out[] = array(
				'server_id'   => $id,
				'server_slug' => (string) ( $row->server_slug ?? '' ),
				'label'       => (string) ( $row->server_name ?? '' ),
				'server_type' => (string) ( $row->server_type ?? '' ),
				'is_enabled'  => ! empty( $row->is_enabled ),
			);
		}

		return $out;
	}
}
