<?php
/**
 * Tests for RegistryEntryNormalizer — the Feature 084 shared entry validator.
 *
 * Both the level-1 tab registry and the level-2 Connect method registry route
 * through this class, so a divergence here breaks the placeholder-override
 * pattern (D41) at both levels simultaneously.
 *
 * @package AcrossAI_MCP_Manager\Tests\Includes\Utilities
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Includes\Utilities;

use AcrossAI_MCP_Manager\Includes\Utilities\RegistryEntryNormalizer;
use WP_UnitTestCase;

final class RegistryEntryNormalizerTest extends WP_UnitTestCase {

	private const FILTER = 'acrossai_mcp_manager_test_filter';
	private const SINCE  = '0.4.0';

	/**
	 * Normalizes with this suite's fixed filter name and version.
	 *
	 * @param array<int, mixed> $raw Raw entries.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize( array $raw ): array {
		return RegistryEntryNormalizer::normalize( $raw, self::FILTER, self::SINCE );
	}

	/**
	 * A minimal valid third-party entry.
	 *
	 * @param string $slug     Entry slug.
	 * @param mixed  ...$unused Unused.
	 * @return array<string, mixed>
	 */
	private function entry( string $slug ): array {
		return array(
			'slug'            => $slug,
			'label'           => ucfirst( $slug ),
			'render_callback' => static fn () => null,
		);
	}

	// =========================================================================
	// Slug handling.
	// =========================================================================

	/**
	 * Slugs are passed through `sanitize_key()`.
	 */
	public function test_slug_is_sanitized(): void {
		$out = $this->normalize( array( array_merge( $this->entry( 'x' ), array( 'slug' => 'My Slug!' ) ) ) );

		$this->assertCount( 1, $out );
		$this->assertSame( 'myslug', $out[0]['slug'] );
	}

	/**
	 * An entry whose slug sanitizes to empty is dropped.
	 */
	public function test_empty_slug_is_dropped(): void {
		$this->setExpectedIncorrectUsage( self::FILTER );
		$this->assertSame( array(), $this->normalize( array( array_merge( $this->entry( 'x' ), array( 'slug' => '!!!' ) ) ) ) );
	}

	/**
	 * Non-array members are skipped without fataling.
	 */
	public function test_non_array_entries_are_skipped(): void {
		$out = $this->normalize( array( 'nope', 42, null, $this->entry( 'ok' ) ) );

		$this->assertCount( 1, $out );
		$this->assertSame( 'ok', $out[0]['slug'] );
	}

	// =========================================================================
	// Required fields on non-built-ins.
	// =========================================================================

	/**
	 * A non-built-in without a label is dropped.
	 */
	public function test_missing_label_drops_non_builtin(): void {
		$this->setExpectedIncorrectUsage( self::FILTER );
		$entry = $this->entry( 'x' );
		unset( $entry['label'] );

		$this->assertSame( array(), $this->normalize( array( $entry ) ) );
	}

	/**
	 * A non-built-in without a callable render_callback is dropped.
	 */
	public function test_non_callable_render_callback_drops_non_builtin(): void {
		$this->setExpectedIncorrectUsage( self::FILTER );
		$entry                    = $this->entry( 'x' );
		$entry['render_callback'] = 'definitely_not_a_function_name';

		$this->assertSame( array(), $this->normalize( array( $entry ) ) );
	}

	/**
	 * Built-ins are exempt — they render through their own class instance.
	 */
	public function test_builtin_survives_without_label_or_callback(): void {
		$out = $this->normalize(
			array(
				array(
					'slug'     => 'overview',
					'label'    => '',
					'_builtin' => true,
				),
			)
		);

		$this->assertCount( 1, $out );
		$this->assertTrue( $out[0]['_builtin'] );
	}

	// =========================================================================
	// Coercion + defaults.
	// =========================================================================

	/**
	 * Priority is coerced to int and defaults to 100.
	 */
	public function test_priority_coercion_and_default(): void {
		$out = $this->normalize(
			array(
				array_merge( $this->entry( 'a' ), array( 'priority' => '25' ) ),
				$this->entry( 'b' ),
			)
		);

		$this->assertSame( 25, $out[0]['priority'] );
		$this->assertSame( 100, $out[1]['priority'] );
	}

	/**
	 * Capability is sanitized, and empty falls back to `manage_options`.
	 */
	public function test_capability_sanitized_and_defaulted(): void {
		$out = $this->normalize(
			array(
				array_merge( $this->entry( 'a' ), array( 'capability' => '' ) ),
				array_merge( $this->entry( 'b' ), array( 'capability' => 'Edit Posts' ) ),
				$this->entry( 'c' ),
			)
		);

		$this->assertSame( 'manage_options', $out[0]['capability'] );
		$this->assertSame( 'editposts', $out[1]['capability'] );
		$this->assertSame( 'manage_options', $out[2]['capability'] );
	}

	/**
	 * A non-callable visible_callback is coerced to null rather than kept.
	 */
	public function test_non_callable_visible_callback_becomes_null(): void {
		$out = $this->normalize( array( array_merge( $this->entry( 'a' ), array( 'visible_callback' => 'nope' ) ) ) );

		$this->assertNull( $out[0]['visible_callback'] );
	}

	// =========================================================================
	// D41 — last-wins dedup.
	// =========================================================================

	/**
	 * A later same-slug registration replaces the earlier one.
	 */
	public function test_duplicate_slug_resolves_last_wins(): void {
		$out = $this->normalize(
			array(
				array_merge( $this->entry( 'dup' ), array( 'label' => 'First' ) ),
				array_merge( $this->entry( 'dup' ), array( 'label' => 'Second' ) ),
			)
		);

		$this->assertCount( 1, $out );
		$this->assertSame( 'Second', $out[0]['label'], 'D41: the LATER registration wins.' );
	}

	/**
	 * The winner inherits the earlier insertion index, so priority-tiebreak
	 * sorts stay stable.
	 */
	public function test_last_wins_preserves_original_insertion_order(): void {
		$out = $this->normalize(
			array(
				$this->entry( 'first' ),
				array_merge( $this->entry( 'second' ), array( 'label' => 'Original' ) ),
				$this->entry( 'third' ),
				array_merge( $this->entry( 'second' ), array( 'label' => 'Override' ) ),
			)
		);

		$slugs = array_column( $out, 'slug' );
		$this->assertSame( array( 'first', 'second', 'third' ), $slugs );
		$this->assertSame( 'Override', $out[1]['label'] );
	}

	/**
	 * The returned array is zero-indexed sequential, not slug-keyed —
	 * downstream sort + iteration depends on it.
	 */
	public function test_output_is_zero_indexed(): void {
		$out = $this->normalize( array( $this->entry( 'a' ), $this->entry( 'b' ) ) );

		$this->assertSame( array( 0, 1 ), array_keys( $out ) );
	}

	// =========================================================================
	// C7 — preserved invariants of the extracted doing_it_wrong() helper.
	// =========================================================================

	/**
	 * With `WP_DEBUG` off, a malformed entry is dropped SILENTLY.
	 *
	 * Malformed third-party registrations must never surface as notices to
	 * production administrators. `_doing_it_wrong()` fires a
	 * `doing_it_wrong_run` action, which is what this observes.
	 */
	public function test_no_doing_it_wrong_signal_when_wp_debug_off(): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->markTestSkipped( 'Suite runs with WP_DEBUG on; the off-path is asserted in a WP_DEBUG=false run.' );
		}

		$fired = false;
		add_action(
			'doing_it_wrong_run',
			static function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$entry = $this->entry( 'x' );
		unset( $entry['label'] );
		$this->assertSame( array(), $this->normalize( array( $entry ) ) );
		$this->assertFalse( $fired, 'C7: the WP_DEBUG gate MUST survive the extraction.' );

		remove_all_actions( 'doing_it_wrong_run' );
	}

	/**
	 * Under `WP_DEBUG`, the diagnostic names the offending filter and slug.
	 */
	public function test_doing_it_wrong_reports_filter_and_slug_under_debug(): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			$this->markTestSkipped( 'Requires WP_DEBUG.' );
		}

		$seen = array();
		add_action(
			'doing_it_wrong_run',
			static function ( $function_name, $message ) use ( &$seen ): void {
				$seen[] = array( (string) $function_name, (string) $message );
			},
			10,
			2
		);

		$this->setExpectedIncorrectUsage( self::FILTER );

		$entry = $this->entry( 'badentry' );
		unset( $entry['label'] );
		$this->normalize( array( $entry ) );

		remove_all_actions( 'doing_it_wrong_run' );

		$this->assertNotEmpty( $seen, 'A malformed entry MUST signal under WP_DEBUG.' );
		$this->assertSame( self::FILTER, $seen[0][0], 'The caller-supplied filter name MUST be reported.' );
		$this->assertStringContainsString( 'badentry', $seen[0][1] );
	}
}
