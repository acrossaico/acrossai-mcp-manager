<?php
/**
 * `mcp-manager/summarize-logs` — the period totals, without the rows.
 *
 * "How busy was this site yesterday, and did anything fail?" is answerable from
 * five numbers. Answering it by fetching 200 events and counting them spends a
 * model's context on arithmetic the database already did — and gets the wrong
 * answer whenever the period holds more events than the page returned.
 *
 * So this is the call to make first. {@see QueryLogs} is for after it, once the
 * summary says which day, which server, or which status is worth reading.
 *
 * Backed by `Query::summary()`, which is the same method the Logs screen's stat
 * boxes use and is transient-cached — so asking repeatedly is cheap, and the
 * numbers here always match the numbers on screen.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities\ActivityLog
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities\ActivityLog;

use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\McpManager;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Period totals for the activity log.
 *
 * @since 0.4.8
 */
final class SummarizeLogs {

	/**
	 * Ability name.
	 *
	 * @var string
	 */
	public const SLUG = 'mcp-manager/summarize-logs';

	/**
	 * Period summarised when the caller names none.
	 *
	 * @var int
	 */
	public const DEFAULT_DAYS = 1;

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
				'label'               => __( 'Summarize activity log', 'acrossai-mcp-manager' ),
				'description'         => __(
					'Totals for a period of this site\'s MCP activity: how many requests arrived, the average duration, the share that succeeded, how many distinct clients called, and how much context was returned. Scope it to one server with server_id or leave it out for every server. Call this before mcp-manager/query-logs — it answers "how busy, and did anything fail" from five numbers instead of a page of rows, and its counts cover the whole period rather than only the rows a page returned. Reads only; changes nothing.',
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
						'days'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => self::DEFAULT_DAYS,
							/* translators: %d: default number of days. */
							'description' => sprintf( __( 'How many days back to summarise. Defaults to %d.', 'acrossai-mcp-manager' ), self::DEFAULT_DAYS ),
						),
						'server_id' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Scope to one MCP server by id. Omit, or pass 0, to summarise every server together.', 'acrossai-mcp-manager' ),
						),
					),
					'additionalProperties' => false,
					// See B59 — a zero-argument call needs this.
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'days'      => array( 'type' => 'integer' ),
						'server_id' => array( 'type' => 'integer' ),
						'summary'   => array( 'type' => 'object' ),
					),
					'required'   => array( 'summary' ),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Fetch the totals.
	 *
	 * @since  0.4.8
	 * @param  mixed $input Validated input; untyped because core may pass
	 *                      anything and the guard below is what makes it safe.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = array() ): array {
		$input = is_array( $input ) ? $input : array();

		$days = isset( $input['days'] ) && is_numeric( $input['days'] )
			? max( 1, (int) $input['days'] )
			: self::DEFAULT_DAYS;

		$server_id = isset( $input['server_id'] ) ? max( 0, (int) $input['server_id'] ) : 0;

		return array(
			'days'      => $days,
			'server_id' => $server_id,
			'summary'   => Query::instance()->summary( $days, $server_id ),
		);
	}
}
