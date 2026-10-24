<?php
/**
 * WordPress personal-data exporter and eraser for the activity log (F096).
 *
 * This feature is the first thing in the plugin to store a source address, and
 * the plugin had no privacy plumbing at all before it. Clarification Q1 chose
 * to keep the FULL address — the alternative on offer was an anonymised one —
 * explicitly on the condition that these two callbacks shipped alongside it.
 * They are in User Story 1, not deferred to the governance story, because
 * deferring them would keep the cost and drop the mitigation (SEC-T02).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Observability
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Observability;

use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless registrar. Hooks are wired in Main.php per A1.
 */
final class PersonalData {

	/** Rows per export/erase page. WordPress calls back until `done` is true. */
	private const PAGE_SIZE = 200;

	/**
	 * Register the exporter.
	 *
	 * @param array<string, array<string, mixed>>|mixed $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>|mixed
	 */
	public static function register_exporter( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			return $exporters;
		}

		$exporters['acrossai-mcp-activity-log'] = array(
			'exporter_friendly_name' => __( 'MCP Activity Log', 'acrossai-mcp-manager' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string, array<string, mixed>>|mixed $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>|mixed
	 */
	public static function register_eraser( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			return $erasers;
		}

		$erasers['acrossai-mcp-activity-log'] = array(
			'eraser_friendly_name' => __( 'MCP Activity Log', 'acrossai-mcp-manager' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export one page of a user's recorded activity.
	 *
	 * Source addresses ARE included — SC-C12. An export that silently omitted
	 * them would give the subject an incomplete picture of what is held.
	 *
	 * @param string $email_address Subject's email.
	 * @param int    $page          1-indexed page number.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', (string) $email_address );
		$page = max( 1, (int) $page );

		if ( ! $user instanceof \WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$rows = self::rows_for_user( (int) $user->ID, $page );
		$data = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'          => 'acrossai-mcp-activity-log',
				'group_label'       => __( 'MCP Activity Log', 'acrossai-mcp-manager' ),
				'group_description' => __(
					'A record of requests AI clients made to this site\'s MCP servers on your behalf. Each entry includes the source network address the request came from and the client application that sent it. Erasing this data clears your identity, address and client details from these entries while keeping the events themselves as a security record.',
					'acrossai-mcp-manager'
				),
				'item_id'           => 'acrossai-mcp-activity-' . (int) $row->id,
				'data'              => array(
					array(
						'name'  => __( 'When (UTC)', 'acrossai-mcp-manager' ),
						'value' => (string) $row->created_at,
					),
					array(
						'name'  => __( 'What ran', 'acrossai-mcp-manager' ),
						'value' => (string) $row->component_name,
					),
					array(
						'name'  => __( 'Method', 'acrossai-mcp-manager' ),
						'value' => (string) $row->method,
					),
					array(
						'name'  => __( 'Outcome', 'acrossai-mcp-manager' ),
						'value' => (string) $row->status,
					),
					array(
						'name'  => __( 'Server', 'acrossai-mcp-manager' ),
						'value' => (string) $row->server_slug,
					),
					array(
						'name'  => __( 'How the client connected', 'acrossai-mcp-manager' ),
						'value' => (string) $row->connection_type,
					),
					array(
						'name'  => __( 'Client application', 'acrossai-mcp-manager' ),
						'value' => trim( $row->client_name . ' ' . $row->client_version ),
					),
					array(
						// SC-C12: disclosed explicitly, and LABELLED as personal
						// data. The full address was kept (Clarification Q1) only
						// on condition this path existed; an export that listed
						// it without saying what it is would satisfy the letter
						// of that and not the point.
						'name'  => __( 'Source address (personal data)', 'acrossai-mcp-manager' ),
						'value' => (string) $row->ip_address,
					),
					array(
						'name'  => __( 'Client user agent', 'acrossai-mcp-manager' ),
						'value' => (string) $row->user_agent,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * De-identify one page of a user's recorded activity.
	 *
	 * **This does not delete events, and that is the point.** FR-038 permits
	 * only de-identification: WordPress's eraser runs on an unprivileged
	 * subject request, so an eraser that deleted rows would hand anyone able to
	 * file one a way to destroy audit evidence — no `manage_options` required.
	 * Clearing the identifiers satisfies the request completely, because the
	 * subject is no longer identifiable, while the security record survives.
	 *
	 * Recorded as decision D60 in durable memory, so the next eraser in this
	 * codebase does not reach for `$wpdb->delete()` because "erase" sounds like
	 * it should.
	 *
	 * @param string $email_address Subject's email.
	 * @param int    $page          1-indexed page number.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase( $email_address, $page = 1 ) {

		$user = get_user_by( 'email', (string) $email_address );
		$page = max( 1, (int) $page );

		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		if ( ! $user instanceof \WP_User ) {
			return $result;
		}

		$rows = self::rows_for_user( (int) $user->ID, $page );

		if ( empty( $rows ) ) {
			return $result;
		}

		// Through the Query layer, one row at a time, NOT a single raw UPDATE.
		//
		// A bulk `$wpdb->query( "UPDATE ..." )` is one statement instead of N,
		// but it bypasses BerlinDB's object cache — whose invalidation methods
		// are all private — so cached reads keep serving the PRE-ERASURE values.
		// A privacy eraser that leaves the personal data readable from cache is
		// worse than slow. Caught by test: the subject's export still returned
		// their row after erasure.
		//
		// The cost is acceptable because erasure is rare, operator-initiated,
		// and WordPress already pages it.
		$query   = \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query::instance();
		$updated = 0;

		foreach ( $rows as $row ) {
			$changed = $query->update_item(
				(int) $row->id,
				array(
					'user_id'        => 0,
					'ip_address'     => '',
					'user_agent'     => '',
					'client_name'    => '',
					'client_version' => '',
				)
			);

			if ( $changed ) {
				++$updated;
			}
		}

		if ( $updated > 0 ) {
			// `items_removed` is WordPress's vocabulary for "we acted", and we
			// did: the personal data is gone. The retained flag plus the message
			// is how the operator learns the events themselves survive, so they
			// can explain it to the person who asked.
			$result['items_removed']  = true;
			$result['items_retained'] = true;
			$result['messages'][]     = __(
				'MCP activity events for this person were de-identified: the acting identity, source address, user agent and client name were cleared. The events themselves are retained as a security record, with nothing left that identifies this person.',
				'acrossai-mcp-manager'
			);
		}

		// Always page again: de-identified rows no longer match the user filter,
		// so a full page now means there may be more behind it.
		$result['done'] = count( $rows ) < self::PAGE_SIZE;

		return $result;
	}

	/**
	 * One page of a user's events, newest first.
	 *
	 * @param int $user_id Subject.
	 * @param int $page    1-indexed.
	 * @return array<int, \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row>
	 */
	private static function rows_for_user( int $user_id, int $page ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$rows = \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query::instance()->query(
			array(
				'user_id' => $user_id,
				'number'  => self::PAGE_SIZE,
				'offset'  => ( $page - 1 ) * self::PAGE_SIZE,
				'orderby' => 'id',
				'order'   => 'DESC',
			)
		);

		// Narrow for real: BerlinDB's query() can return ids rather than row
		// objects depending on args, and handing those to the exporter would
		// produce an export full of property-access errors.
		$out = array();

		foreach ( (array) $rows as $row ) {
			if ( $row instanceof \AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Row ) {
				$out[] = $row;
			}
		}

		return $out;
	}
}
