<?php
/**
 * End-to-end role-access matrix tests for the two-layer MCP permission stack.
 *
 * Layer 1 — `TransportPermissionDefault::filter_default_capability` on the
 * vendor `mcp_adapter_default_transport_permission_user_capability` filter.
 * Returns a capability string that `HttpTransport::check_permission` passes
 * to `current_user_can()`.
 *
 * Layer 2 — `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` on the vendor
 * `mcp_adapter_pre_tool_call` filter (F015, unchanged). Returns `$args` on
 * allow, `WP_Error` on deny per the operator's rule.
 *
 * A user reaches an MCP server's endpoint iff BOTH layers allow. These
 * tests compose the two layers (via `user_can_reach()`) and assert the
 * composed result for realistic combinations of:
 *
 *   - user role (administrator, editor, author, contributor, subscriber, anonymous)
 *   - rule state on the server (none, wp_role, wp_user, wp_capability,
 *     `everyone`, `authenticated`, multi-value rules)
 *   - multiple servers with different rules — same user pool
 *
 * @package AcrossAI_MCP_Manager\Tests\Includes\AccessControl
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Includes\AccessControl;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use AcrossAI_MCP_Manager\Includes\AccessControl\TransportPermissionDefault;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use WP\MCP\Transport\Infrastructure\HttpRequestContext;
use WP_REST_Request;
use WP_UnitTestCase;
use WPBoilerplate\AccessControl\Database\Rule\RuleQuery;

/**
 * @coversNothing
 */
final class TransportPermissionRoleMatrixTest extends WP_UnitTestCase {

	private const NAMESPACE_SLUG = 'acrossai-mcp-manager';

	/** @var int */
	private $admin_id;
	/** @var int */
	private $editor_id;
	/** @var int */
	private $author_id;
	/** @var int */
	private $contributor_id;
	/** @var int */
	private $subscriber_id;

	protected function setUp(): void {
		parent::setUp();
		$this->reset_memo();

		$this->admin_id       = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id      = $this->factory->user->create( array( 'role' => 'editor' ) );
		$this->author_id      = $this->factory->user->create( array( 'role' => 'author' ) );
		$this->contributor_id = $this->factory->user->create( array( 'role' => 'contributor' ) );
		$this->subscriber_id  = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Group A — No rule on server → transport gate demands `manage_options`.
	// Only administrators reach the endpoint.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @param string $role_key    Snake-case identifier for the test role slot.
	 * @param bool   $expected    Whether the user of that role should reach the endpoint.
	 *
	 * @dataProvider no_rule_role_matrix
	 */
	public function test_no_rule_only_admin_reaches_endpoint( string $role_key, bool $expected ): void {
		$slug = $this->make_server( 'no-rule-' . uniqid() );
		$this->purge_rule_for( $slug );

		$user_id = $this->user_id_for_role_key( $role_key );
		$actual  = $this->user_can_reach( $user_id, $slug );

		$this->assertSame(
			$expected,
			$actual,
			"On a rule-less server, role '{$role_key}' should " . ( $expected ? 'reach' : 'NOT reach' ) . ' the MCP endpoint'
		);
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function no_rule_role_matrix(): array {
		return array(
			'administrator' => array( 'administrator', true ),
			'editor'        => array( 'editor', false ),
			'author'        => array( 'author', false ),
			'contributor'   => array( 'contributor', false ),
			'subscriber'    => array( 'subscriber', false ),
			'anonymous'     => array( 'anonymous', false ),
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Group B — Rule = single WP role. Transport gate returns 'read';
	// F015 gate allows only users whose role matches the rule (admins bypass).
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @param string $rule_role   The WP role slug configured in the wpb-ac rule.
	 * @param string $user_role   Which of our fixture users to authenticate as.
	 * @param bool   $expected    Whether that user should reach the endpoint.
	 *
	 * @dataProvider single_role_rule_matrix
	 */
	public function test_single_role_rule_grants_matching_role_plus_admin_bypass(
		string $rule_role,
		string $user_role,
		bool $expected
	): void {
		$slug = $this->make_server( 'role-rule-' . uniqid() );
		$this->set_rule_for( $slug, 'wp_role', array( $rule_role ) );

		$user_id = $this->user_id_for_role_key( $user_role );
		$actual  = $this->user_can_reach( $user_id, $slug );

		$this->assertSame(
			$expected,
			$actual,
			"With rule=wp_role({$rule_role}), user_role='{$user_role}' should " . ( $expected ? 'reach' : 'NOT reach' ) . ' the endpoint'
		);
	}

	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function single_role_rule_matrix(): array {
		return array(
			// Rule allows Editor role.
			'editor-rule / admin'       => array( 'editor', 'administrator', true ),  // admin bypass
			'editor-rule / editor'      => array( 'editor', 'editor', true ),         // matches rule
			'editor-rule / author'      => array( 'editor', 'author', false ),        // doesn't match
			'editor-rule / contributor' => array( 'editor', 'contributor', false ),
			'editor-rule / subscriber'  => array( 'editor', 'subscriber', false ),
			'editor-rule / anonymous'   => array( 'editor', 'anonymous', false ),     // transport blocks

			// Rule allows Author role.
			'author-rule / admin'       => array( 'author', 'administrator', true ),  // admin bypass
			'author-rule / editor'      => array( 'author', 'editor', false ),
			'author-rule / author'      => array( 'author', 'author', true ),
			'author-rule / contributor' => array( 'author', 'contributor', false ),
			'author-rule / subscriber'  => array( 'author', 'subscriber', false ),

			// Rule allows Subscriber role — anyone logged in with subscriber+ role gets in when
			// they hold that role (only subscriber does; editors do NOT hold the subscriber role
			// slug, they hold the editor slug — WP roles aren't hierarchical at the slug level).
			'subscriber-rule / admin'      => array( 'subscriber', 'administrator', true ),
			'subscriber-rule / editor'     => array( 'subscriber', 'editor', false ),
			'subscriber-rule / subscriber' => array( 'subscriber', 'subscriber', true ),
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Group C — Rule = multiple WP roles. Any listed role gets in.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_multi_role_rule_grants_every_listed_role(): void {
		$slug = $this->make_server( 'multi-role-' . uniqid() );
		$this->set_rule_for( $slug, 'wp_role', array( 'editor', 'author' ) );

		$this->assertTrue( $this->user_can_reach( $this->admin_id, $slug ), 'admin bypass' );
		$this->assertTrue( $this->user_can_reach( $this->editor_id, $slug ), 'editor is listed' );
		$this->assertTrue( $this->user_can_reach( $this->author_id, $slug ), 'author is listed' );
		$this->assertFalse( $this->user_can_reach( $this->contributor_id, $slug ), 'contributor NOT listed' );
		$this->assertFalse( $this->user_can_reach( $this->subscriber_id, $slug ), 'subscriber NOT listed' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Group D — Rule = specific WP user IDs. Only listed users get in
	// (plus admins via v2 hierarchy bypass).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_user_id_rule_grants_only_listed_users_plus_admins(): void {
		$slug = $this->make_server( 'user-id-rule-' . uniqid() );
		$this->set_rule_for(
			$slug,
			'wp_user',
			array( (string) $this->editor_id, (string) $this->subscriber_id )
		);

		$this->assertTrue( $this->user_can_reach( $this->admin_id, $slug ), 'admin bypass' );
		$this->assertTrue( $this->user_can_reach( $this->editor_id, $slug ), 'editor is in the allow-list' );
		$this->assertTrue( $this->user_can_reach( $this->subscriber_id, $slug ), 'subscriber is in the allow-list' );
		$this->assertFalse( $this->user_can_reach( $this->author_id, $slug ), 'author is NOT in the allow-list' );
		$this->assertFalse( $this->user_can_reach( $this->contributor_id, $slug ), 'contributor is NOT in the allow-list' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Group E — Rule = specific WP capability. Users holding the cap get in.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_capability_rule_grants_holders_of_the_capability(): void {
		$slug = $this->make_server( 'cap-rule-' . uniqid() );
		// `edit_others_posts` is held by editor + administrator in default WP roles.
		$this->set_rule_for( $slug, 'wp_capability', array( 'edit_others_posts' ) );

		$this->assertTrue( $this->user_can_reach( $this->admin_id, $slug ), 'admin holds edit_others_posts' );
		$this->assertTrue( $this->user_can_reach( $this->editor_id, $slug ), 'editor holds edit_others_posts' );
		$this->assertFalse( $this->user_can_reach( $this->author_id, $slug ), 'author does NOT hold edit_others_posts' );
		$this->assertFalse( $this->user_can_reach( $this->contributor_id, $slug ), 'contributor does NOT hold it' );
		$this->assertFalse( $this->user_can_reach( $this->subscriber_id, $slug ), 'subscriber does NOT hold it' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Group F — Sentinel rules: `authenticated` and `everyone`.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_authenticated_rule_grants_every_logged_in_user(): void {
		$slug = $this->make_server( 'authenticated-' . uniqid() );
		$this->set_rule_for( $slug, 'authenticated', array() );

		$this->assertTrue( $this->user_can_reach( $this->admin_id, $slug ), 'admin authenticated' );
		$this->assertTrue( $this->user_can_reach( $this->editor_id, $slug ), 'editor authenticated' );
		$this->assertTrue( $this->user_can_reach( $this->author_id, $slug ), 'author authenticated' );
		$this->assertTrue( $this->user_can_reach( $this->contributor_id, $slug ), 'contributor authenticated' );
		$this->assertTrue( $this->user_can_reach( $this->subscriber_id, $slug ), 'subscriber authenticated' );

		// Anonymous is still blocked by the vendor transport gate (`current_user_can('read')`
		// returns false for user_id=0), NOT by the wpb-ac rule.
		$this->assertFalse(
			$this->user_can_reach( 0, $slug ),
			'anonymous is still blocked at the transport layer regardless of the wpb-ac rule'
		);
	}

	public function test_everyone_rule_still_blocks_anonymous_via_transport_read_gate(): void {
		$slug = $this->make_server( 'everyone-' . uniqid() );
		$this->set_rule_for( $slug, 'everyone', array() );

		$this->assertTrue( $this->user_can_reach( $this->subscriber_id, $slug ) );
		$this->assertFalse(
			$this->user_can_reach( 0, $slug ),
			"Rule=everyone does not override the vendor's transport-layer 'read' cap requirement — anonymous stays blocked"
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Group G — Multi-server × multi-user matrix.
	// One user pool; four servers with different rule shapes; asserts the
	// full 4×5 truth table so cross-server bleed is impossible.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_multi_server_multi_user_matrix_evaluates_each_pair_independently(): void {
		// Server 1 — no rule → admin-only.
		$s_none = $this->make_server( 'matrix-none-' . uniqid() );
		$this->purge_rule_for( $s_none );

		// Server 2 — rule = editor role.
		$s_editor = $this->make_server( 'matrix-editor-' . uniqid() );
		$this->set_rule_for( $s_editor, 'wp_role', array( 'editor' ) );

		// Server 3 — rule = specific user IDs (author + subscriber).
		$s_user = $this->make_server( 'matrix-user-' . uniqid() );
		$this->set_rule_for(
			$s_user,
			'wp_user',
			array( (string) $this->author_id, (string) $this->subscriber_id )
		);

		// Server 4 — rule = authenticated (any logged-in).
		$s_auth = $this->make_server( 'matrix-auth-' . uniqid() );
		$this->set_rule_for( $s_auth, 'authenticated', array() );

		// Full expected truth table (rows = users, cols = servers).
		//                             none    editor   user(a,s) authenticated
		$expected = array(
			'administrator' => array( true,   true,    true,     true ), // admin bypass everywhere
			'editor'        => array( false,  true,    false,    true ),
			'author'        => array( false,  false,   true,     true ),
			'contributor'   => array( false,  false,   false,    true ),
			'subscriber'    => array( false,  false,   true,     true ),
		);

		$user_ids = array(
			'administrator' => $this->admin_id,
			'editor'        => $this->editor_id,
			'author'        => $this->author_id,
			'contributor'   => $this->contributor_id,
			'subscriber'    => $this->subscriber_id,
		);
		$servers  = array( $s_none, $s_editor, $s_user, $s_auth );

		foreach ( $expected as $role => $row ) {
			$uid = $user_ids[ $role ];
			foreach ( $servers as $i => $slug ) {
				$this->reset_memo(); // fresh evaluation per (user, server) pair
				$actual = $this->user_can_reach( $uid, $slug );
				$this->assertSame(
					$row[ $i ],
					$actual,
					sprintf(
						"Matrix cell (%s, server#%d=%s) expected=%s, got=%s",
						$role,
						$i,
						$slug,
						$row[ $i ] ? 'ALLOW' : 'DENY',
						$actual ? 'ALLOW' : 'DENY'
					)
				);
			}
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Group H — Same user, granted access to multiple servers via user_id rules.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_single_user_reaches_only_servers_whose_user_id_rule_lists_them(): void {
		$s_grants_editor      = $this->make_server( 'grants-editor-' . uniqid() );
		$s_grants_author      = $this->make_server( 'grants-author-' . uniqid() );
		$s_grants_editor_auth = $this->make_server( 'grants-both-' . uniqid() );
		$s_none               = $this->make_server( 'grants-none-' . uniqid() );

		$this->set_rule_for( $s_grants_editor, 'wp_user', array( (string) $this->editor_id ) );
		$this->set_rule_for( $s_grants_author, 'wp_user', array( (string) $this->author_id ) );
		$this->set_rule_for(
			$s_grants_editor_auth,
			'wp_user',
			array( (string) $this->editor_id, (string) $this->author_id )
		);
		$this->purge_rule_for( $s_none );

		// Editor:
		$this->reset_memo();
		$this->assertTrue( $this->user_can_reach( $this->editor_id, $s_grants_editor ) );
		$this->reset_memo();
		$this->assertFalse( $this->user_can_reach( $this->editor_id, $s_grants_author ) );
		$this->reset_memo();
		$this->assertTrue( $this->user_can_reach( $this->editor_id, $s_grants_editor_auth ) );
		$this->reset_memo();
		$this->assertFalse( $this->user_can_reach( $this->editor_id, $s_none ), 'no-rule server → admin-only' );

		// Author:
		$this->reset_memo();
		$this->assertFalse( $this->user_can_reach( $this->author_id, $s_grants_editor ) );
		$this->reset_memo();
		$this->assertTrue( $this->user_can_reach( $this->author_id, $s_grants_author ) );
		$this->reset_memo();
		$this->assertTrue( $this->user_can_reach( $this->author_id, $s_grants_editor_auth ) );
		$this->reset_memo();
		$this->assertFalse( $this->user_can_reach( $this->author_id, $s_none ) );

		// Subscriber (nobody granted):
		$this->reset_memo();
		$this->assertFalse( $this->user_can_reach( $this->subscriber_id, $s_grants_editor ) );
		$this->reset_memo();
		$this->assertFalse( $this->user_can_reach( $this->subscriber_id, $s_grants_author ) );
		$this->reset_memo();
		$this->assertFalse( $this->user_can_reach( $this->subscriber_id, $s_grants_editor_auth ) );

		// Admin (bypass everywhere):
		$this->reset_memo();
		$this->assertTrue( $this->user_can_reach( $this->admin_id, $s_grants_editor ) );
		$this->reset_memo();
		$this->assertTrue( $this->user_can_reach( $this->admin_id, $s_grants_author ) );
		$this->reset_memo();
		$this->assertTrue( $this->user_can_reach( $this->admin_id, $s_grants_editor_auth ) );
		$this->reset_memo();
		$this->assertTrue( $this->user_can_reach( $this->admin_id, $s_none ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Group I — Rule mutation invalidates access without leaking through
	// memoization ACROSS filter-callback fires that happen in separate
	// simulated requests.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_rule_change_between_requests_takes_effect_after_memo_reset(): void {
		$slug = $this->make_server( 'mutation-' . uniqid() );
		$this->purge_rule_for( $slug );

		// Request 1 — no rule → editor blocked.
		$this->reset_memo();
		$this->assertFalse( $this->user_can_reach( $this->editor_id, $slug ) );

		// Operator adds a wp_role=editor rule between requests.
		$this->set_rule_for( $slug, 'wp_role', array( 'editor' ) );

		// Request 2 — memo cleared → editor now reaches the endpoint.
		$this->reset_memo();
		$this->assertTrue( $this->user_can_reach( $this->editor_id, $slug ) );

		// Operator removes the rule.
		$this->purge_rule_for( $slug );

		// Request 3 — memo cleared → editor blocked again.
		$this->reset_memo();
		$this->assertFalse( $this->user_can_reach( $this->editor_id, $slug ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Composition helper — the two-layer permission check under test.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Returns true iff the given user reaches the given server's MCP endpoint
	 * through BOTH the transport-layer permission gate (this feature) AND the
	 * F015 tool-call gate (unchanged).
	 *
	 * Mirrors the composition in `HttpTransport::check_permission` (layer 1)
	 * + `AcrossAI_MCP_Access_Control::gate_mcp_tool_call` (layer 2).
	 *
	 * `$user_id === 0` represents the anonymous / logged-out case — WP's
	 * `wp_set_current_user( 0 )` clears the current user, so
	 * `current_user_can()` evaluates against no user (permissions all false).
	 */
	private function user_can_reach( int $user_id, string $server_slug ): bool {
		wp_set_current_user( $user_id );

		// Layer 1 — transport permission (fires the real filter chain).
		$request = new WP_REST_Request( 'POST', '/mcp/' . $server_slug );
		$ctx     = new HttpRequestContext( $request );
		/** @var string $cap */
		$cap = apply_filters(
			'mcp_adapter_default_transport_permission_user_capability',
			'read',
			$ctx
		);
		if ( ! current_user_can( $cap ) ) {
			return false;
		}

		// Layer 2 — F015 tool-call gate (unchanged; enforces the operator's rule).
		$fake_server = new class( $server_slug ) {
			/** @var string */
			private $slug;

			public function __construct( string $slug ) {
				$this->slug = $slug;
			}

			public function get_server_id(): string {
				return $this->slug;
			}
		};

		$result = AcrossAI_MCP_Access_Control::instance()->gate_mcp_tool_call(
			array( 'test' => true ),
			'test-tool',
			null,
			$fake_server
		);
		return ! is_wp_error( $result );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Fixture helpers (mirror TransportPermissionDefaultTest — separate class,
	// no cross-file trait to keep the two test surfaces independent).
	// ─────────────────────────────────────────────────────────────────────────

	private function user_id_for_role_key( string $role_key ): int {
		switch ( $role_key ) {
			case 'administrator':
				return $this->admin_id;
			case 'editor':
				return $this->editor_id;
			case 'author':
				return $this->author_id;
			case 'contributor':
				return $this->contributor_id;
			case 'subscriber':
				return $this->subscriber_id;
			case 'anonymous':
				return 0;
			default:
				$this->fail( "Unknown role key: {$role_key}" );
		}
	}

	private function make_server( string $slug ): string {
		$id = MCPServerQuery::instance()->add_item(
			array(
				'server_name'            => 'Matrix Test Server ' . $slug,
				'server_slug'            => $slug,
				'description'            => 'Fixture server for role-matrix tests.',
				'is_enabled'             => 1,
				'registered_from'        => 'database',
				'server_route_namespace' => 'mcp',
				'server_route'           => $slug,
				'server_version'         => 'v1.0.0',
			)
		);
		$this->assertNotFalse( $id, 'Fixture server insertion MUST succeed' );
		return $slug;
	}

	/**
	 * @param string[] $values
	 */
	private function set_rule_for( string $server_slug, string $ac_key, array $values ): void {
		if ( ! class_exists( RuleQuery::class ) ) {
			$this->markTestSkipped( 'wpb-access-control vendor missing' );
		}
		$rules = new RuleQuery( AcrossAI_MCP_Access_Control::TABLE_SLUG );
		$rules->set_rule( self::NAMESPACE_SLUG, $server_slug, $ac_key, $values );
	}

	private function purge_rule_for( string $server_slug ): void {
		if ( ! class_exists( RuleQuery::class ) ) {
			return;
		}
		$rules = new RuleQuery( AcrossAI_MCP_Access_Control::TABLE_SLUG );
		$rules->clear_rule( self::NAMESPACE_SLUG, $server_slug );
	}

	private function reset_memo(): void {
		$instance   = TransportPermissionDefault::instance();
		$reflection = new \ReflectionClass( $instance );
		$prop       = $reflection->getProperty( 'memo' );
		$prop->setAccessible( true );
		$prop->setValue( $instance, array() );
	}
}
