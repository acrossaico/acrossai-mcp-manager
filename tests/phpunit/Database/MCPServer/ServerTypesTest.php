<?php
/**
 * Feature 090 — `ServerTypes` registry coverage (T013), plus the regression the
 * post-implementation architecture review earned.
 *
 * The important cases here are `provideRealWorldPluginFiles()` and
 * `test_requires_resolves_by_directory_not_filename()`. `requires` is documented
 * in BOTH contracts as a plugin FOLDER slug, but the first implementation
 * resolved it as `is_plugin_active( "$slug/$slug.php" )` — a convention
 * WordPress does not enforce. It held for the two types this plugin ships and
 * failed for most real plugins, so every third-party type resolved as
 * permanently unavailable ON A SITE WHERE ITS DEPENDENCY WAS RUNNING.
 *
 * PHPCS, PHPStan L8, a plan security review and a pre-implementation
 * architecture review all passed over it, because nothing exercised the
 * extension point with a case we had not written ourselves. That is what this
 * data provider is: cases we did not write. See BUGS.md B60 / DECISIONS.md D58.
 *
 * PHPUnit is pinned ^9.6 — `@dataProvider`, never `#[DataProvider]`.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Abilities\ServerGuide;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class ServerTypesTest extends WP_UnitTestCase {

	/** @var array<int, string> */
	private $active_plugins_backup = array();

	public function set_up(): void {
		parent::set_up();
		$this->active_plugins_backup = (array) get_option( 'active_plugins', array() );
	}

	public function tear_down(): void {
		update_option( 'active_plugins', $this->active_plugins_backup );
		remove_all_filters( ServerTypes::FILTER );
		parent::tear_down();
	}

	// ---------------------------------------------------------------- seed --

	public function test_seed_ships_both_built_in_types(): void {
		$types = ServerTypes::all();

		$this->assertArrayHasKey( ServerTypes::LEGACY, $types );
		$this->assertArrayHasKey( ServerTypes::ACROSSAI, $types );
	}

	public function test_legacy_type_is_the_always_available_floor(): void {
		$this->assertTrue(
			ServerTypes::is_available( ServerTypes::LEGACY ),
			'mcp-adapter declares no requirement, so it is available on every site.'
		);
	}

	public function test_unknown_slug_degrades_without_fatal(): void {
		$this->assertNull( ServerTypes::get( 'no-such-type' ) );
		$this->assertFalse( ServerTypes::is_available( 'no-such-type' ) );

		// FR-010: tool resolution falls back to the legacy set rather than
		// returning nothing — an empty set would make Reset WIPE the server.
		$this->assertNotEmpty( ServerTypes::tools_for( 'no-such-type' ) );
	}

	public function test_empty_template_falls_back_to_the_legacy_set(): void {
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['hollow'] = array(
					'label' => 'Hollow',
					'tools' => array(),
				);
				return $types;
			}
		);

		// Caught by live verification, not by static analysis: a type reporting
		// AVAILABLE with an empty tool list made Reset erase every tool on the
		// server — strictly worse than the hardcoded-defaults bug F090 fixes.
		$this->assertNotEmpty( ServerTypes::tools_for( 'hollow' ) );
	}

	public function test_a_template_of_unregistered_tools_falls_back_too(): void {
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['ghosts'] = array(
					'label' => 'Ghosts',
					'tools' => array( 'toolset/nothing-here', 'toolset/nor-here' ),
				);
				return $types;
			}
		);

		// The same wipe reached the other way, and the reason the declaration
		// check above is not sufficient on its own: this template looks healthy
		// and only narrows to nothing, because `tools_for()` keeps just the
		// tools actually registered on this site. That is the ordinary state of
		// every `toolset/*` slug on a site without the abilities add-on, so it
		// is reachable by accident rather than only by a hostile filter.
		$this->assertNotEmpty(
			ServerTypes::tools_for( 'ghosts' ),
			'A template whose tools are all unregistered must not make Reset erase the server.'
		);
	}

	/**
	 * `declared_tools()` does NOT narrow; `tools_for()` does. That is the point.
	 *
	 * The two exist because a reader and a writer want opposite answers. Asking
	 * "what can this type serve right now?" before STORING a template is how an
	 * AcrossAI server came to be stamped with mcp-adapter's four tools on every
	 * site without the add-on — the narrowing emptied the list and the legacy
	 * fallback filled it back up with the wrong thing.
	 */
	public function test_declared_tools_keeps_slugs_that_tools_for_narrows_away(): void {
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['ghosts'] = array(
					'label' => 'Ghosts',
					'tools' => array( 'toolset/nothing-here', 'toolset/nor-here' ),
				);
				return $types;
			}
		);

		$this->assertSame(
			array( 'toolset/nothing-here', 'toolset/nor-here' ),
			ServerTypes::declared_tools( 'ghosts' ),
			'A declaration is what the type says, not what the site happens to have.'
		);

		$this->assertNotSame(
			ServerTypes::declared_tools( 'ghosts' ),
			ServerTypes::tools_for( 'ghosts' ),
			'tools_for() must still narrow — the two answer different questions.'
		);
	}

	/**
	 * An empty declaration still falls back, whichever accessor asks.
	 *
	 * The wipe guard is not weakened by adding a non-narrowing reader: a
	 * template of nothing erases a server no matter how it was resolved.
	 */
	public function test_declared_tools_falls_back_for_an_unknown_or_empty_type(): void {
		$this->assertNotEmpty( ServerTypes::declared_tools( 'no-such-type' ) );

		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['hollow'] = array(
					'label' => 'Hollow',
					'tools' => array(),
				);
				return $types;
			}
		);

		$this->assertNotEmpty( ServerTypes::declared_tools( 'hollow' ) );
	}

	// ------------------------------------------------------------ last-wins --

	public function test_filter_can_add_a_type(): void {
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['mycorp'] = array(
					'label' => 'MyCorp',
					'tools' => array( 'mycorp/dispatcher' ),
				);
				return $types;
			}
		);

		$this->assertArrayHasKey( 'mycorp', ServerTypes::all() );
	}

	public function test_later_registration_replaces_the_acrossai_placeholder(): void {
		// D41 last-wins IS the override mechanism: this plugin ships `acrossai`
		// as a placeholder with an empty tool list, and the sibling re-registers
		// the same slug with its real toolsets. If dedup ever flipped to
		// first-wins the sibling would silently stop contributing and every
		// AcrossAI server would fall back to the legacy tools.
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types[ ServerTypes::ACROSSAI ] = array(
					'label'    => 'AcrossAI (overridden)',
					'tools'    => array( 'toolset/example' ),
					'requires' => null,
				);
				return $types;
			}
		);

		$entry = ServerTypes::get( ServerTypes::ACROSSAI );

		$this->assertSame( 'AcrossAI (overridden)', $entry['label'] );
		$this->assertSame( array( 'toolset/example' ), $entry['tools'] );
	}

	// --------------------------------------------------------- default_slug --

	/**
	 * The type this plugin SHIPS as preferred, asserted with no filters in play.
	 *
	 * The two tests below cover the MECHANISM — that an unmet requirement is
	 * skipped and a met one is honoured — using throwaway filter-registered
	 * types. Neither says anything about which type a stock install actually
	 * lands on, which is exactly how the shipped default could drift back to
	 * `acrossai` unnoticed.
	 *
	 * Asserted against `ServerTypes::LEGACY` rather than the literal
	 * 'mcp-adapter': a literal restates the constant instead of checking it,
	 * and goes stale the day the constant moves (B48).
	 *
	 * Deliberately runs WITH the sibling plugin treated as active. Before this
	 * change `acrossai` carried `is_default` and was available on such a site,
	 * so this is the case that would have failed — a site without the sibling
	 * already resolved to LEGACY via the requirement check and proves nothing.
	 */
	public function test_a_stock_install_defaults_to_the_mcp_adapter_type(): void {
		update_option( 'active_plugins', array( 'acrossai-abilities-manager/acrossai-abilities-manager.php' ) );

		$this->assertTrue(
			ServerTypes::is_available( ServerTypes::ACROSSAI ),
			'setup: the AcrossAI type must be AVAILABLE, or this asserts nothing.'
		);
		$this->assertSame( ServerTypes::LEGACY, ServerTypes::default_slug() );
	}

	public function test_default_slug_skips_a_type_whose_requirement_is_unmet(): void {
		update_option( 'active_plugins', array() );

		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['needs-absent-plugin'] = array(
					'label'      => 'Needs Absent Plugin',
					'requires'   => 'a-plugin-that-is-not-here',
					'is_default' => true,
				);
				return $types;
			}
		);

		// FR-009: a site without the dependency must never default to a type it
		// cannot use, however loudly that type claims to be the default.
		$this->assertSame( ServerTypes::LEGACY, ServerTypes::default_slug() );
	}

	public function test_default_slug_accepts_a_default_whose_requirement_is_met(): void {
		update_option( 'active_plugins', array( 'present-plugin/whatever-main-file.php' ) );

		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['satisfied'] = array(
					'label'      => 'Satisfied',
					'requires'   => 'present-plugin',
					'is_default' => true,
				);
				return $types;
			}
		);

		$this->assertSame( 'satisfied', ServerTypes::default_slug() );
	}

	// ------------------------------------------- requires resolution (B60) --

	/**
	 * @dataProvider provideRealWorldPluginFiles
	 *
	 * @param string $folder      Plugin folder slug, as `requires` names it.
	 * @param string $plugin_file The `folder/file.php` WordPress actually stores.
	 */
	public function test_requires_resolves_by_directory_not_filename( string $folder, string $plugin_file ): void {
		update_option( 'active_plugins', array( $plugin_file ) );

		$this->assertTrue(
			ServerTypes::plugin_is_active( $folder ),
			sprintf( 'A plugin active as "%s" must satisfy requires="%s".', $plugin_file, $folder )
		);
	}

	/**
	 * Real `active_plugins` values, NOT invented ones — that distinction is the
	 * entire point. Every case below except the first breaks the `slug/slug.php`
	 * convention, and the first is included precisely because it is the shape
	 * that let the bug survive four review passes.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideRealWorldPluginFiles(): array {
		return array(
			'convention holds (the sibling)' => array( 'acrossai-abilities-manager', 'acrossai-abilities-manager/acrossai-abilities-manager.php' ),
			'WPCode'                         => array( 'insert-headers-and-footers', 'insert-headers-and-footers/ihaf.php' ),
			'LearnDash'                      => array( 'sfwd-lms', 'sfwd-lms/sfwd_lms.php' ),
			'WP Mail SMTP'                   => array( 'wp-mail-smtp', 'wp-mail-smtp/wp_mail_smtp.php' ),
			'Advanced Custom Fields'         => array( 'advanced-custom-fields', 'advanced-custom-fields/acf.php' ),
			'Yoast SEO'                      => array( 'wordpress-seo', 'wordpress-seo/wp-seo.php' ),
			'All in One SEO'                 => array( 'all-in-one-seo-pack', 'all-in-one-seo-pack/all_in_one_seo_pack.php' ),
		);
	}

	public function test_prefix_match_does_not_leak_across_sibling_folders(): void {
		update_option( 'active_plugins', array( 'acf-pro/acf-pro.php' ) );

		// The trailing slash on the prefix is load-bearing: a bare substring
		// match would report `acf` active because `acf-pro` starts with it.
		$this->assertFalse( ServerTypes::plugin_is_active( 'acf' ) );
		$this->assertTrue( ServerTypes::plugin_is_active( 'acf-pro' ) );
	}

	public function test_inactive_plugin_is_unmet(): void {
		update_option( 'active_plugins', array() );

		$this->assertFalse( ServerTypes::plugin_is_active( 'insert-headers-and-footers' ) );
	}

	public function test_empty_slug_is_never_active(): void {
		$this->assertFalse( ServerTypes::plugin_is_active( '' ) );
	}

	// ------------------------------------------------------- pool vs Reset --

	public function test_pool_is_not_scoped_by_server_type(): void {
		// A type is a TEMPLATE for Reset, never a filter over what an operator
		// may add. An earlier iteration subtracted tools claimed by other types,
		// so an AcrossAI server could not be given a protocol tool even when the
		// site needed one. The picker offers the same set on every type.
		$pool = ServerTypes::pool();

		foreach ( ToolPolicy::PROTOCOL_TOOLS as $protocol_tool ) {
			$this->assertContains(
				$protocol_tool,
				$pool,
				'The three built-in tools are offered on every server type, AcrossAI included.'
			);
		}
	}

	public function test_pool_survives_the_registry_being_blind_to_protocol_tools(): void {
		// B62: `wp_get_abilities()` cannot see the three `mcp-adapter/*` slugs in
		// the Tools tab's REST context, because the vendor attaches its
		// registration listener after `wp_abilities_api_init` has fired. Without
		// the exemption inside registered_only() the picker would silently lose
		// its three built-ins here.
		$this->assertNotEmpty( ServerTypes::pool() );
	}

	public function test_reset_stays_scoped_to_the_type(): void {
		// The counterpart to the two above: the POOL is shared, the TEMPLATE is
		// not. Reset on an mcp-adapter server writes that type's own tools —
		// the protocol tools plus the server guide — and nothing else.
		//
		// Composed from the same parts the type declares rather than listed as
		// literals, so adding a tool to the type does not fail this test, which
		// is about SCOPE (B48).
		$this->assertSame(
			array_merge( ToolPolicy::PROTOCOL_TOOLS, array( ServerGuide::SLUG ) ),
			ServerTypes::tools_for( ServerTypes::LEGACY )
		);

		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['scoped'] = array(
					'label' => 'Scoped',
					'tools' => ToolPolicy::PROTOCOL_TOOLS,
				);
				return $types;
			}
		);

		$template = ServerTypes::tools_for( 'scoped' );

		$this->assertNotContains( 'toolset/content', $template, 'Reset writes the type template, not the pool.' );
	}

	public function test_is_available_uses_the_same_resolver(): void {
		update_option( 'active_plugins', array( 'weird-folder/totally-different.php' ) );

		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['weird'] = array(
					'label'    => 'Weird',
					'requires' => 'weird-folder',
				);
				return $types;
			}
		);

		// B32/D58: selection, the enablement gate and the runtime composer all
		// read is_available(). If it ever stops agreeing with plugin_is_active()
		// the three layers diverge and the admin contradicts the gate.
		$this->assertTrue( ServerTypes::is_available( 'weird' ) );
	}
}
