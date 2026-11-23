<?php
/**
 * Feature 112 - installs every snippet in a WPCode pack, inactive.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Library_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * installs every snippet in a WPCode pack, inactive.
 *
 * @since 0.0.34
 */
final class Apply_Pack extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/apply-pack';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Apply Snippet Pack', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Install every snippet in a WPCode pack onto this site. All of them are installed INACTIVE whatever the pack says, so a human decides which third-party code runs here. Requires confirm: true because a pack installs several snippets at once, and requires the site to be signed in to the WPCode library. Snippets already present are skipped rather than duplicated.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'library';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'slug' => array(
				'type'        => 'string',
				'description' => __( 'Pack slug, from list-packs.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'slug' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'pack'            => array( 'type' => 'string' ),
			'installed'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'installed_count' => array( 'type' => 'integer' ),
			'skipped_count'   => array( 'type' => 'integer' ),
			'failed_count'    => array( 'type' => 'integer' ),
			'safe_mode'       => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This installs a bundle of third-party snippets from the WPCode library. All of them are installed inactive and none will run until you activate them individually. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Library_Repository::apply_pack( (string) ( $input['slug'] ?? '' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge(
			$result,
			array(
				'safe_mode' => WPCode_Guard::safe_mode(),
				'message'   => __( 'Pack installed. Every snippet is inactive until you activate it.', 'acrossai-abilities-manager' ),
			)
		);
	}
}
