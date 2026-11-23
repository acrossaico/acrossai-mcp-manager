<?php
/**
 * Feature 105 — Insert ACF Block.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Acf;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Block_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;

defined( 'ABSPATH' ) || exit;

/**
 * blocks/insert-acf-block — Insert ACF Block.
 */
final class Insert_Acf_Block extends Base_Acf_Ability {

	protected function slug(): string {
		return 'blocks/insert-acf-block';
	}

	protected function ability_label(): string {
		return __( 'Insert ACF Block', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Insert an ACF block instance into a post, with its field values in the data payload. ACF block values live in post_content as part of the block markup, NOT in post meta — so custom-fields/update-acf-field is the wrong tool for them and this is the right one. Call blocks/get-acf-block-fields first to learn the field names. Requires ACF PRO.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'acf-blocks';
	}

	protected function is_writer(): bool {
		return true;
	}

	protected function requires_pro(): bool {
		return true;
	}

	protected function suggested_abilities(): array {
		return array(
			'blocks/get-acf-block-fields',
			'blocks/list-acf-blocks',
		);
	}

	protected function input_properties(): array {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Post to insert the block into.', 'acrossai-abilities-manager' ),
			),

			'name'    => array(
				'type'        => 'string',
				'description' => __( 'Block name, with or without the acf/ prefix.', 'acrossai-abilities-manager' ),
			),

			'data'    => array(
				'type'        => 'object',
				'description' => __( 'Field name => value for the block, matching blocks/get-acf-block-fields.', 'acrossai-abilities-manager' ),
			),

			'index'   => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( '0-based position among the post\'s top-level blocks. Omit to append.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'post_id',
			'name',
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id' => array( 'type' => 'integer' ),

			'name'    => array( 'type' => 'string' ),

			'index'   => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	protected function run( array $input ) {
		$post_id = (int) $input['post_id'];
		$name    = Block_Repository::qualify( (string) $input['name'] );
		$block   = Block_Repository::get( $name );

		if ( is_wp_error( $block ) ) {
			return $block;
		}

		$data  = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
		$index = isset( $input['index'] ) ? (int) $input['index'] : null;

		$inserted = Block_Repository::insert( $post_id, $name, (array) Slash_Input::slash( $data, $input ), $index );

		if ( is_wp_error( $inserted ) ) {
			return $inserted;
		}

		return array(
			'post_id' => $post_id,
			'name'    => $inserted['name'],
			'index'   => $inserted['index'],
			'message' => sprintf(
				/* translators: 1: block name, 2: post id, 3: index */
				__( 'Inserted "%1$s" into post %2$d at position %3$d.', 'acrossai-abilities-manager' ),
				$inserted['name'],
				$post_id,
				$inserted['index']
			),
		);
	}
}
