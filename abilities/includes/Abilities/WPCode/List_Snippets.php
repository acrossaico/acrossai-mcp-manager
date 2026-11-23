<?php
/**
 * Feature 112 - lists every WPCode snippet with the state that decides whether it runs.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Snippet_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;
use WPCode_Snippet;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * lists every WPCode snippet with the state that decides whether it runs.
 *
 * @since 0.0.34
 */
final class List_Snippets extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/list-snippets';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Code Snippets', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List every WPCode snippet with its code type, active state, auto-insert location and priority. Each row also reports whether WPCode\'s loader cache currently holds the snippet, which is what actually decides whether it runs: a snippet can be published in the database and still be inert. Filter by code type or active state.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'snippets';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'code_type' => array(
				'type'        => 'string',
				'enum'        => WPCode_Guard::CODE_TYPES,
				'description' => __( 'Only snippets of this code type.', 'acrossai-abilities-manager' ),
			),
			'active'    => array(
				'type'        => 'boolean',
				'description' => __( 'Only active, or only inactive, snippets.', 'acrossai-abilities-manager' ),
			),
			'per_page'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 200,
				'default'     => 50,
				'description' => __( 'Maximum rows to return.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'snippets'  => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'description' => __( 'One row per snippet.', 'acrossai-abilities-manager' ),
			),
			'count'     => array( 'type' => 'integer' ),
			'safe_mode' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$code_type = isset( $input['code_type'] ) ? (string) $input['code_type'] : '';

		if ( '' !== $code_type && ! in_array( $code_type, WPCode_Guard::CODE_TYPES, true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: supplied type, 2: accepted list. */
					__( '"%1$s" is not a WPCode code type. Accepted types are: %2$s.', 'acrossai-abilities-manager' ),
					$code_type,
					implode( ', ', WPCode_Guard::CODE_TYPES )
				)
			);
		}

		$posts = get_posts(
			array(
				'post_type'        => Snippet_Repository::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => isset( $input['per_page'] ) ? (int) $input['per_page'] : 50,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$rows = array();

		foreach ( $posts as $post ) {
			$row = Snippet_Repository::shape( new WPCode_Snippet( $post ) );

			if ( '' !== $code_type && $row['code_type'] !== $code_type ) {
				continue;
			}

			if ( array_key_exists( 'active', $input ) && (bool) $input['active'] !== $row['active'] ) {
				continue;
			}

			$rows[] = $row;
		}

		return array(
			'snippets'  => $rows,
			'count'     => count( $rows ),
			'safe_mode' => WPCode_Guard::safe_mode(),
		);
	}
}
