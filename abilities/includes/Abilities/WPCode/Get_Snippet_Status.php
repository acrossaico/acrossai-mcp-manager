<?php
/**
 * Feature 112 - reports whether WPCode snippets are running at all on this site.
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
 * reports whether WPCode snippets are running at all on this site.
 *
 * @since 0.0.34
 */
final class Get_Snippet_Status extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/get-snippet-status';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Snippet System Status', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report whether WPCode snippets are actually running on this site: whether safe mode is suppressing everything, whether PHP snippets are disabled site-wide, how many snippets exist and how many are active, how many the loader cache holds, and how many have recorded errors. Run this first when a snippet appears to have no effect: the most common causes are safe mode, a disabled PHP setting, or a cache that does not match the database.', 'acrossai-abilities-manager' );
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
			'safe_mode'      => array( 'type' => 'boolean' ),
			'php_disabled'   => array( 'type' => 'boolean' ),
			'total'          => array( 'type' => 'integer' ),
			'active'         => array( 'type' => 'integer' ),
			'cached'         => array( 'type' => 'integer' ),
			'with_errors'    => array( 'type' => 'integer' ),
			'cache_in_sync'  => array(
				'type'        => 'boolean',
				'description' => __( 'False when the number of active snippets and the number in the loader cache disagree, which means some snippet is not running or is still running after a change.', 'acrossai-abilities-manager' ),
			),
			'wpcode_version' => array( 'type' => 'string' ),
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
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		$active      = 0;
		$with_errors = 0;

		foreach ( $posts as $post_id ) {
			$snippet = new WPCode_Snippet( (int) $post_id );

			if ( $snippet->is_active() ) {
				++$active;
			}

			if ( '' !== Snippet_Repository::last_error( $snippet ) ) {
				++$with_errors;
			}
		}

		$cached = count( Snippet_Repository::cached_ids() );

		return array(
			'safe_mode'      => WPCode_Guard::safe_mode(),
			'php_disabled'   => WPCode_Guard::php_disabled(),
			'total'          => count( $posts ),
			'active'         => $active,
			'cached'         => $cached,
			'with_errors'    => $with_errors,
			'cache_in_sync'  => $active === $cached,
			'wpcode_version' => defined( 'WPCODE_VERSION' ) ? (string) WPCODE_VERSION : '',
		);
	}
}
