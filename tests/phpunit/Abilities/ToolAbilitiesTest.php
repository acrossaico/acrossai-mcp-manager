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

use AcrossAI_MCP_Manager\Includes\Abilities\ServerGuide;
use AcrossAI_MCP_Manager\Includes\Abilities\SetupRequired;
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

	/**
	 * The two non-protocol members are deliberate, and each is here for its
	 * own reason.
	 *
	 * `mcp-adapter/server-guide` is a tool an operator may add. The setup
	 * diagnostic is one they never choose — the plugin puts it in front of a
	 * stranded client by itself — but it is still tool-level, which is what
	 * hides it from the Abilities tab and exempts it from the exposure gate.
	 * Keeping it OUT of the picker is `ServerTypes::pool()`'s job, not this
	 * list's.
	 */
	public function test_defaults_are_the_protocol_slugs_plus_the_guide_and_the_diagnostic() {
		$this->assertSame( self::seed(), ToolAbilities::get_slugs() );
	}

	/**
	 * The seed COMPOSES ToolPolicy's canonical list; it never copies the slugs.
	 *
	 * Asserted as a subset rather than an identity because the guide is a
	 * deliberate fourth member. It is NOT in `PROTOCOL_TOOLS` — that constant is
	 * column-backed storage, and a fourth entry with no `tool_*` column is
	 * stripped by `split_payload()`'s array_diff and has nowhere to be stored,
	 * silently dropping the operator's pick on every save.
	 */
	public function test_defaults_track_tool_policy() {
		foreach ( ToolPolicy::PROTOCOL_TOOLS as $slug ) {
			$this->assertContains( $slug, ToolAbilities::get_slugs() );
		}
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

		// Counted from the seed, never a literal: a hardcoded total goes stale
		// the next time a default is added and fails a test that has nothing to
		// do with the change (B48).
		$this->assertCount( count( self::seed() ) + 2, $slugs );
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
		$this->assertCount( count( self::seed() ) + 1, $slugs );
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

	/**
	 * The unfiltered seed — the one source of truth for every count above.
	 *
	 * @return string[]
	 */
	private static function seed(): array {
		return array_merge(
			ToolPolicy::PROTOCOL_TOOLS,
			array( ServerGuide::SLUG, SetupRequired::SLUG )
		);
	}
}
