<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Content
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Content;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Multilang_Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Link_Post_Translation ability class (absorbed).
 */
class Link_Post_Translation extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'content/link-post-translation',
			'args' => array(
				'label'               => __( 'Link Post Translations', 'acrossai-abilities-manager' ),
				'description'         => __( 'Group two or more posts as translations of each other. Pass a map of language code → post ID. Polylang uses pll_save_post_translations(); WPML links each post to the same trid.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-content',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'translations' => array(
							'type'        => 'object',
							'description' => __( 'Map of language code → post ID, e.g. { "en": 5, "fr": 9 }.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'translations' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'driver'       => array( 'type' => 'string' ),
						'translations' => array( 'type' => 'object' ),
						'message'      => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'multilanguage',
						'sub_group_label' => __( 'Multilanguage', 'acrossai-abilities-manager' ),
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
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$raw = $input['translations'] ?? array();
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array(
				'success' => false,
				'message' => __( 'translations must be a non-empty language→ID map.', 'acrossai-abilities-manager' ),
			);
		}

		$clean = array();
		foreach ( $raw as $lang => $id ) {
			$slug = sanitize_key( (string) $lang );
			$pid  = (int) $id;
			if ( '' === $slug || $pid <= 0 || ! get_post( $pid ) ) {
				return array(
					'success' => false,
					/* translators: 1: language slug, 2: post ID */
					'message' => sprintf( __( 'Invalid entry "%1$s" → %2$d.', 'acrossai-abilities-manager' ), $lang, $pid ),
				);
			}
			$clean[ $slug ] = $pid;
		}

		$result = Multilang_Helpers::link_translations( $clean );
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		return array(
			'success'      => true,
			'driver'       => Multilang_Helpers::detect(),
			'translations' => $clean,
			'message'      => __( 'Translations linked.', 'acrossai-abilities-manager' ),
		);
	}
}
