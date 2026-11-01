<?php
/**
 * Who, if anyone, is calling this site as a fleet host right now.
 *
 * ## Why this exists at all
 *
 * `RoutePermission` authenticates a host by signature and then calls
 * `wp_set_current_user()`, because every gate downstream of it expects a
 * WordPress user. That has one unwanted consequence: by the time the activity
 * log looks at the request, the only observable facts are "a user is logged
 * in" and "there is no application password", which is indistinguishable from
 * somebody using the admin in a browser — and that is exactly what the log
 * recorded before this class existed. A signed server-to-server call appearing
 * in an audit log as a browser session is not a cosmetic problem.
 *
 * So the route states what it knows, once, and the log reads it back. One
 * static per request, set only after a signature has actually verified.
 *
 * ## Deliberately not a transient, option or user meta
 *
 * This is true for the duration of one request and false for the next. Giving
 * it any storage would let one request's identity leak into another's log row,
 * which on an audit trail is worse than the missing column it replaced.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Fleet
 * @since      0.3.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Fleet;

defined( 'ABSPATH' ) || exit;

final class FleetContext {

	/**
	 * Hostname of the host calling in, or '' when this is not a fleet request.
	 *
	 * @since 0.3.8
	 * @var   string
	 */
	private static $peer = '';

	/**
	 * This hub's own key for itself, as the host list records it.
	 *
	 * Kept separate from {@see self::$peer} because the two answer different
	 * questions: the key identifies which stored row authorised the call, the
	 * hostname is what a person reads. Only the hostname reaches the log.
	 *
	 * @since 0.3.8
	 * @var   string
	 */
	private static $host_key = '';

	/**
	 * Record that a verified host is driving this request.
	 *
	 * Call ONLY after the signature has verified. Calling it earlier would
	 * stamp an attacker's claimed identity onto the log row for a request that
	 * was then refused, which is the opposite of what an audit log is for.
	 *
	 * @since 0.3.8
	 *
	 * @param string $host     Hostname of the calling site.
	 * @param string $host_key Key of the host-list row that authorised it.
	 * @return void
	 */
	public static function mark( string $host, string $host_key ): void {
		self::$peer     = $host;
		self::$host_key = $host_key;
	}

	/**
	 * Whether a verified fleet host is driving this request.
	 *
	 * @since 0.3.8
	 * @return bool
	 */
	public static function is_fleet(): bool {
		return '' !== self::$peer;
	}

	/**
	 * Hostname of the calling host, or '' when this is not a fleet request.
	 *
	 * @since 0.3.8
	 * @return string
	 */
	public static function peer(): string {
		return self::$peer;
	}

	/**
	 * Key of the host-list row that authorised this request.
	 *
	 * @since 0.3.8
	 * @return string
	 */
	public static function host_key(): string {
		return self::$host_key;
	}

	/**
	 * Forget the current request's host.
	 *
	 * For tests, which share one PHP process across cases and would otherwise
	 * carry a fleet identity from one into the next.
	 *
	 * @since 0.3.8
	 * @return void
	 */
	public static function reset(): void {
		self::$peer     = '';
		self::$host_key = '';
	}
}
