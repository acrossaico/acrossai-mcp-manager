<?php
/**
 * Feature 090 — `ServerEnablement` is the ONE sanctioned `is_enabled` writer (T028).
 *
 * The invariant is asymmetric, and the asymmetry is the safety property:
 *
 *   off -> on   gated on the server type's requirement being met
 *   on  -> off  ALWAYS permitted, unconditionally
 *
 * Gating the disable direction too would strand a server whose dependency was
 * deactivated underneath it — enabled, unable to serve, and impossible to switch
 * off. Every test here that asserts a refusal is paired with one asserting the
 * opposite direction still works, because a facade that refuses everything would
 * satisfy a one-sided suite while being useless.
 *
 * `set()` is also deliberately a no-op-returns-true for a redundant call, so a
 * bulk action over a mixed selection cannot fail on a server that was already in
 * the requested state.
 *
 * Harness: public set_up/tear_down (B56); PHPUnit ^9.6 so `@dataProvider`, never
 * `#[DataProvider]`. No DDL here, so the per-test transaction covers the writes.
 *
 * @package AcrossAI_MCP_Manager\Tests\Database\MCPServer
 */

namespace AcrossAI_MCP_Manager\Tests\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerEnablement;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use WP_Error;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class ServerEnablementTest extends WP_UnitTestCase {

	/** @var int[] */
	private $created = array();

	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->created as $server_id ) {
			$wpdb->delete( $wpdb->prefix . 'acrossai_mcp_servers', array( 'id' => $server_id ), array( '%d' ) );
		}

		$this->created = array();
		remove_all_actions( 'acrossai_mcp_server_enable_refused' );
		remove_all_filters( ServerTypes::FILTER );

		parent::tear_down();
	}

	// --------------------------------------------------- the happy path ----

	public function test_enabling_a_satisfiable_server_succeeds(): void {
		$id = $this->make_server( ServerTypes::LEGACY, false );

		$this->assertTrue( ServerEnablement::set( $id, true ) );
		$this->assertTrue( $this->is_enabled( $id ) );
	}

	public function test_disabling_a_satisfiable_server_succeeds(): void {
		$id = $this->make_server( ServerTypes::LEGACY, true );

		$this->assertTrue( ServerEnablement::set( $id, false ) );
		$this->assertFalse( $this->is_enabled( $id ) );
	}

	// ------------------------------------------------ the gate, off->on ----

	/**
	 * INVERTED in 0.3.6. Enabling is now ALLOWED; connecting is what waits.
	 *
	 * The refusal broke the main setup path for exactly the person it was
	 * written for. Quick Connect cannot complete against a server it is
	 * forbidden to switch on, so the operator who has not installed the add-on
	 * yet — the overwhelmingly common case — hit a wall inside the wizard.
	 *
	 * `is_enabled` is the operator's INTENT and is always writable. Whether the
	 * type's plugin is active is a separate condition deciding whether that
	 * intent takes effect. Install the plugin and the server runs with no
	 * further action, because the intent was already recorded.
	 *
	 * Nothing is lost at runtime: `ToolPolicy` resolves such a server to
	 * exactly `acrossai/setup-required`, so a connected client is told what to
	 * install rather than handed a broken tool list.
	 */
	public function test_enabling_is_allowed_when_the_type_requirement_is_unmet(): void {
		$id     = $this->make_server( 'needs-absent-plugin', false );
		$result = ServerEnablement::set( $id, true );

		$this->assertTrue( $result );
		$this->assertTrue( $this->is_enabled( $id ), 'The operator\'s intent must be recorded.' );
	}

	/**
	 * The server is Enabled but NOT Ready, and says which.
	 */
	public function test_an_enabled_server_still_reports_what_it_is_waiting_for(): void {
		$id = $this->make_server( 'needs-absent-plugin', false );
		ServerEnablement::set( $id, true );

		$notice = ServerTypes::requirement_notice( 'needs-absent-plugin' );

		$this->assertInstanceOf( WP_Error::class, $notice );
		$this->assertSame( 'acrossai_mcp_server_type_unavailable', $notice->get_error_code() );
		$this->assertStringContainsString( 'Abilities Manager', $notice->get_error_message() );
	}

	/**
	 * The hard refusal SURVIVES, and is now the gate's whole job.
	 *
	 * Without this the split would have turned the gate into one that never
	 * says no. An unrecognised slug cannot be made to work by installing
	 * anything, so there is nothing to wait for.
	 */
	public function test_an_unrecognised_type_cannot_be_enabled(): void {
		// FR-010: an unknown slug must never fatal — but it must not be a way to
		// bypass the gate either. Unrecognised means unavailable.
		$id     = $this->make_server( 'a-type-nobody-registered', false );
		$result = ServerEnablement::set( $id, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'acrossai_mcp_unknown_server_type', $result->get_error_code() );
		$this->assertFalse( $this->is_enabled( $id ) );
	}

	/**
	 * SC-003 still holds — it just applies to the surviving refusal.
	 *
	 * A bare "not allowed" leaves the operator with no next step, which is the
	 * failure mode the whole design exists to avoid.
	 */
	public function test_the_refusal_names_what_is_wrong(): void {
		$id     = $this->make_server( 'a-type-nobody-registered', false );
		$result = ServerEnablement::set( $id, true );

		$this->assertNotEmpty( $result->get_error_message() );
		$this->assertStringContainsString( 'a-type-nobody-registered', $result->get_error_message() );
	}

	public function test_a_refusal_fires_the_observability_action(): void {
		$seen = array();
		add_action(
			'acrossai_mcp_server_enable_refused',
			static function ( $server_id, $server_type, $reason ) use ( &$seen ): void {
				$seen[] = array( $server_id, $server_type, $reason );
			},
			10,
			3
		);

		$id = $this->make_server( 'a-type-nobody-registered', false );
		ServerEnablement::set( $id, true );

		$this->assertCount( 1, $seen, 'D19 fire-and-forget observability — a refusal must be auditable.' );
		$this->assertSame( $id, $seen[0][0] );
		$this->assertSame( 'a-type-nobody-registered', $seen[0][1] );
	}

	/**
	 * A permitted enable must NOT fire the refusal action.
	 *
	 * Guards the inversion from the other side: an observability hook that
	 * fires on success would turn an operator's refusal audit into noise, and
	 * nothing else would notice.
	 */
	public function test_an_allowed_enable_fires_no_refusal(): void {
		$seen = 0;
		add_action(
			'acrossai_mcp_server_enable_refused',
			static function () use ( &$seen ): void {
				++$seen;
			}
		);

		ServerEnablement::set( $this->make_server( 'needs-absent-plugin', false ), true );

		$this->assertSame( 0, $seen );
	}

	// ------------------------------------- the asymmetry, on->off always ----

	public function test_disabling_is_permitted_even_when_the_requirement_is_unmet(): void {
		// The stranded-server case: the sibling was deactivated underneath a
		// running server. Gating this direction too would leave the operator with
		// a server they cannot serve from and cannot switch off.
		$id = $this->make_server( 'needs-absent-plugin', true );

		$this->assertTrue( ServerEnablement::set( $id, false ) );
		$this->assertFalse( $this->is_enabled( $id ) );
	}

	public function test_disabling_is_permitted_for_an_unrecognised_type(): void {
		$id = $this->make_server( 'a-type-nobody-registered', true );

		$this->assertTrue( ServerEnablement::set( $id, false ) );
		$this->assertFalse( $this->is_enabled( $id ) );
	}

	public function test_nothing_auto_disables_a_running_server(): void {
		// FR-018: a running server whose dependency later breaks stays ON. The
		// runtime layer swaps its tool list for the diagnostic ability; it does
		// not 404 the route mid-session by switching the server off.
		$id = $this->make_server( 'needs-absent-plugin', true );

		ServerTypes::is_available( 'needs-absent-plugin' );
		ServerEnablement::set( $id, true );

		$this->assertTrue( $this->is_enabled( $id ), 'Reading the gate must never write.' );
	}

	// ------------------------------------------------------- edge cases ----

	public function test_a_redundant_call_is_a_successful_no_op(): void {
		// Even on a stranded server: re-requesting the state it is already in
		// must not re-run the gate, or a bulk action fails on rows it did not
		// need to change.
		$id = $this->make_server( 'needs-absent-plugin', true );

		$this->assertTrue( ServerEnablement::set( $id, true ) );
		$this->assertTrue( $this->is_enabled( $id ) );
	}

	public function test_a_missing_server_is_reported_not_fatal(): void {
		$result = ServerEnablement::set( 999999, true );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'acrossai_mcp_server_not_found', $result->get_error_code() );
	}

	// ------------------------------------------ FR-016a: partial success ----

	/**
	 * Relabelled with the inversion: the skipped one is the UNRECOGNISED type.
	 *
	 * FR-016a's two requirements are unchanged and still what this asserts — a
	 * bulk enable must not fail wholesale, and must not skip silently. Only the
	 * membership moved: a server merely waiting for its plugin now enables with
	 * the rest, and the one that cannot ever work is the one named.
	 */
	public function test_bulk_enable_partially_succeeds_and_names_every_skip(): void {
		$ok      = $this->make_server( ServerTypes::LEGACY, false );
		$waiting = $this->make_server( 'needs-absent-plugin', false );
		$blocked = $this->make_server( 'a-type-nobody-registered', false );

		$result = ServerEnablement::set_many( array( $ok, $waiting, $blocked ), true );

		$this->assertSame( array( $ok, $waiting ), $result['changed'] );
		$this->assertArrayHasKey( $blocked, $result['skipped'] );
		$this->assertNotEmpty( $result['skipped'][ $blocked ], 'Every skip carries its reason.' );
		$this->assertArrayNotHasKey(
			$waiting,
			$result['skipped'],
			'Waiting for a plugin is not a reason to skip — the intent is recorded either way.'
		);

		$this->assertTrue( $this->is_enabled( $ok ) );
		$this->assertTrue( $this->is_enabled( $waiting ) );
		$this->assertFalse( $this->is_enabled( $blocked ) );
	}

	public function test_bulk_disable_never_skips_anything(): void {
		$ok      = $this->make_server( ServerTypes::LEGACY, true );
		$blocked = $this->make_server( 'needs-absent-plugin', true );

		$result = ServerEnablement::set_many( array( $ok, $blocked ), false );

		$this->assertSame( array(), $result['skipped'], 'Disabling is never gated, in bulk either.' );
		$this->assertCount( 2, $result['changed'] );
	}

	// ---------------------------------------------------------- helpers ----

	/**
	 * Create a server row, registering its type when it is not a built-in.
	 *
	 * `needs-absent-plugin` requires a plugin that is not installed, which is how
	 * these tests reach the unmet-requirement branch without touching
	 * `active_plugins` or deactivating anything real.
	 *
	 * @param string $server_type Type slug to store on the row.
	 * @param bool   $enabled     Initial state.
	 * @return int Server row id.
	 */
	private function make_server( string $server_type, bool $enabled ): int {
		if ( 'needs-absent-plugin' === $server_type ) {
			add_filter(
				ServerTypes::FILTER,
				static function ( array $types ): array {
					$types['needs-absent-plugin'] = array(
						'label'    => 'Needs Absent Plugin',
						'requires' => 'a-plugin-that-is-not-installed-here',
					);
					return $types;
				}
			);
		}

		$slug = 'enablement-' . uniqid();
		$id   = (int) MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Enablement test server',
				'server_slug'            => $slug,
				'description'            => 'Seeded by ServerEnablementTest',
				'is_enabled'             => $enabled ? 1 : 0,
				'server_type'            => $server_type,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);

		$this->created[] = $id;

		return $id;
	}

	private function is_enabled( int $server_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT is_enabled FROM `' . $wpdb->prefix . 'acrossai_mcp_servers` WHERE id = %d',
				$server_id
			)
		);
	}
}
