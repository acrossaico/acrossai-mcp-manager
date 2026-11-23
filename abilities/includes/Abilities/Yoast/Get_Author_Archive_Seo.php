<?php
/**
 * Feature 106 — Get Author Archive SEO.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Indexable_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-author-archive-seo — Get Author Archive SEO.
 */
final class Get_Author_Archive_Seo extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-author-archive-seo';
	}

	protected function ability_label(): string {
		return __( 'Get Author Archive SEO', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The SEO Yoast produces for one author\'s archive. Author archives are frequently noindexed on single-author sites; seo/update-archive-settings controls that.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-indexables';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-seo-settings',
		);
	}

	protected function input_properties(): array {
		return array(
			'subject' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The user ID.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'subject',
		);
	}

	protected function output_properties(): array {
		return array(
			'indexable' => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$row = Indexable_Repository::meta_for( 'author', $input['subject'] ?? '' );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return array(
			'indexable' => $row,
			'message'   => __( 'Resolved.', 'acrossai-abilities-manager' ),
		);
	}
}
