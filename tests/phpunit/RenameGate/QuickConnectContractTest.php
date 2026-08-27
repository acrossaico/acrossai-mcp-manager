<?php
/**
 * F080 — QuickConnect class-shape contract.
 *
 * Reflection-based lock-in for the machine identifiers the F080 rename
 * moved onto: the new `QuickConnectController` class exists at the new
 * FQN, the retired `QuickSetupController` FQN is gone, and the two
 * load-bearing private constants hold the new values (route prefix +
 * scratchpad transient key prefix). A rename regression would fail
 * loudly here even before REST-integration tests could catch it.
 *
 * Runs under the pure-PHP `rename-gate` suite. No WordPress bootstrap
 * required — reflection reads class metadata without instantiating.
 *
 * @package AcrossAI_MCP_Manager\Tests\PHPUnit\RenameGate
 * @since   0.3.2
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\RenameGate;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class QuickConnectContractTest extends TestCase {

	private const NEW_CONTROLLER_FQN = 'AcrossAI_MCP_Manager\\Includes\\REST\\QuickConnectController';
	private const OLD_CONTROLLER_FQN = 'AcrossAI_MCP_Manager\\Includes\\REST\\QuickSetupController';
	private const NEW_PAGE_FQN       = 'AcrossAI_MCP_Manager\\Admin\\Partials\\QuickConnect\\QuickConnectPage';
	private const OLD_PAGE_FQN       = 'AcrossAI_MCP_Manager\\Admin\\Partials\\QuickSetup\\QuickSetupPage';
	private const NEW_ADMIN_BAR_FQN  = 'AcrossAI_MCP_Manager\\Admin\\Partials\\QuickConnect\\AdminBarEntry';
	private const OLD_ADMIN_BAR_FQN  = 'AcrossAI_MCP_Manager\\Admin\\Partials\\QuickSetup\\AdminBarEntry';
	private const NEW_REDIRECT_FQN   = 'AcrossAI_MCP_Manager\\Admin\\Partials\\QuickConnect\\ActivationRedirect';
	private const OLD_REDIRECT_FQN   = 'AcrossAI_MCP_Manager\\Admin\\Partials\\QuickSetup\\ActivationRedirect';

	public function test_renamed_controller_class_exists_and_retired_fqn_is_gone(): void {
		$this->assertTrue(
			class_exists( self::NEW_CONTROLLER_FQN ),
			'F080 contract broken — QuickConnectController FQN MUST exist post-rename.'
		);
		$this->assertFalse(
			class_exists( self::OLD_CONTROLLER_FQN, false ),
			'F080 contract broken — retired QuickSetupController FQN MUST NOT exist. '
			. 'No backwards-compat shim was authorised.'
		);
	}

	public function test_renamed_page_class_exists_and_retired_fqn_is_gone(): void {
		$this->assertTrue(
			class_exists( self::NEW_PAGE_FQN ),
			'F080 contract broken — QuickConnectPage FQN MUST exist post-rename.'
		);
		$this->assertFalse(
			class_exists( self::OLD_PAGE_FQN, false ),
			'F080 contract broken — retired QuickSetupPage FQN MUST NOT exist.'
		);
	}

	public function test_renamed_admin_bar_class_exists_and_retired_fqn_is_gone(): void {
		$this->assertTrue(
			class_exists( self::NEW_ADMIN_BAR_FQN ),
			'F080 contract broken — QuickConnect\\AdminBarEntry FQN MUST exist post-rename.'
		);
		$this->assertFalse(
			class_exists( self::OLD_ADMIN_BAR_FQN, false ),
			'F080 contract broken — retired QuickSetup\\AdminBarEntry FQN MUST NOT exist.'
		);
	}

	public function test_renamed_activation_redirect_class_exists_and_retired_fqn_is_gone(): void {
		$this->assertTrue(
			class_exists( self::NEW_REDIRECT_FQN ),
			'F080 contract broken — QuickConnect\\ActivationRedirect FQN MUST exist post-rename.'
		);
		$this->assertFalse(
			class_exists( self::OLD_REDIRECT_FQN, false ),
			'F080 contract broken — retired QuickSetup\\ActivationRedirect FQN MUST NOT exist.'
		);
	}

	public function test_controller_route_prefix_constant_holds_new_value(): void {
		$rc    = new ReflectionClass( self::NEW_CONTROLLER_FQN );
		$const = $rc->getConstant( 'ROUTE_PREFIX' );

		$this->assertSame(
			'/quick-connect',
			$const,
			'F080 contract broken — QuickConnectController::ROUTE_PREFIX MUST equal "/quick-connect".'
		);
	}

	public function test_controller_scratchpad_prefix_constant_holds_new_value(): void {
		$rc    = new ReflectionClass( self::NEW_CONTROLLER_FQN );
		$const = $rc->getConstant( 'SCRATCHPAD_KEY_PREFIX' );

		$this->assertSame(
			'acrossai_mcp_manager_quick_connect_state_',
			$const,
			'F080 contract broken — QuickConnectController::SCRATCHPAD_KEY_PREFIX MUST equal '
			. '"acrossai_mcp_manager_quick_connect_state_" so old-name transients cannot be read.'
		);
	}

	public function test_admin_bar_node_id_constant_holds_new_value(): void {
		$rc    = new ReflectionClass( self::NEW_ADMIN_BAR_FQN );
		$const = $rc->getConstant( 'NODE_ID' );

		$this->assertSame(
			'acrossai-mcp-quick-connect',
			$const,
			'F080 contract broken — AdminBarEntry::NODE_ID MUST equal "acrossai-mcp-quick-connect".'
		);
	}

	public function test_activation_redirect_transient_constant_holds_new_value(): void {
		$rc    = new ReflectionClass( self::NEW_REDIRECT_FQN );
		$const = $rc->getConstant( 'REDIRECT_TRANSIENT' );

		$this->assertSame(
			'acrossai_mcp_manager_quick_connect_do_redirect',
			$const,
			'F080 contract broken — ActivationRedirect::REDIRECT_TRANSIENT MUST equal '
			. '"acrossai_mcp_manager_quick_connect_do_redirect" — the boot key set inside '
			. 'acrossai_mcp_manager_activate().'
		);
	}
}
