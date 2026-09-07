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
