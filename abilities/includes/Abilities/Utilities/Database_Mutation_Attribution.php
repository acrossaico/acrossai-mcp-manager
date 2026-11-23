<?php
/**
 * Feature 087 — mutation-attribution envelope for irreversible DDL.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.32
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Encapsulates the "statement outcome vs postcondition vs mutation outcome"
 * tri-state used by irreversible DDL abilities. Statement success alone is
 * not enough — the postcondition must be verified separately, and when the
 * two disagree (or the postcondition read fails), the mutation state is
 * "unknown" rather than "changed" or "unchanged".
 */
class Database_Mutation_Attribution {

	public const OUTCOME_NONE                 = 'none';
	public const OUTCOME_CHANGED              = 'changed';
	public const OUTCOME_UNCHANGED            = 'unchanged';
	public const OUTCOME_PARTIAL              = 'partial';
	public const OUTCOME_UNKNOWN              = 'unknown';
	public const OUTCOME_PARTIAL_OR_UNKNOWN   = 'partial_or_unknown';

	public const POSTCOND_MET     = 'met';
	public const POSTCOND_FAILED  = 'failed';
	public const POSTCOND_UNKNOWN = 'unknown';

	/**
	 * Attribute one write: given the statement outcome and postcondition,
	 * return one of the OUTCOME_* constants.
	 *
	 * @param bool   $statement_succeeded Did $wpdb->query return non-false?
	 * @param string $postcondition       One of POSTCOND_* constants.
	 * @param bool   $before_matched_target Was the target state already true before the write?
	 * @return string
	 */
	public static function classify( bool $statement_succeeded, string $postcondition, bool $before_matched_target ): string {
		if ( $before_matched_target ) {
			if ( self::POSTCOND_MET === $postcondition ) {
				return self::OUTCOME_UNCHANGED;
			}
			return self::OUTCOME_UNKNOWN;
		}
		if ( $statement_succeeded && self::POSTCOND_MET === $postcondition ) {
			return self::OUTCOME_CHANGED;
		}
		if ( ! $statement_succeeded && self::POSTCOND_MET === $postcondition ) {
			return self::OUTCOME_CHANGED;
		}
		if ( $statement_succeeded && self::POSTCOND_FAILED === $postcondition ) {
			return self::OUTCOME_UNKNOWN;
		}
		if ( ! $statement_succeeded && self::POSTCOND_FAILED === $postcondition ) {
			return self::OUTCOME_NONE;
		}
		return self::OUTCOME_UNKNOWN;
	}

	/**
	 * Aggregate per-item outcomes into a single mutation attribution.
	 * Returns [ overall_outcome, mutation_occurred (bool|null), partial_mutation (bool|null) ].
	 *
	 * @param string[] $outcomes Per-item outcomes.
	 * @return array{outcome: string, mutation_occurred: bool|null, partial_mutation: bool|null}
	 */
	public static function aggregate( array $outcomes ): array {
		$has_changed    = false;
		$has_unchanged  = false;
		$has_unknown    = false;
		$has_none       = false;

		foreach ( $outcomes as $o ) {
			switch ( $o ) {
				case self::OUTCOME_CHANGED:
					$has_changed = true;
					break;
				case self::OUTCOME_UNCHANGED:
					$has_unchanged = true;
					break;
				case self::OUTCOME_UNKNOWN:
					$has_unknown = true;
					break;
				case self::OUTCOME_NONE:
					$has_none = true;
					break;
			}
		}

		if ( ! $has_changed && ! $has_unknown && $has_none && ! $has_unchanged ) {
			return array(
				'outcome'           => self::OUTCOME_NONE,
				'mutation_occurred' => false,
				'partial_mutation'  => false,
			);
		}
		if ( $has_changed && ! $has_none && ! $has_unknown && ! $has_unchanged ) {
			return array(
				'outcome'           => self::OUTCOME_CHANGED,
				'mutation_occurred' => true,
				'partial_mutation'  => false,
			);
		}
		if ( $has_changed && ! $has_unknown ) {
			return array(
				'outcome'           => self::OUTCOME_PARTIAL,
				'mutation_occurred' => true,
				'partial_mutation'  => true,
			);
		}
		if ( $has_unknown && $has_changed ) {
			return array(
				'outcome'           => self::OUTCOME_PARTIAL_OR_UNKNOWN,
				'mutation_occurred' => null,
				'partial_mutation'  => null,
			);
		}
		if ( $has_unknown ) {
			return array(
				'outcome'           => self::OUTCOME_UNKNOWN,
				'mutation_occurred' => null,
				'partial_mutation'  => null,
			);
		}
		return array(
			'outcome'           => self::OUTCOME_UNCHANGED,
			'mutation_occurred' => false,
			'partial_mutation'  => false,
		);
	}
}
