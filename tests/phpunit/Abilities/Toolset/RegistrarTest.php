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

	/**
	 * Abilities this test registers, which must not outlive it.
	 *
	 * The abilities registry is a static singleton and the bootstrap does not
	 * reset it, so a registration leaks into every later test in the process —
	 * and two of the tests below assert that NOTHING is registered. Cleaning up
	 * at both ends keeps them independent of run order rather than merely
	 * passing today.
	 *
	 * @var string[]
	 */
	private const SCRATCH = array( 'toolset/stub', Guide::SLUG );

	public function set_up(): void {
		parent::set_up();
		$this->forget_scratch_abilities();
	}

	public function tear_down(): void {
		$this->forget_scratch_abilities();
		remove_all_actions( self::COLLISION );
		parent::tear_down();
	}

	/**
	 * Run one callback inside a real `wp_abilities_api_init`.
	 *
	 * `wp_register_ability()` refuses outright unless
	 * `doing_action( 'wp_abilities_api_init' )`, so calling a Toolset's
	 * `register()` straight from a test can never register anything — it fails
	 * silently and the test passes or fails for the wrong reason. (The
	 * bootstrap's `acrossai_test_register_ability()` exists to dodge the same
	 * gate for plain abilities; a Toolset has to go through its own
	 * `register()`, so it needs the context rather than a bypass.)
	 *
	 * Everything else attached to the action is lifted out for the duration,
	 * because re-firing it would re-run every other plugin registration in the
	 * process and trip core's duplicate-registration notice.
	 *
	 * @param  callable $callback What to run in that context.
	 * @return void
	 */
	private function inside_abilities_init( callable $callback ): void {
		global $wp_filter;

		$saved = $wp_filter['wp_abilities_api_init'] ?? null;
		unset( $wp_filter['wp_abilities_api_init'] );

		add_action( 'wp_abilities_api_init', $callback );
		do_action( 'wp_abilities_api_init', \WP_Abilities_Registry::get_instance() );

		unset( $wp_filter['wp_abilities_api_init'] );

		if ( null !== $saved ) {
			$wp_filter['wp_abilities_api_init'] = $saved;
		}
	}

	/**
	 * @return void
	 */
	private function forget_scratch_abilities(): void {
		foreach ( self::SCRATCH as $slug ) {
			if ( wp_has_ability( $slug ) ) {
				wp_unregister_ability( $slug );
			}
		}
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
	 * A guide to nothing is not published.
	 *
	 * Same rule the dispatchers apply to themselves — a Toolset for a group
	 * with no abilities advertises a subject area that does not exist. Taken to
	 * its limit, a guide with no Toolsets to describe is a table of contents
	 * for an empty book, and it would sit in the admin's tool list one line
	 * under `mcp-adapter/server-guide`, which is the guide that does apply.
	 *
	 * This is what keeps a site running this plugin ALONE unchanged, so it is
	 * the assertion standing between us and the regression that prompted it.
	 */
	public function test_the_guide_declines_when_there_is_nothing_to_describe() {
		Category_Registrar::instance()->register();

		$this->assertSame(
			array(),
			$this->registered_toolsets(),
			'Precondition: no Toolset registers in the test environment.'
		);

		$this->inside_abilities_init( array( new Guide(), 'register' ) );

		$this->assertFalse( wp_has_ability( Guide::SLUG ) );
	}

	/**
	 * Having declined, it must not offer itself as a tool either.
	 *
	 * The admin's tool pool and the protected-slug list both answer "which
	 * slugs are Toolsets on this site?", so a slug naming nothing belongs on
	 * neither — offering it would put a tool in the picker that cannot be
	 * added.
	 */
	public function test_a_guide_that_declined_contributes_no_slug() {
		Category_Registrar::instance()->register();

		$guide = new Guide();
		$this->inside_abilities_init( array( $guide, 'register' ) );

		$this->assertFalse( wp_has_ability( Guide::SLUG ), 'Precondition: it declined.' );
		$this->assertSame( array(), $guide->declare_tool_level_ability( array() ) );
		$this->assertSame( array(), $guide->protect_own_slug( array() ) );
	}

	/**
	 * Given something to describe, it publishes — and a second copy stands down
	 * rather than clobbering the first, saying so through the same action the
	 * dispatchers use.
	 */
	public function test_a_second_guide_stands_down_rather_than_replacing_the_first() {
		Category_Registrar::instance()->register();

		// The guide needs a Toolset to describe before it will publish at all,
		// so give it one. Registering a real dispatcher is not an option here:
		// each declines unless its ability group has members, which is the very
		// thing a bare test site does not have.
		acrossai_test_register_ability(
			'toolset/stub',
			array(
				'label'            => 'Stub',
				'description'      => 'Stands in for a dispatcher.',
				'category'         => Base_Toolset_Ability::CATEGORY,
				'input_schema'     => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'    => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback' => static fn () => array(),
			)
		);

		$this->assertTrue( wp_has_ability( 'toolset/stub' ), 'Precondition: the stub Toolset registered.' );

		$this->inside_abilities_init( array( new Guide(), 'register' ) );
		$this->assertTrue( wp_has_ability( Guide::SLUG ), 'With a Toolset present the guide publishes.' );

		$incumbent  = wp_get_ability( Guide::SLUG );
		$collisions = array();

		add_action(
			self::COLLISION,
			static function ( string $slug ) use ( &$collisions ): void {
				$collisions[] = $slug;
			}
		);

		$this->inside_abilities_init( array( new Guide(), 'register' ) );

		$this->assertSame( array( Guide::SLUG ), $collisions, 'Standing down must be reported.' );
		$this->assertSame(
			$incumbent,
			wp_get_ability( Guide::SLUG ),
			'Whoever holds the slug keeps it — a second copy must never replace the incumbent.'
		);
	}

	/**
	 * Every registered `toolset/*` ability except the guide itself.
	 *
	 * @return string[]
	 */
	private function registered_toolsets(): array {
		$registry = \WP_Abilities_Registry::get_instance();

		return array_values(
			array_filter(
				null === $registry ? array() : array_keys( $registry->get_all_registered() ),
				static function ( $slug ): bool {
					return Guide::SLUG !== $slug && 0 === strpos( (string) $slug, 'toolset/' );
				}
			)
		);
	}
}
