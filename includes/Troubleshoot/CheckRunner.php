<?php
/**
 * Runs the troubleshooter's checks in dependency order (Feature 101).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot;

use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\AnonymousRestCheck;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\CacheStalenessCheck;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\DiscoveryCheck;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\EdgeLayerCheck;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\RewriteRulesCheck;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\ServerStateCheck;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\UserAgentProbeCheck;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\WpCronCheck;

defined( 'ABSPATH' ) || exit;

/**
 * The run order is the diagnostic order: cheapest and most fundamental
 * first, so a failure appears next to its cause rather than five rows later
 * dressed as five separate problems.
 *
 * `EdgeLayerCheck` is deliberately last even though it has no prerequisites —
 * it reports on headers the earlier probes collected, so it has nothing to
 * say until they have run.
 */
final class CheckRunner {

	/**
	 * Every check class, in run order.
	 *
	 * Class names rather than instances, matching the `*_classes` filter
	 * shape the plugin already uses elsewhere. It also makes the validation
	 * below real: a string either names a class implementing the interface or
	 * it does not, which is a question that can be asked at runtime —
	 * `instanceof Check` on something already typed as a Check is not.
	 *
	 * @return array<int, class-string<Check>>
	 */
	public static function check_classes(): array {
		$classes = array(
			ServerStateCheck::class,
			RewriteRulesCheck::class,
			AnonymousRestCheck::class,
			DiscoveryCheck::class,
			CacheStalenessCheck::class,
			UserAgentProbeCheck::class,
			WpCronCheck::class,
			EdgeLayerCheck::class,
		);

		/**
		 * Filters the troubleshooter's checks.
		 *
		 * A companion plugin can append the class name of its own check.
		 * Order is preserved, so one appended here runs after every built-in
		 * check and may depend on their ids.
		 *
		 * @since 0.4.6
		 *
		 * @param array<int, string> $classes Built-in check classes, in run order.
		 */
		$filtered = (array) apply_filters( 'acrossai_mcp_troubleshoot_checks', $classes );

		$valid = array();
		foreach ( $filtered as $class ) {
			// A filter that returns something else must not take down the one
			// screen an operator reaches when things are already broken, so
			// anything that is not a Check implementation is dropped rather
			// than instantiated.
			if ( is_a( $class, Check::class, true ) ) {
				$valid[] = $class;
			}
		}

		return $valid;
	}

	/**
	 * Run everything and return the results as flat arrays.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function run_all(): array {
		$context = new Context();
		$results = array();

		foreach ( self::check_classes() as $class ) {
			$check = new $class();

			$blockers = $context->blockers( ...$check->requires() );

			$result = array() === $blockers
				? $check->run( $context )
				: Result::skipped( $check->id(), $check->label(), $blockers );

			$context->record( $result );
			$results[] = $result->to_array();
		}

		return $results;
	}
}
