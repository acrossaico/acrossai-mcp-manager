<?php
/**
 * Feature 075 — create a page from a registered pattern slug.
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
 * Create a new WordPress page from a registered block pattern. Supports
 * simple {{token}} substitutions in the pattern markup.
 */
class Create_Page_From_Pattern extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/create-page-from-pattern',
			'args' => array(
				'label'               => __( 'Create Page From Pattern', 'acrossai-abilities-manager' ),
				'description'         => __( 'Create a new WordPress page from a registered block pattern slug. Optional substitutions object replaces {{token}} placeholders in the pattern content before saving.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'         => array( 'type' => 'string' ),
						'pattern_slug'  => array( 'type' => 'string' ),
						'substitutions' => array( 'type' => 'object' ),
						'status'        => array(
							'type'    => 'string',
							'enum'    => array( 'publish', 'draft' ),
							'default' => 'publish',
						),
					),
					'required'             => array( 'title', 'pattern_slug' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'page_id'  => array( 'type' => 'integer' ),
						'edit_url' => array( 'type' => 'string' ),
						'view_url' => array( 'type' => 'string' ),
						'message'  => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'content',
						'sub_group_label' => __( 'Content', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
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
		if ( ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			return $this->failure( __( 'WP_Block_Patterns_Registry is unavailable.', 'acrossai-abilities-manager' ) );
		}

		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$slug  = sanitize_text_field( (string) ( $input['pattern_slug'] ?? '' ) );
		$subs  = is_array( $input['substitutions'] ?? null ) ? $input['substitutions'] : array();
		$status = in_array( (string) ( $input['status'] ?? '' ), array( 'publish', 'draft' ), true ) ? (string) $input['status'] : 'publish';

		if ( '' === $title || '' === $slug ) {
			return $this->failure( __( 'title and pattern_slug are required.', 'acrossai-abilities-manager' ) );
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();
		$pattern  = $registry->get_registered( $slug );
		if ( null === $pattern ) {
			return $this->failure( sprintf( 'Pattern "%s" is not registered.', $slug ) );
		}

		$content = (string) ( $pattern['content'] ?? '' );

		foreach ( $subs as $key => $value ) {
			$content = str_replace( '{{' . (string) $key . '}}', (string) $value, $content );
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $page_id ) ) {
			return $this->failure( (string) $page_id->get_error_message() );
		}

		return array(
			'success'  => true,
			'page_id'  => (int) $page_id,
			'edit_url' => esc_url_raw( (string) get_edit_post_link( (int) $page_id, 'raw' ) ),
			'view_url' => esc_url_raw( (string) get_permalink( (int) $page_id ) ),
			/* translators: %d: page ID */
			'message'  => sprintf( __( 'Page #%d created from pattern.', 'acrossai-abilities-manager' ), (int) $page_id ),
		);
	}

	/**
	 * Failure envelope.
	 *
	 * @param string $message Failure message.
	 * @return array<string,mixed>
	 */
	private function failure( string $message ): array {
		return array(
			'success'  => false,
			'page_id'  => 0,
			'edit_url' => '',
			'view_url' => '',
			'message'  => $message,
		);
	}
}
