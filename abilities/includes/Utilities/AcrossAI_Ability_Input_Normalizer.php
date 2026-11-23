<?php
/**
 * Normalises the arguments passed to an ability before invocation.
 *
 * MCP clients send `{}` for a tool that takes no arguments, while WordPress
 * expects `null` for an ability declaring no input schema. Passing the empty
 * object straight through makes every zero-input ability fail validation, so
 * the two shapes have to be reconciled.
 *
 * The MCP adapter ships a normaliser that already does this, and it is the
 * authority when present — matching its behaviour matters more than owning
 * the logic, because the adapter is what invokes abilities on the other path.
 * The local fallback exists so this plugin keeps working when the adapter is
 * absent, which is a supported configuration (Constitution §V).
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Reconciles caller-supplied arguments with what an ability expects.
 */
class AcrossAI_Ability_Input_Normalizer {

	/**
	 * The adapter's normaliser, when it is installed.
	 *
	 * @var string
	 */
	private const VENDOR_CLASS = '\\WP\\MCP\\Domain\\Utils\\AbilityArgumentNormalizer';

	/**
	 * Normalise arguments for one ability.
	 *
	 * @since  0.0.34
	 * @param  object|null $ability   The target ability, when resolved.
	 * @param  mixed       $arguments Caller-supplied arguments.
	 * @return mixed Arguments in the shape the ability expects.
	 */
	public static function normalize( $ability, $arguments ) {
		if ( class_exists( self::VENDOR_CLASS ) && is_callable( array( self::VENDOR_CLASS, 'normalize' ) ) ) {
			return call_user_func( array( self::VENDOR_CLASS, 'normalize' ), $ability, $arguments );
		}

		return self::fallback( $ability, $arguments );
	}

	/**
	 * Local equivalent, used when the adapter is not installed.
	 *
	 * An ability that declares no input schema wants `null`, not an empty
	 * array — validation rejects the latter. Anything else is passed through
	 * untouched, because guessing at a caller's payload is worse than letting
	 * the ability's own validation reject it with a useful message.
	 *
	 * @since  0.0.34
	 * @param  object|null $ability   The target ability, when resolved.
	 * @param  mixed       $arguments Caller-supplied arguments.
	 * @return mixed
	 */
	private static function fallback( $ability, $arguments ) {
		$has_schema = false;

		if ( is_object( $ability ) && method_exists( $ability, 'get_input_schema' ) ) {
			$schema     = $ability->get_input_schema();
			$has_schema = is_array( $schema ) && array() !== $schema;
		}

		if ( $has_schema ) {
			return $arguments;
		}

		if ( null === $arguments || array() === $arguments || new \stdClass() == $arguments ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- an empty stdClass from json_decode is the shape being detected.
			return null;
		}

		return $arguments;
	}
}
