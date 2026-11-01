<?php
/**
 * Runner behaviour: ordering, the class filter, and skip propagation.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Troubleshoot;

use AcrossAI_MCP_Manager\Includes\Troubleshoot\Check;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\CheckRunner;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks\RewriteRulesCheck;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;
use WP_UnitTestCase;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Troubleshoot\CheckRunner
 */
final class CheckRunnerTest extends WP_UnitTestCase {

	/**
	 * Remove any filter a test added, so one test cannot change another's
	 * check list.
	 */
	public function tear_down(): void {
		remove_all_filters( 'acrossai_mcp_troubleshoot_checks' );
		parent::tear_down();
	}

	public function test_every_built_in_check_implements_the_interface(): void {
		$classes = CheckRunner::check_classes();

		self::assertNotEmpty( $classes );

		foreach ( $classes as $class ) {
			self::assertTrue(
				is_a( $class, Check::class, true ),
				sprintf( '%s is registered but does not implement Check.', $class )
			);
		}
	}

	/**
	 * Run order is diagnostic order. The server switch is asked about before
	 * anything that probes a route only an enabled server creates, and the
	 * edge report is last because it reads headers the earlier probes
	 * collected.
	 */
	public function test_server_state_runs_first_and_edge_last(): void {
		$classes = CheckRunner::check_classes();

		self::assertStringEndsWith( 'ServerStateCheck', (string) reset( $classes ) );
		self::assertStringEndsWith( 'EdgeLayerCheck', (string) end( $classes ) );
	}

	public function test_rewrite_check_runs_before_everything_that_depends_on_it(): void {
		$ids = array();
		foreach ( CheckRunner::check_classes() as $class ) {
			$check        = new $class();
			$ids[]        = $check->id();
			$seen_so_far  = $ids;

			foreach ( $check->requires() as $required ) {
				self::assertContains(
					$required,
					$seen_so_far,
					sprintf( '%s requires "%s", which has not run yet.', $check->id(), $required )
				);
			}
		}
	}

	public function test_a_filter_can_append_a_check(): void {
		add_filter(
			'acrossai_mcp_troubleshoot_checks',
			static function ( array $classes ): array {
				$classes[] = AlwaysOkCheck::class;
				return $classes;
			}
		);

		self::assertContains( AlwaysOkCheck::class, CheckRunner::check_classes() );
	}

	/**
	 * A filter is other people's code running on the one screen an operator
	 * reaches when things are already broken. Garbage in it must not take the
	 * page down.
	 *
	 * @dataProvider garbage_provider
	 *
	 * @param mixed $returned Whatever the filter returns.
	 */
	public function test_garbage_from_a_filter_is_dropped_not_fatal( $returned ): void {
		add_filter(
			'acrossai_mcp_troubleshoot_checks',
			static function () use ( $returned ) {
				return $returned;
			}
		);

		$classes = CheckRunner::check_classes();

		// Always asserts, including when everything was dropped — the point
		// is that nothing invalid survives and nothing fatals, and "the list
		// came back empty" is a pass, not an untested path.
		self::assertSame(
			array(),
			array_filter(
				$classes,
				static function ( $class ): bool {
					return ! is_a( $class, Check::class, true );
				}
			),
			'A check class that does not implement Check must be dropped, not instantiated.'
		);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public function garbage_provider(): array {
		return array(
			'a string'            => array( 'not an array' ),
			'null'                => array( null ),
			'a class that is not a Check' => array( array( \stdClass::class ) ),
			'a name of nothing'   => array( array( 'AcrossAI\\NoSuchClass' ) ),
			'an integer'          => array( array( 42 ) ),
			'empty'               => array( array() ),
		);
	}

	/**
	 * A failed prerequisite must produce one honest "not run", naming the
	 * blocker — not a second red row restating the same cause.
	 */
	public function test_a_failed_prerequisite_skips_its_dependants(): void {
		add_filter(
			'acrossai_mcp_troubleshoot_checks',
			static function (): array {
				return array( AlwaysFailCheck::class, DependsOnFailureCheck::class );
			}
		);

		$results = CheckRunner::run_all();

		self::assertCount( 2, $results );
		self::assertSame( 'fail', $results[0]['status'] );
		self::assertSame( 'skipped', $results[1]['status'] );
		self::assertStringContainsString( 'A failing check', $results[1]['message'] );
	}

	/**
	 * A warning is not a failure. Only `fail` blocks, or a site with one
	 * honest caveat would report nothing else at all.
	 */
	public function test_a_passing_prerequisite_does_not_skip(): void {
		add_filter(
			'acrossai_mcp_troubleshoot_checks',
			static function (): array {
				return array( AlwaysOkCheck::class, DependsOnOkCheck::class );
			}
		);

		$results = CheckRunner::run_all();

		self::assertSame( 'ok', $results[0]['status'] );
		self::assertSame( 'ok', $results[1]['status'] );
	}

	/**
	 * Diagnostics must be safe to run repeatedly — the operator will press
	 * the button more than once.
	 */
	public function test_running_twice_produces_the_same_verdicts(): void {
		$first  = array_column( CheckRunner::run_all(), 'status', 'id' );
		$second = array_column( CheckRunner::run_all(), 'status', 'id' );

		self::assertSame( $first, $second );
	}

	public function test_every_result_carries_the_full_shape(): void {
		foreach ( CheckRunner::run_all() as $result ) {
			self::assertSame(
				array( 'id', 'status', 'label', 'message', 'remedy', 'copy' ),
				array_keys( $result )
			);
			self::assertNotSame( '', $result['id'] );
			self::assertNotSame( '', $result['label'] );
			self::assertNotSame( '', $result['message'], sprintf( '%s produced no message.', $result['id'] ) );
		}
	}
}

/** A check that always passes. */
final class AlwaysOkCheck implements Check {

	/** @return string */
	public function id(): string {
		return 'always_ok';
	}

	/** @return string */
	public function label(): string {
		return 'A passing check';
	}

	/** @return array<int, string> */
	public function requires(): array {
		return array();
	}

	/**
	 * @param Context $context Shared state.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		return Result::ok( $this->id(), $this->label(), 'Fine.' );
	}
}

/** A check that always fails. */
final class AlwaysFailCheck implements Check {

	/** @return string */
	public function id(): string {
		return 'always_fail';
	}

	/** @return string */
	public function label(): string {
		return 'A failing check';
	}

	/** @return array<int, string> */
	public function requires(): array {
		return array();
	}

	/**
	 * @param Context $context Shared state.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		return Result::fail( $this->id(), $this->label(), 'Broken.' );
	}
}

/** Depends on the failing check. */
final class DependsOnFailureCheck implements Check {

	/** @return string */
	public function id(): string {
		return 'depends_on_failure';
	}

	/** @return string */
	public function label(): string {
		return 'Downstream of a failure';
	}

	/** @return array<int, string> */
	public function requires(): array {
		return array( 'always_fail' );
	}

	/**
	 * @param Context $context Shared state.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		return Result::ok( $this->id(), $this->label(), 'Should never be reached.' );
	}
}

/** Depends on the passing check. */
final class DependsOnOkCheck implements Check {

	/** @return string */
	public function id(): string {
		return 'depends_on_ok';
	}

	/** @return string */
	public function label(): string {
		return 'Downstream of a pass';
	}

	/** @return array<int, string> */
	public function requires(): array {
		return array( 'always_ok' );
	}

	/**
	 * @param Context $context Shared state.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		return Result::ok( $this->id(), $this->label(), 'Reached.' );
	}
}
