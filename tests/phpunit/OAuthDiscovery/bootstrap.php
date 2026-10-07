<?php
/**
 * PHPUnit bootstrap for the multi-server OAuth discovery suite (B18).
 *
 * These tests exercise the real controllers rather than asserting on source
 * text, so the bootstrap has to make three things observable that normally
 * terminate the request or touch the database:
 *
 *   - `wp_send_json()` throws {@see SentJson} carrying the payload + status,
 *     so a renderer's output can be asserted instead of exiting.
 *   - `add_rewrite_rule()` / `add_filter()` record into `$GLOBALS`, so rule
 *     shape AND registration ORDER can be asserted (order is load-bearing:
 *     the path-inserted rule must precede the bare one).
 *   - BerlinDB's Kern classes and mcp-manager's MCPServer\Query are stubbed
 *     via `eval()` before Composer can autoload the real ones, which need a
 *     live WordPress + `$wpdb`.
 *
 * `eval()` (rather than a top-level `class`) keeps every stub out of
 * Composer's classmap scan — a real class here ships a dangling autoloader
 * entry to production through the Jetpack Autoloader. Same reasoning as
 * tests/Unit/AccessControl/bootstrap.php; see B16.
 *
 * Tests drive the stubs through `$GLOBALS` and reset them in setUp().
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

// Silence vendor deprecation notices BEFORE the autoloader runs. Mockery 1.x
// emits them on PHP 8.4, and any output before a header() call turns every
// subsequent header() into a "headers already sent" warning — which the
// discovery controllers legitimately call.
error_reporting( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED );

// Keep our own output buffered. Note this does NOT fully suppress the
// "Cannot modify header information" warnings the discovery controllers
// produce under CLI: Mockery 1.x emits a PHP 8.4 deprecation while PHPUnit
// boots its own autoloader, which runs BEFORE this file and marks headers as
// sent. Those warnings are an artefact of the test SAPI, not a defect — the
// suite still reports OK. Do not "fix" them by removing header() calls from
// the controllers; the no-store header on the 404 branch is load-bearing.
ob_start();

require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'ACROSSAI_MCP_MANAGER_VERSION' ) ) {
	define( 'ACROSSAI_MCP_MANAGER_VERSION', '0.0.0-test' );
}

// Test helpers are require()d, not autoloaded: this plugin ships no
// autoload-dev mapping over tests/ on purpose (see B16 — the Jetpack
// Autoloader merges it even under --no-dev and fatals production sites).
require_once __DIR__ . '/ServerFixtureTrait.php';

// ---------------------------------------------------------------------------
// Observable terminator for wp_send_json().
// ---------------------------------------------------------------------------
if ( ! class_exists( 'AcrossAI_MCP_Manager_Test_SentJson' ) ) {
	// phpcs:ignore Squiz.PHP.Eval -- see file docblock (classmap hygiene).
	eval( 'class AcrossAI_MCP_Manager_Test_SentJson extends \RuntimeException {
		/** @var mixed */   public $payload;
		/** @var int|null */ public $status;
		public function __construct( $payload, $status = null ) {
			parent::__construct( "wp_send_json" );
			$this->payload = $payload;
			$this->status  = $status;
		}
	}' );
}

// ---------------------------------------------------------------------------
// BerlinDB Kern stubs. The real classes resolve $wpdb at definition time.
// ---------------------------------------------------------------------------
if ( ! class_exists( '\\BerlinDB\\Database\\Kern\\Row', false ) ) {
	// phpcs:ignore Squiz.PHP.Eval -- see file docblock (classmap hygiene).
	eval( 'namespace BerlinDB\\Database\\Kern { class Row {
		public function __construct( $item = array() ) {
			foreach ( (array) $item as $k => $v ) { $this->{$k} = $v; }
		}
	} }' );
}
if ( ! class_exists( '\\BerlinDB\\Database\\Kern\\Query', false ) ) {
	// phpcs:ignore Squiz.PHP.Eval -- see file docblock (classmap hygiene).
	eval( 'namespace BerlinDB\\Database\\Kern { class Query {
		public function __construct( $query = array() ) {}
		public function query( $args = array() ) { return array(); }
	} }' );
}

// ---------------------------------------------------------------------------
// acrossai-mcp-manager MCPServer\Query stub — the server table this plugin
// resolves resource URLs against. Driven by $GLOBALS["acrossai_test_servers"],
// a list of objects with server_route_namespace / server_route / id /
// is_enabled. Unset the global entirely to simulate mcp-manager being absent
// is NOT possible (the class is declared for the process), so the
// "dependency missing" branch is covered by a source-level assertion instead.
// ---------------------------------------------------------------------------
if ( ! class_exists( '\\AcrossAI_MCP_Manager\\Includes\\Database\\MCPServer\\Query', false ) ) {
	// phpcs:ignore Squiz.PHP.Eval -- see file docblock (classmap hygiene).
	eval( 'namespace AcrossAI_MCP_Manager\\Includes\\Database\\MCPServer { class Query {
		private static $instance = null;
		public static function instance() {
			if ( null === self::$instance ) { self::$instance = new self(); }
			return self::$instance;
		}
		public function query( $args = array() ) {
			$rows = isset( $GLOBALS["acrossai_test_servers"] ) ? $GLOBALS["acrossai_test_servers"] : array();
			if ( isset( $args["is_enabled"] ) ) {
				$filtered = array();
				foreach ( $rows as $r ) {
					if ( (int) $r->is_enabled === (int) $args["is_enabled"] ) { $filtered[] = $r; }
				}
				$rows = $filtered;
			}
			if ( isset( $args["number"] ) ) { $rows = array_slice( $rows, 0, (int) $args["number"] ); }
			return $rows;
		}
	} }' );
}

// ---------------------------------------------------------------------------
// WordPress function stubs. Pure helpers only — nothing here is something a
// test needs Brain Monkey to intercept, which keeps Patchwork out of the way.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		$base = $GLOBALS['acrossai_test_home_url'] ?? 'https://example.test';
		return rtrim( $base, '/' ) . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) );
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		return home_url( '/wp-json/' . ltrim( (string) $path, '/' ) );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		$sep = false === strpos( (string) $url, '?' ) ? '?' : '&';
		return $url . $sep . http_build_query( (array) $args );
	}
}
if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code ) {
		$GLOBALS['acrossai_test_status_header'] = (int) $code;
	}
}
if ( ! function_exists( 'wp_send_json' ) ) {
	function wp_send_json( $data, $status_code = null ) {
		throw new \AcrossAI_MCP_Manager_Test_SentJson( $data, $status_code );
	}
}
if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
		$GLOBALS['acrossai_test_rewrite_rules'][] = array(
			'regex' => $regex,
			'query' => $query,
			'after' => $after,
		);
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['acrossai_test_filters'][] = array(
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
		);
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$rest ) {
		if ( isset( $GLOBALS['acrossai_test_filter_returns'][ $hook ] ) ) {
			return $GLOBALS['acrossai_test_filter_returns'][ $hook ];
		}
		return $value;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return home_url( '/wp-admin/' . ltrim( (string) $path, '/' ) );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! class_exists( 'WP_Error', false ) ) {
	// phpcs:ignore Squiz.PHP.Eval -- see file docblock (classmap hygiene).
	eval( 'class WP_Error { public $code; public function __construct( $code = "" ) { $this->code = $code; } }' );
}

// ---------------------------------------------------------------------------
// Minimal WP_REST_Request / WP_REST_Response for BearerChallengeHeader.
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_REST_Request' ) ) {
	// phpcs:ignore Squiz.PHP.Eval -- see file docblock (classmap hygiene).
	eval( 'class WP_REST_Request {
		private $route;
		public function __construct( $route = "" ) { $this->route = $route; }
		public function get_route() { return $this->route; }
	}' );
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	// phpcs:ignore Squiz.PHP.Eval -- see file docblock (classmap hygiene).
	eval( 'class WP_REST_Response {
		private $status;
		public $sent_headers = array();
		public function __construct( $status = 200 ) { $this->status = $status; }
		public function get_status() { return $this->status; }
		public function header( $key, $value ) { $this->sent_headers[ $key ] = $value; }
	}' );
}
