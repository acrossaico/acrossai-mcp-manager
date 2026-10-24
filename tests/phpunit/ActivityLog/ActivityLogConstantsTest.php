<?php
/**
 * Contract tests for ActivityLogConstants (Feature 096, T007).
 *
 * WP-free: the class exposes only `const` values, so it runs against
 * tests/bootstrap.php with no WordPress and no database.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ActivityLog;

use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants
 */
final class ActivityLogConstantsTest extends TestCase {

	/**
	 * A9 requires a shared-constant holder to be final, constants-only, with a
	 * private constructor and no instance state. Asserted by reflection rather
	 * than by reading the file, so a later refactor that adds state fails here.
	 */
	public function test_satisfies_the_a9_shared_constant_contract(): void {
		$ref = new ReflectionClass( ActivityLogConstants::class );

		$this->assertTrue( $ref->isFinal(), 'A9 holder must be final.' );
		$this->assertSame(
			array(),
			$ref->getProperties(),
			'A9 holder must hold no instance or static state.'
		);
		$this->assertSame(
			array(),
			$ref->getMethods( \ReflectionMethod::IS_PUBLIC ),
			'A9 holder must expose no public methods — constants only.'
		);

		$ctor = $ref->getConstructor();
		$this->assertNotNull( $ctor, 'Constructor must be declared.' );
		$this->assertTrue( $ctor->isPrivate(), 'Constructor must be private.' );
	}

	/**
	 * Every hook and option name must carry the plugin prefix. The constitution's
	 * Code Quality rule is unconditional, and these names are a published
	 * contract once shipped.
	 */
	public function test_every_option_and_hook_name_is_prefixed(): void {
		$ref  = new ReflectionClass( ActivityLogConstants::class );
		$seen = 0;

		foreach ( $ref->getConstants() as $name => $value ) {
			if ( 0 !== strpos( $name, 'OPTION_' ) && 0 !== strpos( $name, 'ACTION_' ) && 0 !== strpos( $name, 'FILTER_' ) ) {
				continue;
			}

			++$seen;
			$this->assertStringStartsWith(
				'acrossai_mcp_',
				(string) $value,
				sprintf( '%s must be prefixed acrossai_mcp_.', $name )
			);
		}

		$this->assertGreaterThan( 8, $seen, 'Expected the option/hook set to be non-trivial.' );
	}

	/**
	 * The removal event is what A24 requires: an audit log must be able to record
	 * its own destruction. If this vocabulary entry disappears, the purge has
	 * nothing to write and SEC-001 reopens.
	 */
	public function test_event_vocabulary_can_express_its_own_destruction(): void {
		$this->assertContains( ActivityLogConstants::EVENT_REQUEST, ActivityLogConstants::EVENT_TYPES );
		$this->assertContains( ActivityLogConstants::EVENT_ABILITY, ActivityLogConstants::EVENT_TYPES );
		$this->assertContains(
			ActivityLogConstants::EVENT_REMOVAL,
			ActivityLogConstants::EVENT_TYPES,
			'A24: removal must be a recordable event, or a purge leaves no trace.'
		);
		$this->assertSame(
			array_values( array_unique( ActivityLogConstants::EVENT_TYPES ) ),
			ActivityLogConstants::EVENT_TYPES,
			'Event vocabulary must be unique and ordered.'
		);
	}

	/**
	 * The recorder ignore list must already cover Feature 097's change log.
	 * Listing it from day one is what stops the third recorder needing an edit
	 * to this list — the edit that gets forgotten.
	 */
	public function test_ignore_list_covers_this_feature_and_the_next(): void {
		$this->assertContains( ActivityLogConstants::TABLE, ActivityLogConstants::IGNORED_TABLES );
		$this->assertContains(
			'acrossai_mcp_change_log',
			ActivityLogConstants::IGNORED_TABLES,
			'F097 recorder must be covered without editing this list.'
		);
		foreach ( ActivityLogConstants::IGNORED_TABLES as $table ) {
			$this->assertStringStartsNotWith(
				'wp_',
				$table,
				'Ignore list must hold unprefixed names — callers strip $wpdb->prefix.'
			);
		}
	}

	/**
	 * Reserved source values exist but are not yet written. They are the reason
	 * a later WebMCP or auth row does not need a schema change, and the reason
	 * we need not repeat easy-mcp-ai's fake-tool-name workaround.
	 */
	public function test_reserves_source_values_for_later_event_sources(): void {
		$this->assertSame( 'mcp', ActivityLogConstants::SOURCE_MCP );
		$this->assertSame( 'webmcp', ActivityLogConstants::SOURCE_WEBMCP );
		$this->assertSame( 'auth', ActivityLogConstants::SOURCE_AUTH );
	}

	/** Retention and the token divisor ship with the values the spec settled on. */
	public function test_defaults_match_the_specified_values(): void {
		$this->assertSame( 30, ActivityLogConstants::DEFAULT_RETENTION_REQUEST_DAYS );
		$this->assertSame( 7, ActivityLogConstants::DEFAULT_RETENTION_ABILITY_DAYS );
		$this->assertSame( 50000, ActivityLogConstants::DEFAULT_MAX_EVENTS );

		// Recording is ON before anyone chooses. Asserted rather than assumed:
		// flipping either of these after 096 ships is a retroactive behaviour
		// change on live sites, over a table holding source addresses and
		// recorded ability arguments. A diff that changes them should have to
		// change this test too, and explain itself here.
		$this->assertTrue( ActivityLogConstants::DEFAULT_REQUEST_LOGGING );
		$this->assertTrue( ActivityLogConstants::DEFAULT_ABILITY_LOGGING );

		// The asymmetry is the decision, so pin the RELATIONSHIP, not just the
		// numbers: ability rows were ~68% of volume when benchmarked and carry
		// the recorded arguments, so they must never outlive requests.
		$this->assertLessThan(
			ActivityLogConstants::DEFAULT_RETENTION_REQUEST_DAYS,
			ActivityLogConstants::DEFAULT_RETENTION_ABILITY_DAYS,
			'Ability runs are the noisier half and must not be kept longer than requests.'
		);
		$this->assertSame( 4, ActivityLogConstants::DEFAULT_BYTES_PER_TOKEN );
		$this->assertSame( '1.1.0', ActivityLogConstants::TABLE_VERSION );
		$this->assertSame( 'acrossai_mcp_activity_log', ActivityLogConstants::TABLE );
	}
}
