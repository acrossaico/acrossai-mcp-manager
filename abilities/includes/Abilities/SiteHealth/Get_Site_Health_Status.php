<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteHealth
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteHealth;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Site_Health;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the Site Health direct tests (and, optionally, the async tests via
 * their `async_direct_test` callbacks) and returns the per-test results plus
 * the same good/recommended/critical totals shown on Tools → Site Health.
 */
class Get_Site_Health_Status extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'site-health/get-site-health-status',
			'args' => array(
				'label'               => __( 'Get Site Health Status', 'acrossai-abilities-manager' ),
				'description'         => __( 'Run the WordPress Site Health direct tests and return the per-test results together with the good / recommended / critical counts shown on Tools → Site Health → Status. Optionally also runs async tests via their direct fallbacks. The description and actions fields carry WordPress core\'s own HTML by default; pass format: "text" for plain readable sentences with link destinations preserved.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-site-health',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'include_async' => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => __( 'Also run async tests via their direct fallback callbacks (loopback, dotorg communication, background updates, https status, page cache when available).', 'acrossai-abilities-manager' ),
						),
						'format'        => array(
							'type'        => 'string',
							'enum'        => array( 'html', 'text' ),
							'default'     => 'html',
							'description' => __( 'How to return the description and actions fields. "html" (the default) passes WordPress core\'s markup through unchanged. "text" strips the tags, drops the dashicon and screen-reader spans, keeps link destinations as "text (url)", decodes entities and collapses whitespace.', 'acrossai-abilities-manager' ),
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'totals'  => array(
							'type'       => 'object',
							'properties' => array(
								'good'        => array( 'type' => 'integer' ),
								'recommended' => array( 'type' => 'integer' ),
								'critical'    => array( 'type' => 'integer' ),
								'total'       => array( 'type' => 'integer' ),
							),
						),
						'tests'   => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'test'        => array( 'type' => 'string' ),
									'label'       => array( 'type' => 'string' ),
									'status'      => array( 'type' => 'string' ),
									'badge'       => array( 'type' => 'object' ),
									'description' => array( 'type' => 'string' ),
									'actions'     => array( 'type' => 'string' ),
								),
							),
						),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'read',
						'sub_group_label' => __( 'Read Site Health', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	/**
	 * Feature 128 — point callers at the readable form.
	 *
	 * @since  0.0.36
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'site-health/get-site-health-status',
				'reason' => __( 'Reading these results rather than rendering them? Pass format: "text". The description and actions fields carry WordPress core\'s own markup - dashicon spans that render as glyphs and read as nothing, and screen-reader spans that repeat the link text - none of which helps a caller decide what to do.', 'acrossai-abilities-manager' ),
				'saves'  => __( 'Removes the markup and the duplicated link labels; keeps every link destination', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Load the admin function libraries `WP_Site_Health` calls internally.
	 *
	 * Same gap as {@see Get_Site_Health_Info::load_admin_dependencies()}, and
	 * the same cause: requiring the class file makes the class exist, not the
	 * functions it calls. `WP_Site_Health`'s own tests reach for five admin
	 * libraries — `update.php` (the auto-update tests), `plugin.php`,
	 * `file.php` (`WP_Filesystem`), `misc.php` (`got_mod_rewrite`,
	 * `wp_check_php_version`) and `screen.php` (`get_current_screen`).
	 *
	 * This one had NOT yet been seen to fail, which is the reason it is worth
	 * fixing now rather than waiting: `get_plugins()` was already loaded by an
	 * unrelated plugin on the site where its sibling broke, so whether this
	 * ability works at all depends on which other plugins are active. It is
	 * the same defect, one plugin deactivation away from showing up.
	 *
	 * @since 0.0.45
	 * @return void
	 */
	private static function load_admin_dependencies(): void {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'got_mod_rewrite' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		if ( ! function_exists( 'get_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
	}

	public function execute( array $input = array() ): array {
		$as_text = isset( $input['format'] ) && 'text' === $input['format'];

		self::load_admin_dependencies();

		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		$include_async = (bool) ( $input['include_async'] ?? true );
		$site_health   = WP_Site_Health::get_instance();
		$tests         = WP_Site_Health::get_tests();
		$results       = array();

		foreach ( $tests['direct'] as $test_id => $test ) {
			$callback = $this->resolve_direct_callback( $site_health, $test );
			if ( null === $callback ) {
				continue;
			}
			$result = $this->run_test( $callback );
			if ( is_array( $result ) ) {
				$results[] = $this->normalize_result( $test_id, $test, $result, $as_text );
			}
		}

		if ( $include_async ) {
			foreach ( $tests['async'] as $test_id => $test ) {
				if ( empty( $test['async_direct_test'] ) || ! is_callable( $test['async_direct_test'] ) ) {
					continue;
				}
				$result = $this->run_test( $test['async_direct_test'] );
				if ( is_array( $result ) ) {
					$results[] = $this->normalize_result( $test_id, $test, $result, $as_text );
				}
			}
		}

		$totals = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
			'total'       => count( $results ),
		);
		foreach ( $results as $result ) {
			$status = $result['status'] ?? '';
			if ( isset( $totals[ $status ] ) ) {
				++$totals[ $status ];
			}
		}

		return array(
			'success' => true,
			'totals'  => $totals,
			'tests'   => $results,
		);
	}

	/**
	 * Resolve the callable for a direct test entry, mirroring WP_Site_Health::wp_cron_scheduled_check().
	 *
	 * @param WP_Site_Health $site_health Instance used for `get_test_*` method lookups.
	 * @param array          $test        Test definition from WP_Site_Health::get_tests()['direct'].
	 * @return callable|null
	 */
	private function resolve_direct_callback( WP_Site_Health $site_health, array $test ) {
		if ( isset( $test['test'] ) && is_string( $test['test'] ) ) {
			$method = sprintf( 'get_test_%s', $test['test'] );
			if ( method_exists( $site_health, $method ) && is_callable( array( $site_health, $method ) ) ) {
				return array( $site_health, $method );
			}
		}
		if ( isset( $test['test'] ) && is_callable( $test['test'] ) ) {
			return $test['test'];
		}
		return null;
	}

	/**
	 * Run a single test callback and apply the same `site_status_test_result` filter that core uses.
	 *
	 * @param callable $callback Test callback returning a result array.
	 * @return mixed
	 */
	private function run_test( $callback ) {
		try {
			$result = call_user_func( $callback );
		} catch ( \Throwable $e ) {
			return null;
		}
		return apply_filters( 'site_status_test_result', $result );
	}

	/**
	 * Ensure the test id and label are present and shrink the result to the documented fields.
	 *
	 * @param string $test_id Identifier from the tests array key.
	 * @param array  $test    Original test definition (provides a fallback label).
	 * @param array  $result  Raw test result.
	 * @param bool   $as_text Whether to flatten description/actions to plain text.
	 * @return array
	 */
	private function normalize_result( string $test_id, array $test, array $result, bool $as_text = false ): array {
		$description = isset( $result['description'] ) ? (string) $result['description'] : '';
		$actions     = isset( $result['actions'] ) ? (string) $result['actions'] : '';

		if ( $as_text ) {
			$description = self::to_text( $description );
			$actions     = self::to_text( $actions );
		}

		return array(
			'test'        => isset( $result['test'] ) ? (string) $result['test'] : $test_id,
			'label'       => isset( $result['label'] ) ? (string) $result['label'] : (string) ( $test['label'] ?? $test_id ),
			'status'      => isset( $result['status'] ) ? (string) $result['status'] : '',
			'badge'       => isset( $result['badge'] ) && is_array( $result['badge'] ) ? $result['badge'] : array(),
			'description' => $description,
			'actions'     => $actions,
		);
	}

	/**
	 * Flatten WordPress core's Site Health markup to something readable.
	 *
	 * Core writes these fields for a browser: `<p>` wrappers, dashicon `<span>`s that render as
	 * glyphs and read as nothing, and screen-reader-text spans that duplicate what the link already
	 * says. Passed through verbatim they cost tokens and tell an AI caller less than the plain
	 * sentence would.
	 *
	 * Link hrefs are kept as `text (url)` because they are frequently the actionable part -- the
	 * whole point of an "actions" field is the place it sends you.
	 *
	 * @since  0.0.36
	 * @param  string $html Core-supplied markup.
	 * @return string
	 */
	private static function to_text( string $html ): string {
		if ( '' === $html ) {
			return '';
		}

		// Drop screen-reader-only spans first: they exist to repeat link text for assistive
		// technology, so keeping them duplicates every link label.
		$html = preg_replace( '#<span[^>]*class="[^"]*screen-reader-text[^"]*"[^>]*>.*?</span>#is', ' ', $html ) ?? $html;

		// Keep the destination of a link, which is usually the actionable part.
		$html = preg_replace_callback(
			'#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			static function ( array $m ): string {
				$text = trim( wp_strip_all_tags( $m[3] ) );
				$url  = trim( $m[2] );

				if ( '' === $url ) {
					return $text;
				}

				return '' === $text ? $url : $text . ' (' . $url . ')';
			},
			$html
		) ?? $html;

		// Block-level boundaries become spaces so sentences do not run together.
		$html = preg_replace( '#</(p|div|li|h[1-6])>#i', ' ', $html ) ?? $html;
		$html = preg_replace( '#<br\s*/?>#i', ' ', $html ) ?? $html;

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;

		return trim( $text );
	}
}
