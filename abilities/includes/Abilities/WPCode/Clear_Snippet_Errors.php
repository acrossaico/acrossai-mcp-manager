<?php
/**
 * Feature 112 - clears recorded snippet errors.
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
 * clears recorded snippet errors.
 *
 * @since 0.0.34
 */
final class Clear_Snippet_Errors extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/clear-snippet-errors';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Clear Snippet Errors', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Clear the error WPCode recorded against a snippet, or against every snippet if no id is given. This only clears the record; it does not fix the code and it does not reactivate anything. Fix the snippet with update-snippet first, then clear the error and activate it, so a later failure is unambiguous.', 'acrossai-abilities-manager' );
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
		return array(
			'id' => array(
				'type'        => 'integer',
				'description' => __( 'Snippet id. Omit to clear the recorded error for every snippet.', 'acrossai-abilities-manager' ),
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
			'cleared'    => array( 'type' => 'integer' ),
			'cleared_ids' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( isset( $input['id'] ) ) {
			$snippet = Snippet_Repository::find( (int) $input['id'] );

			if ( is_wp_error( $snippet ) ) {
				return $snippet;
			}

			/*
			 * reset_last_error(), never set_last_error(''). The setter returns early unless it is
			 * handed an array with a 'message' key (class-wpcode-snippet.php), so clearing through
			 * it is a silent no-op that would report success having changed nothing. The reset also
			 * clears WPCode's aggregated error state, which the meta delete alone does not.
			 */
			$snippet->reset_last_error();

			if ( '' !== Snippet_Repository::last_error( $snippet ) ) {
				return new WP_Error(
					'clear_failed',
					sprintf(
						/* translators: %d: snippet id. */
						__( 'The recorded error for snippet %d is still present after clearing it.', 'acrossai-abilities-manager' ),
						(int) $snippet->get_id()
					)
				);
			}

			return array(
				'cleared'     => 1,
				'cleared_ids' => array( (int) $snippet->get_id() ),
			);
		}

		$posts   = get_posts(
			array(
				'post_type'        => Snippet_Repository::POST_TYPE,
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => -1,
				'suppress_filters' => false,
			)
		);
		$cleared = array();

		foreach ( $posts as $post ) {
			$snippet = new WPCode_Snippet( $post );

			if ( '' === Snippet_Repository::last_error( $snippet ) ) {
				continue;
			}

			$snippet->reset_last_error();
			$cleared[] = (int) $snippet->get_id();
		}

		return array(
			'cleared'     => count( $cleared ),
			'cleared_ids' => $cleared,
		);
	}
}
