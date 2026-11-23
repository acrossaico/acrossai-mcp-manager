<?php
/**
 * Feature 067 — design-audit orchestrator.
 *
 * Composes individual audit results into aggregate reports for
 * evaluate-design and suggest-design-fixes. Individual audits register
 * themselves via register_audit(), and the runner iterates the registry
 * on aggregate calls.
 *
 * Individual audits and this runner are opinionated design-quality
 * heuristics grounded in Elementor's own official pattern guidance
 * (see Guidance_Catalog).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Design-audit orchestrator.
 */
final class Design_Audit_Runner {

	/**
	 * Registry of individual audit callables.
	 *
	 * @var array<string, callable>
	 */
	private static array $audits = array();

	/**
	 * Register an audit by name.
	 *
	 * @param string   $name      Audit identifier (e.g. 'column-balance').
	 * @param callable $callback  fn( int $post_id, string $subtree_id = '' ): array{findings: array, recommendations: array, score?: float}
	 * @return void
	 */
	public static function register_audit( string $name, callable $callback ): void {
		self::$audits[ $name ] = $callback;
	}

	/**
	 * List registered audits.
	 *
	 * @return string[]
	 */
	public static function list_audits(): array {
		return array_keys( self::$audits );
	}

	/**
	 * Run a single audit by name.
	 *
	 * @param string $name       Audit name.
	 * @param int    $post_id    Post ID.
	 * @param string $subtree_id Optional subtree scope.
	 * @return array<string, mixed>
	 */
	public static function run_audit( string $name, int $post_id, string $subtree_id = '' ): array {
		if ( ! isset( self::$audits[ $name ] ) ) {
			return array(
				'audit'           => $name,
				'findings'        => array(),
				'recommendations' => array(),
				'error'           => 'audit_not_registered',
			);
		}
		try {
			$result = call_user_func( self::$audits[ $name ], $post_id, $subtree_id );
		} catch ( \Throwable $e ) {
			return array(
				'audit'           => $name,
				'findings'        => array(),
				'recommendations' => array(),
				'error'           => $e->getMessage(),
			);
		}
		return array_merge( array( 'audit' => $name ), (array) $result );
	}

	/**
	 * Run every registered audit and compose an aggregate report.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $subtree_id Optional subtree scope.
	 * @return array<string, mixed>
	 */
	public static function run_all( int $post_id, string $subtree_id = '' ): array {
		$results         = array();
		$all_findings    = array();
		$all_fixes       = array();
		$score_sum       = 0.0;
		$score_count     = 0;
		$scores_seen     = array();
		foreach ( self::$audits as $name => $_callback ) {
			$result    = self::run_audit( $name, $post_id, $subtree_id );
			$results[] = $result;

			if ( isset( $result['findings'] ) && is_array( $result['findings'] ) ) {
				foreach ( $result['findings'] as $finding ) {
					$all_findings[] = is_array( $finding )
						? array_merge( array( 'audit' => $name ), $finding )
						: array( 'audit' => $name, 'message' => (string) $finding );
				}
			}
			if ( isset( $result['recommendations'] ) && is_array( $result['recommendations'] ) ) {
				foreach ( $result['recommendations'] as $recommendation ) {
					$all_fixes[] = is_array( $recommendation )
						? array_merge( array( 'audit' => $name ), $recommendation )
						: array( 'audit' => $name, 'suggestion' => (string) $recommendation );
				}
			}
			if ( isset( $result['score'] ) && is_numeric( $result['score'] ) ) {
				$score_sum    += (float) $result['score'];
				$scores_seen[] = (float) $result['score'];
				$score_count++;
			}
		}

		return array(
			'post_id'         => $post_id,
			'subtree_id'      => $subtree_id,
			'audit_count'     => count( $results ),
			'findings'        => $all_findings,
			'recommendations' => $all_fixes,
			'score'           => $score_count > 0 ? round( $score_sum / $score_count, 2 ) : null,
			// The score is a MEAN across audits, so audits that found nothing pull it up:
			// a page with real findings can still read in the eighties. Stated here so the
			// number is interpreted rather than trusted — the findings are the substance.
			'score_basis'     => __( 'Mean score across every audit that returned one. Audits finding nothing score 100, so this rises with the number of audits run — read the findings, not the number.', 'acrossai-abilities-manager' ),
			'lowest_score'    => array() === $scores_seen ? null : min( $scores_seen ),
			'source_policy'   => 'elementor_docs_first',
			'guidance_basis'  => 'grounded in Elementor.com official documentation',
			'audits_run'      => array_keys( self::$audits ),
		);
	}
}
