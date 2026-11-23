<?php
/**
 * Feature 086 — options-table health diagnostics (bytes only, never values).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Database
 * @since      0.0.32
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Database;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Aggregate signals on the options table: total rows, autoload bytes,
 * oversized autoload count, expired transient count, plus the top-N
 * autoloaded options by byte size. Never returns option values.
 */
class Audit_Options_Health extends Ability_Definition {

	private const DEFAULT_LIMIT           = 10;
	private const MAX_LIMIT               = 50;
	private const OVERSIZED_AUTOLOAD_BYTES = 262144; // 256 KB per-option threshold.
	private const AUTOLOAD_TOTAL_WARN_BYTES = 819200; // 800 KB combined threshold.

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/audit-options-health',
			'args' => array(
				'label'               => __( 'Audit Options Health', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return options-table diagnostics: option count, total value bytes, autoload bytes, oversized-autoload count, expired-transient count, and the top-N autoloaded options by byte size. Never returns option values.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_LIMIT,
							'default' => self::DEFAULT_LIMIT,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'                   => array( 'type' => 'boolean' ),
						'observed_at'               => array( 'type' => 'string' ),
						'option_count'              => array( 'type' => 'integer' ),
						'total_value_bytes'         => array( 'type' => 'integer' ),
						'autoload_count'            => array( 'type' => 'integer' ),
						'autoload_bytes'            => array( 'type' => 'integer' ),
						'oversized_autoload_count'  => array( 'type' => 'integer' ),
						'transient_row_count'       => array( 'type' => 'integer' ),
						'expired_transient_count'   => array( 'type' => 'integer' ),
						'limit'                     => array( 'type' => 'integer' ),
						'top_autoloaded_options'    => array( 'type' => 'array' ),
						'issue_count'               => array( 'type' => 'integer' ),
						'issues'                    => array( 'type' => 'array' ),
						'message'                   => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group' => 'database',
						'sub_group' => 'audit',
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$limit = (int) ( $input['limit'] ?? self::DEFAULT_LIMIT );
		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		global $wpdb;
		$table = $wpdb->options;
		$now   = time();

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS option_count,
					COALESCE(SUM(OCTET_LENGTH(option_value)), 0) AS total_value_bytes,
					COALESCE(SUM(CASE WHEN autoload IN ('yes','on','auto','auto-on') THEN 1 ELSE 0 END), 0) AS autoload_count,
					COALESCE(SUM(CASE WHEN autoload IN ('yes','on','auto','auto-on') THEN OCTET_LENGTH(option_value) ELSE 0 END), 0) AS autoload_bytes,
					COALESCE(SUM(CASE WHEN autoload IN ('yes','on','auto','auto-on') AND OCTET_LENGTH(option_value) > %d THEN 1 ELSE 0 END), 0) AS oversized_autoload_count,
					COALESCE(SUM(CASE WHEN option_name LIKE '\\_transient_timeout\\_%%' OR option_name LIKE '\\_site\\_transient_timeout\\_%%' THEN 1 ELSE 0 END), 0) AS transient_row_count,
					COALESCE(SUM(CASE WHEN (option_name LIKE '\\_transient_timeout\\_%%' OR option_name LIKE '\\_site\\_transient_timeout\\_%%') AND CAST(option_value AS UNSIGNED) < %d THEN 1 ELSE 0 END), 0) AS expired_transient_count
				FROM {$table}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->options.
				self::OVERSIZED_AUTOLOAD_BYTES,
				$now
			),
			ARRAY_A
		);

		if ( ! is_array( $totals ) ) {
			$totals = array();
		}

		$top = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, autoload, OCTET_LENGTH(option_value) AS value_bytes
				FROM {$table}
				WHERE autoload IN ('yes','on','auto','auto-on')
				ORDER BY OCTET_LENGTH(option_value) DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->options.
				$limit
			),
			ARRAY_A
		);

		$top_out = array();
		if ( is_array( $top ) ) {
			foreach ( $top as $row ) {
				$top_out[] = array(
					'option_name' => sanitize_text_field( (string) ( $row['option_name'] ?? '' ) ),
					'autoload'    => sanitize_text_field( (string) ( $row['autoload'] ?? '' ) ),
					'value_bytes' => (int) ( $row['value_bytes'] ?? 0 ),
				);
			}
		}

		$issues = array();
		$autoload_bytes = (int) ( $totals['autoload_bytes'] ?? 0 );
		$oversized      = (int) ( $totals['oversized_autoload_count'] ?? 0 );
		$expired        = (int) ( $totals['expired_transient_count'] ?? 0 );

		if ( $autoload_bytes > self::AUTOLOAD_TOTAL_WARN_BYTES ) {
			$issues[] = array(
				'code'     => 'autoload_bytes_high',
				'severity' => 'warning',
				'message'  => sprintf( 'Total autoload bytes (%d) exceed recommended threshold (%d).', $autoload_bytes, self::AUTOLOAD_TOTAL_WARN_BYTES ),
			);
		}
		if ( $oversized > 0 ) {
			$issues[] = array(
				'code'     => 'oversized_autoload_option',
				'severity' => 'warning',
				'message'  => sprintf( '%d autoloaded option(s) exceed %d bytes each.', $oversized, self::OVERSIZED_AUTOLOAD_BYTES ),
			);
		}
		if ( $expired > 0 ) {
			$issues[] = array(
				'code'     => 'expired_transients_present',
				'severity' => 'notice',
				'message'  => sprintf( '%d expired transient row(s) can be cleaned up.', $expired ),
			);
		}

		return array(
			'success'                   => true,
			'observed_at'               => gmdate( 'c', $now ),
			'option_count'              => (int) ( $totals['option_count'] ?? 0 ),
			'total_value_bytes'         => (int) ( $totals['total_value_bytes'] ?? 0 ),
			'autoload_count'            => (int) ( $totals['autoload_count'] ?? 0 ),
			'autoload_bytes'            => $autoload_bytes,
			'oversized_autoload_count'  => $oversized,
			'transient_row_count'       => (int) ( $totals['transient_row_count'] ?? 0 ),
			'expired_transient_count'   => $expired,
			'limit'                     => $limit,
			'top_autoloaded_options'    => $top_out,
			'issue_count'               => count( $issues ),
			'issues'                    => $issues,
			/* translators: %d: issue count */
			'message'                   => sprintf( __( 'Options health returned; %d issue(s).', 'acrossai-abilities-manager' ), count( $issues ) ),
		);
	}
}
