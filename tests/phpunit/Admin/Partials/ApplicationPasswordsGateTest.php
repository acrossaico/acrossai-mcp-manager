<?php
/**
 * Gate tests for credential issuance (Feature 099, Phase 2A).
 *
 * Nothing guarded this route before F099. It is the plugin's only
 * credential-minting surface reachable from the admin UI, and F099 makes it
 * markedly more prominent, so each gate is asserted here rather than left to
 * review.
 *
 * The availability branches are driven through WordPress's own filters —
 * `wp_is_application_passwords_available` and
 * `wp_is_application_passwords_available_for_user` — rather than mocks, so a
 * misspelled function name in production code fails the test instead of
 * silently skipping the check. That failure mode is recorded as B77.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\Partials
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\Partials;

use AcrossAI_MCP_Manager\Admin\Partials\ApplicationPasswords;
use WP_UnitTestCase;

final class ApplicationPasswordsGateTest extends WP_UnitTestCase {

	/** @var int */
	private $admin_id;

	public function set_up(): void {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Core treats Application Passwords as supported only when
		// `is_ssl() || 'local' === wp_get_environment_type()`. The harness is
		// neither, so the baseline for every test here is "available", and the
		// two refusal tests override it explicitly. Without this the blocker
		// would refuse everything and the other assertions would never run.
		add_filter( 'wp_is_application_passwords_available', '__return_true', 1 );

		// The activity log table is created on demand by the plugin's own
		// bootstrap; ensure it exists before asserting what it does not contain.
		\AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Table::instance()->maybe_upgrade();
	}

	public function tear_down(): void {
		remove_filter( 'wp_is_application_passwords_available', '__return_true', 1 );
		parent::tear_down();
	}

	/**
	 * Build a request against the live route.
	 *
	 * @param array<string, mixed> $params Body params.
	 * @return \WP_REST_Request
	 */
	private function request( array $params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/generate-app-password' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * FR-014 / SC-C3 — site-wide availability refused with a stated cause.
	 */
	public function test_refuses_with_named_cause_when_unavailable_site_wide(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_false', 99 );

		$result = ApplicationPasswords::instance()->generate_app_password( $this->request() );

		remove_filter( 'wp_is_application_passwords_available', '__return_false', 99 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'app_passwords_disabled', $result->get_error_code() );
		$this->assertNotEmpty(
			$result->get_error_message(),
			'The operator must be told why, not handed an empty failure.'
		);
	}

	/**
	 * FR-014 / SC-C3 — per-user availability is a distinct branch with a
	 * distinct code, because the remedy differs.
	 */
	public function test_refuses_with_distinct_code_when_unavailable_for_user(): void {
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_false' );

		$result = ApplicationPasswords::instance()->generate_app_password( $this->request() );

		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_false' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'app_passwords_disabled_for_user', $result->get_error_code() );
	}

	/**
	 * FR-022 / SC-C12 — messages are hand-authored. A message echoing an
	 * exception, a database error or a filesystem path is a leak.
	 */
	public function test_refusal_messages_expose_no_internals(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_false', 99 );
		$result = ApplicationPasswords::instance()->generate_app_password( $this->request() );
		remove_filter( 'wp_is_application_passwords_available', '__return_false', 99 );

		$message = $result->get_error_message();

		$this->assertStringNotContainsString( ABSPATH, $message );
		$this->assertStringNotContainsString( 'wp_', $message, 'No internal symbol names in operator-facing copy.' );
		$this->assertStringNotContainsString( 'SQL', $message );
	}

	/**
	 * FR-015 / SC-C4 / D19 — with no Access Control package present the gate
	 * MUST fail open. This is the branch most likely to be written backwards,
	 * and getting it wrong locks every operator out of their own site.
	 */
	public function test_fails_open_when_access_control_is_absent(): void {
		$result = ApplicationPasswords::instance()->generate_app_password( $this->request( array( 'server_id' => 0 ) ) );

		$this->assertNotInstanceOf(
			\WP_Error::class,
			$result,
			'Absent Access Control must permit, never refuse.'
		);
	}

	/**
	 * FR-019 / SC-C7 / SC-C16 — the prefix is ALWAYS present, so the credential
	 * stays visible to the Tokens tab and /list-app-passwords, and the name
	 * carries provenance that outlives log retention.
	 */
	public function test_name_always_carries_the_prefix_and_the_date(): void {
		$result = ApplicationPasswords::instance()->generate_app_password(
			$this->request(
				array(
					'client' => 'claude-code',
					'name'   => 'Quick Edit - Claude Desktop',
				)
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$passwords = \WP_Application_Passwords::get_user_application_passwords( $this->admin_id );
		$this->assertNotEmpty( $passwords );

		$name = (string) $passwords[0]['name'];

		$this->assertStringContainsString( ApplicationPasswords::APP_NAME_PREFIX, $name );
		$this->assertStringContainsString( 'claude-code', $name );
		$this->assertStringContainsString( gmdate( 'Y-m-d' ), $name );
		$this->assertStringContainsString(
			'Quick Edit - Claude Desktop',
			$name,
			'A caller-supplied label is appended, never substituted for the prefix.'
		);
	}

	/**
	 * FR-018 / SC-C6 / B72 — the UUID comes back so a later revoke can target
	 * one credential instead of destroying every credential on the user.
	 */
	public function test_response_returns_the_credential_uuid(): void {
		$result = ApplicationPasswords::instance()->generate_app_password( $this->request() );

		$data = $result->get_data();

		$this->assertNotEmpty( $data['app_id'], 'The UUID is what makes precise revocation possible.' );
		$this->assertNotEmpty( $data['username'], 'B19 — a password without its username 401s silently.' );
	}

	/**
	 * FR-017 / SC-C5 — the body carries a plaintext credential, so no cache
	 * between here and the browser may retain it.
	 */
	public function test_response_is_marked_uncacheable(): void {
		$result  = ApplicationPasswords::instance()->generate_app_password( $this->request() );
		$headers = $result->get_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertStringContainsString( 'no-store', (string) $headers['Cache-Control'] );
	}

	/**
	 * SC-C11 / SC-C19 / SEC-T-002 — the issuance record carries the UUID and
	 * name only. Key-name redaction matches 24 patterns and a value outside
	 * them is stored as sent, so this is enforced at the call site and proven
	 * here: no row may contain any part of the issued credential.
	 */
	public function test_no_activity_row_contains_the_issued_credential(): void {
		global $wpdb;

		$result   = ApplicationPasswords::instance()->generate_app_password( $this->request() );
		$password = (string) $result->get_data()['password'];

		$this->assertNotEmpty( $password );

		$table = $wpdb->prefix . 'acrossai_mcp_activity_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test-only read of a known table.
		$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			foreach ( (array) $row as $column => $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$this->assertStringNotContainsString(
					$password,
					$value,
					"The credential leaked into activity_log.{$column}."
				);
			}
		}
	}

	/**
	 * FR-025 / SC-015 — generating again leaves the first credential working.
	 * Accumulation is the intended behaviour; silently revoking would break a
	 * connection the operator already has running.
	 */
	public function test_second_generation_leaves_the_first_credential_intact(): void {
		$first = ApplicationPasswords::instance()->generate_app_password( $this->request( array( 'client' => 'cursor' ) ) );
		$first_uuid = (string) $first->get_data()['app_id'];

		ApplicationPasswords::instance()->generate_app_password( $this->request( array( 'client' => 'zed' ) ) );

		$passwords = \WP_Application_Passwords::get_user_application_passwords( $this->admin_id );
		$uuids     = wp_list_pluck( $passwords, 'uuid' );

		$this->assertCount( 2, $passwords );
		$this->assertContains( $first_uuid, $uuids, 'The earlier credential must still exist and still work.' );
	}
}
