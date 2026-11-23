<?php
/**
 * Feature 112 - lists the snippets WPCode has recorded an error for.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Snippet_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;
use WP_Error;
use WPCode_Snippet;

defined( 'ABSPATH' ) || exit;

/**
 * lists the snippets WPCode has recorded an error for.
 *
 * @since 0.0.34
 */
final class List_Snippet_Errors extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/list-snippet-errors';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Snippet Errors', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List every WPCode snippet that has a recorded error, with the message WPCode captured. WPCode automatically switches off a snippet whose code fatals, so a snippet that has stopped working usually appears here with the reason. Fix the code with update-snippet, then clear-snippet-errors before activating it again.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'diagnostics';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array();
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
			'errors' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'description' => __( 'One row per snippet with a recorded error.', 'acrossai-abilities-manager' ),
			),
			'count'  => array( 'type' => 'integer' ),
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
		$posts = get_posts(
			array(
				'post_type'        => Snippet_Repository::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => -1,
				'suppress_filters' => false,
			)
		);

		$rows = array();

		foreach ( $posts as $post ) {
			$snippet = new WPCode_Snippet( $post );
			$error   = Snippet_Repository::last_error( $snippet );

			if ( '' === $error ) {
				continue;
			}

			$rows[] = array(
				'id'        => (int) $snippet->get_id(),
				'title'     => (string) $snippet->get_title(),
				'code_type' => (string) $snippet->get_code_type(),
				'active'    => (bool) $snippet->is_active(),
				'error'     => $error,
			);
		}

		return array(
			'errors' => $rows,
			'count'  => count( $rows ),
		);
	}
}
