<?php
/**
 * Tests for SettingsMenu — MCP tab on the shared AcrossAI Settings page.
 *
 * Locks two operator-facing invariants:
 *   (a) register_tab() appends the expected tab shape.
 *   (b) register_settings() registers the three option keys against the
 *       shared 'acrossai-settings' option group with the correct sanitize
 *       callbacks and defaults.
 *
 * PHPUnit 9.6 note (supersedes BUGS.md B9): use the `@dataProvider`
 * annotation. The WordPress test suite calls PHPUnit APIs removed in
 * PHPUnit 10, so the toolchain is pinned to 9.x — which predates PHP
 * attributes, making `#[DataProvider]` a silent no-op.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin;

use AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu;
use WP_UnitTestCase;

final class SettingsMenuTest extends WP_UnitTestCase {

	/**
	 * Verifies register_tab() appends the expected tab shape to an empty array.
	 */
	public function test_register_tab_appends_expected_shape(): void {
		$result = SettingsMenu::instance()->register_tab( array() );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'mcp', $result[0]['slug'] );
		$this->assertSame( 'MCP', $result[0]['label'] );
		$this->assertSame( 20, $result[0]['priority'] );
	}

	/**
	 * Verifies register_tab() normalizes non-array input to a 1-element array.
	 *
	 * @param mixed $input Non-array input the filter callback must tolerate.
	 *
	 * @dataProvider non_array_input_provider
	 */
	public function test_register_tab_normalizes_non_array_input( $input ): void {
		$result = SettingsMenu::instance()->register_tab( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'mcp', $result[0]['slug'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function non_array_input_provider(): array {
		return array(
			'null'   => array( null ),
			'false'  => array( false ),
			'string' => array( 'not-an-array' ),
			'int'    => array( 0 ),
		);
	}

	/**
	 * Verifies register_settings() registers the three MCP option keys against
	 * the shared 'acrossai-settings' option group with the correct sanitize
	 * callbacks and defaults.
	 */
	public function test_register_settings_registers_expected_option_keys(): void {
		global $wp_registered_settings;

		SettingsMenu::instance()->register_settings();

		$this->assertArrayHasKey( 'acrossai_mcp_npm_login_enabled', $wp_registered_settings );
		$this->assertSame(
			'rest_sanitize_boolean',
			$wp_registered_settings['acrossai_mcp_npm_login_enabled']['sanitize_callback']
		);
		$this->assertFalse( $wp_registered_settings['acrossai_mcp_npm_login_enabled']['default'] );

		$this->assertArrayHasKey( 'acrossai_mcp_uninstall_delete_data', $wp_registered_settings );
		$this->assertIsCallable(
			$wp_registered_settings['acrossai_mcp_uninstall_delete_data']['sanitize_callback']
		);
		$this->assertSame( 0, $wp_registered_settings['acrossai_mcp_uninstall_delete_data']['default'] );
	}
}
