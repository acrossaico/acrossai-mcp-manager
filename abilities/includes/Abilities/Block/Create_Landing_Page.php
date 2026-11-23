<?php
/**
 * Feature 075 — composite: generate a landing page then create it.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Recipe_Registry;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Block_Recipe_Renderer;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot composite: internally runs generate-landing-page + create-page-from-blocks.
 */
class Create_Landing_Page extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/create-landing-page',
			'args' => array(
				'label'               => __( 'Create Landing Page', 'acrossai-abilities-manager' ),
				'description'         => __( 'Composite: generate a landing-page block tree from business_name + tone + sections, then persist it as a WordPress page. Returns page metadata plus any generator warnings.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'         => array( 'type' => 'string' ),
						'business_name' => array( 'type' => 'string' ),
						'tone'          => array( 'type' => 'string' ),
						'sections'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'recipe_id'     => array( 'type' => 'string' ),
						'status'        => array(
							'type'    => 'string',
							'enum'    => array( 'publish', 'draft' ),
							'default' => 'publish',
						),
					),
					'required'             => array( 'title', 'business_name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'page_id'  => array( 'type' => 'integer' ),
						'edit_url' => array( 'type' => 'string' ),
						'view_url' => array( 'type' => 'string' ),
						'warnings' => array( 'type' => 'array' ),
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
		$title  = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$biz    = sanitize_text_field( (string) ( $input['business_name'] ?? '' ) );
		$tone   = sanitize_text_field( (string) ( $input['tone'] ?? '' ) );
		$rid    = sanitize_text_field( (string) ( $input['recipe_id'] ?? 'landing-simple' ) );
		$status = in_array( (string) ( $input['status'] ?? '' ), array( 'publish', 'draft' ), true ) ? (string) $input['status'] : 'publish';

		if ( '' === $title || '' === $biz ) {
			return $this->failure( __( 'title and business_name are required.', 'acrossai-abilities-manager' ) );
		}

		$sections = is_array( $input['sections'] ?? null )
			? array_map( 'sanitize_text_field', $input['sections'] )
			: array();

		$context  = array( 'business_name' => $biz, 'tone' => $tone );
		$blocks   = array();
		$warnings = array();

		if ( array() !== $sections ) {
			foreach ( $sections as $sid ) {
				$rendered = Block_Recipe_Renderer::render( $sid, $context );
				$blocks   = array_merge( $blocks, $rendered['blocks'] );
				$warnings = array_merge( $warnings, $rendered['warnings'] );
			}
		} else {
			$recipe = Block_Recipe_Registry::get( $rid );
			if ( null === $recipe ) {
				return $this->failure( sprintf( 'Unknown recipe id: %s', $rid ) );
			}
			$page     = Block_Recipe_Renderer::render_page( $recipe, $context );
			$blocks   = $page['blocks'];
			$warnings = $page['warnings'];
		}

		if ( array() === $blocks ) {
			return $this->failure( __( 'Generator produced no blocks; page not created.', 'acrossai-abilities-manager' ) );
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => serialize_blocks( $blocks ),
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
			'warnings' => $warnings,
			/* translators: %d: page ID */
			'message'  => sprintf( __( 'Landing page #%d created.', 'acrossai-abilities-manager' ), (int) $page_id ),
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
			'warnings' => array(),
			'message'  => $message,
		);
	}
}
