<?php
/**
 * Feature 069 — add or update schemas on an object.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Post_Meta_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #34 — rank-math/update-post-schemas.
 *
 * The only schema WRITE in the suite: Rank Math core's rank-math/get-post-schema reads
 * but cannot change.
 *
 * NOT idempotent. Schema keys are either 'new-<n>' to append or 'schema-<meta_id>' to
 * replace, so repeating a payload of new-* keys adds duplicate schemas rather than
 * overwriting. Read the existing meta ids first with rank-math/get-post-schema and use
 * schema-<id> to update in place.
 */
class Update_Post_Schemas extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'update-post-schemas';
	}

	protected function ability_label(): string {
		return __( 'Update Rank Math Post Schemas', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Add or replace schema markup on a post, term or user in one call. Key each schema "new-1", "new-2", … to append, or "schema-<meta_id>" to replace an existing one. Repeating a payload of new-* keys APPENDS duplicates rather than overwriting, so read the existing meta ids with Rank Math\'s rank-math/get-post-schema ability first. That ability also lists which schema types this install supports.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-schema';
	}

	protected function rank_math_cap(): string {
		return 'onpage_snippet';
	}

	/**
	 * Rank Math's own schema route requires this module; matching it means the ability
	 * reports rank_math_module_inactive rather than writing schema that will not render.
	 */
	protected function required_module(): string {
		return 'rich-snippet';
	}

	protected function input_properties(): array {
		return array(
			'object_type' => array(
				'type'        => 'string',
				'enum'        => array( 'post', 'term', 'user' ),
				'default'     => 'post',
				'description' => __( 'What the schema attaches to.', 'acrossai-abilities-manager' ),
			),
			'object_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
			'schemas'     => array(
				'type'                 => 'object',
				'description'          => __( 'Schema payload keyed "new-1" to append or "schema-<meta_id>" to replace.', 'acrossai-abilities-manager' ),
				'additionalProperties' => true,
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'object_type' => array( 'type' => 'string' ),
			'object_id'   => array( 'type' => 'integer' ),
			'saved'       => array( 'type' => 'array' ),
		);
	}

	protected function required_input(): array {
		return array( 'object_id', 'schemas' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( ! isset( $input['schemas'] ) || ! is_array( $input['schemas'] ) ) {
			return new WP_Error( 'invalid_input', __( 'schemas must be an object.', 'acrossai-abilities-manager' ) );
		}

		$result = Post_Meta_Repository::update_schemas(
			isset( $input['object_type'] ) ? sanitize_key( (string) $input['object_type'] ) : 'post',
			isset( $input['object_id'] ) ? absint( $input['object_id'] ) : 0,
			$input['schemas']
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number of schemas written, 2: object id */
			_n( 'Wrote %1$d schema to object %2$d.', 'Wrote %1$d schemas to object %2$d.', count( $result['saved'] ), 'acrossai-abilities-manager' ),
			count( $result['saved'] ),
			$result['object_id']
		);

		return $result;
	}
}
