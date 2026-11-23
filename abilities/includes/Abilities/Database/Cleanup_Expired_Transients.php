<?php
/**
 * Feature 086 — bounded, dry-run-first cleanup of expired transient rows.
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
 * Delete expired transient timeout+value pairs in bounded batches. Dry-run
 * default; writes require both `dry_run=false` AND `confirm=true`. Never
 * returns transient names or values — counts only.
 */
class Cleanup_Expired_Transients extends Ability_Definition {

	private const DEFAULT_LIMIT = 100;
	private const MAX_LIMIT     = 500;

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/cleanup-expired-transients',
			'args' => array(
				'label'               => __( 'Cleanup Expired Transients', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete expired transient timeout+value pairs in a bounded batch (default 100, max 500). Dry-run first: live writes require both dry_run=false AND confirm=true. Never returns transient names or values.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_LIMIT,
							'default' => self::DEFAULT_LIMIT,
						),
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'confirm' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'                    => array( 'type' => 'boolean' ),
						'dry_run'                    => array( 'type' => 'boolean' ),
						'confirmed'                  => array( 'type' => 'boolean' ),
						'limit'                      => array( 'type' => 'integer' ),
						'expired_before'             => array( 'type' => 'integer' ),
						'selected_count'             => array( 'type' => 'integer' ),
						'deleted_transient_count'    => array( 'type' => 'integer' ),
						'deleted_timeout_count'      => array( 'type' => 'integer' ),
						'failed_count'               => array( 'type' => 'integer' ),
						'expired_after'              => array( 'type' => 'integer' ),
						'more_expired_may_remain'    => array( 'type' => 'boolean' ),
						'message'                    => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group' => 'cache',
						'sub_group' => 'safe-writes',
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
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
		$limit   = max( 1, min( self::MAX_LIMIT, (int) ( $input['limit'] ?? self::DEFAULT_LIMIT ) ) );
		$dry_run = array_key_exists( 'dry_run', $input ) ? (bool) $input['dry_run'] : true;
		$confirm = (bool) ( $input['confirm'] ?? false );

		global $wpdb;
		$now   = time();
		$table = $wpdb->options;

		$expired_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE (option_name LIKE '\\_transient_timeout\\_%%' OR option_name LIKE '\\_site\\_transient_timeout\\_%%')
				AND CAST(option_value AS UNSIGNED) < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->options.
				$now
			)
		);

		$timeout_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$table}
				WHERE (option_name LIKE '\\_transient_timeout\\_%%' OR option_name LIKE '\\_site\\_transient_timeout\\_%%')
				AND CAST(option_value AS UNSIGNED) < %d
				ORDER BY option_id ASC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->options.
				$now,
				$limit
			)
		);
		$timeout_rows = is_array( $timeout_rows ) ? array_map( 'strval', $timeout_rows ) : array();
		$selected     = count( $timeout_rows );

		if ( $dry_run || ! $confirm ) {
			return array(
				'success'                 => true,
				'dry_run'                 => true,
				'confirmed'               => $confirm,
				'limit'                   => $limit,
				'expired_before'          => $expired_before,
				'selected_count'          => $selected,
				'deleted_transient_count' => 0,
				'deleted_timeout_count'   => 0,
				'failed_count'            => 0,
				'expired_after'           => $expired_before,
				'more_expired_may_remain' => $expired_before > $selected,
				/* translators: %d: selected row count */
				'message'                 => sprintf( __( 'Dry run: %d expired transient row(s) selected. Pass dry_run=false and confirm=true to delete.', 'acrossai-abilities-manager' ), $selected ),
			);
		}

		$deleted_timeout   = 0;
		$deleted_transient = 0;
		$failed            = 0;

		foreach ( $timeout_rows as $timeout_name ) {
			if ( 0 === strpos( $timeout_name, '_site_transient_timeout_' ) ) {
				$key   = substr( $timeout_name, strlen( '_site_transient_timeout_' ) );
				$value = '_site_transient_' . $key;
			} elseif ( 0 === strpos( $timeout_name, '_transient_timeout_' ) ) {
				$key   = substr( $timeout_name, strlen( '_transient_timeout_' ) );
				$value = '_transient_' . $key;
			} else {
				++$failed;
				continue;
			}

			if ( delete_option( $timeout_name ) ) {
				++$deleted_timeout;
			}
			if ( delete_option( $value ) ) {
				++$deleted_transient;
			}
		}

		$expired_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE (option_name LIKE '\\_transient_timeout\\_%%' OR option_name LIKE '\\_site\\_transient_timeout\\_%%')
				AND CAST(option_value AS UNSIGNED) < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->options.
				time()
			)
		);

		return array(
			'success'                 => true,
			'dry_run'                 => false,
			'confirmed'               => true,
			'limit'                   => $limit,
			'expired_before'          => $expired_before,
			'selected_count'          => $selected,
			'deleted_transient_count' => $deleted_transient,
			'deleted_timeout_count'   => $deleted_timeout,
			'failed_count'            => $failed,
			'expired_after'           => $expired_after,
			'more_expired_may_remain' => $expired_after > 0,
			/* translators: 1: deleted timeout count, 2: deleted transient count */
			'message'                 => sprintf( __( 'Deleted %1$d timeout row(s) and %2$d transient row(s).', 'acrossai-abilities-manager' ), $deleted_timeout, $deleted_transient ),
		);
	}
}
