<?php
/**
 * Feature 069 — detect importable data from other SEO plugins.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Status_Tools_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #26 — rank-math/detect-seo-plugins.
 *
 * Detection only. Rank Math's importer runs in chunks over potentially tens of
 * thousands of posts, which does not fit a single ability call — that belongs to a
 * dedicated migration flow.
 *
 * Read-only, idempotent.
 */
class Detect_Seo_Plugins extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'detect-seo-plugins';
	}

	protected function ability_label(): string {
		return __( 'Detect Other SEO Plugin Data', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Detect leftover data from other SEO plugins — Yoast, All in One SEO, SEOPress, Schema Pro and others — that Rank Math could import, along with what each offers (settings, post meta, terms, redirections). Detection only: running the import is chunked and long-running, so do that from Rank Math\'s own import screen.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-status';
	}

	protected function rank_math_cap(): string {
		return '';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'detected' => array( 'type' => 'array' ),
			'count'    => array( 'type' => 'integer' ),
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
		$result = Status_Tools_Repository::detect_seo_plugins();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = 0 === $result['count']
			? __( 'No other SEO plugin data was detected on this site.', 'acrossai-abilities-manager' )
			: sprintf(
				/* translators: %d: number of plugins detected */
				_n( 'Detected importable data from %d other SEO plugin.', 'Detected importable data from %d other SEO plugins.', $result['count'], 'acrossai-abilities-manager' ),
				$result['count']
			);

		return $result;
	}
}
