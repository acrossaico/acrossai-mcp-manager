<?php
/**
 * Tests for Connect\MethodRegistry — the Feature 084 level-2 method registry.
 *
 * PHPUnit 13+ note (per BUGS.md B9): use `#[DataProvider]` PHP attribute
 * instead of `@dataProvider` annotation — the annotation is silently ignored.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\ServerTabs\Connect
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs\Connect;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Connect\MethodRegistry;
use ReflectionClass;
use WP_UnitTestCase;

final class MethodRegistryTest extends WP_UnitTestCase {

	/**
	 * A database-source server row.
	 *
	 * @var array<string, mixed>
	 */
	private array $server = array(
		'id'              => 7,
		'registered_from' => 'database',
	);

	/**
	 * The per-server Edit screen is `manage_options`-gated, so every method in
	 * these suites is evaluated as an administrator. Without this, C2's
	 * capability pre-filter correctly yields an empty method set.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Removes any filter callback the current test registered.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( MethodRegistry::FILTER_NAME );
		parent::tear_down();
	}

	/**
	 * Maps the visible method list to slugs.
	 *
	 * @return string[]
	 */
	private function visible_slugs(): array {
		return array_map(
			static fn ( $method ) => $method->slug(),
			MethodRegistry::instance()->visible_methods( $this->server )
		);
	}

	// =========================================================================
	// Shape — singleton, S6, and the C2 accessor contract.
	// =========================================================================

	/**
	 * Singleton identity.
	 */
	public function test_instance_returns_singleton(): void {
		$this->assertSame( MethodRegistry::instance(), MethodRegistry::instance() );
	}

	/**
	 * S6 — the constructor MUST be private.
	 */
	public function test_constructor_is_private(): void {
		$ctor = ( new ReflectionClass( MethodRegistry::class ) )->getConstructor();
		$this->assertNotNull( $ctor );
		$this->assertTrue( $ctor->isPrivate(), 'S6: singleton constructors MUST be private.' );
	}

	/**
	 * C2 / D53 — `visible_methods()` is the SOLE public read path.
	 *
	 * There must be no public accessor returning an unfiltered list, and in
	 * particular none named `for_server()` — that name means *unfiltered* in the
	 * sibling `ServerTabs\Registry`, and reusing it here with inverted semantics
	 * is the mismatch decision D53 exists to prevent.
	 */
	public function test_no_public_unfiltered_accessor_exists(): void {
		$reflection = new ReflectionClass( MethodRegistry::class );

		$this->assertFalse(
			$reflection->hasMethod( 'for_server' ),
			'C2/D53: MethodRegistry MUST NOT expose for_server() — the name means unfiltered in the sibling registry.'
		);

		$public = array_map(
			static fn ( $m ) => $m->getName(),
			$reflection->getMethods( \ReflectionMethod::IS_PUBLIC )
		);
		sort( $public );

		$this->assertSame(
			array( 'all_methods', 'instance', 'visible_methods' ),
			$public,
			'Only the filtered read path, the built-in seed list and instance() may be public.'
		);
	}

	/**
	 * C8 — the registry MUST NOT memoize.
	 *
	 * `visible_methods()` embeds `current_user_can()` results, so a cache keyed
	 * on server id alone would serve one user's permitted set to another.
	 */
	public function test_visible_methods_is_not_memoized(): void {
		$before = $this->visible_slugs();

		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ): array {
				return array_values(
					array_filter(
						$methods,
						static fn ( array $entry ): bool => 'npm' !== ( $entry['slug'] ?? '' )
					)
				);
			}
		);

		$this->assertContains( 'npm', $before );
		$this->assertNotContains( 'npm', $this->visible_slugs(), 'C8: results MUST NOT be cached across calls.' );
	}

	// =========================================================================
	// Seeding + ordering.
	// =========================================================================

	/**
	 * The four built-ins seed at their reserved priorities, with 40 free.
	 */
	public function test_builtin_seeding_and_reserved_priority(): void {
		$priorities = array();
		foreach ( MethodRegistry::instance()->all_methods() as $method ) {
			$priorities[ $method->slug() ] = $method->priority();
		}

		$this->assertSame(
			array(
				'ai-connectors' => 10,
				'clients'       => 20,
				'npm'           => 30,
				'wp-cli'        => 50,
			),
			$priorities
		);
		$this->assertNotContains( 40, $priorities, 'Priority 40 is reserved for the companion n8n method.' );
	}

	/**
	 * Methods come back sorted ascending by priority.
	 */
	public function test_visible_methods_are_priority_ordered(): void {
		$this->assertSame(
			array( 'ai-connectors', 'clients', 'npm', 'wp-cli' ),
			$this->visible_slugs()
		);
	}

	// =========================================================================
	// Filter contract.
	// =========================================================================

	/**
	 * A third-party method appears at its requested position.
	 */
	public function test_third_party_method_registers_at_its_priority(): void {
		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ): array {
				$methods[] = array(
					'slug'            => 'ftp',
					'label'           => 'FTP',
					'priority'        => 25,
					'render_callback' => static fn () => print( 'ftp body' ),
				);
				return $methods;
			}
		);

		$this->assertSame(
			array( 'ai-connectors', 'clients', 'ftp', 'npm', 'wp-cli' ),
			$this->visible_slugs(),
			'Priority 25 slots between clients (20) and npm (30).'
		);
	}

	/**
	 * D41 — a later same-slug registration REPLACES the built-in.
	 *
	 * This is the mechanism by which the acrossai-pro companion swaps its real
	 * Connectors method in for the free promotional card.
	 */
	public function test_last_wins_replaces_a_builtin(): void {
		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ): array {
				$methods[] = array(
					'slug'            => 'ai-connectors',
					'label'           => 'Real Connectors',
					'priority'        => 10,
					'render_callback' => static fn () => print( 'REAL CONNECTORS' ),
				);
				return $methods;
			}
		);

		$methods = MethodRegistry::instance()->visible_methods( $this->server );
		$slugs   = array_map( static fn ( $m ) => $m->slug(), $methods );

		$this->assertSame( 1, array_count_values( $slugs )['ai-connectors'], 'Slug MUST appear exactly once.' );
		$this->assertSame( 'Real Connectors', $methods[0]->label(), 'The LATER registration MUST win (D41).' );
	}

	/**
	 * C2 — an entry whose capability the user lacks is filtered out and its
	 * render callback is never invoked.
	 */
	public function test_capability_excluded_method_is_hidden_and_never_rendered(): void {
		$render_ran = false;

		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ) use ( &$render_ran ): array {
				$methods[] = array(
					'slug'            => 'restricted',
					'label'           => 'Restricted',
					'priority'        => 15,
					'capability'      => 'do_not_grant_this',
					'render_callback' => static function () use ( &$render_ran ): void {
						$render_ran = true;
					},
				);
				return $methods;
			}
		);

		$this->assertNotContains( 'restricted', $this->visible_slugs() );
		$this->assertFalse( $render_ran );
	}

	/**
	 * A `visible_callback` returning false hides the method.
	 */
	public function test_visible_callback_false_hides_method(): void {
		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ): array {
				$methods[] = array(
					'slug'             => 'conditional',
					'label'            => 'Conditional',
					'priority'         => 15,
					'render_callback'  => static fn () => null,
					'visible_callback' => static fn (): bool => false,
				);
				return $methods;
			}
		);

		$this->assertNotContains( 'conditional', $this->visible_slugs() );
	}

	/**
	 * Malformed entries are dropped rather than fataling.
	 */
	public function test_malformed_entries_are_dropped(): void {
		$this->setExpectedIncorrectUsage( MethodRegistry::FILTER_NAME );

		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ): array {
				$methods[] = array( 'slug' => 'no-label', 'render_callback' => static fn () => null );
				$methods[] = array( 'slug' => 'no-callback', 'label' => 'No Callback' );
				$methods[] = array( 'label' => 'No Slug', 'render_callback' => static fn () => null );
				$methods[] = 'not-an-array';
				return $methods;
			}
		);

		$slugs = $this->visible_slugs();
		$this->assertNotContains( 'no-label', $slugs );
		$this->assertNotContains( 'no-callback', $slugs );
		$this->assertSame( array( 'ai-connectors', 'clients', 'npm', 'wp-cli' ), $slugs );
	}

	/**
	 * A non-array filter return falls back to the seeded built-ins.
	 */
	public function test_non_array_filter_return_falls_back_to_builtins(): void {
		add_filter( MethodRegistry::FILTER_NAME, static fn (): string => 'garbage' );

		$this->assertSame( array( 'ai-connectors', 'clients', 'npm', 'wp-cli' ), $this->visible_slugs() );
	}

	/**
	 * A third-party method whose render throws is contained exactly as a
	 * built-in would be — the promise the documented extension point makes.
	 */
	public function test_third_party_render_failure_is_contained(): void {
		add_filter(
			MethodRegistry::FILTER_NAME,
			static function ( array $methods ): array {
				$methods[] = array(
					'slug'            => 'explodes',
					'label'           => 'Explodes',
					'priority'        => 15,
					'render_callback' => static function (): void {
						throw new \RuntimeException( 'LEAKY_DETAIL' );
					},
				);
				return $methods;
			}
		);

		$methods = MethodRegistry::instance()->visible_methods( $this->server );
		$target  = null;
		foreach ( $methods as $method ) {
			if ( 'explodes' === $method->slug() ) {
				$target = $method;
			}
		}
		$this->assertNotNull( $target );

		ob_start();
		$target->render( $this->server );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringNotContainsString( 'LEAKY_DETAIL', $html );
	}
}
