<?php
/**
 * Feature 069 — read one AI Visibility brand.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Entitlement_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #43 — rank-math/get-ai-visibility-brand.
 *
 * Rank Math core already ships get-ai-visibility-overview (the brand LIST) and
 * -brand-insights (the analysis). The gap is fetching a single brand's own record —
 * its configuration rather than its results — which is what an update needs to read
 * first.
 *
 * Read-only, idempotent.
 */
class Get_Ai_Visibility_Brand extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'get-ai-visibility-brand';
	}

	protected function ability_label(): string {
		return __( 'Get AI Visibility Brand', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Return one AI Visibility brand\'s own record — its name, URL, competitors and monitoring configuration. Rank Math\'s own abilities cover the brand list and the analysis results; this is the single-brand configuration read, which is what you need before changing it with rank-math/update-ai-visibility-object.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content-ai';
	}

	/**
	 * Rank Math gates its AI Visibility controllers on manage_options, so there is no
	 * granular capability to compose.
	 */
	protected function rank_math_cap(): string {
		return '';
	}

	protected function input_properties(): array {
		return array(
			'brand_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Brand id. List brands with Rank Math\'s rank-math/get-ai-visibility-overview ability.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'brand_id' => array( 'type' => 'integer' ),
			'brand'    => array( 'type' => array( 'object', 'array', 'null' ) ),
		);
	}

	protected function required_input(): array {
		return array( 'brand_id' );
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Entitlement_Repository::get_brand(
			isset( $input['brand_id'] ) ? absint( $input['brand_id'] ) : 0
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: %d: brand id */
			__( 'Returned AI Visibility brand %d.', 'acrossai-abilities-manager' ),
			$result['brand_id']
		);

		return $result;
	}
}
