<?php
/**
 * Regression tests for the MCP Activity Log asset enqueue.
 *
 * Feature 096 shipped this screen in 0.4.4 and it never rendered on any
 * installation. Three defects stacked inside eleven lines:
 *
 *   1. `ACROSSAI_MCP_MANAGER_PLUGIN_DIR` was read for the plugin directory.
 *      That constant has never existed — the plugin defines
 *      `ACROSSAI_MCP_MANAGER_PLUGIN_PATH` — so the `defined()` guard always
 *      failed and the `__DIR__`-derived fallback always won.
 *   2. That fallback resolved to `admin/Partials/`, not the plugin root, so
 *      `build/js/activity-log.asset.php` was never found and the real
 *      dependency list was never read. The stylesheet was skipped for the
 *      same reason.
 *   3. The hardcoded dependency array that then took over named
 *      `wp-dataviews`, which WordPress does not register on any version.
 *      `WP_Scripts` refuses to output a script whose dependency is missing,
 *      so no tag was emitted at all.
 *
 * Nothing covered this enqueue, which is why all three survived review.
 *
 * @package AcrossAI_MCP_Manager\Tests\Admin\Partials
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\Partials;

use AcrossAI_MCP_Manager\Admin\Partials\ActivityLogPage;
use WP_UnitTestCase;

final class ActivityLogAssetEnqueueTest extends WP_UnitTestCase {

	private const HANDLE = 'acrossai-mcp-activity-log';

	/**
	 * Defect 1. The directory constant the enqueue reads must be the one the
	 * plugin actually defines.
	 */
	public function test_plugin_path_constant_is_defined(): void {
		$this->assertTrue(
			defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_PATH' ),
			'ACROSSAI_MCP_MANAGER_PLUGIN_PATH must exist; it is what the enqueue resolves paths from.'
		);
		$this->assertFalse(
			defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_DIR' ),
			'ACROSSAI_MCP_MANAGER_PLUGIN_DIR has never been defined. If this starts passing, '
			. 'someone added it instead of fixing the reference, and the two will drift.'
		);
	}

	/**
	 * Defect 2. The manifest must resolve against the plugin root.
	 */
	public function test_asset_manifest_resolves_to_a_real_file(): void {
		$manifest = ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/activity-log.asset.php';

		$this->assertFileExists(
			$manifest,
			'The built asset manifest must be reachable from the plugin root. '
			. 'When it is not, the screen enqueues nothing and renders blank.'
		);

		$asset = include $manifest;

		$this->assertIsArray( $asset );
		$this->assertArrayHasKey( 'dependencies', $asset );
		$this->assertArrayHasKey( 'version', $asset );
		$this->assertIsArray( $asset['dependencies'] );
	}

	/**
	 * The enqueue must take its dependencies and version from the manifest,
	 * never from a list maintained by hand.
	 */
	public function test_enqueue_uses_manifest_dependencies_and_version(): void {
		$asset = include ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/activity-log.asset.php';

		ActivityLogPage::instance()->enqueue_bundle();

		$scripts = wp_scripts();

		$this->assertTrue(
			wp_script_is( self::HANDLE, 'enqueued' ),
			'The activity log bundle must actually enqueue.'
		);

		$registered = $scripts->registered[ self::HANDLE ];

		$this->assertSame( $asset['dependencies'], $registered->deps );
		$this->assertSame( (string) $asset['version'], (string) $registered->ver );
	}

	/**
	 * Defect 3, and the generalised guard. WordPress silently drops a script
	 * whose dependency is not registered, so a single bad handle takes the
	 * whole screen down with no PHP error anywhere.
	 */
	public function test_every_declared_dependency_is_a_registered_handle(): void {
		ActivityLogPage::instance()->enqueue_bundle();

		$scripts = wp_scripts();

		$this->assertArrayHasKey( self::HANDLE, $scripts->registered );

		$unregistered = array();

		foreach ( $scripts->registered[ self::HANDLE ]->deps as $dep ) {
			if ( ! isset( $scripts->registered[ $dep ] ) ) {
				$unregistered[] = $dep;
			}
		}

		$this->assertSame(
			array(),
			$unregistered,
			'Every dependency must be a handle WordPress registers. Unregistered: '
			. implode( ', ', $unregistered )
		);
	}

	/**
	 * Named canary. `@wordpress/dataviews` is an npm package with no core
	 * script handle on any WordPress version; webpack bundles it into the
	 * output. Declaring it as a dependency can only ever break the screen.
	 */
	public function test_dataviews_is_never_declared_as_a_dependency(): void {
		ActivityLogPage::instance()->enqueue_bundle();

		$scripts = wp_scripts();

		$this->assertNotContains(
			'wp-dataviews',
			$scripts->registered[ self::HANDLE ]->deps,
			'wp-dataviews is not a core handle. DataViews is bundled, not externalised.'
		);
	}

	/**
	 * The stylesheet is optional. The activity-log entry imports no styles, so
	 * webpack emits no activity-log.css and the `file_exists()` guard correctly
	 * skips it — this was NOT a casualty of the path defect. The test is written
	 * conditionally so that it starts asserting the moment someone adds styles,
	 * rather than silently passing forever.
	 */
	public function test_stylesheet_is_enqueued_when_one_is_built(): void {
		$style = ACROSSAI_MCP_MANAGER_PLUGIN_PATH . 'build/js/activity-log.css';

		ActivityLogPage::instance()->enqueue_bundle();

		if ( ! file_exists( $style ) ) {
			$this->assertFalse(
				wp_style_is( self::HANDLE, 'enqueued' ),
				'No stylesheet is built for this entry, so none should be enqueued.'
			);
			return;
		}

		$this->assertTrue(
			wp_style_is( self::HANDLE, 'enqueued' ),
			'A built stylesheet exists, so it must be enqueued from the plugin root.'
		);
	}
}
