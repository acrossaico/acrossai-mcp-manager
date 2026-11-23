<?php
/**
 * Feature 074 — score copy quality and flag weak patterns.
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
 * Score copy quality 0-100 and return the issue list.
 */
class Evaluate_Copy extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/evaluate-copy',
			'args' => array(
				'label'               => __( 'Evaluate Copy', 'acrossai-abilities-manager' ),
				'description'         => __( 'Score copy quality 0-100 and flag weak patterns (bare label chips, sentence run-ons, weak headline verbs). Filter-extensible via acrossai_block_qa_rules.', 'acrossai-abilities-manager' ),
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
						'content' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'score'   => array( 'type' => 'integer' ),
						'issues'  => array( 'type' => 'array' ),
						'message' => array( 'type' => 'string' ),
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
		$blocks = self::resolve_blocks( $input );
		if ( null === $blocks ) {
			return array(
				'success' => false,
				'score'   => 0,
				'issues'  => array(),
				'message' => __( 'Provide post_id, blocks[], or content.', 'acrossai-abilities-manager' ),
			);
		}

		$issues  = Block_QA_Rules::run( Block_QA_Rules::KIND_COPY, $blocks );
		$penalty = 0;
		foreach ( $issues as $i ) {
			$sev = (string) ( $i['severity'] ?? '' );
			if ( 'error' === $sev ) {
				$penalty += 20;
			} elseif ( 'warning' === $sev ) {
				$penalty += 10;
			} elseif ( 'notice' === $sev ) {
				$penalty += 3;
			}
		}
		$score = max( 0, min( 100, 100 - $penalty ) );

		return array(
			'success' => true,
			'score'   => $score,
			'issues'  => $issues,
			/* translators: 1: score, 2: issue count */
			'message' => sprintf( __( 'Copy score %1$d/100 with %2$d issue(s).', 'acrossai-abilities-manager' ), $score, count( $issues ) ),
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
		if ( isset( $input['content'] ) && is_string( $input['content'] ) ) {
			return parse_blocks( $input['content'] );
		}
		return null;
	}
}
