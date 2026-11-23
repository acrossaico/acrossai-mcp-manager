<?php
/**
 * Feature 104 — Get The Crawler Sitemap.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Crawler_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/get-crawler-map — Get The Crawler Sitemap.
 */
final class Get_Crawler_Map extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'get-crawler-map';
	}

	protected function ability_label(): string {
		return __( 'Get The Crawler Sitemap', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'A page of the sitemap the crawler works from, with the total URL count. Use limit and offset to page through it. If this is empty the crawler has nothing to do, which is the usual reason a crawl appears to finish instantly.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-crawler';
	}

	protected function input_properties(): array {
		return array(
			'limit'  => array(
				'type'        => 'integer',
				'default'     => 100,
				'minimum'     => 1,
				'maximum'     => 500,
				'description' => __( 'Maximum URLs to return.', 'acrossai-abilities-manager' ),
			),

			'offset' => array(
				'type'        => 'integer',
				'default'     => 0,
				'minimum'     => 0,
				'description' => __( 'URLs to skip.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'total' => array( 'type' => 'integer' ),

			'urls'  => array( 'type' => 'array' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	protected function run( array $input ) {
		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 100;
		$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		$map    = Crawler_Repository::map( $limit, $offset );

		return array(
			'total'   => $map['total'],
			'urls'    => $map['urls'],
			'message' => sprintf(
				/* translators: 1: returned count, 2: total */
				__( '%1$d of %2$d sitemap URLs.', 'acrossai-abilities-manager' ),
				count( $map['urls'] ),
				$map['total']
			),
		);
	}
}
