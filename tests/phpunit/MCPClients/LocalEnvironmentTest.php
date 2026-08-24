<?php
/**
 * Utilities\LocalEnvironment — WP-free tests for the local-dev TLS-bypass
 * detection helper introduced by Feature 075.
 *
 * Lives inside the `mcpclients` suite because the MCP client `env`
 * generation pipeline now depends on `LocalEnvironment::needs_tls_bypass()`
 * — keeps the SC-003 WP-free bootstrap contract intact by flipping the
 * `acrossai_test_home_url` + `acrossai_test_env_type` globals from
 * `tests/bootstrap.php` instead of booting WP.
 *
 * @package AcrossAI_MCP_Manager\Tests\MCPClients
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\MCPClients;

use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;
use PHPUnit\Framework\TestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

final class LocalEnvironmentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Reset knobs + filter registry before every test. Bootstrap defaults
		// point at a non-local host on a production env-type, so any test
		// that forgets to set the site explicitly gets a false-returning
		// baseline — never a spurious true.
		acrossai_test_reset_filters();
		$GLOBALS['acrossai_test_home_url'] = 'http://example.com';
		$GLOBALS['acrossai_test_env_type'] = 'production';
	}

	protected function tearDown(): void {
		acrossai_test_reset_filters();
		// Restore bootstrap-safe defaults so any test class running AFTER
		// this one (e.g. ConcreteClientsTest with its golden fixtures) sees
		// LocalEnvironment::needs_tls_bypass() === false again, regardless
		// of whichever knob the last LocalEnvironmentTest method set.
		$GLOBALS['acrossai_test_home_url'] = 'http://example.com';
		$GLOBALS['acrossai_test_env_type'] = 'production';
		parent::tearDown();
	}

	private function set_site( string $home_url, string $env_type = 'production' ): void {
		$GLOBALS['acrossai_test_home_url'] = $home_url;
		$GLOBALS['acrossai_test_env_type'] = $env_type;
	}

	public function test_env_type_local_over_https_triggers_bypass(): void {
		$this->set_site( 'https://foo.example', 'local' );
		$this->assertTrue( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_env_type_development_over_https_triggers_bypass(): void {
		$this->set_site( 'https://foo.example', 'development' );
		$this->assertTrue( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_env_type_production_with_local_host_suffix_triggers_bypass(): void {
		// Host-suffix rule fires independently of env-type when the domain
		// looks like a Local by Flywheel / MAMP install.
		$this->set_site( 'https://example.local', 'production' );
		$this->assertTrue( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_env_type_staging_with_local_host_suffix_triggers_bypass(): void {
		// Clarification Q1 (2026-08-24): staging env-type alone does NOT
		// trigger — but a staging site on .local DOES via the host suffix.
		$this->set_site( 'https://staging-site.local', 'staging' );
		$this->assertTrue( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_env_type_staging_with_public_host_does_not_trigger(): void {
		// Clarification Q1 (2026-08-24): staging alone must NOT trigger.
		$this->set_site( 'https://staging.example.com', 'staging' );
		$this->assertFalse( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_env_type_production_with_public_host_does_not_trigger(): void {
		$this->set_site( 'https://example.com', 'production' );
		$this->assertFalse( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_local_env_over_http_triggers_bypass(): void {
		// Clarification Q4 (2026-08-24): the earlier HTTPS-scheme gate
		// (previously FR-009) was dropped. The rule is now "site looks
		// local → show the affordance", regardless of scheme. On HTTP the
		// injected NODE_TLS_REJECT_UNAUTHORIZED is a harmless no-op —
		// Node's HTTP client never runs TLS validation — but the warning
		// notice + doc link still guide the operator toward Automattic's
		// troubleshooting page for non-TLS local-dev issues.
		$this->set_site( 'http://foo.local', 'local' );
		$this->assertTrue( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_public_host_over_http_does_not_trigger(): void {
		// Sanity: dropping the HTTPS gate MUST NOT enable the flag on live
		// HTTP sites. A public hostname with no local-suffix match + env
		// type != local/development still returns false.
		$this->set_site( 'http://example.com', 'production' );
		$this->assertFalse( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_localhost_over_https_triggers_bypass(): void {
		$this->set_site( 'https://localhost:8443', 'production' );
		$this->assertTrue( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_ipv4_loopback_over_https_triggers_bypass(): void {
		$this->set_site( 'https://127.0.0.1', 'production' );
		$this->assertTrue( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_test_host_suffix_over_https_triggers_bypass(): void {
		$this->set_site( 'https://mysite.test', 'production' );
		$this->assertTrue( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_custom_suffix_added_via_filter_triggers_bypass(): void {
		// FR-010: ops teams can extend the suffix list.
		add_filter(
			'acrossai_mcp_local_hostname_suffixes',
			static function ( array $suffixes ): array {
				$suffixes[] = '.docker';
				return $suffixes;
			}
		);
		$this->set_site( 'https://myapp.docker', 'production' );
		$this->assertTrue( LocalEnvironment::needs_tls_bypass() );
	}

	public function test_filter_returning_non_array_falls_back_to_defaults(): void {
		// SC-005: defensive against a buggy filter callback returning
		// false / string / object. Must NOT fatal; falls back to the
		// default suffix list so .local etc still work.
		add_filter(
			'acrossai_mcp_local_hostname_suffixes',
			static fn (): bool => false
		);
		$this->set_site( 'https://example.local', 'production' );
		$this->assertTrue(
			LocalEnvironment::needs_tls_bypass(),
			'Bad filter callback must not disable detection — defaults still apply.'
		);
	}

	public function test_troubleshooting_doc_url_is_stable_public_string(): void {
		// Single source of truth reached by both the admin notice and the
		// wizard's wp_localize_script payload — locking the value prevents
		// a rename from silently drifting one surface.
		$this->assertSame(
			'https://github.com/Automattic/mcp-wordpress-remote/blob/trunk/Docs/troubleshooting.md',
			LocalEnvironment::troubleshooting_doc_url()
		);
	}
}
