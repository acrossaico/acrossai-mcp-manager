<?php
/**
 * Feature 069 — Content AI keyword research.
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
 * Ability #42 — rank-math/research-keyword.
 *
 * The only credit-consuming Content AI ability in the suite. Credits are money and
 * cannot be refunded, so:
 *
 * - the balance is checked BEFORE the remote call, and a zero balance fails with
 *   content_ai_no_credits without spending a round-trip;
 * - confirm:true is required, on the same reasoning as the destructive abilities —
 *   irreversibility, not data loss, is what earns the gate;
 * - credits_before and credits_after are returned so the spend is visible.
 *
 * Declared destructive:true. It destroys no DATA, but the destructive annotation exists
 * to warn clients about IRREVERSIBILITY, and unrecoverable spend from a paid balance
 * qualifies. Keeping one machine-checkable rule — destructive and requires_confirmation
 * always agree — is better than an exception every client has to reason about. The
 * architecture test enforces it.
 */
class Research_Keyword extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'research-keyword';
	}

	protected function ability_label(): string {
		return __( 'Research Keyword with Content AI', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Run Rank Math Content AI keyword research and return the recommended keywords, questions and related terms. CONSUMES CREDITS from the connected Rank Math account, which cannot be refunded — the balance is checked first and the request fails without spending anything if none remain. Check the balance beforehand with rank-math/get-content-ai-status.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-content-ai';
	}

	protected function rank_math_cap(): string {
		return 'content_ai';
	}

	protected function required_module(): string {
		return Entitlement_Repository::CONTENT_AI_MODULE;
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'keyword' => array(
				'type'        => 'string',
				'description' => __( 'Keyword or phrase to research.', 'acrossai-abilities-manager' ),
			),
			'country' => array(
				'type'        => 'string',
				'default'     => 'all',
				'description' => __( 'Country code to target, or "all".', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'keyword'        => array( 'type' => 'string' ),
			'country'        => array( 'type' => 'string' ),
			'research'       => array( 'type' => array( 'object', 'array', 'null' ) ),
			'credits_before' => array( 'type' => array( 'integer', 'null' ) ),
			'credits_after'  => array( 'type' => array( 'integer', 'null' ) ),
		);
	}

	/**
	 * 'confirm' is intentionally absent — see Base_Rank_Math_Ability::ability().
	 */
	protected function required_input(): array {
		return array( 'keyword' );
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$keyword = isset( $input['keyword'] ) ? sanitize_text_field( (string) $input['keyword'] ) : '';
		if ( '' === $keyword ) {
			return new WP_Error( 'invalid_input', __( 'keyword is required.', 'acrossai-abilities-manager' ) );
		}

		$result = Entitlement_Repository::research_keyword(
			$keyword,
			isset( $input['country'] ) ? sanitize_text_field( (string) $input['country'] ) : 'all'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$spent = ( null !== $result['credits_before'] && null !== $result['credits_after'] )
			? max( 0, $result['credits_before'] - $result['credits_after'] )
			: null;

		$result['message'] = null !== $spent
			? sprintf(
				/* translators: 1: researched keyword, 2: credits spent, 3: credits remaining */
				__( 'Researched "%1$s". %2$d credits spent, %3$d remaining.', 'acrossai-abilities-manager' ),
				$keyword,
				$spent,
				$result['credits_after']
			)
			: sprintf(
				/* translators: %s: researched keyword */
				__( 'Researched "%s".', 'acrossai-abilities-manager' ),
				$keyword
			);

		return $result;
	}
}
