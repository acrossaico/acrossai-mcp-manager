<?php
/**
 * Feature 055 — review (approve / reject) an internal-link suggestion.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContentSearch
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContentSearch;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Mark a single suggestion as `approved` or `rejected` with optional
 * reviewer notes. Applied suggestions cannot be re-reviewed.
 */
class Review_Internal_Link_Suggestion extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content-search/review-internal-link-suggestion',
			'args' => array(
				'label'               => __( 'Review Internal Link Suggestion', 'acrossai-abilities-manager' ),
				'description'         => __( 'Mark a suggestion as approved or rejected with optional reviewer notes. Applied suggestions cannot be re-reviewed.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content-search',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'suggestion_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'verdict'       => array(
							'type' => 'string',
							'enum' => array( 'approved', 'rejected' ),
						),
						'notes'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'suggestion_id', 'verdict' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'suggestion' => array( 'type' => 'object' ),
						'message'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'internal-links',
						'sub_group_label' => __( 'Internal Links', 'acrossai-abilities-manager' ),
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
		$id      = absint( $input['suggestion_id'] ?? 0 );
		$verdict = sanitize_key( (string) ( $input['verdict'] ?? '' ) );
		$notes   = sanitize_text_field( (string) ( $input['notes'] ?? '' ) );
		if ( $id <= 0 || ! in_array( $verdict, array( 'approved', 'rejected' ), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'suggestion_id and verdict (approved|rejected) are required.', 'acrossai-abilities-manager' ),
			);
		}
		$existing = Suggestion_Store::get( $id );
		if ( null === $existing ) {
			return array(
				'success' => false,
				'message' => __( 'Suggestion not found.', 'acrossai-abilities-manager' ),
			);
		}
		if ( 'applied' === (string) $existing['status'] ) {
			return array(
				'success' => false,
				'message' => __( 'Applied suggestions cannot be re-reviewed.', 'acrossai-abilities-manager' ),
			);
		}
		Suggestion_Store::update_status( $id, $verdict, $notes );
		return array(
			'success'    => true,
			'suggestion' => (object) ( Suggestion_Store::get( $id ) ?? array() ),
			/* translators: 1: id, 2: verdict */
			'message'    => sprintf( __( 'Suggestion #%1$d marked %2$s.', 'acrossai-abilities-manager' ), $id, $verdict ),
		);
	}
}
