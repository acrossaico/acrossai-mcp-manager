<?php
/**
 * Feature 069 — find redirections matching a URL.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Redirections_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #52 — rank-math/find-redirection.
 *
 * Evaluates Rank Math's own source-matching rules (exact, contains, start, end,
 * regex) against a URL, rather than doing a text search. That is what answers "why
 * is this URL redirecting?" — a listing filtered by substring cannot, because a
 * regex or contains rule need not contain the URL literally.
 *
 * Read-only, idempotent.
 */
class Find_Redirection extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'find-redirection';
	}

	protected function ability_label(): string {
		return __( 'Find Rank Math Redirection', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Find which redirections match a given URL or path by evaluating Rank Math\'s own source rules — exact, contains, start, end and regex. Use this to explain why a URL redirects; a text search over the redirection list cannot answer that, because a regex or contains rule need not contain the URL literally.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-redirections';
	}

	protected function rank_math_cap(): string {
		return 'redirections';
	}

	protected function required_module(): string {
		return Redirections_Repository::MODULE;
	}

	protected function input_properties(): array {
		return array(
			'url'         => array(
				'type'        => 'string',
				'description' => __( 'URL or site-relative path to test. Only the path is matched, as Rank Math matches on paths.', 'acrossai-abilities-manager' ),
			),
			'active_only' => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Restrict to active rules. Set false to see inactive or trashed rules that would match if enabled.', 'acrossai-abilities-manager' ),
			),
			'limit'       => array(
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Maximum matches to return.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'url'     => array( 'type' => 'string' ),
			'path'    => array( 'type' => 'string' ),
			'matches' => array( 'type' => 'array' ),
			'count'   => array( 'type' => 'integer' ),
			'scanned' => array( 'type' => 'integer' ),
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
		$url = isset( $input['url'] ) ? trim( (string) $input['url'] ) : '';
		if ( '' === $url ) {
			return new WP_Error( 'invalid_input', __( 'url is required.', 'acrossai-abilities-manager' ) );
		}

		$result = Redirections_Repository::find(
			$url,
			array_key_exists( 'active_only', $input ) ? (bool) $input['active_only'] : true,
			isset( $input['limit'] ) ? max( 1, min( 100, (int) $input['limit'] ) ) : 20
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = 0 === $result['count']
			? sprintf(
				/* translators: %s: path tested */
				__( 'No redirection matches "%s".', 'acrossai-abilities-manager' ),
				$result['path']
			)
			: sprintf(
				/* translators: 1: number of matches, 2: path tested */
				_n( '%1$d redirection matches "%2$s".', '%1$d redirections match "%2$s".', $result['count'], 'acrossai-abilities-manager' ),
				$result['count'],
				$result['path']
			);

		return $result;
	}
}
