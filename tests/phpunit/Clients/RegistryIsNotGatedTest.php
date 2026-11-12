<?php
/**
 * The regression this whole design exists to prevent (Feature 104).
 *
 * `HandlerResolver` used to hand the adapter `NullMcpObservabilityHandler`
 * whenever request logging was off, and the activity log's own docs say the
 * recording path is then "never reached at all". Had the client row been
 * written from inside that handler, turning a diagnostic log off would also
 * have stopped recording who connects to the site — silently, with an empty
 * Clients list as the only symptom, which looks exactly like a quiet site.
 *
 * It is the kind of coupling that is obvious while the feature is being built
 * and invisible six months later, so it is pinned here rather than explained
 * in a comment.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Clients
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Clients;

use AcrossAI_MCP_Manager\Includes\Observability\ActivityObservabilityHandler;
use AcrossAI_MCP_Manager\Includes\Observability\ConnectionObservabilityHandler;
use AcrossAI_MCP_Manager\Includes\Observability\HandlerResolver;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class RegistryIsNotGatedTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['__acrossai_test_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['__acrossai_test_options'] = array();

		parent::tearDown();
	}

	/**
	 * With request logging OFF the adapter must still get a handler that
	 * records client sightings — never the Null one.
	 */
	public function test_logging_off_still_installs_a_recording_handler(): void {
		$GLOBALS['__acrossai_test_options'][ ActivityLogConstants::OPTION_REQUEST_LOGGING ] = false;

		$class = HandlerResolver::class_string();

		$this->assertSame(
			ConnectionObservabilityHandler::class,
			$class,
			'With request logging off the resolver must return the connection handler. Returning the Null handler puts the clients registry behind the activity-log toggle.'
		);
	}

	/**
	 * The Null handler must not be reachable from the resolver at all. Stated
	 * separately from the test above because a future third toggle could
	 * reintroduce it on a path that still passes that one.
	 *
	 * @dataProvider provideToggleStates
	 */
	public function test_the_null_handler_is_never_returned( $logging ): void {
		$GLOBALS['__acrossai_test_options'][ ActivityLogConstants::OPTION_REQUEST_LOGGING ] = $logging;

		$this->assertNotSame(
			'WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler',
			ltrim( HandlerResolver::class_string(), '\\' )
		);
	}

	public function provideToggleStates(): array {
		return array(
			'logging off' => array( false ),
			'logging on'  => array( true ),
			'unset'       => array( null ),
		);
	}

	/**
	 * With logging ON the adapter gets the activity handler — and because it
	 * EXTENDS the connection handler, the client row is recorded on that path
	 * too. One handler slot, both concerns.
	 */
	public function test_logging_on_still_records_the_client(): void {
		$GLOBALS['__acrossai_test_options'][ ActivityLogConstants::OPTION_REQUEST_LOGGING ] = true;

		$class = HandlerResolver::class_string();

		$this->assertSame( ActivityObservabilityHandler::class, $class );

		$this->assertTrue(
			is_subclass_of( $class, ConnectionObservabilityHandler::class ),
			'ActivityObservabilityHandler must extend ConnectionObservabilityHandler, or enabling the activity log silently disables the clients registry.'
		);
	}

	/**
	 * Whichever handler the resolver picks, it records client sightings.
	 *
	 * The assertion the two tests above are really making, in one line that
	 * survives either of them being rewritten.
	 *
	 * @dataProvider provideToggleStates
	 */
	public function test_every_resolved_handler_records_clients( $logging ): void {
		$GLOBALS['__acrossai_test_options'][ ActivityLogConstants::OPTION_REQUEST_LOGGING ] = $logging;

		$class = HandlerResolver::class_string();

		$this->assertTrue(
			ConnectionObservabilityHandler::class === $class
				|| is_subclass_of( $class, ConnectionObservabilityHandler::class ),
			"The resolver returned {$class}, which does not record client sightings."
		);
	}
}
