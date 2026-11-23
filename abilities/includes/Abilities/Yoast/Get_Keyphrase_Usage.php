<?php
/**
 * Feature 106 — Get Keyphrase Usage.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Content_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-keyphrase-usage — Get Keyphrase Usage.
 */
final class Get_Keyphrase_Usage extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-keyphrase-usage';
	}

	protected function ability_label(): string {
		return __( 'Get Keyphrase Usage', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Which focus keyphrases are in use and which posts use each. A keyphrase used on more than one page is keyword cannibalisation — those pages compete with each other in search results, and Yoast free does not warn about it, so this is often the first time anyone sees it.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-content';
	}

	protected function input_properties(): array {
		return array(
			'duplicates_only' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Return only keyphrases used by more than one post.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'keyphrases' => array( 'type' => 'array' ),

			'duplicate_count' => array( 'type' => 'integer' ),
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
		$rows = Content_Repository::keyphrase_usage( ! empty( $input['duplicates_only'] ) );
		$dupes = 0;

		foreach ( $rows as $row ) {
			if ( (int) $row['count'] > 1 ) {
				++$dupes;
			}
		}

		return array(
			'keyphrases'      => $rows,
			'duplicate_count' => $dupes,
			'message'         => 0 === $dupes
				? __( 'No keyphrase is used by more than one post.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of duplicated keyphrases */
					__( '%d keyphrase(s) used by more than one post — those pages compete with each other.', 'acrossai-abilities-manager' ),
					$dupes
				),
		);
	}
}
