<?php
/**
 * Feature 106 — Invalidate Sitemap Cache.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Sitemap_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/invalidate-sitemap — Invalidate Sitemap Cache.
 */
final class Invalidate_Sitemap extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/invalidate-sitemap';
	}

	protected function ability_label(): string {
		return __( 'Invalidate Sitemap Cache', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Clear Yoast\'s cached sitemap so the next request rebuilds it. Pass a type — a post type or taxonomy name — to clear just that one, or omit it to clear everything. Useful after a bulk import or a permalink change, when the sitemap still shows the old URLs.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-sitemap';
	}

	protected function input_properties(): array {
		return array(
			'type' => array(
				'type'        => 'string',
				'description' => __( 'Post type or taxonomy name. Omit to clear every sitemap.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'invalidated' => array( 'type' => 'array' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$type   = isset( $input['type'] ) ? (string) $input['type'] : '';
		$result = Sitemap_Repository::invalidate( $type );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'invalidated' => $result,
			'message'     => '' === $type
				? __( 'Every sitemap cache cleared; they rebuild on the next request.', 'acrossai-abilities-manager' )
				: sprintf( /* translators: %s: sitemap type */ __( 'Cleared the %s sitemap cache.', 'acrossai-abilities-manager' ), $type ),
		);
	}
}
