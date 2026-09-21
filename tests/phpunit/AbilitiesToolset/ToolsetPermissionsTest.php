<?php
/**
 * Tests: Base_Toolset_Ability permission layers.
 *
 * A Toolset is one route to ~450 abilities, so a gap in its gating is a gap
 * in all of them at once. Three layers have to hold, and the third is the one
 * an optimiser is most likely to remove:
 *
 *   1. the Toolset's own capability, gating the listing;
 *   2. for execute, the TARGET's own permission check, run before dispatch so
 *      a denial is a real authorisation failure rather than a soft miss;
 *   3. invocation through WP_Ability::execute(), which runs that check again
 *      along with validation and every lifecycle event.
 *
 * The duplicate check in layers 2 and 3 is deliberate. These tests exist so
 * that removing either one fails loudly.
 *
 * @package AcrossAI_Abilities_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\AbilitiesToolset;

use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\AbilityGroup;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Permission behaviour.
 */
class ToolsetPermissionsTest extends TestCase {

	/**
	 * Signed in, with the default capability, and an empty registry.
	 */
	protected function setUp(): void {
		parent::setUp();

		// This bootstrap's `add_filter`/`apply_filters` are FUNCTIONAL, unlike
		// the no-ops these tests ran against in the plugin they came from. That
		// is the better harness — a filter test that cannot fail is not a test
		// — but it means a filter attached by one case survives into the next
		// unless something clears it.
		acrossai_test_reset_filters();
		$GLOBALS['acrossai_test_abilities']    = array();
		$GLOBALS['acrossai_test_capabilities'] = array( 'read' );
		$GLOBALS['acrossai_test_logged_in']    = true;
		Fixture_Ability::$calls                = array();
		Fixture_Ability::$permissions          = array();
		Fixture_Ability::$throws               = array();
		Fixture_Toolset::$for_group            = 'content';
		AbilityGroup::flush();
	}

	/**
	 * Tear down so a leaked fixture cannot reach the next suite.
	 */
	protected function tearDown(): void {
		acrossai_test_reset_filters();
		$GLOBALS['acrossai_test_abilities'] = array();
		unset( $GLOBALS['acrossai_test_logged_in'] );
		unset( $GLOBALS['acrossai_test_filter_values']['acrossai_toolset_capability'] );
		unset( $GLOBALS['acrossai_test_filter_callbacks']['acrossai_toolset_member_visible'] );
		$GLOBALS['acrossai_test_hooks'] = array();
		AbilityGroup::flush();
		parent::tearDown();
	}

	/**
	 * Register a member whose permission outcome a test controls.
	 *
	 * @param  string             $name    Ability name.
	 * @param  bool|WP_Error|null $allowed Permission outcome.
	 * @return void
	 */
	private function given_member( string $name, $allowed = true ): void {
		$GLOBALS['acrossai_test_abilities'][ $name ] = new Fixture_Ability(
			$name,
			array(
				'label'       => $name,
				'description' => 'Fixture ' . $name,
				'category'    => 'acrossai-content',
				'meta'        => array(
					'acrossai' => array( 'tab_group' => 'content' ),
					'mcp'      => array( 'type' => 'tool' ),
				),
			)
		);

		if ( null !== $allowed ) {
			Fixture_Ability::$permissions[ $name ] = $allowed;
		}

		AbilityGroup::flush();
	}

	/* ----------------------------------------------------------------- */

	/**
	 * A signed-out caller is refused before anything else happens.
	 */
	public function test_signed_out_caller_is_refused(): void {
		$GLOBALS['acrossai_test_logged_in'] = false;
		$this->given_member( 'content/get-post' );

		$result = ( new Fixture_Toolset() )->check_permission( array( 'action' => 'discover' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * The Toolset's own capability gates the listing.
	 */
	public function test_missing_capability_is_refused(): void {
		$GLOBALS['acrossai_test_capabilities'] = array();
		$this->given_member( 'content/get-post' );

		$result = ( new Fixture_Toolset() )->check_permission( array( 'action' => 'discover' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The default capability is deliberately low.
	 *
	 * It gates a listing, not an execution. Requiring manage_options here
	 * would lock out a legitimately-scoped editor while protecting nothing,
	 * because every run still passes the target's own check.
	 */
	public function test_default_capability_is_read(): void {
		$GLOBALS['acrossai_test_capabilities'] = array( 'read' );
		$this->given_member( 'content/get-post' );

		$this->assertTrue(
			( new Fixture_Toolset() )->check_permission( array( 'action' => 'discover' ) )
		);
	}

	/**
	 * The capability is filterable, and raising it restricts discovery.
	 */
	public function test_capability_is_filterable(): void {
		$GLOBALS['acrossai_test_capabilities']                                     = array( 'read' );
		$GLOBALS['acrossai_test_filter_values']['acrossai_toolset_capability'] = 'manage_options';
		$this->given_member( 'content/get-post' );

		$result = ( new Fixture_Toolset() )->check_permission( array( 'action' => 'discover' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/* ----------------------------------------------------------------- */

	/**
	 * For execute, the target's own check runs before dispatch.
	 *
	 * The refusal must originate from the target, so it is indistinguishable
	 * from calling that ability directly.
	 */
	public function test_execute_runs_the_targets_own_permission_check(): void {
		$this->given_member(
			'content/delete-post',
			new WP_Error( 'forbidden', 'Not for you.', array( 'status' => 403 ) )
		);

		$result = ( new Fixture_Toolset() )->check_permission(
			array( 'action' => 'execute', 'ability' => 'content/delete-post' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 'Not for you.', $result->get_error_message() );
		$this->assertContains( 'check:content/delete-post', Fixture_Ability::$calls );
	}

	/**
	 * A plain false from the target is also a refusal.
	 */
	public function test_target_returning_false_is_refused(): void {
		$this->given_member( 'content/delete-post', false );

		$result = ( new Fixture_Toolset() )->check_permission(
			array( 'action' => 'execute', 'ability' => 'content/delete-post' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'acrossai_toolset_target_forbidden', $result->get_error_code() );
	}

	/**
	 * A permitted target passes.
	 */
	public function test_permitted_target_passes(): void {
		$this->given_member( 'content/get-post', true );

		$this->assertTrue(
			( new Fixture_Toolset() )->check_permission(
				array( 'action' => 'execute', 'ability' => 'content/get-post' )
			)
		);
	}

	/**
	 * A soft miss is not an authorisation failure.
	 *
	 * Naming an ability that does not exist, or lives elsewhere, must reach
	 * execute() so the caller gets a machine-readable reason it can act on —
	 * not a 403 that tells it nothing.
	 */
	public function test_unknown_ability_is_not_an_authorisation_failure(): void {
		$result = ( new Fixture_Toolset() )->check_permission(
			array( 'action' => 'execute', 'ability' => 'nope/at-all' )
		);

		$this->assertTrue( $result );
	}

	/**
	 * An ability a policy has hidden is denied, not reported as a miss.
	 *
	 * This is the sibling transport's per-server exposure arriving through
	 * `acrossai_toolset_member_visible`. It is an operator decision, so it
	 * denies with a 403 exactly as that plugin's own execute tool does —
	 * retrying will never help, and the same policy should look the same
	 * whichever route the caller took.
	 */
	public function test_hidden_member_is_denied_with_403(): void {
		$this->given_member( 'content/get-post', true );

		$GLOBALS['acrossai_test_filter_callbacks']['acrossai_toolset_member_visible'] =
			static fn( bool $visible, $ability ): bool => 'content/get-post' !== $ability->get_name();

		$result = ( new Fixture_Toolset() )->check_permission(
			array( 'action' => 'execute', 'ability' => 'content/get-post' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'acrossai_toolset_ability_not_exposed', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A hidden ability is never handed to the target's permission check.
	 *
	 * Denying first is what keeps the policy a policy: the target must not get
	 * a say in whether an operator's decision applies.
	 */
	public function test_hidden_member_short_circuits_before_the_target(): void {
		$this->given_member( 'content/get-post', true );

		$GLOBALS['acrossai_test_filter_callbacks']['acrossai_toolset_member_visible'] =
			static fn(): bool => false;

		$result = ( new Fixture_Toolset() )->check_permission(
			array( 'action' => 'execute', 'ability' => 'content/get-post' )
		);

		// Assert the denial too: without it this passes in the broken state,
		// where the miss simply falls through to execute() and the target is
		// never consulted for a different reason.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotContains( 'check:content/get-post', Fixture_Ability::$calls );
	}

	/**
	 * A typo is still a soft miss, not a 403.
	 *
	 * The split is the point: hidden is policy, unknown is a mistake the
	 * caller can correct. Collapsing them would make one of the two useless.
	 */
	public function test_unknown_ability_is_still_soft_after_the_hidden_split(): void {
		$this->given_member( 'content/get-post', true );

		$this->assertTrue(
			( new Fixture_Toolset() )->check_permission(
				array( 'action' => 'execute', 'ability' => 'content/typo-here' )
			)
		);
	}

	/**
	 * An ability that throws is reported, not allowed to escape.
	 *
	 * Left unhandled it reaches the transport as an exception and comes back
	 * as a generic "Failed to execute tool", losing both the message and which
	 * ability threw.
	 */
	public function test_throwing_ability_is_caught_and_reported(): void {
		$this->given_member( 'content/get-post', true );
		Fixture_Ability::$throws[] = 'content/get-post';

		$out = ( new Fixture_Toolset() )->execute(
			array( 'action' => 'execute', 'ability' => 'content/get-post', 'parameters' => array() )
		);

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'ability_threw', $out['error_code'] );
		$this->assertStringContainsString( 'Boom from content/get-post', $out['error_message'] );
	}

	/* ----------------------------------------------------------------- */

	/**
	 * A registered Toolset declares itself as a tool-level ability.
	 *
	 * The transport hides these on its Abilities tab and offers only these on
	 * its Tools tab. The hook belongs to acrossai-mcp-manager; this plugin only
	 * contributes to it.
	 */
	public function test_registered_toolset_declares_itself(): void {
		$this->given_member( 'content/get-post' );

		$toolset = new Fixture_Toolset();
		$toolset->register();

		$this->assertSame(
			array( 'toolset/content' ),
			$toolset->declare_tool_level_ability( array() )
		);
	}

	/**
	 * The slug is contributed before registration has run.
	 *
	 * This is the case that decides the whole design. The transport builds the
	 * list during admin page setup, which happens BEFORE
	 * `wp_abilities_api_init`. Gating on "have I registered yet?" therefore
	 * declares nothing at all — which is exactly what shipped first and had to
	 * be reverted.
	 */
	public function test_slug_is_contributed_before_registration_runs(): void {
		$toolset = new Fixture_Toolset();

		$this->assertSame(
			array( 'toolset/content' ),
			$toolset->declare_tool_level_ability( array() ),
			'The list is built before registration; withholding here declares nothing at all.'
		);
	}

	/**
	 * A Toolset that never registered still contributes, and that is fine.
	 *
	 * `register()` bails on an empty group. Naming a slug nothing has claimed
	 * is inert, so there is nothing to protect against — and the alternative
	 * costs the case above.
	 */
	public function test_unregistered_toolset_still_contributes(): void {
		// No members, so register() bails before claiming the slug.
		$toolset = new Fixture_Toolset();
		$toolset->register();

		$this->assertSame( array( 'toolset/content' ), $toolset->declare_tool_level_ability( array() ) );
	}

	/**
	 * A slug held by someone else is never claimed on the hide-list.
	 *
	 * The one outcome worth protecting against: on a collision the slug belongs
	 * to another plugin, so contributing it would hide THEIR ability from the
	 * Abilities tab and misrepresent it as a tool-level entry.
	 */
	public function test_collided_slug_is_not_hidden(): void {
		$this->given_member( 'content/get-post' );

		// Something else already holds the Toolset's slug.
		$GLOBALS['acrossai_test_abilities']['toolset/content'] = new Fixture_Ability(
			'toolset/content',
			array( 'label' => 'Someone else', 'description' => 'Not ours.' )
		);

		$toolset = new Fixture_Toolset();
		$toolset->register();

		$this->assertSame( array(), $toolset->declare_tool_level_ability( array() ) );
	}

	/**
	 * Slugs contributed by other callbacks are preserved.
	 */
	public function test_existing_slugs_are_kept(): void {
		$this->given_member( 'content/get-post' );

		$toolset = new Fixture_Toolset();
		$toolset->register();

		$this->assertSame(
			array( 'mcp-adapter/discover-abilities', 'toolset/content' ),
			$toolset->declare_tool_level_ability( array( 'mcp-adapter/discover-abilities' ) )
		);
	}

	/**
	 * A junk value from an earlier callback does not drag the Toolsets along.
	 *
	 * Returning it untouched would list all 13 dispatchers on the Abilities tab
	 * and drop them from the Tools tab pool, because of someone else's bug.
	 */
	public function test_junk_input_still_yields_the_toolset(): void {
		$this->given_member( 'content/get-post' );

		$toolset = new Fixture_Toolset();
		$toolset->register();

		$this->assertSame( array( 'toolset/content' ), $toolset->declare_tool_level_ability( null ) );
		$this->assertSame( array( 'toolset/content' ), $toolset->declare_tool_level_ability( 'nonsense' ) );
	}

	/**
	 * A Toolset protects its own slug.
	 *
	 * Protection is what makes AcrossAI_Abilities_Write_Controller refuse a
	 * write. Without it a Toolset reads as an ordinary ability an operator can
	 * override or disable, which takes the whole group behind it offline.
	 */
	public function test_toolset_protects_its_own_slug(): void {
		$toolset = new Fixture_Toolset();

		$this->assertSame(
			array( 'mcp-adapter/discover-abilities', 'toolset/content' ),
			$toolset->protect_own_slug( array( 'mcp-adapter/discover-abilities' ) )
		);
	}

	/**
	 * A slug another plugin holds is neither protected nor tool-listed.
	 *
	 * Acting on a collided slug would act on THEIR ability — protecting it
	 * would block writes to something that is not ours to protect.
	 */
	public function test_collided_slug_is_not_protected(): void {
		$this->given_member( 'content/get-post' );

		$GLOBALS['acrossai_test_abilities']['toolset/content'] = new Fixture_Ability(
			'toolset/content',
			array( 'label' => 'Someone else', 'description' => 'Not ours.' )
		);

		$toolset = new Fixture_Toolset();
		$toolset->register();

		$this->assertSame( array(), $toolset->protect_own_slug( array() ) );
		$this->assertSame( array(), $toolset->declare_tool_level_ability( array() ) );
	}

	/**
	 * Both published lists get the same answer from one implementation.
	 *
	 * They ask the same question — "which slugs are Toolsets?" — so they share
	 * a helper. If the two ever disagree, one of them has grown a special case
	 * that belongs in the shared path or in neither.
	 */
	public function test_both_lists_receive_the_same_slug(): void {
		$toolset = new Fixture_Toolset();

		$this->assertSame(
			$toolset->declare_tool_level_ability( array() ),
			$toolset->protect_own_slug( array() )
		);
	}

	/**
	 * discover and info do not consult any target.
	 */
	public function test_non_execute_actions_do_not_check_a_target(): void {
		$this->given_member( 'content/delete-post', false );

		$toolset = new Fixture_Toolset();

		$this->assertTrue( $toolset->check_permission( array( 'action' => 'discover' ) ) );
		$this->assertTrue( $toolset->check_permission( array( 'action' => 'info', 'ability' => 'content/delete-post' ) ) );
		$this->assertNotContains( 'check:content/delete-post', Fixture_Ability::$calls );
	}

	/* ----------------------------------------------------------------- */

	/**
	 * Dispatch goes through WP_Ability::execute(), never the raw callback.
	 *
	 * That is what keeps validation, the override processor, access control
	 * and every lifecycle event in the loop. The target's check therefore runs
	 * twice on a permitted call — once in check_permission(), once inside
	 * execute(). Both are intentional.
	 */
	public function test_dispatch_invokes_execute_and_checks_twice(): void {
		$this->given_member( 'content/get-post', true );

		$toolset = new Fixture_Toolset();
		$toolset->check_permission( array( 'action' => 'execute', 'ability' => 'content/get-post' ) );
		$toolset->execute( array( 'action' => 'execute', 'ability' => 'content/get-post', 'parameters' => array() ) );

		$checks = array_filter(
			Fixture_Ability::$calls,
			static fn( string $call ): bool => 'check:content/get-post' === $call
		);

		$this->assertContains( 'execute:content/get-post', Fixture_Ability::$calls );
		$this->assertCount( 2, $checks, 'The target check must run in both layers; removing either is a regression.' );
	}

	/**
	 * A target that refuses inside execute() surfaces its own error.
	 */
	public function test_error_from_the_target_is_returned_verbatim(): void {
		$this->given_member(
			'content/delete-post',
			new WP_Error( 'nope', 'Refused by the ability.' )
		);

		$out = ( new Fixture_Toolset() )->execute(
			array( 'action' => 'execute', 'ability' => 'content/delete-post', 'parameters' => array() )
		);

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'nope', $out['error_code'] );
		$this->assertSame( 'Refused by the ability.', $out['error_message'] );
	}
}
