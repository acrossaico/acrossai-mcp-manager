<?php
/**
 * Feature 074 — remediation suggestions for design-evaluation issues.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_QA_Rules;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Convert design-evaluation issues into concrete remediation suggestions. Does
 * not apply changes — returns suggestions only.
 */
class Suggest_Design_Fixes extends Ability_Definition {

	private const SUGGESTIONS = array(
		'card_monotony_risk'         => 'Vary section treatments: alternate boxed and open sections; keep at most 2 consecutive boxed groups.',
		'spacing_rhythm_drift'       => 'Consolidate section spacers to at most 3 unique heights; use theme spacing tokens where possible.',
		'internal_measure_mismatch'  => 'Set inner container widths to match the top-level section measure.',
		'row_treatment_inconsistency' => 'Apply the same box/open treatment to every sibling row in a repeated pattern.',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/suggest-design-fixes',
			'args' => array(
				'label'               => __( 'Suggest Design Fixes', 'acrossai-abilities-manager' ),
				'description'         => __( 'Convert design-evaluation issues into concrete remediation suggestions. Accepts post_id (re-runs design evaluation) or issues[] (pass-through mode).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'blocks'  => array( 'type' => 'array' ),
						'issues'  => array( 'type' => 'array' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'     => array( 'type' => 'boolean' ),
						'suggestions' => array( 'type' => 'array' ),
						'message'     => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'analysis',
						'sub_group_label' => __( 'Analysis', 'acrossai-abilities-manager' ),
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
		$issues = array();
		if ( isset( $input['issues'] ) && is_array( $input['issues'] ) ) {
			$issues = $input['issues'];
		} else {
			$blocks = self::resolve_blocks( $input );
			if ( null === $blocks ) {
				return array(
					'success'     => false,
					'suggestions' => array(),
					'message'     => __( 'Provide post_id, blocks[], or issues[].', 'acrossai-abilities-manager' ),
				);
			}
			$issues = Block_QA_Rules::run( Block_QA_Rules::KIND_DESIGN, $blocks );
		}

		$suggestions = array();
		$catalog     = apply_filters( 'acrossai_block_design_fix_catalog', self::SUGGESTIONS );
		foreach ( $issues as $i ) {
			if ( ! is_array( $i ) ) {
				continue;
			}
			$code          = (string) ( $i['code'] ?? '' );
			$rationale     = (string) ( $catalog[ $code ] ?? __( 'Review the block and its layout.', 'acrossai-abilities-manager' ) );
			$suggestions[] = array(
				'issue_code'         => $code,
				'path'               => (array) ( $i['path'] ?? array() ),
				'suggestion_type'    => 'guidance',
				'suggestion_payload' => (object) array( 'summary' => $rationale ),
				'rationale'          => $rationale,
			);
		}

		return array(
			'success'     => true,
			'suggestions' => $suggestions,
			/* translators: %d: suggestion count */
			'message'     => sprintf( __( 'Returned %d suggestion(s).', 'acrossai-abilities-manager' ), count( $suggestions ) ),
		);
	}

	/**
	 * Resolve input into a parsed block tree.
	 *
	 * @param array<string,mixed> $input Input payload.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function resolve_blocks( array $input ): ?array {
		if ( isset( $input['post_id'] ) ) {
			$id   = absint( $input['post_id'] );
			$post = $id > 0 ? get_post( $id ) : null;
			if ( ! $post instanceof \WP_Post ) {
				return null;
			}
			return parse_blocks( (string) $post->post_content );
		}
		if ( isset( $input['blocks'] ) && is_array( $input['blocks'] ) ) {
			return $input['blocks'];
		}
		return null;
	}
}
