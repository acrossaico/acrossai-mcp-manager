<?php
/**
 * Feature 069 — inbound internal link graph.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Content_Audit_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #49 — rank-math/get-inbound-links.
 *
 * Deliberately the OPPOSITE direction from everything else available. Rank Math's
 * rank-math/get-post-links lists links going out of a post and is PRO-dependent for
 * much of its data; the plugin's own content-search/find-internal-links also parses outbound
 * links. Neither answers "which pages link TO this one", which is the question that
 * matters for internal-linking work — and neither counts navigation-menu links at all.
 *
 * Read-only, idempotent.
 */
class Get_Inbound_Links extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-inbound-links';
	}

	protected function ability_label(): string {
		return __( 'Get Inbound Internal Links', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Build an inbound internal-link graph: which published pages link TO a given page, including links from navigation menus. This is the opposite direction from Rank Math\'s rank-math/get-post-links and the plugin\'s content-search/find-internal-links, which both list outbound links. Pass a target to inspect one page, or omit it to rank every linked page by inbound count and find orphans.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content';
	}

	protected function rank_math_cap(): string {
		return 'link_builder';
	}

	protected function input_properties(): array {
		return array(
			'target_post_id'  => array( 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Inspect inbound links to this post.', 'acrossai-abilities-manager' ) ),
			'target_url'      => array( 'type' => 'string', 'description' => __( 'Inspect inbound links to this URL, resolved to a post.', 'acrossai-abilities-manager' ) ),
			'post_types'      => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Source post types to scan. Defaults to all public types.', 'acrossai-abilities-manager' ),
			),
			'include_sources' => array( 'type' => 'boolean', 'default' => true, 'description' => __( 'Include the linking pages, not just counts.', 'acrossai-abilities-manager' ) ),
			'include_menus'   => array( 'type' => 'boolean', 'default' => true, 'description' => __( 'Count navigation-menu links as inbound.', 'acrossai-abilities-manager' ) ),
			'min_count'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 0, 'description' => __( 'Minimum inbound count. Use 0 with no target to include orphans.', 'acrossai-abilities-manager' ) ),
			'limit'           => array( 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500 ),
		);
	}

	protected function output_properties(): array {
		return array(
			'items'           => array( 'type' => 'array' ),
			'count'           => array( 'type' => 'integer' ),
			'scanned_sources' => array( 'type' => 'integer' ),
			'menus_included'  => array( 'type' => 'boolean' ),
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
		$result = Content_Audit_Repository::inbound_links( $input );

		$result['message'] = sprintf(
			/* translators: 1: number of targets returned, 2: number of source pages scanned */
			__( 'Returned %1$d link targets after scanning %2$d source pages.', 'acrossai-abilities-manager' ),
			$result['count'],
			$result['scanned_sources']
		);

		return $result;
	}
}
