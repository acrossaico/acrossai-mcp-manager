<?php
/**
 * Feature 074 — validate a block tree for shape and mutation safety.
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
 * Validate a block tree. Accepts post_id, blocks[], or raw content.
 */
class Validate_Content extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/validate-content',
			'args' => array(
				'label'               => __( 'Validate Content', 'acrossai-abilities-manager' ),
				'description'         => __( 'Validate a block tree for shape and mutation safety. Accepts either post_id (fetch stored content), blocks[] (structured), or content (raw markup). Returns issues with severity + block path.', 'acrossai-abilities-manager' ),
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
						'issues'  => array( 'type' => 'array' ),
						'summary' => array( 'type' => 'object' ),
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
				'issues'  => array(),
				'summary' => (object) array( 'errors' => 0, 'warnings' => 0, 'notices' => 0 ),
				'message' => __( 'Provide post_id, blocks[], or content.', 'acrossai-abilities-manager' ),
			);
		}

		$issues  = Block_QA_Rules::run( Block_QA_Rules::KIND_VALIDATE, $blocks );
		$summary = self::summarize( $issues );

		return array(
			'success' => true,
			'issues'  => $issues,
			'summary' => (object) $summary,
			/* translators: 1: error count, 2: warning count, 3: notice count */
			'message' => sprintf( __( 'Validation: %1$d error(s), %2$d warning(s), %3$d notice(s).', 'acrossai-abilities-manager' ), $summary['errors'], $summary['warnings'], $summary['notices'] ),
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

	/**
	 * Summarize issues by severity.
	 *
	 * @param array<int,array<string,mixed>> $issues Issues.
	 * @return array<string,int>
	 */
	private static function summarize( array $issues ): array {
		$sum = array( 'errors' => 0, 'warnings' => 0, 'notices' => 0 );
		foreach ( $issues as $i ) {
			$sev = (string) ( $i['severity'] ?? '' );
			if ( 'error' === $sev ) {
				++$sum['errors'];
			} elseif ( 'warning' === $sev ) {
				++$sum['warnings'];
			} elseif ( 'notice' === $sev ) {
				++$sum['notices'];
			}
		}
		return $sum;
	}
}
