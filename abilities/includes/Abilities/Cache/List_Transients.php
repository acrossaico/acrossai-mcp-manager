<?php
/**
 * Feature 064 — Enumerate transients stored on the site.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Cache
 * @since      0.0.23
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Cache;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Enumerate transients (blog- and site-scope) with expiry metadata.
 *
 * WP core provides no `list_transients()` helper — this ability queries
 * `$wpdb->options` directly via `$wpdb->prepare()` with an escaped LIKE
 * pattern. Companion `_transient_timeout_*` rows are filtered out of the
 * returned set but joined in as `expires_at` on the parent row.
 */
class List_Transients extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cache/list-transients',
			'args' => array(
				'label'               => __( 'List Transients', 'acrossai-abilities-manager' ),
				'description'         => __( 'Enumerate every transient (or site-transient) stored on the site with expiry metadata. Supports substring search, blog/site-scope filter, include-expired toggle, and pagination.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cache',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'          => array( 'type' => 'string' ),
						'limit'           => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 500,
							'default' => 100,
						),
						'offset'          => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
						'site_only'       => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'include_expired' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'transients' => array( 'type' => 'array' ),
						'count'      => array( 'type' => 'integer' ),
						'total'      => array( 'type' => 'integer' ),
						'message'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group' => 'cache',
						'sub_group' => 'cache',
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		global $wpdb;

		$search          = sanitize_text_field( (string) ( $input['search'] ?? '' ) );
		$limit           = isset( $input['limit'] ) ? max( 1, min( 500, (int) $input['limit'] ) ) : 100;
		$offset          = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;
		$site_only       = ! empty( $input['site_only'] );
		$include_expired = ! isset( $input['include_expired'] ) || ! empty( $input['include_expired'] );

		$blog_like = $wpdb->esc_like( '_transient_' ) . '%';
		$site_like = $wpdb->esc_like( '_site_transient_' ) . '%';

		// Build WHERE clause. We deliberately over-fetch (LIMIT * 2) because
		// we still need to filter out timeout companion rows in PHP.
		$where_clauses = array();
		$where_args    = array();

		if ( $site_only ) {
			$where_clauses[] = 'option_name LIKE %s';
			$where_args[]    = $site_like;
		} else {
			$where_clauses[] = '(option_name LIKE %s OR option_name LIKE %s)';
			$where_args[]    = $blog_like;
			$where_args[]    = $site_like;
		}

		if ( '' !== $search ) {
			$where_clauses[] = 'option_name LIKE %s';
			$where_args[]    = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Fetch all candidate rows (timeout + value together) so we can join
		// expiry in PHP without a second SQL round-trip per transient.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE {$where_sql} ORDER BY option_name",
				$where_args
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $all_rows ) ) {
			$all_rows = array();
		}

		// Index timeout rows keyed by the transient name they gate.
		$timeouts = array();
		foreach ( $all_rows as $row ) {
			$name = (string) $row['option_name'];
			if ( str_starts_with( $name, '_site_transient_timeout_' ) ) {
				$timeouts[ substr( $name, strlen( '_site_transient_timeout_' ) ) . '::site' ] = (int) $row['option_value'];
			} elseif ( str_starts_with( $name, '_transient_timeout_' ) ) {
				$timeouts[ substr( $name, strlen( '_transient_timeout_' ) ) . '::blog' ] = (int) $row['option_value'];
			}
		}

		$now         = time();
		$transients  = array();
		foreach ( $all_rows as $row ) {
			$name = (string) $row['option_name'];

			// Skip companion timeout rows in the returned set.
			if ( str_starts_with( $name, '_site_transient_timeout_' ) || str_starts_with( $name, '_transient_timeout_' ) ) {
				continue;
			}

			if ( str_starts_with( $name, '_site_transient_' ) ) {
				$transient_name = substr( $name, strlen( '_site_transient_' ) );
				$is_site        = true;
				$timeout        = $timeouts[ $transient_name . '::site' ] ?? 0;
			} elseif ( str_starts_with( $name, '_transient_' ) ) {
				$transient_name = substr( $name, strlen( '_transient_' ) );
				$is_site        = false;
				$timeout        = $timeouts[ $transient_name . '::blog' ] ?? 0;
			} else {
				continue;
			}

			$is_expired = $timeout > 0 && $timeout < $now;
			if ( ! $include_expired && $is_expired ) {
				continue;
			}

			$transients[] = array(
				'name'       => $transient_name,
				'expires_at' => $timeout > 0 ? $timeout : null,
				'is_site'    => $is_site,
				'is_expired' => $is_expired,
			);
		}

		$total = count( $transients );

		// Apply pagination in PHP (dataset already bounded by WHERE clause).
		$page = array_slice( $transients, $offset, $limit );

		return array(
			'success'    => true,
			'transients' => $page,
			'count'      => count( $page ),
			'total'      => $total,
			'message'    => sprintf(
				/* translators: 1: page size, 2: total */
				__( 'Returned %1$d of %2$d transient(s).', 'acrossai-abilities-manager' ),
				count( $page ),
				$total
			),
		);
	}
}
