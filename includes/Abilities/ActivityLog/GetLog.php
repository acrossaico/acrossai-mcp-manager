<?php
/**
 * `mcp-manager/get-log` — one event, with what happened inside it.
 *
 * The log is a two-level tree: a `request` row for the call that arrived, and
 * an `ability_run` child for each ability it ran. Reading a request without its
 * children answers "something failed" and nothing more; the children are where
 * the failure actually is.
 *
 * So this returns the event and assembles its children in one call, rather than
 * making a caller notice the shape, work out that `parent_id` exists, and issue
 * a second query. Asking for a child returns that child with its parent
 * attached, for the same reason in reverse.
 *
 * Previews are ON by default here, the opposite of {@see QueryLogs}. That is
 * deliberate: this ability is reached when someone is looking at ONE event, and
 * the input and output are usually the reason they are looking. The cost
 * argument that keeps them off a 200-row list does not apply to a single row.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities\ActivityLog
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities\ActivityLog;

use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\McpManager;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One activity event and its immediate relatives.
 *
 * @since 0.4.8
 */
final class GetLog {

	/**
	 * Ability name.
	 *
	 * @var string
	 */
	public const SLUG = 'mcp-manager/get-log';

	/**
	 * Children returned with a request before the list is cut.
	 *
	 * A pathological request can run hundreds of abilities. The count is always
	 * reported honestly, so a caller can tell a truncated list from a short one.
	 *
	 * @var int
	 */
	public const MAX_CHILDREN = 200;

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
				'label'               => __( 'Get one activity event', 'acrossai-mcp-manager' ),
				'description'         => __(
					'Read one activity log event in full, with the ability runs that happened inside it. Give it the id of a request and you get that request plus its children; give it the id of an ability run and you get that run plus the request it belonged to. Input and output previews are included by default, because looking at one event is usually about what it sent and received. Use mcp-manager/query-logs to find an id first. Reads only; changes nothing.',
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
						'id'               => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'The event id, from mcp-manager/query-logs.', 'acrossai-mcp-manager' ),
						),
						'include_previews' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Include the recorded input and output previews. On by default here; turn it off if you only need timings and status.', 'acrossai-mcp-manager' ),
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'event'          => array( 'type' => 'object' ),
						'children'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'child_count'    => array( 'type' => 'integer' ),
						'children_shown' => array( 'type' => 'integer' ),
						'parent'         => array( 'type' => array( 'object', 'null' ) ),
					),
					'required'   => array( 'event' ),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Fetch the event.
	 *
	 * @since  0.4.8
	 * @param  mixed $input Validated input; untyped because core may pass
	 *                      anything and the guard below is what makes it safe.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;

		if ( $id <= 0 ) {
			return new WP_Error(
				'acrossai_mcp_invalid_log_id',
				__( 'Give the id of an event to read. Use mcp-manager/query-logs to find one.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		$previews = ! isset( $input['include_previews'] ) || ! empty( $input['include_previews'] );
		$query    = Query::instance();

		$row = self::find( $query, $id );

		if ( null === $row ) {
			return new WP_Error(
				'acrossai_mcp_log_not_found',
				sprintf(
					/* translators: %d: event id. */
					__( 'No activity event with id %d. It may have been pruned by the log retention settings.', 'acrossai-mcp-manager' ),
					$id
				),
				array( 'status' => 404 )
			);
		}

		$out = array(
			'event'          => QueryLogs::shape( $row, $previews ),
			'children'       => array(),
			'child_count'    => 0,
			'children_shown' => 0,
			'parent'         => null,
		);

		// A child names its parent; a parent is found by asking for children.
		// Doing both from one entry point is the whole point of this ability.
		if ( $row->parent_id > 0 ) {
			$parent = self::find( $query, (int) $row->parent_id );

			if ( null !== $parent ) {
				$out['parent'] = QueryLogs::shape( $parent, $previews );
			}

			return $out;
		}

		$out['child_count'] = $query->count_children( $id );
		$children           = $query->children( $id, self::MAX_CHILDREN );

		foreach ( $children as $child ) {
			$out['children'][] = QueryLogs::shape( $child, $previews );
		}

		$out['children_shown'] = count( $out['children'] );

		if ( $out['child_count'] > $out['children_shown'] ) {
			$out['note'] = sprintf(
				/* translators: 1: children shown, 2: children in total. */
				__( 'Showing %1$d of %2$d ability runs. Call mcp-manager/query-logs with parent_id set to this event to page through the rest.', 'acrossai-mcp-manager' ),
				$out['children_shown'],
				$out['child_count']
			);
		}

		return $out;
	}

	/**
	 * One row by id.
	 *
	 * Reuses `page_by_cursor()` rather than adding a `find()` to `Query`: the id
	 * is an allow-listed column filter, so a one-row page is an exact lookup on
	 * the primary key and there is no second code path to keep correct.
	 *
	 * @since  0.4.8
	 * @param  Query $query Activity log query service.
	 * @param  int   $id    Event id.
	 * @return Row|null
	 */
	private static function find( Query $query, int $id ): ?Row {
		$page = $query->page_by_cursor( array( 'id' => $id ), 1 );
		return $page['rows'][0] ?? null;
	}
}
