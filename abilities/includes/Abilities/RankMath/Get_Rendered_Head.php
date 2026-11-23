<?php
/**
 * Feature 069 — fetch Rank Math's rendered <head> for a URL.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Routes_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #37 — rank-math/get-rendered-head.
 *
 * The verification workhorse: every other ability in this suite reports what is
 * STORED, and this reports what is actually OUTPUT. Template variables resolved,
 * fallbacks applied, schema serialised.
 *
 * Fetched over HTTP loopback, never in-process — Rank Math's handler calls
 * remove_all_actions() and re-runs the main query, which would corrupt any later
 * ability in the same request.
 *
 * Read-only, idempotent.
 */
class Get_Rendered_Head extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-rendered-head';
	}

	protected function ability_label(): string {
		return __( 'Get Rank Math Rendered Head', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Fetch the complete <head> markup Rank Math actually outputs for a URL, with template variables resolved, fallbacks applied and JSON-LD serialised. Use this to verify a change rather than trusting stored settings. Requires Rank Math\'s headless support to be enabled; the error names the setting if it is not.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content';
	}

	protected function rank_math_cap(): string {
		return 'general';
	}

	protected function input_properties(): array {
		return array(
			'url' => array(
				'type'        => 'string',
				'description' => __( 'Absolute URL on this site to render.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'url'           => array( 'type' => 'string' ),
			'response_code' => array( 'type' => 'integer' ),
			'head'          => array( 'type' => 'string' ),
			'length'        => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array( 'url' );
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$url = isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ) ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'invalid_input', __( 'url is required.', 'acrossai-abilities-manager' ) );
		}

		$result = Routes_Repository::rendered_head( $url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number of characters, 2: URL */
			__( 'Returned %1$d characters of rendered head markup for %2$s.', 'acrossai-abilities-manager' ),
			$result['length'],
			$url
		);

		return $result;
	}
}
