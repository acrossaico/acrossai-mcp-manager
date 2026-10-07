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
	private const MAIN_PATH               = self::PLUGIN_ROOT . '/includes/Main.php';
	private const PROFILES_FILTER         = 'acrossai_mcp_manager_connector_profiles';
	private const CONNECTORS_FILTER       = 'acrossai_mcp_manager_discovery_ai_connectors';
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

	/**
	 * The three consumer-side wirings, which nothing pinned before F095.
	 *
	 * This class already asserted that `ConnectionMethodRegistry` *declares*
	 * the instructions filter — and it passed throughout the window in which
	 * Step 10 rendered an empty state, because the registry fires both
	 * discovery filters and contributes nothing itself. Declaring a seam and
	 * filling it are separate facts, and only the first was covered.
	 *
	 * All three callbacks live in `Main.php` per A1. Asserting on source text
	 * rather than `has_filter()` keeps this suite WordPress-free, at the cost
	 * of not proving the callbacks resolve — `Main.php` being the single legal
	 * home for them is what makes the weaker check worth having.
	 *
	 * @dataProvider provideRequiredWirings
	 */
	public function test_main_wires_the_connector_lanes( string $filter, string $callback, string $why ): void {
		$src = $this->read( self::MAIN_PATH );

		$this->assertStringContainsString(
			$filter,
			$src,
			'F095 wiring broken — Main.php MUST register a callback on `' . $filter . '`. ' . $why
		);
		$this->assertStringContainsString(
			$callback,
			$src,
			'F095 wiring broken — Main.php MUST point `' . $filter . '` at `' . $callback . '`. ' . $why
		);
	}

	public static function provideRequiredWirings(): array {
		return array(
			'built-in profiles'  => array(
				self::PROFILES_FILTER,
				'register_builtin_profiles',
				'ConnectorProfileRegistry builds its list purely from this filter, so without '
					. 'it every connector surface is empty: the AI Connectors tab, its Settings '
					. 'checkboxes, and Quick Connect Step 10.',
			),
			'discovery DTOs'     => array(
				self::CONNECTORS_FILTER,
				'provide_ai_connectors',
				'Supplies the connector tabs on Step 10. Unwired, the step renders its empty state.',
			),
			'walkthrough HTML'   => array(
				self::FILTER_NAME,
				'provide_ai_connector_instructions',
				'Supplies the per-connector walkthrough panels (F082). Unwired, Step 10 silently '
					. 'degrades to the DCR-only notice with no error anywhere.',
			),
		);
	}

	/**
	 * No connector surface may advertise the add-on (FR-018).
	 *
	 * The empty states are where this leaks: before F095 an empty connector
	 * list WAS the unlicensed state, so the copy sold the add-on. Both strings
	 * survived the namespace sweep because they say "AcrossAI Pro" with a
	 * space, which the `acrossai-pro` / `AcrossAI_Pro` patterns do not match.
	 *
	 * @dataProvider provideConnectorSurfaces
	 */
	public function test_connector_surfaces_do_not_advertise_the_addon( string $path ): void {
		$src = $this->read( $path );

		foreach ( array( 'AcrossAI Pro', 'Browse add-ons', 'Install and activate an add-on' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$src,
				'FR-018 broken — ' . basename( $path ) . ' must not advertise the add-on. '
					. 'Connectors are free as of F095; found: "' . $forbidden . '".'
			);
		}
	}

	public static function provideConnectorSurfaces(): array {
		return array(
			'wizard step 10'     => array( self::STEP10_JSX_PATH ),
			'connectors tab'     => array( self::PLUGIN_ROOT . '/admin/Partials/ServerTabs/AIConnectorsTab.php' ),
			'wizard step titles' => array( self::PLUGIN_ROOT . '/src/js/quick-connect/StepLayout.jsx' ),
		);
	}

	private function read( string $path ): string {
		$this->assertFileExists( $path, 'F082 wiring test — expected file missing: ' . $path );
		return (string) file_get_contents( $path );
	}
}
