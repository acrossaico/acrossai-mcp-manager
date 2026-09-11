<?php
/**
 * ToolAbilities — the filterable tool-level list behind both admin pickers.
 *
 * One list, two opposite jobs: the Abilities tab hides every slug in it, and
 * the Tools tab's left pool shows nothing else. Before F087 the list was three
 * literals inside `src/js/abilities.js`, unreachable from any other plugin — so
 * the sibling's `toolset/*` dispatchers showed up as operator-toggleable rows on
 * one tab while ~370 individual abilities crowded the other.
 *
 * These cover the resolver's own contract: what it seeds from, that the filter
 * both adds and subtracts, and that a careless callback return normalizes
 * instead of reaching JSON encoding as junk.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Abilities
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\ToolAbilities;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ToolAbilitiesTest extends WP_UnitTestCase {

	private const HOOK = 'acrossai_mcp_manager_tool_abilities';

	public function set_up(): void {
		parent::set_up();
		remove_all_filters( self::HOOK );
	}

	public function tear_down(): void {
		remove_all_filters( self::HOOK );
		parent::tear_down();
	}

	public function test_defaults_are_exactly_the_three_protocol_slugs() {
		$this->assertSame(
			array(
				'mcp-adapter/discover-abilities',
				'mcp-adapter/get-ability-info',
				'mcp-adapter/execute-ability',
			),
			ToolAbilities::get_slugs()
		);
	}

	/**
	 * The seed is ToolPolicy's canonical list, not a fourth copy of the slugs.
	 */
	public function test_defaults_track_tool_policy() {
		$this->assertSame( ToolPolicy::PROTOCOL_TOOLS, ToolAbilities::get_slugs() );
	}

	/**
	 * The sibling plugin's use case — declare `toolset/*` dispatchers without
	 * this plugin knowing the vocabulary.
	 */
	public function test_filter_can_add_slugs() {
		add_filter(
			self::HOOK,
			static function ( array $slugs ): array {
				$slugs[] = 'toolset/content';
				$slugs[] = 'toolset/cron';
				return $slugs;
			}
		);

		$slugs = ToolAbilities::get_slugs();

		$this->assertContains( 'toolset/content', $slugs );
		$this->assertContains( 'toolset/cron', $slugs );
		$this->assertContains( 'mcp-adapter/execute-ability', $slugs );
		$this->assertCount( 5, $slugs );
	}

	/**
	 * The filter subtracts as well as adds — dropping a default puts it back on
	 * the Abilities tab and out of the Tools pool.
	 */
	public function test_filter_can_remove_a_default_slug() {
		add_filter(
			self::HOOK,
			static function ( array $slugs ): array {
				return array_values(
					array_diff( $slugs, array( 'mcp-adapter/execute-ability' ) )
				);
			}
		);

		$slugs = ToolAbilities::get_slugs();

		$this->assertNotContains( 'mcp-adapter/execute-ability', $slugs );
		$this->assertContains( 'mcp-adapter/discover-abilities', $slugs );
	}

	public function test_a_callback_emptying_the_list_degrades_gracefully() {
		add_filter( self::HOOK, '__return_empty_array' );

		$this->assertSame( array(), ToolAbilities::get_slugs() );
	}

	public function test_duplicates_are_collapsed_and_keys_are_list_indexed() {
		add_filter(
			self::HOOK,
			static function ( array $slugs ): array {
				$slugs[] = 'toolset/content';
				$slugs[] = 'toolset/content';
				$slugs[] = 'mcp-adapter/execute-ability';
				return $slugs;
			}
		);

		$slugs = ToolAbilities::get_slugs();

		$this->assertSame( array_values( $slugs ), $slugs, 'Keys must be a 0..n list for wp_localize_script to emit a JS array.' );
		$this->assertSame( 1, count( array_keys( $slugs, 'toolset/content', true ) ) );
		$this->assertCount( 4, $slugs );
	}

	/**
	 * @dataProvider junk_returns
	 *
	 * @param mixed    $returned Whatever a careless callback hands back.
	 * @param string[] $expected Normalized result.
	 */
	public function test_junk_returns_normalize_without_fataling( $returned, array $expected ) {
		add_filter(
			self::HOOK,
			static function () use ( $returned ) {
				return $returned;
			}
		);

		$this->assertSame( $expected, ToolAbilities::get_slugs() );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string[]}>
	 */
	public function junk_returns(): array {
		return array(
			'null'             => array( null, array() ),
			'a bare string'    => array( 'toolset/content', array( 'toolset/content' ) ),
			'ints and empties' => array( array( 1, '', 'toolset/cron', 0 ), array( '1', 'toolset/cron', '0' ) ),
			'an assoc array'   => array( array( 'a' => 'toolset/files' ), array( 'toolset/files' ) ),
		);
	}
}
