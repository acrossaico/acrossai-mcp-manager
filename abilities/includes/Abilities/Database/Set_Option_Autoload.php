<?php
/**
 * Feature 086 — bounded, value-preserving autoload toggle for options.
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
 * Toggle the autoload flag on up to 25 explicit non-transient option names.
 * Never reads or writes option values. Dry-run first; live writes require
 * both `dry_run=false` AND `confirm=true`. Postcondition verified.
 */
class Set_Option_Autoload extends Ability_Definition {

	private const MAX_NAMES  = 25;
	private const NAME_LIMIT = 191; // WordPress option_name column length.

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'database/set-option-autoload',
			'args' => array(
				'label'               => __( 'Set Option Autoload', 'acrossai-abilities-manager' ),
				'description'         => __( 'Toggle the autoload flag on up to 25 explicit option names. Rejects transient names and names longer than 191 chars. Never reads or writes option values. Dry-run default; live writes require dry_run=false AND confirm=true. Postcondition verified.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-database',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'option_names' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'minItems' => 1,
							'maxItems' => self::MAX_NAMES,
						),
						'autoload'     => array( 'type' => 'boolean' ),
						'dry_run'      => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'confirm'      => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'option_names', 'autoload' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'              => array( 'type' => 'boolean' ),
						'dry_run'              => array( 'type' => 'boolean' ),
						'confirmed'            => array( 'type' => 'boolean' ),
						'target_autoload'      => array( 'type' => 'string' ),
						'option_names'         => array( 'type' => 'array' ),
						'invalid_option_names' => array( 'type' => 'array' ),
						'requested_count'      => array( 'type' => 'integer' ),
						'planned_count'        => array( 'type' => 'integer' ),
						'changed_count'        => array( 'type' => 'integer' ),
						'unchanged_count'      => array( 'type' => 'integer' ),
						'missing_count'        => array( 'type' => 'integer' ),
						'failed_count'         => array( 'type' => 'integer' ),
						'results'              => array( 'type' => 'array' ),
						'message'              => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group' => 'database',
						'sub_group' => 'safe-writes',
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
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
		$autoload = (bool) ( $input['autoload'] ?? false );
		$dry_run  = array_key_exists( 'dry_run', $input ) ? (bool) $input['dry_run'] : true;
		$confirm  = (bool) ( $input['confirm'] ?? false );
		$target   = $autoload ? 'yes' : 'no';

		list( $valid, $invalid ) = $this->partition_names( $input['option_names'] ?? array() );

		global $wpdb;
		$table = $wpdb->options;

		$results       = array();
		$planned_count = 0;
		$changed_count = 0;
		$unchanged     = 0;
		$missing       = 0;
		$failed        = 0;

		foreach ( $valid as $name ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT autoload, OCTET_LENGTH(option_value) AS value_bytes
					FROM {$table} WHERE option_name = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->options.
					$name
				),
				ARRAY_A
			);

			if ( ! is_array( $row ) ) {
				++$missing;
				$results[] = array(
					'option_name'     => $name,
					'exists'          => false,
					'value_bytes'     => 0,
					'before_autoload' => '',
					'after_autoload'  => '',
					'planned'         => false,
					'changed'         => false,
					'success'         => true,
					'error_code'      => 'missing',
				);
				continue;
			}

			$before      = (string) $row['autoload'];
			$needs_change = ( $autoload && ! in_array( $before, array( 'yes', 'on', 'auto', 'auto-on' ), true ) )
				|| ( ! $autoload && in_array( $before, array( 'yes', 'on', 'auto', 'auto-on' ), true ) );
			++$planned_count;

			if ( ! $needs_change ) {
				++$unchanged;
				$results[] = array(
					'option_name'     => $name,
					'exists'          => true,
					'value_bytes'     => (int) $row['value_bytes'],
					'before_autoload' => $before,
					'after_autoload'  => $before,
					'planned'         => true,
					'changed'         => false,
					'success'         => true,
					'error_code'      => '',
				);
				continue;
			}

			if ( $dry_run || ! $confirm ) {
				$results[] = array(
					'option_name'     => $name,
					'exists'          => true,
					'value_bytes'     => (int) $row['value_bytes'],
					'before_autoload' => $before,
					'after_autoload'  => $before,
					'planned'         => true,
					'changed'         => false,
					'success'         => true,
					'error_code'      => 'dry_run',
				);
				continue;
			}

			$ok = $wpdb->update( $table, array( 'autoload' => $target ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) );
			$after = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT autoload FROM {$table} WHERE option_name = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->options.
					$name
				)
			);
			$was_changed = ( $after !== $before );
			if ( false === $ok && ! $was_changed ) {
				++$failed;
				$results[] = array(
					'option_name'     => $name,
					'exists'          => true,
					'value_bytes'     => (int) $row['value_bytes'],
					'before_autoload' => $before,
					'after_autoload'  => $after,
					'planned'         => true,
					'changed'         => false,
					'success'         => false,
					'error_code'      => 'update_failed',
				);
				continue;
			}
			if ( $was_changed ) {
				++$changed_count;
			} else {
				++$unchanged;
			}
			$results[] = array(
				'option_name'     => $name,
				'exists'          => true,
				'value_bytes'     => (int) $row['value_bytes'],
				'before_autoload' => $before,
				'after_autoload'  => $after,
				'planned'         => true,
				'changed'         => $was_changed,
				'success'         => true,
				'error_code'      => '',
			);

			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $name, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
		}

		$live = ! $dry_run && $confirm;

		return array(
			'success'              => true,
			'dry_run'              => $dry_run,
			'confirmed'            => $confirm,
			'target_autoload'      => $target,
			'option_names'         => $valid,
			'invalid_option_names' => $invalid,
			'requested_count'      => is_array( $input['option_names'] ?? null ) ? count( $input['option_names'] ) : 0,
			'planned_count'        => $planned_count,
			'changed_count'        => $changed_count,
			'unchanged_count'      => $unchanged,
			'missing_count'        => $missing,
			'failed_count'         => $failed,
			'results'              => $results,
			'message'              => $live
				/* translators: 1: changed count, 2: target autoload string */
				? sprintf( __( 'Changed autoload for %1$d option(s) to %2$s.', 'acrossai-abilities-manager' ), $changed_count, $target )
				/* translators: 1: planned count, 2: target autoload string */
				: sprintf( __( 'Dry run: %1$d option(s) planned for autoload=%2$s. Pass dry_run=false and confirm=true to apply.', 'acrossai-abilities-manager' ), $planned_count, $target ),
		);
	}

	/**
	 * Split option names into valid + invalid. Rejects transients, empty
	 * strings, names > 191 chars, and control characters.
	 *
	 * @param mixed $raw Raw input.
	 * @return array{0: string[], 1: string[]}
	 */
	private function partition_names( $raw ): array {
		$valid   = array();
		$invalid = array();
		if ( ! is_array( $raw ) ) {
			return array( $valid, $invalid );
		}
		$seen = array();
		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$name = trim( $item );
			if ( '' === $name || strlen( $name ) > self::NAME_LIMIT || preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
				$invalid[] = $name;
				continue;
			}
			if ( 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' ) ) {
				$invalid[] = $name;
				continue;
			}
			if ( isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;
			$valid[]       = $name;
			if ( count( $valid ) >= self::MAX_NAMES ) {
				break;
			}
		}
		return array( $valid, $invalid );
	}
}
