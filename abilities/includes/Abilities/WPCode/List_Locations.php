<?php
/**
 * Feature 112 - lists the auto-insert locations WPCode supports.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Snippet_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * lists the auto-insert locations WPCode supports.
 *
 * @since 0.0.34
 */
final class List_Locations extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/list-locations';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Auto-Insert Locations', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List every auto-insert location a WPCode snippet can be placed in, with a plain description of when each one fires. Read this before calling set-location: an unrecognised location is refused rather than silently stored.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'placement';
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
			'locations' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'description' => __( 'One row per location.', 'acrossai-abilities-manager' ),
			),
			'count'     => array( 'type' => 'integer' ),
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
		$descriptions = array(
			'site_wide_header' => __( 'In the site head, on every page, via wp_head.', 'acrossai-abilities-manager' ),
			'site_wide_body'   => __( 'Immediately after the opening body tag, via wp_body_open. Requires theme support for that hook.', 'acrossai-abilities-manager' ),
			'site_wide_footer' => __( 'In the site footer, on every page, via wp_footer.', 'acrossai-abilities-manager' ),
			'everywhere'       => __( 'Runs on every request, front end and admin, with no output position.', 'acrossai-abilities-manager' ),
			'admin_only'       => __( 'Runs in wp-admin only.', 'acrossai-abilities-manager' ),
			'before_post'      => __( 'Before the post, on single post views.', 'acrossai-abilities-manager' ),
			'after_post'       => __( 'After the post, on single post views.', 'acrossai-abilities-manager' ),
			'before_content'   => __( 'Before the post content.', 'acrossai-abilities-manager' ),
			'after_content'    => __( 'After the post content.', 'acrossai-abilities-manager' ),
			'before_paragraph' => __( 'Before a given paragraph of the content.', 'acrossai-abilities-manager' ),
			'after_paragraph'  => __( 'After a given paragraph of the content.', 'acrossai-abilities-manager' ),
		);

		$rows = array();

		foreach ( Snippet_Repository::LOCATIONS as $location ) {
			$rows[] = array(
				'location'    => $location,
				'description' => $descriptions[ $location ] ?? '',
			);
		}

		return array(
			'locations' => $rows,
			'count'     => count( $rows ),
		);
	}
}
