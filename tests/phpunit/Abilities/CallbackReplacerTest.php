<?php
/**
 * CallbackReplacer — unit coverage.
 *
 * Verifies the `wp_register_ability_args` callback-swap for the three
 * vendor default ability slugs, and pass-through for any other slug.
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CallbackReplacer;
use AcrossAI_MCP_Manager\Includes\Abilities\Discover;
use AcrossAI_MCP_Manager\Includes\Abilities\Execute;
use AcrossAI_MCP_Manager\Includes\Abilities\GetAbilityInfo;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class CallbackReplacerTest extends WP_UnitTestCase {

	public function test_replaces_discover_callbacks(): void {
		$args = $this->stub_args();
		$out  = CallbackReplacer::instance()->replace_callbacks( $args, 'mcp-adapter/discover-abilities' );

		$this->assertSame( array( Discover::class, 'execute' ), $out['execute_callback'] );
		$this->assertSame( array( Discover::class, 'check_permission' ), $out['permission_callback'] );
	}

	public function test_replaces_get_ability_info_callbacks(): void {
		$args = $this->stub_args();
		$out  = CallbackReplacer::instance()->replace_callbacks( $args, 'mcp-adapter/get-ability-info' );

		$this->assertSame( array( GetAbilityInfo::class, 'execute' ), $out['execute_callback'] );
		$this->assertSame( array( GetAbilityInfo::class, 'check_permission' ), $out['permission_callback'] );
	}

	public function test_replaces_execute_ability_callbacks(): void {
		$args = $this->stub_args();
		$out  = CallbackReplacer::instance()->replace_callbacks( $args, 'mcp-adapter/execute-ability' );

		$this->assertSame( array( Execute::class, 'execute' ), $out['execute_callback'] );
		$this->assertSame( array( Execute::class, 'check_permission' ), $out['permission_callback'] );
	}

	public function test_passes_through_other_ability_names(): void {
		$args = $this->stub_args();
		$out  = CallbackReplacer::instance()->replace_callbacks( $args, 'myplugin/some-other-ability' );
		$this->assertSame( $args, $out );
	}

	public function test_preserves_non_callback_args_on_swap(): void {
		$args = array(
			'label'               => 'X',
			'description'         => 'Y',
			'category'            => 'mcp-adapter',
			'input_schema'        => array( 'type' => 'object' ),
			'execute_callback'    => '__return_true',
			'permission_callback' => '__return_true',
		);
		$out  = CallbackReplacer::instance()->replace_callbacks( $args, 'mcp-adapter/get-ability-info' );

		$this->assertSame( 'X', $out['label'] );
		$this->assertSame( 'Y', $out['description'] );
		$this->assertSame( 'mcp-adapter', $out['category'] );
		$this->assertSame( array( 'type' => 'object' ), $out['input_schema'] );
	}

	/**
	 * F089 makes discover-abilities the documented exception to the rule above:
	 * it also receives an input_schema (the vendor registers none, and WP core
	 * then refuses any input) and a description written for the LLM. Label and
	 * category are still passed through untouched.
	 */
	public function test_discover_abilities_also_receives_schema_and_description(): void {
		$args = array(
			'label'       => 'X',
			'description' => 'Y',
			'category'    => 'mcp-adapter',
		);
		$out  = CallbackReplacer::instance()->replace_callbacks( $args, CallbackReplacer::DISCOVER_ABILITY );

		$this->assertSame( 'X', $out['label'] );
		$this->assertSame( 'mcp-adapter', $out['category'] );
		$this->assertNotSame( 'Y', $out['description'], 'F089 replaces the description.' );
		$this->assertArrayHasKey( 'per_page', $out['input_schema']['properties'] );
	}

	private function stub_args(): array {
		return array(
			'label'               => 'x',
			'description'         => 'x',
			'category'            => 'mcp-adapter',
			'execute_callback'    => '__return_true',
			'permission_callback' => '__return_true',
		);
	}
}
