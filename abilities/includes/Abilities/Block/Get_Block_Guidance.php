<?php
/**
 * Feature 075 — recommend blocks / layouts for a scenario.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return recommended block layouts for a given scenario description.
 * Filter-extensible via `acrossai_block_guidance_rules`.
 */
class Get_Block_Guidance extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/get-block-guidance',
			'args' => array(
				'label'               => __( 'Get Block Guidance', 'acrossai-abilities-manager' ),
				'description'         => __( 'Given a scenario description (e.g. "hero section", "three-column grid"), return recommended block layouts with rationale and starter block trees. Filter-extensible via acrossai_block_guidance_rules.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'scenario'            => array( 'type' => 'string' ),
						'max_recommendations' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 10,
							'default' => 3,
						),
					),
					'required'             => array( 'scenario' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'         => array( 'type' => 'boolean' ),
						'recommendations' => array( 'type' => 'array' ),
						'message'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'generation',
						'sub_group_label' => __( 'Generation', 'acrossai-abilities-manager' ),
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
		$scenario = strtolower( sanitize_text_field( (string) ( $input['scenario'] ?? '' ) ) );
		$max      = max( 1, min( 10, (int) ( $input['max_recommendations'] ?? 3 ) ) );

		if ( '' === $scenario ) {
			return array(
				'success'         => false,
				'recommendations' => array(),
				'message'         => __( 'scenario is required.', 'acrossai-abilities-manager' ),
			);
		}

		$catalog = apply_filters( 'acrossai_block_guidance_rules', self::builtin_rules() );

		$matches = array();
		foreach ( $catalog as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$keywords = (array) ( $rule['keywords'] ?? array() );
			$hit      = false;
			foreach ( $keywords as $kw ) {
				if ( '' !== (string) $kw && false !== strpos( $scenario, strtolower( (string) $kw ) ) ) {
					$hit = true;
					break;
				}
			}
			if ( $hit ) {
				$matches[] = array(
					'pattern_name'   => (string) ( $rule['pattern_name'] ?? '' ),
					'rationale'      => (string) ( $rule['rationale'] ?? '' ),
					'starter_blocks' => is_array( $rule['starter_blocks'] ?? null ) ? $rule['starter_blocks'] : array(),
					'notes'          => is_array( $rule['notes'] ?? null ) ? array_map( 'strval', $rule['notes'] ) : array(),
				);
			}
			if ( count( $matches ) >= $max ) {
				break;
			}
		}

		return array(
			'success'         => true,
			'recommendations' => $matches,
			/* translators: %d: recommendation count */
			'message'         => sprintf( __( 'Returned %d recommendation(s).', 'acrossai-abilities-manager' ), count( $matches ) ),
		);
	}

	/**
	 * Built-in guidance rule set.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function builtin_rules(): array {
		return array(
			array(
				'pattern_name' => 'Simple Hero',
				'keywords'     => array( 'hero', 'header', 'above the fold', 'landing' ),
				'rationale'    => 'A single H1 heading and a short intro paragraph read fastest on mobile and set page context immediately.',
				'starter_blocks' => array(
					array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 1 ), 'innerHTML' => '<h1>Headline</h1>', 'innerBlocks' => array(), 'innerContent' => array( '<h1>Headline</h1>' ) ),
					array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => '<p>Intro copy.</p>', 'innerBlocks' => array(), 'innerContent' => array( '<p>Intro copy.</p>' ) ),
				),
				'notes'          => array( 'Prefer align:full only when the theme supports full-width breakouts safely.' ),
			),
			array(
				'pattern_name' => 'Three-Column Feature Grid',
				'keywords'     => array( 'grid', 'three-column', 'columns', 'features' ),
				'rationale'    => 'core/columns with three equal columns balances scan-ability on desktop and stacks cleanly on mobile.',
				'starter_blocks' => array(
					array(
						'blockName'    => 'core/columns',
						'attrs'        => array(),
						'innerHTML'    => '',
						'innerBlocks'  => array(
							array( 'blockName' => 'core/column', 'attrs' => array(), 'innerHTML' => '', 'innerBlocks' => array(), 'innerContent' => array( null ) ),
							array( 'blockName' => 'core/column', 'attrs' => array(), 'innerHTML' => '', 'innerBlocks' => array(), 'innerContent' => array( null ) ),
							array( 'blockName' => 'core/column', 'attrs' => array(), 'innerHTML' => '', 'innerBlocks' => array(), 'innerContent' => array( null ) ),
						),
						'innerContent' => array( null, null, null ),
					),
				),
				'notes'          => array(),
			),
			array(
				'pattern_name' => 'FAQ List with Schema',
				'keywords'     => array( 'faq', 'questions', 'faqs' ),
				'rationale'    => 'Use core/details for each Q/A so it renders as a native disclosure list. Pair with FAQPage JSON-LD in the head for SEO.',
				'starter_blocks' => array(
					array( 'blockName' => 'core/details', 'attrs' => array(), 'innerHTML' => '', 'innerBlocks' => array(), 'innerContent' => array( null ) ),
				),
				'notes'          => array( 'Add FAQPage JSON-LD schema separately (theme or SEO plugin).' ),
			),
		);
	}
}
