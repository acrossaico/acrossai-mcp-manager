<?php
/**
 * Feature 064 — Read one nested key inside a serialized-array option.
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
 * Walks a serialized option's nested structure along a caller-supplied
 * key path and returns the leaf value together with an exists flag.
 *
 * The `path` is an array of strings — each element is one array-key or
 * public-object-property to descend into, in order. Numeric keys are
 * accepted as their string form ("0", "1", ...); PHP array access
 * normalises both.
 */
class Get_Nested_Option_Value extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'options/get-nested-option-value',
			'args' => array(
				'label'               => __( 'Get Nested Option Value', 'acrossai-abilities-manager' ),
				'description'         => __( 'Read one nested key inside a serialized-array option without transferring the whole option. path is an array of string keys walked in order (e.g. ["a","b","c"]).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-options',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'option' => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'path'   => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'minItems' => 1,
						),
					),
					'required'             => array( 'option', 'path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'exists'  => array( 'type' => 'boolean' ),
						'value'   => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ) ),
						'message' => array( 'type' => 'string' ),
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
		$option = sanitize_text_field( (string) ( $input['option'] ?? '' ) );
		if ( '' === $option ) {
			return array(
				'success' => false,
				'message' => __( 'option is required.', 'acrossai-abilities-manager' ),
			);
		}

		$raw_path = isset( $input['path'] ) && is_array( $input['path'] ) ? $input['path'] : array();
		if ( empty( $raw_path ) ) {
			return array(
				'success' => false,
				'message' => __( 'path must be a non-empty array of string keys.', 'acrossai-abilities-manager' ),
			);
		}
		$path = array_map(
			static function ( $segment ): string {
				return sanitize_text_field( (string) $segment );
			},
			$raw_path
		);

		$option_value = get_option( $option, null );
		if ( null === $option_value ) {
			return array(
				'success' => true,
				'exists'  => false,
				'value'   => null,
				'message' => sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" does not exist.', 'acrossai-abilities-manager' ),
					$option
				),
			);
		}

		$cursor = $option_value;
		foreach ( $path as $segment ) {
			if ( is_array( $cursor ) && array_key_exists( $segment, $cursor ) ) {
				$cursor = $cursor[ $segment ];
				continue;
			}
			if ( $cursor instanceof \ArrayAccess && $cursor->offsetExists( $segment ) ) {
				$cursor = $cursor->offsetGet( $segment );
				continue;
			}
			if ( is_object( $cursor ) && property_exists( $cursor, $segment ) ) {
				$cursor = $cursor->{$segment};
				continue;
			}
			return array(
				'success' => true,
				'exists'  => false,
				'value'   => null,
				'message' => sprintf(
					/* translators: 1: option name, 2: path */
					__( 'Nested key not found at "%1$s" -> %2$s.', 'acrossai-abilities-manager' ),
					$option,
					implode( '.', $path )
				),
			);
		}

		return array(
			'success' => true,
			'exists'  => true,
			'value'   => $cursor,
			'message' => sprintf(
				/* translators: 1: option name, 2: path */
				__( 'Read "%1$s" -> %2$s.', 'acrossai-abilities-manager' ),
				$option,
				implode( '.', $path )
			),
		);
	}
}
