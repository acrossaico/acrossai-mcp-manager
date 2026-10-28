<?php
/**
 * `setupPrompt` DTO field tests (Feature 099, T021).
 *
 * `ConnectionMethodRegistry::get_clients()` is the single producer both the
 * PHP admin renderer and the React wizard read (D43). These tests pin the two
 * properties that make that safe: the field is server-scoped, and it never
 * carries a credential.
 *
 * @package AcrossAI_MCP_Manager\Tests\Public\Discovery
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Public\Discovery;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry;
use WP_UnitTestCase;

final class SetupPromptDtoTest extends WP_UnitTestCase {

	/**
	 * A raw server row shaped as `MCPServerQuery::query()[0]->to_array()`.
	 *
	 * @return array<string, mixed>
	 */
	private function server_row(): array {
		return array(
			'id'                     => 1,
			'server_name'            => 'Test Server',
			'server_slug'            => 'test-server',
			'server_route_namespace' => 'mcp',
			'server_route'           => 'test-server',
		);
	}

	public function set_up(): void {
		parent::set_up();
		ConnectionMethodRegistry::instance()->flush_cache();
	}

	public function tear_down(): void {
		ConnectionMethodRegistry::instance()->flush_cache();
		parent::tear_down();
	}

	/**
	 * FR-012 — server-scoped, exactly like `config`. A consumer enumerating
	 * clients without a server must not receive a prompt naming no server.
	 */
	public function test_setup_prompt_is_absent_without_a_server(): void {
		$clients = ConnectionMethodRegistry::instance()->get_clients( null );

		$this->assertNotEmpty( $clients );

		foreach ( $clients as $dto ) {
			$this->assertArrayNotHasKey( 'setupPrompt', $dto, "{$dto['slug']} leaked a server-scoped field." );
			$this->assertArrayNotHasKey( 'config', $dto, 'Precondition: config is server-scoped too.' );
		}
	}

	/**
	 * FR-012 — present for every client once a server is resolved. A field
	 * that appears for only some clients produces a screen where the one-click
	 * path silently vanishes for certain selections.
	 */
	public function test_setup_prompt_is_present_for_every_client_with_a_server(): void {
		$clients = ConnectionMethodRegistry::instance()->get_clients( $this->server_row() );

		$this->assertNotEmpty( $clients );

		foreach ( $clients as $dto ) {
			$this->assertArrayHasKey( 'setupPrompt', $dto, "{$dto['slug']} has no setup prompt." );
			$this->assertNotSame( '', trim( (string) $dto['setupPrompt'] ) );
		}
	}

	/**
	 * FR-008 / SC-C1 — the strongest single guarantee in this feature. The DTO
	 * is memoised per request and read by companion plugins; if it could carry
	 * a credential, every one of those paths would become a leak. It carries
	 * the placeholder instead.
	 */
	public function test_setup_prompt_never_carries_a_credential(): void {
		$clients = ConnectionMethodRegistry::instance()->get_clients( $this->server_row() );

		foreach ( $clients as $dto ) {
			$this->assertStringContainsString(
				AbstractMCPClient::EMPTY_TOKEN_PLACEHOLDER,
				(string) $dto['setupPrompt'],
				"{$dto['slug']} should carry the placeholder, not a credential."
			);
		}
	}

	/**
	 * D43 — the prompt embeds the same `config` string the DTO already
	 * publishes. If these ever diverge, the two surfaces would disagree with
	 * each other while both claiming to come from one producer.
	 */
	public function test_prompt_embeds_the_dto_config_verbatim(): void {
		$clients = ConnectionMethodRegistry::instance()->get_clients( $this->server_row() );

		foreach ( $clients as $dto ) {
			$this->assertStringContainsString(
				(string) $dto['config'],
				(string) $dto['setupPrompt'],
				"{$dto['slug']}: the prompt and the manual block must be the same bytes."
			);
		}
	}

	/**
	 * FR-013 / SC-006 / D64 — the DTO carries per-OS paths for every client
	 * that declares them, which is what the wizard was ignoring.
	 *
	 * Both surfaces now read `meta.config_files`. This asserts the data is
	 * there to read: for Claude Desktop, VS Code and GitHub Copilot the single
	 * legacy `config_file` is the macOS path, so a surface reading only that
	 * tells Windows and Linux operators to edit a directory they do not have.
	 */
	public function test_dto_carries_per_os_paths_the_wizard_must_read(): void {
		$clients = ConnectionMethodRegistry::instance()->get_clients( $this->server_row() );

		$by_slug = array();

		foreach ( $clients as $dto ) {
			$by_slug[ $dto['slug'] ] = $dto;
		}

		foreach ( array( 'claude-desktop', 'vscode', 'github-copilot' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $by_slug );

			$files = $by_slug[ $slug ]['meta']['config_files'];

			$this->assertNotEmpty( $files, "{$slug} declares no per-OS paths." );
			$this->assertArrayHasKey( 'windows', $files, "{$slug} has no Windows path in the DTO." );

			$single = (string) $by_slug[ $slug ]['meta']['config_file'];

			$this->assertNotSame(
				$files['windows'],
				$single,
				"{$slug}: the legacy single path happens to equal the Windows one, so this "
				. 'test would pass even if the wizard still read only config_file. Pick a '
				. 'different client to pin the regression.'
			);
		}
	}

	/**
	 * D43 — additive. A consumer that predates this field must be unaffected,
	 * so no existing key may have been renamed or removed.
	 */
	public function test_existing_dto_keys_are_unchanged(): void {
		$clients = ConnectionMethodRegistry::instance()->get_clients( $this->server_row() );

		foreach ( $clients as $dto ) {
			foreach ( array( 'category', 'slug', 'name', 'instructions', 'restartStep', 'meta', 'config' ) as $key ) {
				$this->assertArrayHasKey( $key, $dto, "Pre-existing DTO key '{$key}' disappeared." );
			}
			$this->assertArrayHasKey( 'config_files', $dto['meta'] );
			$this->assertArrayHasKey( 'top_level_key', $dto['meta'] );
		}
	}
}
