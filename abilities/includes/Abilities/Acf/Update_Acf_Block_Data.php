<?php
/**
 * Feature 105 — Update ACF Block Data.
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
 * blocks/update-acf-block-data — Update ACF Block Data.
 */
final class Update_Acf_Block_Data extends Base_Acf_Ability {

	protected function slug(): string {
		return 'blocks/update-acf-block-data';
	}

	protected function ability_label(): string {
		return __( 'Update ACF Block Data', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Patch the field values of one ACF block already in a post, addressed by its block path. Merges by default so unnamed fields keep their values; pass replace true to overwrite the whole payload. Refuses a path that is not an ACF block, pointing at blocks/update-post-block for ordinary ones. Call blocks/outline-post-blocks to find the path. Requires ACF PRO.',
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
		);
	}

	protected function input_properties(): array {
		return array(
			'post_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Post holding the block.', 'acrossai-abilities-manager' ),
			),

			'path'    => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'Block path as reported by blocks/outline-post-blocks, e.g. [2] or [1, 0].', 'acrossai-abilities-manager' ),
			),

			'data'    => array(
				'type'        => 'object',
				'description' => __( 'Field name => new value.', 'acrossai-abilities-manager' ),
			),

			'replace' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Replace the whole data payload rather than merging into it.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'post_id',
			'path',
			'data',
		);
	}

	protected function output_properties(): array {
		return array(
			'post_id' => array( 'type' => 'integer' ),

			'data'    => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$post_id = (int) $input['post_id'];
		$path    = isset( $input['path'] ) && is_array( $input['path'] ) ? array_map( 'intval', $input['path'] ) : array();
		$data    = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
		$replace = ! empty( $input['replace'] );

		$updated = Block_Repository::update_data( $post_id, $path, (array) Slash_Input::slash( $data, $input ), $replace );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return array(
			'post_id' => $post_id,
			'data'    => $updated,
			'message' => sprintf(
				/* translators: 1: post id, 2: block path */
				__( 'Updated the ACF block at path [%2$s] in post %1$d.', 'acrossai-abilities-manager' ),
				$post_id,
				implode( ', ', array_map( 'strval', $path ) )
			),
		);
	}
}
