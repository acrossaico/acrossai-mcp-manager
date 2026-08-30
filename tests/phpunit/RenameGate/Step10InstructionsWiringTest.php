<?php
/**
 * F082 — Step 10 walkthrough wiring canary.
 *
 * Locks in the three source-of-truth strings that carry the connector
 * walkthrough HTML from acrossai-pro to the Quick Connect Step 10 JSX:
 *
 *   1. The free plugin's Discovery registry exposes the filter name.
 *   2. The REST controller merges the map into the Quick Connect state
 *      payload under the expected key.
 *   3. Step 10 JSX reads that key AND substitutes the sentinel URL token.
 *
 * A refactor that quietly drops any of these strings would revert Step 10
 * to today's DCR-only fallback with no functional test coverage catching
 * it. This is a pure-PHP grep gate: no WordPress bootstrap needed.
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\RenameGate
 * @since   0.3.2
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\RenameGate;

use PHPUnit\Framework\TestCase;

final class Step10InstructionsWiringTest extends TestCase {

	private const PLUGIN_ROOT             = __DIR__ . '/../../..';
	private const REGISTRY_PATH           = self::PLUGIN_ROOT . '/public/Discovery/ConnectionMethodRegistry.php';
	private const REST_CONTROLLER_PATH    = self::PLUGIN_ROOT . '/includes/REST/QuickConnectController.php';
	private const STEP10_JSX_PATH         = self::PLUGIN_ROOT . '/src/js/quick-connect/steps/Step10_ConnectorsDetail.jsx';
	private const FILTER_NAME             = 'acrossai_mcp_manager_discovery_ai_connector_instructions';
	private const REST_KEY                = 'ai_connector_instructions';
	private const SENTINEL_TOKEN          = '__ACROSSAI_MCP_URL__';

	public function test_registry_declares_instructions_filter(): void {
		$this->assertStringContainsString(
			self::FILTER_NAME,
			$this->read( self::REGISTRY_PATH ),
			'F082 contract broken — ConnectionMethodRegistry MUST expose the '
			. '`acrossai_mcp_manager_discovery_ai_connector_instructions` filter.'
		);
	}

	public function test_rest_controller_merges_instructions_into_state(): void {
		$src = $this->read( self::REST_CONTROLLER_PATH );

		$this->assertStringContainsString(
			self::REST_KEY,
			$src,
			'F082 contract broken — QuickConnectController MUST expose '
			. '`ai_connector_instructions` on the state payload so Step 10 can consume it.'
		);
		$this->assertStringContainsString(
			'get_ai_connector_instructions',
			$src,
			'F082 contract broken — QuickConnectController MUST call '
			. 'ConnectionMethodRegistry::get_ai_connector_instructions() to source the map.'
		);
	}

	public function test_step10_jsx_reads_and_substitutes_the_new_lane(): void {
		$src = $this->read( self::STEP10_JSX_PATH );

		$this->assertStringContainsString(
			'ai_connector_instructions',
			$src,
			'F082 contract broken — Step10_ConnectorsDetail.jsx MUST read '
			. '`state.methods.ai_connector_instructions` to source per-connector walkthroughs.'
		);
		$this->assertStringContainsString(
			self::SENTINEL_TOKEN,
			$src,
			'F082 contract broken — Step10_ConnectorsDetail.jsx MUST substitute the '
			. '`__ACROSSAI_MCP_URL__` sentinel with the currently-selected server URL '
			. 'before dangerouslySetInnerHTML. Removing the substitution leaks the '
			. 'placeholder into the rendered walkthrough.'
		);
	}

	private function read( string $path ): string {
		$this->assertFileExists( $path, 'F082 wiring test — expected file missing: ' . $path );
		return (string) file_get_contents( $path );
	}
}
