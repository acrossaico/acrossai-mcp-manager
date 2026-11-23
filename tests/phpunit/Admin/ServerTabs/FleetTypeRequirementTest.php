<?php
/**
 * What the Fleet server type declares it needs.
 *
 * The lasting invariant is that Fleet and AcrossAI answer this identically:
 * Fleet serves the AcrossAI toolsets, so it can never require more or less.
 *
 * The shared answer has moved twice. It was `null` on two beliefs that were
 * both wrong, and the cost was silent — the type reported itself available on a
 * site where its tools could not exist, so a hub calling in got an empty answer
 * instead of one naming what was missing. It then became
 * `acrossai-abilities-manager`, which was true for as long as that add-on was a
 * separate plugin. F106 absorbed it, so it is `null` again — this time because
 * the toolsets ship here and the requirement is structurally met, not because
 * anyone decided it did not matter.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs;

use AcrossAI_MCP_Manager\Includes\Abilities\SetupRequired;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use WP_UnitTestCase;

final class FleetTypeRequirementTest extends WP_UnitTestCase {

	/**
	 * Fleet declares the same requirement as the AcrossAI type.
	 *
	 * It serves the same toolsets, so it can never need more or less. That
	 * equality is the durable invariant and is what this test is really for.
	 *
	 * Since F106 the shared answer is `null` on both. The toolsets ship inside
	 * this plugin, so there is no add-on left to be missing — where this test
	 * used to assert a requirement was DECLARED, it now asserts neither type
	 * invents one, which would make both report themselves unavailable on every
	 * site and serve `SetupRequired` instead of their tools.
	 */
	public function test_fleet_declares_the_same_requirement_as_the_acrossai_type(): void {
		$fleet    = ServerTypes::get( ServerTypes::FLEET );
		$acrossai = ServerTypes::get( ServerTypes::ACROSSAI );

		$this->assertNotNull( $fleet );
		$this->assertNotNull( $acrossai );

		$this->assertSame(
			$acrossai['requires'],
			$fleet['requires'],
			'Fleet serves the AcrossAI toolsets, so it cannot require anything different from the AcrossAI type.'
		);

		$this->assertNull(
			$acrossai['requires'],
			'The AcrossAI toolsets ship in this plugin since F106. A requirement here would make the type unavailable on every site.'
		);

		$this->assertTrue(
			ServerTypes::is_available( ServerTypes::ACROSSAI ),
			'A built-in type must be available without any other plugin installed.'
		);
		$this->assertTrue(
			ServerTypes::is_available( ServerTypes::FLEET ),
			'A built-in type must be available without any other plugin installed.'
		);
	}

	/** Both types draw on the same toolset list, which is why the above holds. */
	public function test_fleet_serves_the_same_toolsets_as_the_acrossai_type(): void {
		$fleet    = ServerTypes::get( ServerTypes::FLEET );
		$acrossai = ServerTypes::get( ServerTypes::ACROSSAI );

		$this->assertSame( $acrossai['tools'], $fleet['tools'] );
	}

	/**
	 * The setup message names no plugin, because it cannot know one.
	 *
	 * This test used to assert the opposite: that the message names the single
	 * add-on every requiring type needed, and it carried a warning that once
	 * two types needed DIFFERENT add-ons the message would have to take the
	 * name from the type.
	 *
	 * F106 reached that condition from the other side. No built-in type
	 * requires anything any more, so the only way `SetupRequired` can be served
	 * is a THIRD-PARTY type whose `requires` plugin is inactive — and the one
	 * name the message used to carry, AcrossAI Abilities Manager, is now part of
	 * this plugin and cannot be the answer to anybody's requirement. A hardcoded
	 * name would send the operator to install something that no longer exists.
	 *
	 * So the invariant inverts: while the ability stays global and context-free,
	 * the message must stay generic. If `SetupRequired` ever learns which server
	 * it is answering for, this is the test to revisit.
	 */
	public function test_no_built_in_type_requires_another_plugin(): void {
		$needed = array();

		foreach ( ServerTypes::all() as $slug => $type ) {
			if ( ! empty( $type['requires'] ) ) {
				$needed[ $slug ] = (string) $type['requires'];
			}
		}

		$this->assertSame(
			array(),
			$needed,
			'Every built-in type ships its own tools since F106. A declared requirement here makes the type unavailable until an unrelated plugin is installed.'
		);
	}

	/**
	 * The generic message still explains itself, and still names no plugin.
	 *
	 * Kept separate from the registry assertion above so a failure says which
	 * of the two broke: the types declaring a requirement again, or the message
	 * regaining a hardcoded name.
	 */
	public function test_the_setup_message_explains_itself_without_naming_a_plugin(): void {
		$message = SetupRequired::message();

		$this->assertStringNotContainsString(
			'Abilities Manager',
			$message,
			'That plugin is part of this one since F106, so it can never be the plugin a server type is waiting for.'
		);

		$this->assertStringContainsString(
			'no tools are available',
			$message,
			'The message exists to tell an AI client why it got nothing.'
		);
		$this->assertStringContainsString(
			'change this server',
			$message,
			'The operator needs the way out, not just the diagnosis.'
		);
	}

	/**
	 * An unmet requirement swaps the tools, and nothing else.
	 *
	 * The reason declaring `requires` is safe: pairing goes through
	 * `/fleet/register`, which never consults `is_available()`. Only the tool
	 * composition changes, and it changes to a notice that explains itself.
	 */
	public function test_an_unmet_requirement_only_changes_which_tools_are_served(): void {
		$this->assertTrue(
			method_exists( ToolPolicy::class, 'compose_effective_tools_for_row' ),
			'The composer is where an unmet requirement takes effect.'
		);

		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Fleet/Register.php' );

		$this->assertStringNotContainsString(
			'is_available(',
			$source,
			'Pairing must not depend on the add-on being present — a hub can be paired before it arrives.'
		);
	}
}
