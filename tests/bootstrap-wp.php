<?php
/**
 * PHPUnit bootstrap — WP-PHPUnit harness (Phase 5.0 per D11).
 *
 * Loads the WordPress test environment so OAuth tests can use
 * wp_set_current_user, nonces, sessions, wp_safe_redirect, and
 * register_rest_route. Parallel to tests/bootstrap.php (Phase 4's
 * WP-free harness) — both coexist via separate testsuites in
 * phpunit.xml.dist.
 *
 * Environment:
 *   WP_TESTS_DIR  — path to wp-phpunit / WP develop test suite
 *                   (e.g. /tmp/wordpress-tests-lib). bin/install-wp-tests.sh
 *                   provisions this.
 *   WP_TESTS_PHPUNIT_POLYFILLS_PATH — path to yoast/phpunit-polyfills'
 *                   phpunitpolyfills-autoload.php. Cannot live in this
 *                   project's composer.json (its phpunit ^13 pin conflicts
 *                   with every polyfills release), so it is resolved from a
 *                   `composer global require yoast/phpunit-polyfills`
 *                   install by default.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $wp_tests_dir || '' === $wp_tests_dir ) {
	$wp_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$acrossai_polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
	if ( false === $acrossai_polyfills || '' === $acrossai_polyfills ) {
		$acrossai_polyfills = ( getenv( 'HOME' ) ?: '~' )
			. '/.composer/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
	}
	if ( file_exists( $acrossai_polyfills ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $acrossai_polyfills );
	}
}

if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"WP-PHPUnit harness not found at {$wp_tests_dir}.\n"
		. "Run bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] first.\n"
	);
	exit( 1 );
}

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$plugin = dirname( __DIR__ ) . '/acrossai-mcp-manager.php';
		if ( file_exists( $plugin ) ) {
			require_once $plugin;
		} else {
			require_once dirname( __DIR__ ) . '/vendor/autoload.php';
		}
	}
);

require $wp_tests_dir . '/includes/bootstrap.php';

// Create the plugin's BerlinDB tables. Production runs this on the activation
// hook (never fired for test-loaded plugins) and re-reconciles on admin_init@3
// (never fired in the test bootstrap). Several Database-suite tests document
// the expectation that activation has run by the time they execute — this call
// is what makes that true. DDL is not rolled back by WP_UnitTestCase's
// per-test transactions, so the tables persist for the whole run.
\AcrossAI_MCP_Manager\Includes\Activator::activate();

/**
 * Register an ability from a test, bypassing the hook-context requirement.
 *
 * WordPress 6.9 made `acrossai_test_register_ability()` refuse to run outside the
 * `wp_abilities_api_init` action — it emits `_doing_it_wrong` and returns null.
 * Every ability registration in this test estate predates that tightening and
 * calls the function directly, which is why `wp_get_ability()` then returns
 * null and 24 abilities-suite tests error or fail.
 *
 * Re-firing `wp_abilities_api_init` from a test is not a safe fix: the action
 * has already run in this process, so firing it again re-invokes every other
 * listener and core then reports duplicate registrations.
 *
 * `WP_Abilities_Registry::register()` is public and is exactly what
 * `acrossai_test_register_ability()` calls once its hook check passes, so tests go
 * straight to it. Same registration, same validation, no hook gymnastics.
 *
 * @param string               $name Ability name, e.g. 'my-plugin/do-thing'.
 * @param array<string, mixed> $args Ability registration args.
 * @return \WP_Ability|null The registered ability, or null on failure.
 */
function acrossai_test_register_ability( string $name, array $args ) {
	$registry = \WP_Abilities_Registry::get_instance();
	if ( null === $registry ) {
		return null;
	}
	return $registry->register( $name, $args );
}
