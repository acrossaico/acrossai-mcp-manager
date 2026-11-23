<?php
/**
 * Feature 055 — expose the internal-link suggestion policy.
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
 * Return the policy that governs internal-link suggestion creation +
 * application (currently a static v1 policy — extend in a future spec).
 */
class Get_Internal_Link_Policy extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'content-search/get-internal-link-policy',
			'args' => array(
				'label'               => __( 'Get Internal Link Policy', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return the policy that governs internal-link suggestion creation and application. v1 policy is a static ruleset — future specs may make the ruleset editable via an options page.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content-search',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'policy_version' => array( 'type' => 'string' ),
						'rules'          => array( 'type' => 'array' ),
						'message'        => array( 'type' => 'string' ),
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
		unset( $input );
		return array(
			'success'        => true,
			'policy_version' => '1.0.0',
			'rules'          => array(
				array(
					'rule'        => 'target-must-be-on-site',
					'description' => __( 'Suggestion target URLs must resolve to a same-site URL at apply time.', 'acrossai-abilities-manager' ),
					'enabled'     => true,
				),
				array(
					'rule'        => 'target-must-be-published',
					'description' => __( 'Suggestion target post must be in `publish` status at apply time.', 'acrossai-abilities-manager' ),
					'enabled'     => true,
				),
				array(
					'rule'        => 'max-per-post',
					'description' => __( 'At most 500 suggestions across the whole store (option-backed cap).', 'acrossai-abilities-manager' ),
					'enabled'     => true,
				),
			),
			'message'        => __( 'Internal-link suggestion policy v1.0.0.', 'acrossai-abilities-manager' ),
		);
	}
}
