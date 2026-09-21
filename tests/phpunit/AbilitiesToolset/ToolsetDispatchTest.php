<?php
/**
 * Tests: Base_Toolset_Ability — the three actions and their guards.
 *
 * The permission cases here are the load-bearing ones. A Toolset is a route
 * to ~450 abilities, so a gap in its gating is a gap in all of them at once.
 * Two properties in particular are asserted rather than assumed: that a
 * refusal originates from the *target's* own permission check, and that
 * `info` is gated exactly as strictly as `execute` — an input schema names
 * parameters and constraints, so an ungated describe is a read-side
 * disclosure.
 *
 * @package AcrossAI_Abilities_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\AbilitiesToolset;

use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\Base_Toolset_Ability;
use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\AbilityGroup;
use PHPUnit\Framework\TestCase;
use WP_Ability;

/**
 * A Toolset covering an arbitrary group, for testing.
 */
final class Fixture_Toolset extends Base_Toolset_Ability {

	/** @var string */
	public static string $for_group = 'content';

	/** @return string */
	protected function group(): string {
		return self::$for_group;
	}

	/** @return string */
	protected function slug(): string {
		return 'toolset/' . self::$for_group;
	}

	/** @return string */
	protected function toolset_label(): string {
		return 'Fixture';
	}

	/** @return string */
	protected function toolset_description(): string {
		return 'Fixture toolset.';
	}
}

/**
 * An ability whose permission and execution outcomes a test controls.
 */
final class Fixture_Ability extends WP_Ability {

	/** @var array<string, mixed> */
	public static array $calls = array();

	/** @var array<string, bool|\WP_Error> */
	public static array $permissions = array();

	/** @var string[] Names whose execute() throws. */
	public static array $throws = array();

	/**
	 * @param  mixed $input Input.
	 * @return bool|\WP_Error
	 */
	public function check_permissions( $input = null ) {
		self::$calls[] = 'check:' . $this->get_name();
		return self::$permissions[ $this->get_name() ] ?? true;
	}

	/**
	 * @param  mixed $input Input.
	 * @return mixed
	 */
	public function execute( $input = null ) {
		self::$calls[] = 'execute:' . $this->get_name();
		$allowed       = $this->check_permissions( $input );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( in_array( $this->get_name(), self::$throws, true ) ) {
			throw new \RuntimeException( 'Boom from ' . $this->get_name() );
		}

		return array( 'ran' => $this->get_name(), 'input' => $input );
	}
}

/**
 * Dispatch behaviour.
 */
class ToolsetDispatchTest extends TestCase {

	/**
	 * Reset registry, memo and recorded calls.
	 */
	protected function setUp(): void {
		parent::setUp();

		// This bootstrap's `add_filter`/`apply_filters` are FUNCTIONAL, unlike
		// the no-ops these tests ran against in the plugin they came from. That
		// is the better harness — a filter test that cannot fail is not a test
		// — but it means a filter attached by one case survives into the next
		// unless something clears it.
		acrossai_test_reset_filters();
		$GLOBALS['acrossai_test_abilities']     = array();
		$GLOBALS['acrossai_test_capabilities']  = array( 'read' );
		$GLOBALS['acrossai_test_current_user']  = 1;
		Fixture_Ability::$calls                 = array();
		Fixture_Ability::$permissions           = array();
		Fixture_Ability::$throws                = array();
		Fixture_Toolset::$for_group             = 'content';
		AbilityGroup::flush();
	}

	/**
	 * Tear down so a leaked fixture cannot reach the next suite.
	 */
	protected function tearDown(): void {
		acrossai_test_reset_filters();
		$GLOBALS['acrossai_test_abilities'] = array();
		AbilityGroup::flush();
		parent::tearDown();
	}

	/**
	 * Register a member of a group.
	 *
	 * @param  string $name      Ability name.
	 * @param  string $group     Group identifier.
	 * @param  string $card      Category slug.
	 * @param  string $sub_group Sub-group.
	 * @param  array  $extra     Extra meta.
	 * @return Fixture_Ability
	 */
	private function given_member(
		string $name,
		string $group = 'content',
		string $card = 'acrossai-content',
		string $sub_group = 'posts',
		array $extra = array()
	): Fixture_Ability {
		$meta = array_merge(
			array(
				'acrossai'    => array(
					'tab_group' => $group,
					'sub_group' => $sub_group,
				),
				'mcp'         => array( 'type' => 'tool' ),
				'annotations' => array( 'readonly' => true ),
			),
			$extra
		);

		$ability = new Fixture_Ability(
			$name,
			array(
				'label'         => ucwords( str_replace( array( '/', '-' ), ' ', $name ) ),
				'description'   => 'Fixture ability ' . $name,
				'category'      => $card,
				'meta'          => $meta,
				'input_schema'  => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ) ) ),
				'output_schema' => array( 'type' => 'object' ),
			)
		);

		$GLOBALS['acrossai_test_abilities'][ $name ] = $ability;
		AbilityGroup::flush();

		return $ability;
	}

	/**
	 * A Toolset instance for the current fixture group.
	 *
	 * @return Fixture_Toolset
	 */
	private function toolset(): Fixture_Toolset {
		return new Fixture_Toolset();
	}

	/* ----------------------------------------------------------------- */

	/**
	 * discover lists the group and nothing else.
	 */
	public function test_discover_lists_only_its_own_group(): void {
		$this->given_member( 'content/get-post' );
		$this->given_member( 'comments/list-comments', 'content', 'acrossai-comments', 'manage' );
		$this->given_member( 'cron/list-cron-jobs', 'cron', 'acrossai-cron', 'read' );

		$out = $this->toolset()->execute( array( 'action' => 'discover' ) );

		$this->assertTrue( $out['success'] );
		$this->assertSame( 'discover', $out['action'] );
		$this->assertSame( 'content', $out['group'] );
		$this->assertSame( 2, $out['total'] );
		$this->assertSame(
			array( 'comments/list-comments', 'content/get-post' ),
			array_column( $out['abilities'], 'name' )
		);
	}

	/**
	 * discover carries no schemas — that is the info surface.
	 */
	public function test_discover_omits_schemas(): void {
		$this->given_member( 'content/get-post' );

		$out = $this->toolset()->execute( array( 'action' => 'discover' ) );
		$row = $out['abilities'][0];

		$this->assertArrayHasKey( 'name', $row );
		$this->assertArrayHasKey( 'card', $row );
		$this->assertArrayHasKey( 'sub_group', $row );
		$this->assertArrayNotHasKey( 'input_schema', $row );
		$this->assertArrayNotHasKey( 'output_schema', $row );
		$this->assertArrayNotHasKey( 'annotations', $row );
	}

	/**
	 * Paging reports total, returned, offset and has_more.
	 */
	public function test_discover_pages(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->given_member( sprintf( 'content/item-%d', $i ) );
		}

		$out = $this->toolset()->execute(
			array( 'action' => 'discover', 'limit' => 2, 'offset' => 0 )
		);

		$this->assertSame( 5, $out['total'] );
		$this->assertSame( 2, $out['returned'] );
		$this->assertSame( 0, $out['offset'] );
		$this->assertTrue( $out['has_more'] );

		$last = $this->toolset()->execute(
			array( 'action' => 'discover', 'limit' => 2, 'offset' => 4 )
		);

		$this->assertSame( 1, $last['returned'] );
		$this->assertFalse( $last['has_more'] );
	}

	/**
	 * An offset past the end is empty, not an error.
	 */
	public function test_discover_offset_past_end_is_empty_not_an_error(): void {
		$this->given_member( 'content/get-post' );

		$out = $this->toolset()->execute(
			array( 'action' => 'discover', 'offset' => 99 )
		);

		$this->assertTrue( $out['success'] );
		$this->assertSame( array(), $out['abilities'] );
		$this->assertSame( 1, $out['total'] );
		$this->assertFalse( $out['has_more'] );
	}

	/**
	 * search matches name, label and description.
	 */
	public function test_discover_search(): void {
		$this->given_member( 'content/get-post' );
		$this->given_member( 'content/list-pages' );

		$out = $this->toolset()->execute(
			array( 'action' => 'discover', 'search' => 'PAGES' )
		);

		$this->assertSame( array( 'content/list-pages' ), array_column( $out['abilities'], 'name' ) );
	}

	/**
	 * card narrows to one contributing category — the axis that replaces
	 * having a separate tool per card.
	 */
	public function test_discover_by_card(): void {
		$this->given_member( 'content/get-post', 'content', 'acrossai-content' );
		$this->given_member( 'comments/get-comment', 'content', 'acrossai-comments' );
		$this->given_member( 'media/list-media', 'content', 'acrossai-media' );

		$out = $this->toolset()->execute(
			array( 'action' => 'discover', 'card' => 'comments' )
		);

		$this->assertSame( array( 'comments/get-comment' ), array_column( $out['abilities'], 'name' ) );

		// The full category slug works too.
		$full = $this->toolset()->execute(
			array( 'action' => 'discover', 'card' => 'acrossai-comments' )
		);
		$this->assertSame( array( 'comments/get-comment' ), array_column( $full['abilities'], 'name' ) );
	}

	/**
	 * sub_group narrows within a card.
	 */
	public function test_discover_by_sub_group(): void {
		$this->given_member( 'content/get-post', 'content', 'acrossai-content', 'posts' );
		$this->given_member( 'content/get-page', 'content', 'acrossai-content', 'pages' );

		$out = $this->toolset()->execute(
			array( 'action' => 'discover', 'sub_group' => 'pages' )
		);

		$this->assertSame( array( 'content/get-page' ), array_column( $out['abilities'], 'name' ) );
	}

	/**
	 * include_fields trims the row and always keeps the name.
	 */
	public function test_include_fields_trims_and_keeps_name(): void {
		$this->given_member( 'content/get-post' );

		$out = $this->toolset()->execute(
			array( 'action' => 'discover', 'include_fields' => array( 'label', 'nonsense' ) )
		);

		$this->assertSame( array( 'name', 'label' ), array_keys( $out['abilities'][0] ) );
	}

	/**
	 * A group with no members returns an explanation, not an error.
	 */
	public function test_empty_group_returns_message_not_error(): void {
		$out = $this->toolset()->execute( array( 'action' => 'discover' ) );

		$this->assertTrue( $out['success'] );
		$this->assertSame( array(), $out['abilities'] );
		$this->assertArrayHasKey( 'message', $out );
	}

	/* ----------------------------------------------------------------- */

	/**
	 * info returns schemas and annotations.
	 */
	public function test_info_returns_schemas(): void {
		$this->given_member( 'content/get-post' );

		$out = $this->toolset()->execute(
			array( 'action' => 'info', 'ability' => 'content/get-post' )
		);

		$this->assertTrue( $out['success'] );
		$this->assertArrayHasKey( 'input_schema', $out['abilities'][0] );
		$this->assertArrayHasKey( 'output_schema', $out['abilities'][0] );
		$this->assertArrayHasKey( 'annotations', $out['abilities'][0] );
	}

	/**
	 * Batch info reports found and not-found separately.
	 */
	public function test_info_batch_reports_not_found(): void {
		$this->given_member( 'content/get-post' );

		$out = $this->toolset()->execute(
			array(
				'action'    => 'info',
				'abilities' => array( 'content/get-post', 'content/nope' ),
			)
		);

		$this->assertSame( array( 'content/get-post' ), array_column( $out['abilities'], 'name' ) );
		$this->assertSame( array( 'content/nope' ), $out['not_found'] );
	}

	/**
	 * info without a name is a recoverable failure with a reason code.
	 */
	public function test_info_without_a_name_fails_recoverably(): void {
		$out = $this->toolset()->execute( array( 'action' => 'info' ) );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'missing_ability', $out['error_code'] );
	}

	/* ----------------------------------------------------------------- */

	/**
	 * execute runs the ability and returns its result.
	 */
	public function test_execute_runs_the_ability(): void {
		$this->given_member( 'content/get-post' );

		$out = $this->toolset()->execute(
			array(
				'action'     => 'execute',
				'ability'    => 'content/get-post',
				'parameters' => array( 'id' => 7 ),
			)
		);

		$this->assertTrue( $out['success'] );
		$this->assertSame( 'content/get-post', $out['data']['ran'] );
		$this->assertSame( array( 'id' => 7 ), $out['data']['input'] );
	}

	/**
	 * Only the caller's parameters reach the target — never the envelope.
	 */
	public function test_execute_forwards_only_the_parameters(): void {
		$this->given_member( 'content/get-post' );

		$out = $this->toolset()->execute(
			array(
				'action'     => 'execute',
				'ability'    => 'content/get-post',
				'parameters' => array( 'id' => 7 ),
			)
		);

		$this->assertArrayNotHasKey( 'action', $out['data']['input'] );
		$this->assertArrayNotHasKey( 'ability', $out['data']['input'] );
	}

	/**
	 * WP_Ability::execute() is the invocation path, so the target's own
	 * permission check runs as part of it.
	 */
	public function test_execute_goes_through_the_abilitys_own_execute(): void {
		$this->given_member( 'content/get-post' );

		$this->toolset()->execute(
			array( 'action' => 'execute', 'ability' => 'content/get-post', 'parameters' => array() )
		);

		$this->assertContains( 'execute:content/get-post', Fixture_Ability::$calls );
		$this->assertContains( 'check:content/get-post', Fixture_Ability::$calls );
	}

	/**
	 * An out-of-group ability is refused with a code naming where it lives.
	 */
	public function test_execute_refuses_an_ability_from_another_group(): void {
		$this->given_member( 'cron/list-cron-jobs', 'cron', 'acrossai-cron' );

		$out = $this->toolset()->execute(
			array( 'action' => 'execute', 'ability' => 'cron/list-cron-jobs' )
		);

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'ability_not_in_group', $out['error_code'] );
		$this->assertNotContains( 'execute:cron/list-cron-jobs', Fixture_Ability::$calls );
	}

	/**
	 * An unknown ability is distinguished from one that exists elsewhere.
	 */
	public function test_execute_unknown_ability(): void {
		$out = $this->toolset()->execute(
			array( 'action' => 'execute', 'ability' => 'nope/at-all' )
		);

		$this->assertSame( 'ability_not_found', $out['error_code'] );
	}

	/**
	 * A Toolset never dispatches to a Toolset.
	 */
	public function test_execute_refuses_to_target_another_toolset(): void {
		$this->given_member(
			'toolset/blocks',
			'content',
			'acrossai-toolset',
			'',
			array( 'acrossai' => array( 'tab_group' => 'content', 'toolset' => true ) )
		);

		$out = $this->toolset()->execute(
			array( 'action' => 'execute', 'ability' => 'toolset/blocks' )
		);

		$this->assertFalse( $out['success'] );
		$this->assertNotContains( 'execute:toolset/blocks', Fixture_Ability::$calls );
	}

	/**
	 * An unknown action is refused rather than defaulting to a listing.
	 */
	public function test_unknown_action_is_refused(): void {
		$out = $this->toolset()->execute( array( 'action' => 'sing' ) );

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'invalid_action', $out['error_code'] );
	}

	/**
	 * A missing action never silently becomes discover.
	 *
	 * A caller sending {ability, parameters} and meaning to execute must not
	 * receive a listing back with nothing signalling the mistake.
	 */
	public function test_missing_action_does_not_default_to_discover(): void {
		$this->given_member( 'content/get-post' );

		$out = $this->toolset()->execute(
			array( 'ability' => 'content/get-post', 'parameters' => array() )
		);

		$this->assertFalse( $out['success'] );
		$this->assertSame( 'invalid_action', $out['error_code'] );
		$this->assertArrayNotHasKey( 'abilities', $out );
	}

	/* ----------------------------------------------------------------- */

	/**
	 * The visibility filter removes a member from every action.
	 */
	public function test_visibility_filter_applies_to_all_three_actions(): void {
		$this->given_member( 'content/get-post' );
		$this->given_member( 'content/delete-post' );

		$GLOBALS['acrossai_test_filter_callbacks']['acrossai_toolset_member_visible'] = static function ( $visible, $ability ) {
			return 'content/delete-post' !== $ability->get_name();
		};

		$discover = $this->toolset()->execute( array( 'action' => 'discover' ) );
		$this->assertSame( array( 'content/get-post' ), array_column( $discover['abilities'], 'name' ) );

		$info = $this->toolset()->execute(
			array( 'action' => 'info', 'ability' => 'content/delete-post' )
		);
		$this->assertSame( array( 'content/delete-post' ), $info['not_found'] );

		$exec = $this->toolset()->execute(
			array( 'action' => 'execute', 'ability' => 'content/delete-post' )
		);
		$this->assertFalse( $exec['success'] );
		$this->assertNotContains( 'execute:content/delete-post', Fixture_Ability::$calls );

		unset( $GLOBALS['acrossai_test_filter_callbacks']['acrossai_toolset_member_visible'] );
	}

	/**
	 * info is gated exactly as strictly as execute.
	 *
	 * An input schema names parameters and constraints, so describing an
	 * ability a caller may not run would disclose its shape. This is the
	 * requirement most likely to be forgotten.
	 */
	public function test_info_is_gated_as_strictly_as_execute(): void {
		$this->given_member( 'content/delete-post' );

		$GLOBALS['acrossai_test_filter_callbacks']['acrossai_toolset_member_visible'] = static function ( $visible, $ability, $group, $context ) {
			// Hidden for execute only — info must not become a way around it.
			return 'content/delete-post' !== $ability->get_name();
		};

		$info = $this->toolset()->execute(
			array( 'action' => 'info', 'ability' => 'content/delete-post' )
		);

		$this->assertSame( array(), $info['abilities'] );
		$this->assertSame( array( 'content/delete-post' ), $info['not_found'] );

		unset( $GLOBALS['acrossai_test_filter_callbacks']['acrossai_toolset_member_visible'] );
	}

	/* ----------------------------------------------------------------- */

	/**
	 * Schemas are identical across Toolsets and stable.
	 */
	public function test_schemas_are_shared_and_stable(): void {
		$input = Base_Toolset_Ability::input_schema();

		$this->assertSame( array( 'action' ), $input['required'] );
		$this->assertFalse( $input['additionalProperties'] );
		$this->assertSame(
			array( 'discover', 'info', 'execute' ),
			$input['properties']['action']['enum']
		);
		$this->assertSame( 200, $input['properties']['limit']['maximum'] );
		$this->assertSame( 20, $input['properties']['abilities']['maxItems'] );

		$output = Base_Toolset_Ability::output_schema();
		$this->assertSame( array( 'action', 'success' ), $output['required'] );
	}
}
