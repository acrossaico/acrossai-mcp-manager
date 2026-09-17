<?php
/**
 * Feature 090 — write-path validation on the Tools REST routes (T016, per SEC-006).
 *
 * Both new inputs are ENUM-CONSTRAINED and both are stored on a server row that
 * downstream layers trust: `server_type` decides whether the enablement gate lets
 * the server run at all, and `tools_default_policy` decides whether the operator's
 * curation is consulted. A forged value reaching either column is not a cosmetic
 * bug — it is a row whose behaviour nobody chose.
 *
 * The case that matters most here is the FORGED one: a well-formed, plausible slug
 * that simply is not registered. `sanitize_key()` passes it happily — sanitisation
 * and validation are different jobs, and only the registry lookup can reject it.
 *
 * Every rejection is paired with an assertion that the stored value is UNCHANGED.
 * A 400 that still wrote would be the worst outcome: the caller believes it failed
 * and the row disagrees.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\REST
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\REST;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as MCPServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\REST\ToolsController;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class ToolsControllerValidationTest extends WP_UnitTestCase {

	private const ROUTE = '/acrossai-mcp-manager/v1/servers/';

	private int $admin_id  = 0;
	private int $editor_id = 0;
	private int $server_id = 0;

	public function set_up(): void {
		parent::set_up();

		MCPServerTable::instance()->maybe_upgrade();
		DefaultServerSeeder::seed();

		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		global $wpdb;
		$row             = $wpdb->get_row( "SELECT id FROM {$wpdb->prefix}acrossai_mcp_servers LIMIT 1" ); // phpcs:ignore
		$this->server_id = $row ? (int) $row->id : 0;

		do_action( 'rest_api_init' );
		ToolsController::instance()->register_routes();
	}

	public function tear_down(): void {
		remove_all_filters( ServerTypes::FILTER );
		parent::tear_down();
	}

	// ------------------------------------------- server_type validation ----

	/**
	 * @dataProvider provideRejectedTypes
	 *
	 * @param string $server_type A value that must never reach the column.
	 * @param string $why         Shown when the assertion fails.
	 */
	public function test_post_tools_rejects_an_unregistered_server_type( string $server_type, string $why ): void {
		wp_set_current_user( $this->admin_id );
		$before = $this->stored( 'server_type' );

		$req = new WP_REST_Request( 'POST', self::ROUTE . $this->server_id . '/tools' );
		$req->set_param( 'tools', ToolPolicy::PROTOCOL_TOOLS );
		$req->set_param( 'server_type', $server_type );
		$res = rest_do_request( $req );

		$this->assertSame( 400, $res->get_status(), $why );
		$this->assertSame( 'acrossai_mcp_invalid_server_type', $this->code( $res ) );
		$this->assertSame( $before, $this->stored( 'server_type' ), 'A rejected write must leave the column untouched.' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideRejectedTypes(): array {
		return array(
			// THE case SEC-006 asks for: well-formed, plausible, unregistered.
			// sanitize_key() cannot tell this from a real slug.
			'forged but well-formed' => array( 'acrossai-pro', 'A plausible slug is still not a registered type.' ),
			'plain nonsense'         => array( 'not-a-type', 'Unregistered is unregistered.' ),
			'path-ish'               => array( '../../etc/passwd', 'Never trust the shape of the input.' ),
			'script-ish'             => array( '<script>x</script>', 'Sanitisation is not validation.' ),
		);
	}

	public function test_post_tools_accepts_a_registered_type(): void {
		// The negative tests above are only meaningful if the positive one works:
		// a validator that rejects everything would pass all of them.
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', self::ROUTE . $this->server_id . '/tools' );
		$req->set_param( 'tools', ToolPolicy::PROTOCOL_TOOLS );
		$req->set_param( 'server_type', ServerTypes::LEGACY );
		$res = rest_do_request( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( ServerTypes::LEGACY, $this->stored( 'server_type' ) );
	}

	public function test_a_filter_registered_type_is_accepted(): void {
		// The registry is the authority, not a hard-coded list — so a type a
		// companion plugin contributed must pass the same gate.
		add_filter(
			ServerTypes::FILTER,
			static function ( array $types ): array {
				$types['mycorp'] = array( 'label' => 'MyCorp' );
				return $types;
			}
		);

		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', self::ROUTE . $this->server_id . '/tools' );
		$req->set_param( 'tools', ToolPolicy::PROTOCOL_TOOLS );
		$req->set_param( 'server_type', 'mycorp' );
		$res = rest_do_request( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'mycorp', $this->stored( 'server_type' ) );
	}

	// ------------------------------------------------ policy validation ----

	/**
	 * @dataProvider provideRejectedPolicies
	 *
	 * @param string $policy A value outside the enum.
	 */
	public function test_policy_route_rejects_a_value_outside_the_enum( string $policy ): void {
		wp_set_current_user( $this->admin_id );
		$before = $this->stored( 'tools_default_policy' );

		$req = new WP_REST_Request( 'POST', self::ROUTE . $this->server_id . '/tools/policy' );
		$req->set_param( 'policy', $policy );
		$res = rest_do_request( $req );

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( $before, $this->stored( 'tools_default_policy' ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provideRejectedPolicies(): array {
		return array(
			// 'all' and 'none' were the vocabulary during development, before it
			// was aligned with abilities_default_policy. They must now be
			// rejected like any other unknown value — a stale client sending the
			// old words must fail loudly rather than write a value no branch of
			// the precedence chain matches.
			'the retired all'  => array( 'all' ),
			'the retired none' => array( 'none' ),
			'nonsense'         => array( 'whatever' ),
			'empty'            => array( '' ),
		);
	}

	/**
	 * @dataProvider providePolicies
	 *
	 * @param string $policy A value inside the enum.
	 */
	public function test_policy_route_accepts_every_enum_member( string $policy ): void {
		wp_set_current_user( $this->admin_id );

		$req = new WP_REST_Request( 'POST', self::ROUTE . $this->server_id . '/tools/policy' );
		$req->set_param( 'policy', $policy );
		$res = rest_do_request( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $policy, $this->stored( 'tools_default_policy' ) );
	}

	/**
	 * Driven from the constant, so adding a policy cannot leave this test behind
	 * (B48 — count- and list-based assertions drift from the source of truth).
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function providePolicies(): array {
		$cases = array();

		foreach ( ToolPolicy::POLICIES as $policy ) {
			$cases[ $policy ] = array( $policy );
		}

		return $cases;
	}

	// ------------------------------------------------------ permissions ----

	public function test_policy_route_refuses_an_editor(): void {
		wp_set_current_user( $this->editor_id );

		$req = new WP_REST_Request( 'POST', self::ROUTE . $this->server_id . '/tools/policy' );
		$req->set_param( 'policy', ToolPolicy::POLICY_HIDE );
		$res = rest_do_request( $req );

		$this->assertSame( 403, $res->get_status() );
	}

	public function test_policy_route_refuses_a_logged_out_caller(): void {
		wp_set_current_user( 0 );

		$req = new WP_REST_Request( 'POST', self::ROUTE . $this->server_id . '/tools/policy' );
		$req->set_param( 'policy', ToolPolicy::POLICY_HIDE );
		$res = rest_do_request( $req );

		$this->assertSame( 401, $res->get_status() );
	}

	// ---------------------------------------------------------- helpers ----

	private function stored( string $column ): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT `' . $column . '` FROM `' . $wpdb->prefix . 'acrossai_mcp_servers` WHERE id = %d',
				$this->server_id
			)
		);
	}

	/**
	 * @param \WP_REST_Response $res Dispatched response.
	 */
	private function code( $res ): string {
		$body = $res->get_data();

		return is_array( $body ) ? (string) ( $body['code'] ?? '' ) : (string) $body->get_error_code();
	}
}
