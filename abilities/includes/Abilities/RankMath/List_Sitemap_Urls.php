<?php
/**
 * Feature 069 — enumerate URLs from the Rank Math sitemap.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Sitemap_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #58 — rank-math/list-sitemap-urls.
 *
 * Fetches and parses the served sitemap rather than reading configuration, so it
 * answers "what is actually being advertised to search engines" — which can differ
 * from the settings when the file cache is stale.
 *
 * Read-only, idempotent.
 */
class List_Sitemap_Urls extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'list-sitemap-urls';
	}

	protected function ability_label(): string {
		return __( 'List Rank Math Sitemap URLs', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Fetch the served sitemap index and, by default, follow its child sitemaps to enumerate the URLs actually advertised to search engines. This reflects what is being served, which can differ from the current settings when the sitemap file cache is stale — clear it with rank-math/invalidate-sitemap-cache.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-sitemap';
	}

	protected function rank_math_cap(): string {
		return 'sitemap';
	}

	protected function required_module(): string {
		return Sitemap_Repository::MODULE;
	}

	protected function input_properties(): array {
		return array(
			'include_children' => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Follow each child sitemap to collect page URLs. When false, only the child sitemap URLs listed in the index are returned.', 'acrossai-abilities-manager' ),
			),
			'limit'            => array(
				'type'        => 'integer',
				'default'     => 250,
				'minimum'     => 1,
				'maximum'     => 1000,
				'description' => __( 'Maximum URLs to return. Fetching stops once reached.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'index_url'        => array( 'type' => 'string' ),
			'sitemaps'         => array( 'type' => 'array' ),
			'urls'             => array( 'type' => 'array' ),
			'count'            => array( 'type' => 'integer' ),
			'children_fetched' => array( 'type' => 'integer' ),
			'limit_reached'    => array( 'type' => 'boolean' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$include_children = array_key_exists( 'include_children', $input ) ? (bool) $input['include_children'] : true;
		$limit            = isset( $input['limit'] ) ? (int) $input['limit'] : 250;
		$limit            = max( 1, min( 1000, $limit ) );

		$result = Sitemap_Repository::list_urls( $include_children, $limit );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number of URLs returned, 2: number of child sitemaps in the index */
			__( 'Returned %1$d URLs from %2$d child sitemaps.', 'acrossai-abilities-manager' ),
			$result['count'],
			count( $result['sitemaps'] )
		);

		return $result;
	}
}
