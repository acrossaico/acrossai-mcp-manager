<?php
/**
 * PHPUnit bootstrap for AcrossAI MCP Manager.
 *
 * Phase 4 (MCP Client Classes) deliberately bootstraps WITHOUT
 * WordPress per SC-003 — the MCPClients module is a pure service
 * layer (FR-008) and its tests prove that purity by running in a
 * WP-free environment.
 *
 * Tests for WordPress-dependent modules (Database/, Admin/Partials/,
 * etc.) will need a different bootstrap (`tests/bootstrap-wp.php`)
 * that loads wp-phpunit. That harness is a Phase 2 RT-4 follow-up,
 * not this phase's concern.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

// ABSPATH guard so any production file that has `defined('ABSPATH')||exit;`
// at its top still loads cleanly under test (it would otherwise exit).
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

// Composer autoloader (PSR-4 mapping: AcrossAI_MCP_Manager\Includes\* → includes/*).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ────────────────────────────────────────────────────────────────────────────
// F034 — minimal in-memory stubs for the WP core symbols the MCPClients
// module now calls from `AbstractMCPClient::get_all_registered_clients()`.
// These stubs preserve the SC-003 "no WP bootstrap" contract: plugin code
// under test still runs against a WP-free environment, but tests can register
// filter callbacks + observe _doing_it_wrong invocations without pulling in
// wp-phpunit. Real WP core provides equivalent behaviour post-bootstrap.
// ────────────────────────────────────────────────────────────────────────────

if ( ! isset( $GLOBALS['acrossai_test_filters'] ) ) {
	$GLOBALS['acrossai_test_filters'] = array();
}
if ( ! isset( $GLOBALS['acrossai_test_doing_it_wrong'] ) ) {
	$GLOBALS['acrossai_test_doing_it_wrong'] = array();
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $accepted_args );
		$GLOBALS['acrossai_test_filters'][ $hook ][ $priority ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['acrossai_test_filters'][ $hook ] ) ) {
			return $value;
		}
		$priorities = $GLOBALS['acrossai_test_filters'][ $hook ];
		ksort( $priorities, SORT_NUMERIC );
		foreach ( $priorities as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback( $value, ...$args );
			}
		}
		return $value;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( string $function_name, string $message, string $version ): void {
		$GLOBALS['acrossai_test_doing_it_wrong'][] = array(
			'function' => $function_name,
			'message'  => $message,
			'version'  => $version,
		);
	}
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

if ( ! function_exists( 'acrossai_test_reset_filters' ) ) {
	function acrossai_test_reset_filters(): void {
		$GLOBALS['acrossai_test_filters']         = array();
		$GLOBALS['acrossai_test_doing_it_wrong'] = array();
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

// ────────────────────────────────────────────────────────────────────────────
// Feature 075 — additional WP-free stubs so `Utilities\LocalEnvironment` can
// be exercised inside the mcpclients suite (SC-003 WP-free contract). The
// `acrossai_test_home_url` + `acrossai_test_env_type` globals are the knobs
// each test flips before calling `LocalEnvironment::needs_tls_bypass()`.
// ────────────────────────────────────────────────────────────────────────────

// Default to a NON-local host + production env so any test class that does
// not explicitly manage these globals (e.g. ConcreteClientsTest with its
// golden fixtures) sees LocalEnvironment::needs_tls_bypass() === false —
// which matches the shape those fixtures were captured against. Tests that
// exercise the local-dev branch (LocalEnvironmentTest) set these knobs
// explicitly per test and reset them in tearDown.
if ( ! isset( $GLOBALS['acrossai_test_home_url'] ) ) {
	$GLOBALS['acrossai_test_home_url'] = 'http://example.com';
}
if ( ! isset( $GLOBALS['acrossai_test_env_type'] ) ) {
	$GLOBALS['acrossai_test_env_type'] = 'production';
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '', ?string $scheme = null ): string {
		unset( $path, $scheme );
		return (string) $GLOBALS['acrossai_test_home_url'];
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type(): string {
		return (string) $GLOBALS['acrossai_test_env_type'];
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Thin wrapper around parse_url matching WordPress core's signature for
	 * the argument shapes LocalEnvironment uses (scheme + host).
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component PHP_URL_* constant.
	 *
	 * @return string|null
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		$parsed = parse_url( $url, $component );
		return false === $parsed ? null : $parsed;
	}
}
