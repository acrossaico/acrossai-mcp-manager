<?php
/**
 * Issue #159 — per-OS config file paths for every MCP client.
 *
 * The Clients tab used to show a macOS path to everyone. A Windows user was
 * handed `~/Library/Application Support/...`, which does not exist on their
 * machine, with no qualifier saying it was macOS-only. `get_config_files()`
 * replaces that single string with a per-OS map.
 *
 * This suite pins all 16 clients so a regression on any one OS fails CI. It is
 * a sibling of `ConcreteClientMetadataTest` rather than an extension of it:
 * that provider has a fixed eight-column row shape, and widening it would mean
 * rewriting all sixteen rows to add one field.
 *
 * Runs WITHOUT bootstrapping WordPress, like its sibling.
 *
 * @package AcrossAI_MCP_Manager\Tests\MCPClients
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

namespace AcrossAI_MCP_Manager\Tests\MCPClients;

use AcrossAI_MCP_Manager\Includes\MCPClients\AbstractMCPClient;
use PHPUnit\Framework\TestCase;

final class PerOsConfigPathsTest extends TestCase {

	private const CLIENT_DIR = __DIR__ . '/../../../includes/MCPClients';

	private const NS = 'AcrossAI_MCP_Manager\\Includes\\MCPClients\\';

	/** The only OS keys the renderer knows how to label. */
	private const ALLOWED_OS = array( 'macos', 'windows', 'linux' );

	/**
	 * Clients that legitimately declare NO per-OS paths.
	 *
	 * Workspace-relative configs are identical on every OS, and the custom
	 * client's "path" is a descriptive placeholder rather than a location.
	 * Listed explicitly so that a NEW client cannot quietly join them — see
	 * the coverage test below, which fails for anything not in this list and
	 * not declaring paths.
	 */
	private const NO_PER_OS_PATHS = array(
		'ClineClient',
		'KiloCodeClient',
		'RooCodeClient',
		'CustomClient',
	);

	/**
	 * Expected map per client. Every path here is cited in the overriding
	 * method's docblock against the client's own upstream documentation.
	 *
	 * @return array<string, array{string, array<string, string>}>
	 */
	public static function providePerOsPaths(): array {
		$vscode = array(
			'macos'   => '~/Library/Application Support/Code/User/mcp.json',
			'windows' => '%APPDATA%\\Code\\User\\mcp.json',
			'linux'   => '~/.config/Code/User/mcp.json',
		);

		/** Home-relative clients: same path on macOS and Linux, %USERPROFILE% on Windows. */
		$home = static function ( string $unix, string $win ): array {
			return array(
				'macos'   => $unix,
				'windows' => $win,
				'linux'   => $unix,
			);
		};

		return array(
			// Ships for macOS and Windows only — no Linux build exists, so no
			// Linux key. Inventing one would send Linux users to a file the
			// app never reads.
			'ClaudeDesktopClient' => array(
				'ClaudeDesktopClient',
				array(
					'macos'   => '~/Library/Application Support/Claude/claude_desktop_config.json',
					'windows' => '%APPDATA%\\Claude\\claude_desktop_config.json',
				),
			),
			'VSCodeClient'        => array( 'VSCodeClient', $vscode ),
			'GitHubCopilotClient' => array( 'GitHubCopilotClient', $vscode ),
			// Zed uses ~/.config/zed on macOS AND Linux. Its Windows location
			// is not documented upstream, so the key is deliberately absent.
			'ZedClient'           => array(
				'ZedClient',
				array(
					'macos' => '~/.config/zed/settings.json',
					'linux' => '~/.config/zed/settings.json',
				),
			),
			'ClaudeCodeClient'    => array( 'ClaudeCodeClient', $home( '~/.claude.json', '%USERPROFILE%\\.claude.json' ) ),
			'CodexClient'         => array( 'CodexClient', $home( '~/.codex/config.toml', '%USERPROFILE%\\.codex\\config.toml' ) ),
			'CursorClient'        => array( 'CursorClient', $home( '~/.cursor/mcp.json', '%USERPROFILE%\\.cursor\\mcp.json' ) ),
			'GeminiClient'        => array( 'GeminiClient', $home( '~/.gemini/settings.json', '%USERPROFILE%\\.gemini\\settings.json' ) ),
			'WindsurfClient'      => array( 'WindsurfClient', $home( '~/.codeium/windsurf/mcp_config.json', '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json' ) ),
			'AmazonQClient'       => array( 'AmazonQClient', $home( '~/.aws/amazonq/mcp.json', '%USERPROFILE%\\.aws\\amazonq\\mcp.json' ) ),
			'OpenCodeClient'      => array( 'OpenCodeClient', $home( '~/.config/opencode/opencode.json', '%USERPROFILE%\\.config\\opencode\\opencode.json' ) ),
			'AntigravityClient'   => array( 'AntigravityClient', $home( '~/.gemini/config/mcp_config.json', '%USERPROFILE%\\.gemini\\config\\mcp_config.json' ) ),
		);
	}

	/**
	 * @dataProvider providePerOsPaths
	 * @param array<string, string> $expected
	 */
	public function test_client_declares_the_expected_per_os_paths( string $class, array $expected ): void {
		$client = $this->client( $class );

		$this->assertSame(
			$expected,
			$client->get_config_files(),
			$class . ' per-OS paths drifted. Every path is cited upstream in the method docblock — '
				. 'update the citation too, or the next reader cannot tell a fix from a typo.'
		);
	}

	/**
	 * @dataProvider providePerOsPaths
	 * @param array<string, string> $expected
	 */
	public function test_paths_are_well_formed_for_their_os( string $class, array $expected ): void {
		foreach ( $this->client( $class )->get_config_files() as $os => $path ) {
			$this->assertContains( $os, self::ALLOWED_OS, $class . ": unknown OS key '{$os}' — the renderer has no label for it." );
			$this->assertNotSame( '', $path, $class . ": empty path for {$os}." );

			if ( 'windows' === $os ) {
				$this->assertStringNotContainsString(
					'~',
					$path,
					$class . ': the Windows path uses `~`, which is unix shorthand a Windows user cannot paste into Explorer. That is the bug issue #159 exists to fix.'
				);
				$this->assertStringContainsString( '\\', $path, $class . ': the Windows path has no backslash separator.' );
				$this->assertStringNotContainsString( '/', $path, $class . ': the Windows path mixes in a forward slash.' );
			} else {
				$this->assertStringNotContainsString( '\\', $path, $class . ": the {$os} path contains a backslash." );
				$this->assertStringNotContainsString( '%', $path, $class . ": the {$os} path contains a Windows environment variable." );
			}
		}
	}

	/**
	 * The macOS value must still match the legacy single-string getter.
	 *
	 * `get_config_file()` stays the documented default and is still what the
	 * discovery payload's `config_file` key carries, so the two must not drift
	 * apart — a consumer reading the old key would otherwise get a path the UI
	 * never shows.
	 *
	 * @dataProvider providePerOsPaths
	 * @param array<string, string> $expected
	 */
	public function test_macos_value_matches_the_legacy_getter( string $class, array $expected ): void {
		$client = $this->client( $class );
		$files  = $client->get_config_files();

		if ( ! isset( $files['macos'] ) ) {
			$this->markTestSkipped( $class . ' declares no macOS path.' );
		}

		$this->assertSame(
			$client->get_config_file(),
			$files['macos'],
			$class . ': get_config_file() and the macos entry disagree. They describe the same file.'
		);
	}

	/**
	 * Every client is accounted for — no silent gaps, now or later.
	 *
	 * This is the test that makes the work self-extending: add a new client
	 * class and it fails until you either declare per-OS paths or state, in
	 * NO_PER_OS_PATHS, that it has none.
	 */
	public function test_every_client_either_declares_paths_or_is_listed_as_exempt(): void {
		$covered = array_keys( self::providePerOsPaths() );
		$missing = array();

		foreach ( glob( self::CLIENT_DIR . '/*Client.php' ) ?: array() as $file ) {
			$base = basename( $file, '.php' );
			if ( 'AbstractMCPClient' === $base ) {
				continue;
			}
			if ( in_array( $base, self::NO_PER_OS_PATHS, true ) ) {
				$this->assertSame(
					array(),
					$this->client( $base )->get_config_files(),
					$base . ' is listed as having no per-OS paths but declares some. Remove it from NO_PER_OS_PATHS.'
				);
				continue;
			}
			if ( ! in_array( $base, $covered, true ) ) {
				$missing[] = $base;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'These clients have neither per-OS paths nor an explicit exemption: ' . implode( ', ', $missing )
				. '. A Windows user on their tab sees a path that may not exist on their machine.'
		);
	}

	private function client( string $base ): AbstractMCPClient {
		$fqn = self::NS . $base;
		$this->assertTrue( class_exists( $fqn ), "Client class {$fqn} MUST exist." );
		/** @var AbstractMCPClient $instance */
		$instance = new $fqn();
		return $instance;
	}
}
