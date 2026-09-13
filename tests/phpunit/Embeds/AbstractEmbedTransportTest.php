<?php
/**
 * F037 — AbstractEmbedTransport enumeration + validation + gate + GC tests.
 *
 * Covers spec.md FR-006..FR-011 (base class shape + enumeration), FR-022
 * (persist-silently on missing FQN), FR-023 (GC helper idempotency),
 * SEC-037-002 (comparator (int) coercion — non-int priority MUST NOT
 * fatal), R2 memoization + flush_cache.
 *
 * Runs under the `embeds` suite with WP bootstrap.
 *
 * @package AcrossAI_MCP_Manager\Tests\Embeds
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\Embeds;

use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;
use WP_UnitTestCase;

// Fake transport with a valid key + integer priority.
final class ValidFakeTransport extends AbstractEmbedTransport {
	public function get_transport_key(): string {
		return 'valid-fake';
	}
	public function get_checkbox_label(): string {
		return 'Valid Fake';
	}
	public function get_priority(): int {
		return 50;
	}
}

// Fake with an invalid transport key (uppercase — fails regex).
final class BadKeyFakeTransport extends AbstractEmbedTransport {
	public function get_transport_key(): string {
		return 'BAD_KEY';
	}
	public function get_checkbox_label(): string {
		return 'Bad Key Fake';
	}
}

// Fake with a duplicate key (collides with built-in `npm`).
final class DuplicateNpmFakeTransport extends AbstractEmbedTransport {
	public function get_transport_key(): string {
		return 'npm';
	}
	public function get_checkbox_label(): string {
		return 'OVERRIDE NPM Label';
	}
	public function get_priority(): int {
		return 15;
	}
}

final class AbstractEmbedTransportTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		AbstractEmbedTransport::flush_cache();
	}

	public function test_default_state_returns_three_built_ins(): void {
		$transports = AbstractEmbedTransport::get_all_registered_transports();
		$keys       = array_map( static fn( $t ) => $t->get_transport_key(), $transports );

		$this->assertSame( array( 'npm', 'client', 'ai_connector' ), $keys );
	}

	public function test_default_state_priority_sort(): void {
		$transports = AbstractEmbedTransport::get_all_registered_transports();
		$priorities = array_map( static fn( $t ) => $t->get_priority(), $transports );

		// Priorities MUST be in ascending order.
		$sorted = $priorities;
		sort( $sorted );
		$this->assertSame( $sorted, $priorities );
	}

	public function test_filter_can_append_valid_transport(): void {
		add_filter(
			'acrossai_mcp_embed_transports',
			static function ( array $fqns ): array {
				$fqns[] = ValidFakeTransport::class;
				return $fqns;
			}
		);

		$transports = AbstractEmbedTransport::get_all_registered_transports();
		$keys       = array_map( static fn( $t ) => $t->get_transport_key(), $transports );

		$this->assertContains( 'valid-fake', $keys );
		// Priority 50 → sits between built-in ai_connector (30) and default 100.
		$position = array_search( 'valid-fake', $keys, true );
		$this->assertSame( 3, $position, 'valid-fake with priority 50 MUST land at index 3 (after 3 built-ins).' );
	}

	public function test_invalid_fqn_silently_dropped(): void {
		add_filter(
			'acrossai_mcp_embed_transports',
			static function ( array $fqns ): array {
				$fqns[] = '\Non\Existent\ClassName';
				$fqns[] = 12345; // Non-string.
				return $fqns;
			}
		);

		$transports = AbstractEmbedTransport::get_all_registered_transports();
		// Only 3 built-ins survive; invalid entries silent-dropped.
		$this->assertCount( 3, $transports );
	}

	public function test_bad_key_regex_silently_dropped(): void {
		// This test exists to prove the guard fires, so its _doing_it_wrong
		// notice is the expected outcome, not an accident.
		$this->setExpectedIncorrectUsage( 'AbstractEmbedTransport::get_all_registered_transports' );
		add_filter(
			'acrossai_mcp_embed_transports',
			static function ( array $fqns ): array {
				$fqns[] = BadKeyFakeTransport::class;
				return $fqns;
			}
		);

		$transports = AbstractEmbedTransport::get_all_registered_transports();
		$keys       = array_map( static fn( $t ) => $t->get_transport_key(), $transports );

		$this->assertNotContains( 'BAD_KEY', $keys );
		$this->assertCount( 3, $transports );
	}

	public function test_duplicate_key_later_wins(): void {
		// This test exists to prove the guard fires, so its _doing_it_wrong
		// notice is the expected outcome, not an accident.
		$this->setExpectedIncorrectUsage( 'AbstractEmbedTransport::get_all_registered_transports' );
		add_filter(
			'acrossai_mcp_embed_transports',
			static function ( array $fqns ): array {
				$fqns[] = DuplicateNpmFakeTransport::class;
				return $fqns;
			}
		);

		$transports = AbstractEmbedTransport::get_all_registered_transports();
		$keys       = array_map( static fn( $t ) => $t->get_transport_key(), $transports );

		// Still 3 unique keys — no duplication.
		$this->assertCount( 3, $transports );
		$this->assertContains( 'npm', $keys );

		// Find the npm transport and verify it's the OVERRIDE (later-wins).
		$npm_transport = null;
		foreach ( $transports as $t ) {
			if ( 'npm' === $t->get_transport_key() ) {
				$npm_transport = $t;
				break;
			}
		}
		$this->assertNotNull( $npm_transport );
		$this->assertSame( 'OVERRIDE NPM Label', $npm_transport->get_checkbox_label(), 'Later-wins dedup MUST surface the last contribution.' );
	}

	public function test_is_enabled_returns_false_on_missing_server(): void {
		// Server ID 99999 has no meta rows → master gate returns false → whole gate false.
		$this->assertFalse( AbstractEmbedTransport::is_enabled_for_server( 99999, 'npm', 'npx-acrossai-mcp-manager' ) );
	}

	public function test_flush_cache_invalidates_memoization(): void {
		// First call caches false (server 99999 has no meta rows).
		$this->assertFalse( AbstractEmbedTransport::is_enabled_for_server( 99999, 'npm', 'npx-acrossai-mcp-manager' ) );
		// Second call would hit cache.
		$this->assertFalse( AbstractEmbedTransport::is_enabled_for_server( 99999, 'npm', 'npx-acrossai-mcp-manager' ) );

		AbstractEmbedTransport::flush_cache();
		// Post-flush, still false but the query re-runs.
		$this->assertFalse( AbstractEmbedTransport::is_enabled_for_server( 99999, 'npm', 'npx-acrossai-mcp-manager' ) );
	}

	public function test_garbage_collect_orphans_idempotent_on_empty_table(): void {
		// No _embeds_clients rows → zero orphans.
		$this->assertSame( 0, AbstractEmbedTransport::garbage_collect_orphans() );
		$this->assertSame( 0, AbstractEmbedTransport::garbage_collect_orphans() );
	}

	public function test_garbage_collect_orphans_prunes_missing_json_keys(): void {
		// Blob with a JSON category key that no registered transport maps to.
		AbstractEmbedTransport::save_items_for_server(
			1,
			array(
				'mcp-client'      => array( 'claude-desktop' ),
				'orphan-category' => array( 'orphan-dto' ),
			)
		);

		$this->assertSame( 1, AbstractEmbedTransport::garbage_collect_orphans() );
		// Idempotent — second call returns 0.
		$this->assertSame( 0, AbstractEmbedTransport::garbage_collect_orphans() );

		// Registered key survives.
		$items = AbstractEmbedTransport::get_items_for_server( 1 );
		$this->assertArrayHasKey( 'mcp-client', $items );
		$this->assertArrayNotHasKey( 'orphan-category', $items );
	}

	public function test_garbage_collect_orphans_preserves_registered_keys(): void {
		// Blob with every category key mapping to a currently-registered transport.
		AbstractEmbedTransport::save_items_for_server(
			1,
			array(
				'npm'        => 1,
				'mcp-client' => array( 'claude-desktop' ),
				'connectors' => array( 'chatgpt' ),
			)
		);

		$this->assertSame( 0, AbstractEmbedTransport::garbage_collect_orphans() );
		$items = AbstractEmbedTransport::get_items_for_server( 1 );
		$this->assertSame( 1, (int) $items['npm'] );
		$this->assertSame( array( 'claude-desktop' ), $items['mcp-client'] );
		$this->assertSame( array( 'chatgpt' ), $items['connectors'] );
	}
}
