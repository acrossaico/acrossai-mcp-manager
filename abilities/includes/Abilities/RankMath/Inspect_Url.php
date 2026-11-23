<?php
/**
 * Feature 069 — schedule or run a Google URL inspection.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Analytics_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #32 — rank-math/inspect-url.
 *
 * mode=now calls Google's URL Inspection API synchronously and CONSUMES that site's
 * daily inspection quota, which is small and resets on Google's schedule — hence
 * idempotent:false and a description that says so. mode=schedule queues the work
 * instead and is the default.
 *
 * Not destructive: nothing is lost, only quota is spent, and quota is not data.
 */
class Inspect_Url extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'inspect-url';
	}

	protected function ability_label(): string {
		return __( 'Inspect URL with Google', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Ask Google Search Console to inspect a URL. mode=schedule (the default) queues the inspection in the background. mode=now runs it immediately and consumes one of the site\'s limited daily URL Inspection quota units, so use it sparingly. Read results afterwards with rank-math/get-index-status.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-analytics';
	}

	protected function rank_math_cap(): string {
		return 'analytics';
	}

	protected function required_module(): string {
		return Analytics_Repository::MODULE;
	}

	protected function input_properties(): array {
		return array(
			'url'  => array(
				'type'        => 'string',
				'description' => __( 'Absolute URL to inspect. Must belong to the connected Search Console property.', 'acrossai-abilities-manager' ),
			),
			'mode' => array(
				'type'        => 'string',
				'enum'        => array( 'schedule', 'now' ),
				'default'     => 'schedule',
				'description' => __( 'schedule queues the work; now runs it immediately and spends daily Google quota.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'url'       => array( 'type' => 'string' ),
			'mode'      => array( 'type' => 'string' ),
			'scheduled' => array( 'type' => 'boolean' ),
			'result'    => array( 'type' => 'object' ),
		);
	}

	protected function required_input(): array {
		return array( 'url' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
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

		$mode   = isset( $input['mode'] ) && 'now' === $input['mode'] ? 'now' : 'schedule';
		$result = Analytics_Repository::inspect_url( $url, $mode );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = 'now' === $mode
			? sprintf(
				/* translators: %s: inspected URL */
				__( 'Inspected %s immediately. One Google URL Inspection quota unit was consumed.', 'acrossai-abilities-manager' ),
				$url
			)
			: sprintf(
				/* translators: %s: inspected URL */
				__( 'Queued %s for inspection. Read the result later with rank-math/get-index-status.', 'acrossai-abilities-manager' ),
				$url
			);

		return $result;
	}
}
