<?php
/**
 * Feature 069 — submit URLs to IndexNow.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Instant_Indexing_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #7 — rank-math/submit-urls.
 *
 * Not idempotent: each call is a fresh outbound submission and a new log entry.
 * Not destructive: nothing is lost by submitting twice, so no confirm gate — that
 * would only train agents to pass confirm reflexively.
 */
class Submit_Urls extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'submit-urls';
	}

	protected function ability_label(): string {
		return __( 'Submit URLs to IndexNow', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Submit one or more URLs to IndexNow so Bing, Yandex and Seznam are notified immediately. Recorded as a manual submission, which is not subject to the auto-submit throttle. Returns the IndexNow response code, or a specific error naming the HTTP status when the service rejects the batch.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-instant-indexing';
	}

	protected function rank_math_cap(): string {
		return 'general';
	}

	protected function required_module(): string {
		return Instant_Indexing_Repository::MODULE;
	}

	protected function input_properties(): array {
		return array(
			'urls' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'minItems'    => 1,
				'description' => __( 'Absolute URLs to submit. Each must be a valid URL on this site.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'submitted'     => array( 'type' => 'array' ),
			'accepted'      => array( 'type' => 'boolean' ),
			'response_code' => array( 'type' => 'integer' ),
			'key_location'  => array( 'type' => 'string' ),
		);
	}

	protected function required_input(): array {
		return array( 'urls' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( ! isset( $input['urls'] ) || ! is_array( $input['urls'] ) ) {
			return new WP_Error( 'invalid_input', __( 'urls must be a list of URLs.', 'acrossai-abilities-manager' ) );
		}

		$result = Instant_Indexing_Repository::submit( $input['urls'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$count             = count( $result['submitted'] );
		$result['message'] = sprintf(
			/* translators: %d: number of URLs submitted */
			_n( 'Submitted %d URL to IndexNow.', 'Submitted %d URLs to IndexNow.', $count, 'acrossai-abilities-manager' ),
			$count
		);

		return $result;
	}
}
