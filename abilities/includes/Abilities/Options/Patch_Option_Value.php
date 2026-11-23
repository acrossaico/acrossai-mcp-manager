<?php
/**
 * Feature 064 — Insert, update, or delete a single nested value inside a
 * serialized option.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Options
 * @since      0.0.23
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Options;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Mutate one nested key inside a serialized option (insert / update / delete)
 * without touching any other key.
 *
 * Guardrails, in order:
 *   1. Empty path -> `blocked_reason: empty_path`.
 *   2. Option name in Update_Option::BLOCKED_OPTIONS ->
 *      `blocked_reason: blocked_option`.
 *   3. Any intermediate node along the path is not array-like ->
 *      `blocked_reason: non_traversable_intermediate`.
 */
class Patch_Option_Value extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'options/patch-option-value',
			'args' => array(
				'label'               => __( 'Patch Option Value', 'acrossai-abilities-manager' ),
				'description'         => __( 'Insert, update, or delete a single value inside a serialized-array option at a caller-supplied key path — without touching any other key. Refuses to operate on options in the shared block-list of protected core options.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-options',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'option'    => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'operation' => array(
							'type' => 'string',
							'enum' => array( 'insert', 'update', 'delete' ),
						),
						'path'      => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'minItems' => 1,
						),
						'value'     => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ) ),
					),
					'required'             => array( 'option', 'operation', 'path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'configuration',
						'sub_group'       => 'manage',
						'sub_group_label' => __( 'Manage', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
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
		$option = sanitize_text_field( (string) ( $input['option'] ?? '' ) );
		if ( '' === $option ) {
			return array(
				'success' => false,
				'message' => __( 'option is required.', 'acrossai-abilities-manager' ),
			);
		}

		$operation = sanitize_text_field( (string) ( $input['operation'] ?? '' ) );
		if ( ! in_array( $operation, array( 'insert', 'update', 'delete' ), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'operation must be one of: insert, update, delete.', 'acrossai-abilities-manager' ),
			);
		}

		$raw_path = isset( $input['path'] ) && is_array( $input['path'] ) ? $input['path'] : array();
		if ( empty( $raw_path ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'empty_path',
				'message'        => __( 'path must be a non-empty array of string keys.', 'acrossai-abilities-manager' ),
			);
		}
		$path = array_values(
			array_map(
				static function ( $segment ): string {
					return sanitize_text_field( (string) $segment );
				},
				$raw_path
			)
		);

		if ( in_array( $option, Update_Option::BLOCKED_OPTIONS, true ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'blocked_option',
				'message'        => sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" is protected and cannot be patched through this ability.', 'acrossai-abilities-manager' ),
					$option
				),
			);
		}

		$current = get_option( $option, null );
		$root    = null === $current ? array() : $current;
		if ( ! is_array( $root ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'non_traversable_intermediate',
				'message'        => sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" is not an array — nested patching requires an array-shaped root.', 'acrossai-abilities-manager' ),
					$option
				),
			);
		}

		// Walk down to the parent of the leaf, creating empty arrays for
		// missing intermediates only during insert/update. For delete we
		// early-return success when any intermediate is missing (nothing to
		// remove).
		$leaf_key      = array_pop( $path );
		$cursor        = &$root;
		$intermediates = $path;
		foreach ( $intermediates as $segment ) {
			if ( ! is_array( $cursor ) ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'non_traversable_intermediate',
					'message'        => __( 'Cannot traverse into a non-array intermediate value.', 'acrossai-abilities-manager' ),
				);
			}

			if ( ! array_key_exists( $segment, $cursor ) ) {
				if ( 'delete' === $operation ) {
					return array(
						'success' => true,
						'message' => __( 'Nothing to delete — intermediate key is absent.', 'acrossai-abilities-manager' ),
					);
				}
				$cursor[ $segment ] = array();
			}

			if ( ! is_array( $cursor[ $segment ] ) ) {
				return array(
					'success'        => false,
					'blocked_reason' => 'non_traversable_intermediate',
					'message'        => __( 'Cannot traverse into a non-array intermediate value.', 'acrossai-abilities-manager' ),
				);
			}

			$cursor = &$cursor[ $segment ];
		}

		if ( ! is_array( $cursor ) ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'non_traversable_intermediate',
				'message'        => __( 'Cannot mutate a leaf whose parent is not an array.', 'acrossai-abilities-manager' ),
			);
		}

		$leaf_exists = array_key_exists( (string) $leaf_key, $cursor );

		switch ( $operation ) {
			case 'insert':
				if ( $leaf_exists ) {
					return array(
						'success'        => false,
						'blocked_reason' => 'key_exists',
						'message'        => __( 'Cannot insert — leaf key already exists. Use operation:update to overwrite.', 'acrossai-abilities-manager' ),
					);
				}
				$cursor[ (string) $leaf_key ] = $input['value'] ?? null;
				break;

			case 'update':
				// Update creates the leaf if missing (per spec) — no guardrail here.
				$cursor[ (string) $leaf_key ] = $input['value'] ?? null;
				break;

			case 'delete':
				if ( ! $leaf_exists ) {
					return array(
						'success' => true,
						'message' => __( 'Nothing to delete — leaf key is absent.', 'acrossai-abilities-manager' ),
					);
				}
				unset( $cursor[ (string) $leaf_key ] );
				break;
		}

		unset( $cursor );

		update_option( $option, $root );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: operation verb, 2: option name */
				__( 'Applied %1$s to option "%2$s".', 'acrossai-abilities-manager' ),
				$operation,
				$option
			),
		);
	}
}
