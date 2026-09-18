<?php
/**
 * The Toolset layer this plugin owns from 0.3.6.
 *
 * A Toolset is a TOOL — one `tools/list` entry that routes
 * `action=discover|info|execute` at a whole ability group — so the dispatchers
 * belong to the plugin that owns servers. The abilities they route to stay in
 * the AcrossAI Abilities Manager add-on.
 *
 * Moving code between two independently-updated plugins cannot be a
 * remove-then-add, so for one release BOTH carry these classes. That makes the
 * stand-down behaviour the load-bearing contract here, not a nicety: if a
 * second copy ever registered over the add-on's, a site with both plugins would
 * get whichever won the race, and `_doing_it_wrong()` noise on the category.
 * These lock the three guards that make the overlap a deliberate no-op.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Abilities\Toolset
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Abilities\Toolset;

use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\Base_Toolset_Ability;
use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\Category_Registrar;
use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\Guide;
use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\Integrations;
use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\Registrar;
use ReflectionClass;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class RegistrarTest extends WP_UnitTestCase {

	private const COLLISION = 'acrossai_toolset_slug_collision';

	/**
	 * The dispatcher class names Registrar constructs.
	 *
	 * Read out of the private constant rather than restated, so a class added
	 * to the plugin but forgotten in the list is what fails — restating it here
	 * would let both drift together (B48).
	 *
	 * @return string[]
	 */
	private function core_classes(): array {
		$constant = ( new ReflectionClass( Registrar::class ) )->getConstant( 'CORE' );

		return is_array( $constant ) ? $constant : array();
	}

	public function tear_down(): void {
		remove_all_actions( self::COLLISION );
		parent::tear_down();
	}

	/**
	 * Every dispatcher file in the directory is actually constructed.
	 *
	 * The failure this catches is silent: a new Toolset class ships, nothing
	 * instantiates it, and the tool simply never appears on any server. Nothing
	 * else in the plugin would notice.
	 */
	public function test_every_dispatcher_in_the_directory_is_registered() {
		$registered = array_map(
			static function ( string $class ): string {
				return ( new ReflectionClass( $class ) )->getShortName();
			},
			array_merge( $this->core_classes(), array( Integrations::class, Guide::class ) )
		);

		$on_disk = array();

		foreach ( glob( dirname( __DIR__, 4 ) . '/includes/Abilities/Toolset/*.php' ) as $file ) {
			$short = basename( $file, '.php' );

			// The shared machinery, not dispatchers: the base class, the
			// category, the two ported utilities and the registrar itself.
			if ( in_array( $short, array( 'Base_Toolset_Ability', 'Category_Registrar', 'Registrar', 'AbilityGroup', 'InputNormalizer' ), true ) ) {
				continue;
			}

			$on_disk[] = $short;
		}

		sort( $on_disk );
		sort( $registered );

		$this->assertSame( $on_disk, $registered );
	}

	/**
	 * Slugs are namespaced and match the group, because the MCP layer keys off
	 * the `toolset/` prefix rather than the class name.
	 */
	public function test_each_dispatcher_declares_a_namespaced_slug_matching_its_group() {
		foreach ( $this->core_classes() as $class ) {
			$toolset = new ReflectionClass( $class );
			$object  = $toolset->newInstanceWithoutConstructor();

			$slug  = $toolset->getMethod( 'slug' );
			$group = $toolset->getMethod( 'group' );
			$slug->setAccessible( true );
			$group->setAccessible( true );

			$this->assertSame(
				'toolset/' . $group->invoke( $object ),
				$slug->invoke( $object ),
				$toolset->getShortName() . ' must name its slug after its group.'
			);
		}
	}

	/**
	 * Constructing a dispatcher must not touch the abilities registry.
	 *
	 * Registrar runs at `plugins_loaded` 21, and core fires
	 * `wp_abilities_api_init` lazily from
	 * `WP_Abilities_Registry::get_instance()`. If a constructor resolved the
	 * registry it would drag that firing forward, ahead of plugins that have
	 * not attached yet — so the constructors attach hooks and nothing else.
	 */
	public function test_registering_attaches_hooks_without_registering_abilities() {
		$abilities = count( wp_get_abilities() );
		$hooks     = $this->abilities_init_callback_count();

		Registrar::register();

		$this->assertSame(
			$abilities,
			count( wp_get_abilities() ),
			'Constructing the dispatchers must not register anything by itself.'
		);
		$this->assertSame(
			$hooks + count( $this->core_classes() ) + 2,
			$this->abilities_init_callback_count(),
			'Each dispatcher, plus Integrations and the Guide, attaches one hook.'
		);
	}

	/**
	 * How many callbacks are attached to the abilities-registry hook.
	 *
	 * @return int
	 */
	private function abilities_init_callback_count(): int {
		$hook = $GLOBALS['wp_filter']['wp_abilities_api_init'] ?? null;

		if ( ! $hook instanceof \WP_Hook ) {
			return 0;
		}

		$count = 0;

		foreach ( $hook->callbacks as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
	}

	/**
	 * The category guard — the add-on declares the same slug, and core treats a
	 * second registration of one slug as `_doing_it_wrong()`.
	 */
	public function test_the_category_is_not_registered_twice() {
		Category_Registrar::instance()->register();

		$this->assertTrue( wp_has_ability_category( Base_Toolset_Ability::CATEGORY ) );

		// Would emit a doing_it_wrong notice, which WP_UnitTestCase fails on,
		// if the guard were missing.
		Category_Registrar::instance()->register();

		$this->assertTrue( wp_has_ability_category( Base_Toolset_Ability::CATEGORY ) );
	}

	/**
	 * The guide stands down rather than clobbering a slug the add-on holds, and
	 * says so through the same action the dispatchers use.
	 */
	public function test_the_guide_stands_down_when_its_slug_is_already_claimed() {
		Category_Registrar::instance()->register();

		// Establish the precondition rather than assume it. Whether the slug is
		// already taken here depends on whether anything resolved the abilities
		// registry during boot — which is exactly the lazy timing this layer
		// has to tolerate, so the test must not depend on it either.
		if ( ! wp_has_ability( Guide::SLUG ) ) {
			( new Guide() )->register();
		}

		$this->assertTrue( wp_has_ability( Guide::SLUG ) );

		$incumbent  = wp_get_ability( Guide::SLUG );
		$collisions = array();

		add_action(
			self::COLLISION,
			static function ( string $slug ) use ( &$collisions ): void {
				$collisions[] = $slug;
			}
		);

		( new Guide() )->register();

		$this->assertSame( array( Guide::SLUG ), $collisions, 'Standing down must be reported.' );
		$this->assertSame(
			$incumbent,
			wp_get_ability( Guide::SLUG ),
			'Whoever holds the slug keeps it — a second copy must never replace the incumbent.'
		);
	}
}
