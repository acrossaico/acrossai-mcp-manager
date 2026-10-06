<?php
/**
 * Site Health check for a PHP default timezone changed out from under us.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\Support
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reports when another plugin has moved PHP's default timezone off UTC.
 *
 * WordPress sets `date_default_timezone_set( 'UTC' )` during bootstrap and
 * expects it to stay there; `wp_timezone()` and the `gmdate()`/`get_date_from_gmt()`
 * pair are how a site's local time is meant to be expressed. A plugin that
 * calls `date_default_timezone_set()` and does not restore it changes the
 * clock for every line of code that runs afterwards in that request, including
 * other plugins'.
 *
 * {@see DatetimeColumn} makes this plugin's own datetime storage immune. The
 * check exists because the condition is otherwise invisible: it produces no
 * error, and its symptoms elsewhere on the site (dates off by the UTC offset,
 * expiries computed wrong) look like unrelated bugs. This is a diagnostic, not
 * a fix — correctness does not depend on it.
 *
 * Registered as a `direct` test, same as {@see \AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryHealthCheck}.
 *
 * @since 0.9.16
 */
final class TimezoneHealthCheck {

	public const TEST_KEY = 'acrossai_mcp_php_default_timezone';

	/** @var TimezoneHealthCheck|null */
	private static $instance = null;

	/**
	 * Private constructor — singleton per the plugin-wide Module Contract.
	 */
	private function __construct() {
	}

	/**
	 * Returns the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register as a `direct` Site Health test.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $tests Existing tests, keyed by bucket.
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function add_test( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct'][ self::TEST_KEY ] = array(
			'label' => __( 'PHP default timezone', 'acrossai-mcp-manager' ),
			'test'  => array( $this, 'run_self_check' ),
		);

		return $tests;
	}

	/**
	 * Compare PHP's current default timezone against the UTC that WordPress
	 * sets during bootstrap.
	 *
	 * Runs inside an admin request, so it reports on the plugins loaded for
	 * THAT request. A plugin that only shifts the timezone on a front-end
	 * shortcode or a specific admin action will not be caught here — a clean
	 * result is therefore weaker evidence than a failing one.
	 *
	 * @return array<string, mixed>
	 */
	public function run_self_check(): array {
		$current = (string) date_default_timezone_get();

		if ( 'UTC' === $current ) {
			return $this->build_result(
				'good',
				sprintf(
					/* translators: %s: the PHP timezone identifier, e.g. UTC. */
					esc_html__( "PHP's default timezone is %s, as WordPress sets it. Stored dates and token expiry times are recorded in UTC as intended.", 'acrossai-mcp-manager' ),
					'<code>' . esc_html( $current ) . '</code>'
				)
			);
		}

		$offset  = $this->offset_label( $current );
		$message = '<p>' . sprintf(
			/* translators: 1: the PHP timezone identifier another plugin set, 2: its offset from UTC, e.g. +05:30. */
			esc_html__( "Another plugin has changed PHP's default timezone to %1\$s (%2\$s from UTC) and has not restored it. WordPress sets this to UTC during startup and expects it to stay there.", 'acrossai-mcp-manager' ),
			'<code>' . esc_html( $current ) . '</code>',
			'<code>' . esc_html( $offset ) . '</code>'
		) . '</p>';

		$message .= '<p>' . esc_html__( 'This plugin records its own dates in UTC regardless, so connector sign-in and token expiry are not affected. Other plugins on this site may not be: anything that stores a date while the timezone is shifted can record it wrong by that offset, which typically shows up as times that are off by a fixed amount, or as logins and links expiring too early or too late.', 'acrossai-mcp-manager' ) . '</p>';

		$message .= '<p>' . esc_html__( 'To find the plugin responsible, search your plugins for date_default_timezone_set. The correct fix is for that plugin to restore the previous timezone immediately after it is done, or to use WordPress\'s own date functions instead.', 'acrossai-mcp-manager' ) . '</p>';

		return $this->build_result( 'recommended', $message );
	}

	/**
	 * Human-readable UTC offset for a timezone identifier, e.g. `+05:30`.
	 *
	 * Computed for the current instant so it reflects daylight saving where
	 * that applies.
	 *
	 * @param string $timezone PHP timezone identifier.
	 * @return string
	 */
	private function offset_label( string $timezone ): string {
		try {
			$seconds = ( new \DateTimeZone( $timezone ) )->getOffset( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		} catch ( \Exception $e ) {
			return __( 'unknown offset', 'acrossai-mcp-manager' );
		}

		return sprintf(
			'%s%02d:%02d',
			( $seconds < 0 ) ? '-' : '+',
			(int) floor( abs( $seconds ) / HOUR_IN_SECONDS ),
			(int) floor( ( abs( $seconds ) % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS )
		);
	}

	/**
	 * Shape a Site Health result.
	 *
	 * @param string $status      One of `good` or `recommended`.
	 * @param string $description Already-escaped HTML.
	 * @return array<string, mixed>
	 */
	private function build_result( string $status, string $description ): array {
		return array(
			'label'       => ( 'good' === $status )
				? __( "PHP's default timezone is UTC", 'acrossai-mcp-manager' )
				: __( "PHP's default timezone has been changed away from UTC", 'acrossai-mcp-manager' ),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Configuration', 'acrossai-mcp-manager' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => self::TEST_KEY,
		);
	}
}
