<?php
/**
 * Shared MCP-server fixtures for the OAuth discovery suite.
 *
 * The default fixture mirrors the site the B18 field report came from: a
 * plugin-seeded default server plus two operator-created database servers,
 * all enabled, plus one on a non-`mcp` route namespace (which the old
 * `strpos( $route, '/mcp' )` challenge test missed entirely).
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

trait ServerFixtureTrait {

	/**
	 * Build one server row in the shape MCPServer\Query returns.
	 *
	 * @param int    $id         Row id.
	 * @param string $namespace  REST namespace.
	 * @param string $route      REST route.
	 * @param int    $is_enabled 1 or 0.
	 * @return object
	 */
	private function server( int $id, string $namespace, string $route, int $is_enabled = 1 ): object {
		return (object) array(
			'id'                     => $id,
			'server_route_namespace' => $namespace,
			'server_route'           => $route,
			'is_enabled'             => $is_enabled,
		);
	}

	/**
	 * Replace the server table contents.
	 *
	 * @param array<int, object> $servers Rows in insertion order.
	 * @return void
	 */
	private function set_servers( array $servers ): void {
		$GLOBALS['acrossai_test_servers'] = $servers;
	}

	/**
	 * Reset every global the bootstrap stubs write to.
	 *
	 * @return void
	 */
	private function reset_fixtures(): void {
		$GLOBALS['acrossai_test_home_url']       = 'https://example.test';
		$GLOBALS['acrossai_test_rewrite_rules']  = array();
		$GLOBALS['acrossai_test_filters']        = array();
		$GLOBALS['acrossai_test_filter_returns'] = array();
		unset( $GLOBALS['acrossai_test_status_header'] );
		unset( $_GET['resource'] );

		$this->set_servers(
			array(
				$this->server( 1, 'mcp', 'mcp-adapter-default-server' ),
				$this->server( 2, 'mcp', 'testing-servers' ),
				$this->server( 3, 'mcp', 'third-server' ),
				$this->server( 4, 'custom-ns', 'vendor-server' ),
			)
		);
	}
}
