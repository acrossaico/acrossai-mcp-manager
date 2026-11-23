<?php
/**
 * Feature 069 — Rank Math maintenance tool catalogue and dispatch.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only dispatcher for Rank Math's database/maintenance tools.
 *
 * CRITICAL (research F3): dispatch does NOT go through
 * apply_filters( 'rank_math/tools/{id}' ). Database_Tools::hooks() only registers
 * those filters when Helper::is_rest() AND the request URI contains 'toolsAction'
 * (class-database-tools.php:45), and its constructor early-returns entirely unless
 * advanced mode is on. From an ability context the filter has no listener and
 * apply_filters() returns its literal default, the string 'Something went wrong.'
 *
 * So each tool is dispatched to a concrete [object, method] pair. The handlers are
 * INSTANCE methods, so the objects are memoised per request.
 */
final class Maintenance_Tools {

	/**
	 * Tool id => [ class, method, required module, async ].
	 *
	 * async marks tools whose work continues after the response returns — via
	 * Action Scheduler or a background job — so a caller does not read success as
	 * completion and act on stale data.
	 */
	private const TOOLS = array(
		'clear_transients'                => array( 'tools', 'clear_transients', '', false ),
		'clear_seo_analysis'              => array( 'tools', 'clear_seo_analysis', 'seo-analysis', false ),
		'delete_links'                    => array( 'tools', 'delete_links', '', false ),
		'delete_log'                      => array( 'tools', 'delete_log', '404-monitor', false ),
		'delete_redirections'             => array( 'tools', 'delete_redirections', 'redirections', false ),
		'recreate_tables'                 => array( 'tools', 'recreate_tables', '', true ),
		'recreate_actionscheduler_tables' => array( 'tools', 'recreate_actionscheduler_tables', '', false ),
		'yoast_blocks'                    => array( 'tools', 'yoast_blocks', '', true ),
		'aioseo_blocks'                   => array( 'tools', 'aioseo_blocks', '', true ),
		'analytics_clear_caches'          => array( 'analytics', 'analytics_clear_caches', 'analytics', false ),
		'analytics_reindex_posts'         => array( 'analytics', 'analytics_reindex_posts', 'analytics', true ),
		'analytics_fix_collations'        => array( 'analytics', 'analytics_fix_collations', 'analytics', false ),
	);

	/**
	 * Action Scheduler group Rank Math queues background work into.
	 */
	private const POLL_GROUP = 'rank-math';

	/**
	 * Memoised handler objects, keyed 'tools' | 'analytics'.
	 *
	 * @var array<string,object|null>
	 */
	private static array $handlers = array();

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Valid tool ids. This is the input enum for run-maintenance-tool; the two must
	 * not drift, which Test_Rank_Math_Maintenance_Tools asserts.
	 *
	 * @return string[]
	 */
	public static function tool_ids(): array {
		return array_keys( self::TOOLS );
	}

	/**
	 * Whether a tool's work continues after the response.
	 *
	 * @param string $tool Tool id.
	 * @return bool
	 */
	public static function is_async( string $tool ): bool {
		return isset( self::TOOLS[ $tool ] ) && true === self::TOOLS[ $tool ][3];
	}

	/**
	 * The module a tool requires, or '' when none.
	 *
	 * @param string $tool Tool id.
	 * @return string
	 */
	public static function required_module( string $tool ): string {
		return isset( self::TOOLS[ $tool ] ) ? (string) self::TOOLS[ $tool ][2] : '';
	}

	/**
	 * The tool catalogue with runnability per tool.
	 *
	 * Rank Math's own get_tools() is private and module-conditional, so the public
	 * get_json_data() is used for its titles/descriptions where available and our
	 * own table supplies the rest.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function catalogue(): array {
		$described = array();
		if ( class_exists( '\RankMath\Tools\Database_Tools' ) && method_exists( '\RankMath\Tools\Database_Tools', 'get_json_data' ) ) {
			$data = \RankMath\Tools\Database_Tools::get_json_data();
			if ( is_array( $data ) ) {
				// Rank Math nests the tool list under a key that has varied between
				// versions, so accept either shape rather than assume one.
				$described = isset( $data['tools'] ) && is_array( $data['tools'] ) ? $data['tools'] : $data;
			}
		}

		$out = array();
		foreach ( self::TOOLS as $id => $spec ) {
			$module    = (string) $spec[2];
			$available = '' === $module || ( class_exists( '\RankMath\Helper' ) && \RankMath\Helper::is_module_active( $module ) );
			$meta      = isset( $described[ $id ] ) && is_array( $described[ $id ] ) ? $described[ $id ] : array();

			$out[] = array(
				'id'              => $id,
				'title'           => isset( $meta['title'] ) ? (string) $meta['title'] : $id,
				'description'     => isset( $meta['description'] ) ? (string) $meta['description'] : '',
				'confirm_text'    => isset( $meta['confirm_text'] ) ? (string) $meta['confirm_text'] : '',
				'required_module' => $module,
				'runnable'        => $available,
				'async'           => true === $spec[3],
			);
		}

		return $out;
	}

	/**
	 * Run one tool.
	 *
	 * @param string $tool Tool id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function dispatch( string $tool ) {
		if ( ! isset( self::TOOLS[ $tool ] ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: submitted tool id, 2: comma-separated list of valid tool ids */
					__( 'Unknown maintenance tool "%1$s". Valid tools: %2$s.', 'acrossai-abilities-manager' ),
					$tool,
					implode( ', ', self::tool_ids() )
				)
			);
		}

		list( $group, $method, $module, $async ) = self::TOOLS[ $tool ];

		if ( '' !== $module && ( ! class_exists( '\RankMath\Helper' ) || ! \RankMath\Helper::is_module_active( $module ) ) ) {
			return new WP_Error(
				'tool_unavailable',
				sprintf(
					/* translators: 1: tool id, 2: Rank Math module slug */
					__( 'The "%1$s" tool needs the Rank Math "%2$s" module, which is not active. Enable it with rank-math/set-module-status.', 'acrossai-abilities-manager' ),
					$tool,
					$module
				)
			);
		}

		$handler = self::handler( $group );
		if ( null === $handler || ! method_exists( $handler, $method ) ) {
			return new WP_Error(
				'tool_unavailable',
				sprintf(
					/* translators: %s: tool id */
					__( 'This Rank Math build does not provide the "%s" tool.', 'acrossai-abilities-manager' ),
					$tool
				)
			);
		}

		$raw = $handler->$method();

		return self::normalize_result( $tool, $raw, (bool) $async );
	}

	/**
	 * Normalise a handler return into the ability payload.
	 *
	 * Rank Math's handlers are inconsistent: some return a plain status string, some
	 * return [ 'status' => 'error', 'message' => … ]. Both are folded into one shape
	 * so a caller never has to branch on which tool it ran.
	 *
	 * @param string $tool  Tool id.
	 * @param mixed  $raw   Handler return value.
	 * @param bool   $async Whether work continues after the response.
	 * @return array<string,mixed>
	 */
	public static function normalize_result( string $tool, $raw, bool $async ): array {
		$completed = true;
		$message   = '';

		if ( is_array( $raw ) ) {
			$status    = isset( $raw['status'] ) ? (string) $raw['status'] : '';
			$completed = 'error' !== $status;
			$message   = isset( $raw['message'] ) ? (string) $raw['message'] : '';
		} elseif ( is_string( $raw ) ) {
			$message = $raw;
		} elseif ( is_bool( $raw ) ) {
			$completed = $raw;
		}

		$result = array(
			'tool'         => $tool,
			// Async work has been STARTED, not completed — say so explicitly.
			'completed'    => $async ? false : $completed,
			'async'        => $async,
			'tool_message' => $message,
		);

		if ( $async ) {
			$result['poll_hint'] = sprintf(
				/* translators: %s: Action Scheduler group name */
				__( 'Work continues in the background. Watch the "%s" Action Scheduler group, or re-read the affected data, to confirm completion.', 'acrossai-abilities-manager' ),
				self::POLL_GROUP
			);
		}

		return $result;
	}

	/**
	 * Memoised handler object for a dispatch group.
	 *
	 * Constructing Database_Tools instantiates the same singletons its admin screen
	 * does, so it is built once per request rather than per dispatch.
	 *
	 * @param string $group 'tools' or 'analytics'.
	 * @return object|null
	 */
	private static function handler( string $group ): ?object {
		if ( array_key_exists( $group, self::$handlers ) ) {
			return self::$handlers[ $group ];
		}

		$class = 'analytics' === $group
			? '\RankMath\Analytics\Analytics_Common'
			: '\RankMath\Tools\Database_Tools';

		self::$handlers[ $group ] = class_exists( $class ) ? new $class() : null;

		return self::$handlers[ $group ];
	}
}
