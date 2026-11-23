<?php
/**
 * Feature 074 — rewrite suggestions for copy-evaluation issues.
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
 * Convert copy-evaluation issues into rewrite suggestions. Read-only.
 */
class Suggest_Copy_Fixes extends Ability_Definition {

	private const SUGGESTIONS = array(
		'noninteractive_control_affordance_risk' => 'Add supporting text below each chip, or convert chips into real links with destination URLs.',
	);

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/suggest-copy-fixes',
			'args' => array(
				'label'               => __( 'Suggest Copy Fixes', 'acrossai-abilities-manager' ),
				'description'         => __( 'Convert copy-evaluation issues into rewrite suggestions. Accepts post_id (re-runs copy evaluation) or issues[] (pass-through mode).', 'acrossai-abilities-manager' ),
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
			$issues = Block_QA_Rules::run( Block_QA_Rules::KIND_COPY, $blocks );
		}

		$suggestions = array();
		$catalog     = apply_filters( 'acrossai_block_copy_fix_catalog', self::SUGGESTIONS );
		foreach ( $issues as $i ) {
			if ( ! is_array( $i ) ) {
				continue;
			}
			$code          = (string) ( $i['code'] ?? '' );
			$rationale     = (string) ( $catalog[ $code ] ?? __( 'Review the copy for clarity and specificity.', 'acrossai-abilities-manager' ) );
			$suggestions[] = array(
				'issue_code'         => $code,
				'path'               => (array) ( $i['path'] ?? array() ),
				'suggestion_type'    => 'rewrite',
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
