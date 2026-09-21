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
		// 0.3.6 — fixture forms, ported with the Toolset suites.
		//
		// Those tests came from a harness with no hook registry at all, where a
		// test stood in for a filter PROVIDER by seeding the answer directly.
		// Both forms are kept because they answer different questions: the
		// callback form decides per call, which is what per-ability visibility
		// needs; the value form is one fixed answer. Checked before the
		// registry so a test that seeds an answer gets it regardless of what
		// production code has attached.
		if ( isset( $GLOBALS['acrossai_test_filter_callbacks'][ $hook ] )
			&& is_callable( $GLOBALS['acrossai_test_filter_callbacks'][ $hook ] )
		) {
			return call_user_func( $GLOBALS['acrossai_test_filter_callbacks'][ $hook ], $value, ...$args );
		}

		if ( isset( $GLOBALS['acrossai_test_filter_values'][ $hook ] ) ) {
			return $GLOBALS['acrossai_test_filter_values'][ $hook ];
		}

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
		$GLOBALS['acrossai_test_filters']           = array();
		$GLOBALS['acrossai_test_doing_it_wrong']    = array();
		$GLOBALS['acrossai_test_filter_values']     = array();
		$GLOBALS['acrossai_test_filter_callbacks']  = array();
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

// ────────────────────────────────────────────────────────────────────────────
// 0.3.6 — the Abilities-API surface the Toolset dispatch and permission suites
// exercise, ported verbatim from the AcrossAI Abilities Manager bootstrap along
// with the tests themselves.
//
// A18 permits in-memory stubs here and signals a rethink past roughly ten. This
// lands at nine and is a deliberate stop rather than a slide: they were taken
// as a block, unedited, so the ported tests assert exactly what they asserted
// in the plugin they came from. Rewriting 47 tests against real WP would have
// been a rewrite, not a port, and a rewrite cannot be checked against the thing
// it replaced.
//
// `add_filter` / `apply_filters` are NOT among them. This bootstrap already has
// working ones, and the add-on's are no-ops — importing those would have made
// every filter this code attaches silently do nothing.
//
// The knobs: `acrossai_test_abilities` is the registry, `acrossai_test_capabilities`
// what the current user may do, `acrossai_test_current_user` who they are.
// ────────────────────────────────────────────────────────────────────────────

if ( ! isset( $GLOBALS['acrossai_test_abilities'] ) ) {
	$GLOBALS['acrossai_test_abilities'] = array();
}
if ( ! isset( $GLOBALS['acrossai_test_capabilities'] ) ) {
	$GLOBALS['acrossai_test_capabilities'] = array( 'read' );
}
if ( ! isset( $GLOBALS['acrossai_test_current_user'] ) ) {
	$GLOBALS['acrossai_test_current_user'] = 1;
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/** Stub: checks if value is a WP_Error instance. */
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/** Stub: no-op action dispatch. */
	function do_action( string $hook, mixed ...$args ): void {}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub: capability-driven, defaulting to false.
	 *
	 * Feature 099 made this fixture-driven so authorization branches can be
	 * tested. A test grants capabilities by populating
	 * $GLOBALS['acrossai_test_capabilities']. The global defaults to an empty
	 * array, so the historical "always false" behaviour is unchanged for every
	 * test that does not opt in.
	 *
	 * @param  string $capability Capability to check.
	 * @param  mixed  ...$args    Unused.
	 * @return bool True only when the capability was explicitly granted.
	 */
	function current_user_can( string $capability, mixed ...$args ): bool {
		return in_array( $capability, (array) ( $GLOBALS['acrossai_test_capabilities'] ?? array() ), true );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub sufficient for unit tests.
	 */
	class WP_Error {
		/** @var array<string,array<mixed>> */
		private array $errors = array();
		/** @var array<string,mixed> */
		private array $error_data = array();

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Optional data.
		 */
		public function __construct( string $code = '', string $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->errors[ $code ][]      = $message;
				$this->error_data[ $code ]    = $data;
			}
		}

		/** @return array<string,array<mixed>> */
		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}

		/** @param string $code */
		public function get_error_messages( string $code = '' ): array {
			return $code ? ( $this->errors[ $code ] ?? array() ) : array_merge( ...array_values( $this->errors ) );
		}

		/** @param string $code */
		public function get_error_data( string $code = '' ): mixed {
			return $code ? ( $this->error_data[ $code ] ?? null ) : reset( $this->error_data );
		}

		/** Returns the first error code as a string (singular form). */
		public function get_error_code(): string {
			$codes = $this->get_error_codes();
			return $codes[0] ?? '';
		}

		/**
		 * Returns the first error message as a string (singular form).
		 *
		 * Mirrors WP_Error::get_error_message() in core. Production code that
		 * unwraps a WP_Error into a response envelope calls this, so the stub
		 * needs it for those paths to be unit-testable.
		 *
		 * @param string $code Optional. Error code to retrieve the message for.
		 */
		public function get_error_message( string $code = '' ): string {
			$messages = $this->get_error_messages( $code );
			return isset( $messages[0] ) ? (string) $messages[0] : '';
		}

		/** @param string $message */
		public function add( string $code, string $message, mixed $data = '' ): void {
			$this->errors[ $code ][]   = $message;
			$this->error_data[ $code ] = $data;
		}
	}
}

if ( ! class_exists( 'WP_Ability' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Ability.
	 *
	 * Only the accessors this plugin's code actually reads. Constructed from
	 * the same shape `wp_register_ability()` takes, so a fixture reads like a
	 * real registration.
	 */
	class WP_Ability {

		/** @var string */
		private string $name;

		/** @var array<string, mixed> */
		private array $args;

		/**
		 * @param string               $name Ability name.
		 * @param array<string, mixed> $args Registration args.
		 */
		public function __construct( string $name, array $args = array() ) {
			$this->name = $name;
			$this->args = $args;
		}

		/** @return string */
		public function get_name(): string {
			return $this->name;
		}

		/** @return string */
		public function get_label(): string {
			return (string) ( $this->args['label'] ?? '' );
		}

		/** @return string */
		public function get_description(): string {
			return (string) ( $this->args['description'] ?? '' );
		}

		/** @return string */
		public function get_category(): string {
			return (string) ( $this->args['category'] ?? '' );
		}

		/** @return array<string, mixed> */
		public function get_input_schema(): array {
			return (array) ( $this->args['input_schema'] ?? array() );
		}

		/** @return array<string, mixed> */
		public function get_output_schema(): array {
			return (array) ( $this->args['output_schema'] ?? array() );
		}

		/** @return array<string, mixed> */
		public function get_meta(): array {
			return (array) ( $this->args['meta'] ?? array() );
		}

		/**
		 * @param  string $key           Meta key.
		 * @param  mixed  $default_value Fallback.
		 * @return mixed
		 */
		public function get_meta_item( string $key, $default_value = null ) {
			$meta = $this->get_meta();
			return array_key_exists( $key, $meta ) ? $meta[ $key ] : $default_value;
		}
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Stub: records a registration and returns the ability, mirroring core's
	 * `?WP_Ability` return so a caller can tell success from failure.
	 *
	 * @param  string               $name Ability name.
	 * @param  array<string, mixed> $args Ability args.
	 * @return WP_Ability|null
	 */
	function wp_register_ability( string $name, array $args ) {
		if ( ! empty( $GLOBALS['acrossai_test_register_fails'] ) ) {
			return null;
		}

		$ability                                    = new WP_Ability( $name, $args );
		$GLOBALS['acrossai_test_abilities'][ $name ] = $ability;

		return $ability;
	}
}

if ( ! function_exists( 'wp_has_ability' ) ) {
	/**
	 * Stub of wp_has_ability().
	 *
	 * @param  string $name Ability name.
	 * @return bool
	 */
	function wp_has_ability( string $name ): bool {
		return isset( $GLOBALS['acrossai_test_abilities'][ $name ] );
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	/**
	 * Stub of wp_get_ability().
	 *
	 * @param  string $name Ability name.
	 * @return mixed Ability object, or null when unregistered.
	 */
	function wp_get_ability( string $name ) {
		return $GLOBALS['acrossai_test_abilities'][ $name ] ?? null;
	}
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * Stub of wp_get_abilities().
	 *
	 * @return array<string, mixed> Slug => ability map.
	 */
	function wp_get_abilities(): array {
		return (array) ( $GLOBALS['acrossai_test_abilities'] ?? array() );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/** Stub: no-op hook registration for unit tests. */
	function add_action( string $hook, mixed $callback, int $priority = 10, int $args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/** Stub: returns false (no session in unit tests). */
	function is_user_logged_in(): bool {
		// Defaults to false when the global is unset, so every test written
		// before this became configurable behaves exactly as it did.
		return ! empty( $GLOBALS['acrossai_test_logged_in'] );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub: reads from the test-owned $__acrossai_test_options global if the
	 * caller has seeded it; otherwise returns $default. Lets test files
	 * simulate specific option values (e.g. active_plugins for Feature 061
	 * Overrides_Store tests) without a full WP install.
	 */
	function get_option( string $option, mixed $default = false ): mixed {
		global $__acrossai_test_options;
		if ( is_array( $__acrossai_test_options ) && array_key_exists( $option, $__acrossai_test_options ) ) {
			return $__acrossai_test_options[ $option ];
		}
		return $default;
	}
}
