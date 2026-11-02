<?php
/**
 * What the Fleet server type declares it needs.
 *
 * The type carried no `requires` for a while, on two beliefs that were both
 * wrong — see the constant's docblock. The cost was silent: the type reported
 * itself available on a site where its tools could not exist, so a hub calling
 * in got an empty answer instead of one naming what was missing.
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
	 * Fleet needs the same add-on as the AcrossAI type.
	 *
	 * It serves the same toolsets, so it cannot need less.
	 */
	public function test_fleet_requires_the_same_add_on_as_the_acrossai_type(): void {
		$fleet    = ServerTypes::get( ServerTypes::FLEET );
		$acrossai = ServerTypes::get( ServerTypes::ACROSSAI );

		$this->assertNotNull( $fleet );
		$this->assertNotNull( $acrossai );

		$this->assertNotEmpty(
			$fleet['requires'],
			'A type whose tools come from an add-on must say so, or it claims to be available where its tools cannot exist.'
		);
		$this->assertSame(
			$acrossai['requires'],
			$fleet['requires'],
			'Fleet serves the AcrossAI toolsets, so it cannot require less than the AcrossAI type.'
		);
	}

	/** Both types draw on the same toolset list, which is why the above holds. */
	public function test_fleet_serves_the_same_toolsets_as_the_acrossai_type(): void {
		$fleet    = ServerTypes::get( ServerTypes::FLEET );
		$acrossai = ServerTypes::get( ServerTypes::ACROSSAI );

		$this->assertSame( $acrossai['tools'], $fleet['tools'] );
	}

	/**
	 * The message a hub gets when the add-on is missing names the right plugin.
	 *
	 * `SetupRequired` is one global ability with no server-type context, so its
	 * message is hardcoded. That is only correct while every type that can
	 * trigger it needs the SAME add-on — which is exactly what the first test
	 * above keeps true. If a future type requires something else, this is the
	 * test that should fail and send someone to parameterise the message.
	 */
	public function test_the_setup_message_names_what_every_requiring_type_needs(): void {
		$needed = array();

		foreach ( ServerTypes::all() as $slug => $type ) {
			if ( ! empty( $type['requires'] ) ) {
				$needed[] = (string) $type['requires'];
			}
		}

		$this->assertNotEmpty( $needed, 'At least one type should declare a requirement.' );
		$this->assertCount(
			1,
			array_unique( $needed ),
			'SetupRequired hardcodes one add-on name. Once two types need DIFFERENT add-ons, that message must take the name from the type.'
		);
		$this->assertStringContainsString( 'Abilities Manager', SetupRequired::message() );
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
