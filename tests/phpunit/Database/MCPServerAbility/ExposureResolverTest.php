<?php
/**
 * Feature 017 — ExposureResolver test.
 *
 * FR-007 fallback invariant + FR-008 single-source-of-truth invariant.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database\MCPServerAbility
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database\MCPServerAbility;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class ExposureResolverTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Table::instance()->maybe_upgrade();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $wpdb->prefix . 'acrossai_mcp_server_abilities' ) );
		ExposureResolver::_reset_cache_for_tests();
	}

	public function test_row_is_exposed_true_overrides_falsy_meta(): void {
		Query::instance()->upsert( 1, 'core/get-user-info', true );
		$this->assertTrue( ExposureResolver::resolve_row_only( 1, 'core/get-user-info', array( 'mcp' => array( 'public' => false ) ) ) );
	}

	public function test_row_is_exposed_false_overrides_truthy_meta(): void {
		Query::instance()->upsert( 1, 'core/get-user-info', false );
		$this->assertFalse( ExposureResolver::resolve_row_only( 1, 'core/get-user-info', array( 'mcp' => array( 'public' => true ) ) ) );
	}

	public function test_no_row_falls_back_to_meta_true(): void {
		$this->assertTrue( ExposureResolver::resolve_row_only( 1, 'core/get-user-info', array( 'mcp' => array( 'public' => true ) ) ) );
	}

	public function test_no_row_falls_back_to_meta_missing(): void {
		$this->assertFalse( ExposureResolver::resolve_row_only( 1, 'core/get-user-info', array() ) );
	}

	public function test_no_row_falls_back_to_meta_false(): void {
		$this->assertFalse( ExposureResolver::resolve_row_only( 1, 'core/get-user-info', array( 'mcp' => array( 'public' => false ) ) ) );
	}

	public function test_per_request_cache_avoids_second_query(): void {
		Query::instance()->upsert( 1, 'core/get-user-info', true );
		$first  = ExposureResolver::resolve_row_only( 1, 'core/get-user-info', array() );
		// Mutate the row directly and re-resolve — expect the CACHED value
		// (proves the second call did not hit the DB).
		Query::instance()->upsert( 1, 'core/get-user-info', false );
		$second = ExposureResolver::resolve_row_only( 1, 'core/get-user-info', array() );
		$this->assertTrue( $first, 'first read must be true' );
		$this->assertTrue( $second, 'second read must return cached true — did not hit DB after row was flipped' );
	}

	/**
	 * F082 — per-request cache for `resolve_effective()`.
	 *
	 * Mirrors the F017 `test_per_request_cache_avoids_second_query` for the
	 * row-only method. Verifies the new `$effective_cache` static holds
	 * across sequential calls on the same key — mutating the underlying row
	 * between calls MUST return the cached value, proving no DB hit.
	 *
	 * @return void
	 */
	public function test_resolve_effective_per_request_cache_avoids_second_query(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$table,
			array(
				'server_name'              => 'F082 effective-cache test',
				'server_slug'              => 'f082-eff-cache-' . uniqid(),
				'is_enabled'               => 1,
				'abilities_default_policy' => 'per-ability',
			),
			array( '%s', '%s', '%d', '%s' )
		);
		$server_id = (int) $wpdb->insert_id;

		Query::instance()->upsert( $server_id, 'core/get-user-info', true );
		ExposureResolver::_reset_cache_for_tests();

		$first = ExposureResolver::resolve_effective( $server_id, 'core/get-user-info', array() );

		// Mutate the row + policy directly. Cache should NOT reflect either change.
		Query::instance()->upsert( $server_id, 'core/get-user-info', false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->update( $table, array( 'abilities_default_policy' => 'hide' ), array( 'id' => $server_id ), array( '%s' ), array( '%d' ) );

		$second = ExposureResolver::resolve_effective( $server_id, 'core/get-user-info', array() );

		$this->assertTrue( $first, 'First resolve_effective call MUST see the seeded is_exposed=1 row.' );
		$this->assertTrue( $second, 'Second call MUST return cached true — did not hit DB after row + policy flipped.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->delete( $table, array( 'id' => $server_id ), array( '%d' ) );
	}

	/**
	 * F082 — `_reset_cache_for_tests()` MUST reset all THREE caches atomically
	 * (row-only $cache, effective $effective_cache, server-policy $policy_cache).
	 * If any cache survives the reset, downstream tests get stale reads.
	 *
	 * @return void
	 */
	public function test_reset_cache_clears_all_three_caches(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$table,
			array(
				'server_name'              => 'F082 reset-cache test',
				'server_slug'              => 'f082-reset-' . uniqid(),
				'is_enabled'               => 1,
				'abilities_default_policy' => 'expose',
			),
			array( '%s', '%s', '%d', '%s' )
		);
		$server_id = (int) $wpdb->insert_id;

		// Prime all three caches.
		Query::instance()->upsert( $server_id, 'core/prime-row-cache', true );
		ExposureResolver::_reset_cache_for_tests();
		ExposureResolver::resolve_row_only( $server_id, 'core/prime-row-cache', array() ); // primes $cache
		ExposureResolver::resolve_effective( $server_id, 'core/prime-effective-cache', array() ); // primes $effective_cache + $policy_cache

		// Flip the underlying row + drop policy — the caches would return the pre-flip values.
		Query::instance()->upsert( $server_id, 'core/prime-row-cache', false );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->update( $table, array( 'abilities_default_policy' => 'hide' ), array( 'id' => $server_id ), array( '%s' ), array( '%d' ) );

		// Reset. Every subsequent read MUST hit the fresh DB state.
		ExposureResolver::_reset_cache_for_tests();

		$this->assertFalse(
			ExposureResolver::resolve_row_only( $server_id, 'core/prime-row-cache', array() ),
			'After reset, resolve_row_only MUST see the flipped is_exposed=0 row (row-only $cache cleared).'
		);
		$this->assertFalse(
			ExposureResolver::resolve_effective( $server_id, 'core/prime-effective-cache', array() ),
			'After reset, resolve_effective on a policy=hide server without a row MUST return false ($effective_cache + $policy_cache cleared).'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->delete( $table, array( 'id' => $server_id ), array( '%d' ) );
	}

	/**
	 * F082 — `server_policy()` MUST default to `'per-ability'` for nonexistent
	 * server ids. Prevents fail-open behaviour on stale caller state.
	 *
	 * Indirectly tested via `resolve_effective()` on a server_id that doesn't
	 * exist — with no row and no server, only the meta fallback wins, matching
	 * pre-F082 semantics exactly.
	 *
	 * @return void
	 */
	public function test_resolve_effective_falls_back_to_meta_when_server_row_absent(): void {
		ExposureResolver::_reset_cache_for_tests();

		$nonexistent = 999999;

		$this->assertFalse(
			ExposureResolver::resolve_effective( $nonexistent, 'core/any-slug', array() ),
			'Nonexistent server + empty meta → false (server_policy() defaults to per-ability + meta fallback).'
		);
		$this->assertTrue(
			ExposureResolver::resolve_effective( $nonexistent, 'core/any-slug-2', array( 'mcp' => array( 'public' => true ) ) ),
			'Nonexistent server + meta.mcp.public=true → true (server_policy() defaults to per-ability + meta wins).'
		);
	}

	/**
	 * F082 — `server_policy()` MUST reject unknown DB values and fall back to
	 * `'per-ability'`. Prevents a corrupted or migrated-from-external-source
	 * policy string from producing undefined behaviour in `resolve_effective`.
	 *
	 * @return void
	 */
	public function test_server_policy_rejects_unknown_db_value(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$table,
			array(
				'server_name'              => 'F082 bad-policy-value test',
				'server_slug'              => 'f082-bad-policy-' . uniqid(),
				'is_enabled'               => 1,
				// Must fit varchar(16) — an over-length value would be rejected
				// outright by strict-mode MySQL instead of exercising the guard.
				'abilities_default_policy' => 'garbage_value',
			),
			array( '%s', '%s', '%d', '%s' )
		);
		$server_id = (int) $wpdb->insert_id;

		ExposureResolver::_reset_cache_for_tests();

		// With a garbage policy, resolve_effective MUST fall through to the meta
		// fallback (matching pre-F082 semantics) rather than crashing or leaking.
		// Distinct slugs per call — the resolver caches by "{server_id}:{slug}",
		// so reusing one slug would return the first call's cached value.
		$this->assertFalse(
			ExposureResolver::resolve_effective( $server_id, 'core/slug', array() ),
			'Garbage policy + no row + no meta → false (whitelist guard in server_policy defaults to per-ability + meta fallback).'
		);
		$this->assertTrue(
			ExposureResolver::resolve_effective( $server_id, 'core/slug-2', array( 'mcp' => array( 'public' => true ) ) ),
			'Garbage policy + meta.mcp.public=true → true (meta fallback wins).'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->delete( $table, array( 'id' => $server_id ), array( '%d' ) );
	}

	/**
	 * F082 T034 — row override wins over `policy='expose'` server default.
	 *
	 * Even on a server that opted into "expose every ability by default", an
	 * explicit `is_exposed=0` row for slug X MUST hide X. Per spec FR-004.
	 *
	 * @return void
	 */
	public function test_resolve_effective_row_wins_over_expose_policy(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$table,
			array(
				'server_name'              => 'F082 T034 expose+deny row',
				'server_slug'              => 'f082-t034-expose-' . uniqid(),
				'is_enabled'               => 1,
				'abilities_default_policy' => 'expose',
			),
			array( '%s', '%s', '%d', '%s' )
		);
		$server_id = (int) $wpdb->insert_id;

		Query::instance()->upsert( $server_id, 'core/get-user-info', false );
		ExposureResolver::_reset_cache_for_tests();

		$this->assertFalse(
			ExposureResolver::resolve_effective( $server_id, 'core/get-user-info', array( 'mcp' => array( 'public' => true ) ) ),
			'On policy=expose server, an is_exposed=0 row MUST override to false (FR-004 row wins).'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->delete( $table, array( 'id' => $server_id ), array( '%d' ) );
	}

	/**
	 * F082 T034 — row override wins over `policy='hide'` server default.
	 *
	 * Even on a locked-down server, an explicit `is_exposed=1` row for slug Y
	 * MUST expose Y. Per spec FR-004.
	 *
	 * @return void
	 */
	public function test_resolve_effective_row_wins_over_hide_policy(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$table,
			array(
				'server_name'              => 'F082 T034 hide+allow row',
				'server_slug'              => 'f082-t034-hide-' . uniqid(),
				'is_enabled'               => 1,
				'abilities_default_policy' => 'hide',
			),
			array( '%s', '%s', '%d', '%s' )
		);
		$server_id = (int) $wpdb->insert_id;

		Query::instance()->upsert( $server_id, 'core/get-user-info', true );
		ExposureResolver::_reset_cache_for_tests();

		$this->assertTrue(
			ExposureResolver::resolve_effective( $server_id, 'core/get-user-info', array( 'mcp' => array( 'public' => false ) ) ),
			'On policy=hide server, an is_exposed=1 row MUST override to true (FR-004 row wins).'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->delete( $table, array( 'id' => $server_id ), array( '%d' ) );
	}

	/**
	 * F082 T034 — `policy='per-ability'` server falls through to meta.mcp.public.
	 *
	 * When no row exists AND policy is 'per-ability', resolve_effective MUST
	 * return the same value as resolve_row_only (byte-for-byte pre-F082 parity).
	 *
	 * @return void
	 */
	public function test_resolve_effective_per_ability_policy_matches_row_only(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$table,
			array(
				'server_name'              => 'F082 T034 per-ability parity',
				'server_slug'              => 'f082-t034-per-' . uniqid(),
				'is_enabled'               => 1,
				'abilities_default_policy' => 'per-ability',
			),
			array( '%s', '%s', '%d', '%s' )
		);
		$server_id = (int) $wpdb->insert_id;

		ExposureResolver::_reset_cache_for_tests();

		// No row, meta.mcp.public=true → both methods return true.
		$this->assertTrue(
			ExposureResolver::resolve_effective( $server_id, 'core/some-slug', array( 'mcp' => array( 'public' => true ) ) )
		);
		$this->assertTrue(
			ExposureResolver::resolve_row_only( $server_id, 'core/some-slug', array( 'mcp' => array( 'public' => true ) ) )
		);

		ExposureResolver::_reset_cache_for_tests();

		// No row, meta empty → both methods return false.
		$this->assertFalse(
			ExposureResolver::resolve_effective( $server_id, 'core/other-slug', array() )
		);
		$this->assertFalse(
			ExposureResolver::resolve_row_only( $server_id, 'core/other-slug', array() )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->delete( $table, array( 'id' => $server_id ), array( '%d' ) );
	}

	/**
	 * F082 merge-blocker regression fence for the F030 row-only bypass invariant.
	 *
	 * F030's `PermissionOverrideProcessor::should_bypass()` at
	 * `includes/Abilities/PermissionOverrideProcessor.php:150` calls
	 * `ExposureResolver::resolve_row_only( $server_id, $slug, array() )` with
	 * empty meta as a row-existence probe. If someone widens the row-only
	 * method to consult the server-level policy, this test starts returning
	 * true on a `policy='expose'` server with no row — and the F030
	 * permission-callback bypass silently widens to every ability.
	 *
	 * See docs/planings-tasks/082-per-server-ability-policy-defaults.md
	 * (CONSTRAINTS + TASK-9 F030 regression fence).
	 *
	 * @return void
	 */
	public function test_resolve_row_only_still_row_only_for_f030(): void {
		global $wpdb;

		// Seed a server with policy='expose' and NO override row for slug X.
		$table = $wpdb->prefix . 'acrossai_mcp_servers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$table,
			array(
				'server_name'              => 'F082 fence test server',
				'server_slug'              => 'f082-fence-' . uniqid(),
				'is_enabled'               => 1,
				'abilities_default_policy' => 'expose',
			),
			array( '%s', '%s', '%d', '%s' )
		);
		$server_id = (int) $wpdb->insert_id;

		ExposureResolver::_reset_cache_for_tests();

		// F030 uses resolve_row_only() with empty meta as a row-existence probe.
		// If someone widens resolve_row_only() to honour server policy, this
		// test starts returning true and the F030 bypass silently widens.
		$this->assertFalse(
			ExposureResolver::resolve_row_only( $server_id, 'core/example-slug', array() ),
			'F082 review-gate: ExposureResolver::resolve_row_only() MUST remain row-only. '
			. 'F030 depends on this exact behaviour. Do not widen resolve_row_only() to '
			. 'consult server policy — add or update resolve_effective() instead. '
			. 'See docs/planings-tasks/082-per-server-ability-policy-defaults.md CONSTRAINTS.'
		);

		// Sanity: the effective resolver, WITH server policy, DOES flip.
		$this->assertTrue(
			ExposureResolver::resolve_effective( $server_id, 'core/example-slug', array() ),
			'resolve_effective() must honour policy=expose.'
		);

		// Cleanup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->delete( $table, array( 'id' => $server_id ), array( '%d' ) );
	}
}
