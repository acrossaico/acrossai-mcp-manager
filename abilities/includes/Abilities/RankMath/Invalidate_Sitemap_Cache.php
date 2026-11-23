<?php
/**
 * Feature 069 — invalidate the Rank Math sitemap cache.
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
 * Ability #27 — rank-math/invalidate-sitemap-cache.
 *
 * Not destructive: the cache is derived data and regenerates on the next request.
 * Idempotent: invalidating twice has the same effect as once.
 *
 * Reports cache_enabled so a caller can tell the difference between "cleared" and
 * "there was nothing to clear" — a success with cache_enabled false means the call
 * did nothing, which is important when diagnosing a stale sitemap.
 */
class Invalidate_Sitemap_Cache extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'invalidate-sitemap-cache';
	}

	protected function ability_label(): string {
		return __( 'Invalidate Rank Math Sitemap Cache', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Clear Rank Math\'s cached sitemap files so the next request regenerates them. Use scope=all after a settings change, scope=type for one sitemap type, or scope=post with a post_id for a single entry. The response reports cache_enabled: when false the sitemap is generated on every request and this call was a no-op.', 'acrossai-abilities-manager' );
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
			'scope'   => array(
				'type'        => 'string',
				'enum'        => array( 'all', 'type', 'post' ),
				'default'     => 'all',
				'description' => __( 'What to invalidate.', 'acrossai-abilities-manager' ),
			),
			'type'    => array(
				'type'        => 'string',
				'description' => __( 'Sitemap type, required for scope=type. Matches a post type or taxonomy name.', 'acrossai-abilities-manager' ),
			),
			'post_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Post id, required for scope=post.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'scope'         => array( 'type' => 'string' ),
			'target'        => array( 'type' => 'string' ),
			'invalidated'   => array( 'type' => 'boolean' ),
			'cache_enabled' => array( 'type' => 'boolean' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$scope = isset( $input['scope'] ) ? sanitize_key( (string) $input['scope'] ) : 'all';
		if ( ! in_array( $scope, array( 'all', 'type', 'post' ), true ) ) {
			return new WP_Error( 'invalid_input', __( 'scope must be all, type or post.', 'acrossai-abilities-manager' ) );
		}

		$type    = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : '';
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		$result = Sitemap_Repository::invalidate( $scope, $type, $post_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = $result['cache_enabled']
			? sprintf(
				/* translators: %s: what was invalidated */
				__( 'Invalidated the Rank Math sitemap cache for %s.', 'acrossai-abilities-manager' ),
				$result['target']
			)
			: __( 'Sitemap file caching is disabled, so there was nothing to invalidate — the sitemap is already generated on every request.', 'acrossai-abilities-manager' );

		return $result;
	}
}
