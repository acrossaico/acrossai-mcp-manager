<?php
/**
 * Feature 064 — Delete one transient by name.
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
 * Delete a single transient by name via delete_transient() or
 * delete_site_transient() (selected by the `site` flag). Idempotent —
 * WP core returns true for both a successful delete and a no-op delete
 * once the underlying option no longer exists.
 */
class Delete_Transient extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'cache/delete-transient',
			'args' => array(
				'label'               => __( 'Delete Transient', 'acrossai-abilities-manager' ),
				'description'         => __( 'Delete one transient by name via delete_transient() (or delete_site_transient() when site:true). Idempotent — succeeds even if the transient is already gone.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-cache',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'key'  => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'site' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'required'             => array( 'key' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'deleted' => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
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
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$key = sanitize_text_field( (string) ( $input['key'] ?? '' ) );
		if ( '' === $key ) {
			return array(
				'success' => false,
				'message' => __( 'key is required.', 'acrossai-abilities-manager' ),
			);
		}

		$site    = ! empty( $input['site'] );
		$deleted = $site ? delete_site_transient( $key ) : delete_transient( $key );

		return array(
			'success' => true,
			'deleted' => (bool) $deleted,
			'message' => sprintf(
				/* translators: %s: transient key */
				__( 'Deleted transient "%s".', 'acrossai-abilities-manager' ),
				$key
			),
		);
	}
}
