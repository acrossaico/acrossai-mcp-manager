<?php
/**
 * ToolsetExposureBridge — per-server exposure for sibling Toolsets.
 *
 * A Toolset is a fourth route into the ability catalogue, alongside the three
 * plugin-owned meta tools. Before this bridge the Abilities tab's per-server
 * toggles applied to the meta tools and to nothing else, so any `toolset-*`
 * call served abilities the operator had hidden for that server.
 *
 * Scope note: these cover the decisions the bridge makes on its own — the
 * short-circuits. The narrowing path itself (server in flight, override row
 * consulted) runs through `AbilityHelpers::apply_exposure_filter()`, which is
 * already covered by DiscoverTest / GetAbilityInfoTest / ExecuteTest; driving
 * it from here would need a real `McpServer` in `CurrentServerHolder::set()`,
 * which is typed to the vendor class. That path was verified end to end
 * against a live server instead: hiding `users/list-users` for server 1 removed
 * it from `toolset-users` discover (16 → 15), made info report it not_found,
 * and made execute return `ability_not_visible`.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Abilities
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder;
use AcrossAI_MCP_Manager\Includes\Abilities\ToolsetExposureBridge;
use WP_Ability;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ToolsetExposureBridgeTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		CurrentServerHolder::instance()->set( null );
		remove_all_filters( 'acrossai_mcp_is_ability_exposed' );
	}

	public function tear_down(): void {
		CurrentServerHolder::instance()->set( null );
		remove_all_filters( 'acrossai_mcp_is_ability_exposed' );
		parent::tear_down();
	}

	/**
	 * A tool-typed ability standing in for a Toolset member.
	 */
	private function ability( string $name ): WP_Ability {
		return new WP_Ability(
			$name,
			array(
				'label'               => $name,
				'description'         => 'Fixture ' . $name,
				'category'            => 'acrossai-users',
				'execute_callback'    => static fn () => true,
				'permission_callback' => static fn () => true,
				'meta'                => array(
					'mcp' => array(
						'public' => false,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	// -----------------------------------------------------------------

	/**
	 * Outside an MCP request there is no server whose policy could apply.
	 *
	 * WP-CLI, cron and a direct `wp_get_ability( 'toolset/users' )->execute()`
	 * must behave exactly as they did before the bridge existed.
	 */
	public function test_no_mcp_request_leaves_the_decision_alone(): void {
		$out = ToolsetExposureBridge::instance()->filter_member_visible(
			true,
			$this->ability( 'users/list-users' ),
			'users',
			'discover'
		);

		$this->assertTrue( $out, 'With no server in flight the bridge must not narrow anything.' );
	}

	/**
	 * With no server in flight the exposure filter is not consulted at all.
	 *
	 * Calling it would hand a policy callback a null `server_id` and invite it
	 * to make a per-server decision with no server — the bridge short-circuits
	 * first precisely so that cannot happen.
	 */
	public function test_no_mcp_request_does_not_consult_the_exposure_filter(): void {
		$seen = array();
		add_filter(
			'acrossai_mcp_is_ability_exposed',
			static function ( $exposed, $ability, $server_id, $context ) use ( &$seen ) {
				$seen[] = $context;
				return $exposed;
			},
			10,
			4
		);

		$bridge  = ToolsetExposureBridge::instance();
		$ability = $this->ability( 'users/list-users' );

		foreach ( array( 'discover', 'info', 'execute' ) as $action ) {
			$bridge->filter_member_visible( true, $ability, 'users', $action );
		}

		$this->assertSame( array(), $seen );
	}

	/**
	 * The bridge narrows only — it never re-admits a hidden member.
	 *
	 * Deny-precedence, the same rule ToolExposureGate follows: a second gate
	 * on the same catalogue must not become a way back in.
	 */
	public function test_already_hidden_stays_hidden(): void {
		$out = ToolsetExposureBridge::instance()->filter_member_visible(
			false,
			$this->ability( 'users/list-users' ),
			'users',
			'discover'
		);

		$this->assertFalse( $out );
	}

	/**
	 * A non-ability value is passed through rather than fataling.
	 */
	public function test_non_ability_is_passed_through(): void {
		$out = ToolsetExposureBridge::instance()->filter_member_visible( true, null, 'users', 'discover' );

		$this->assertTrue( $out );
	}
}
