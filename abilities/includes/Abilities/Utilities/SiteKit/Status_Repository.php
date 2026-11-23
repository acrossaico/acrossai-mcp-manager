<?php
/**
 * Feature 120 — Site Kit connection and setup state.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only reader for "is this thing connected, and if not, to what".
 *
 * Everything here is designed to answer on a site where NOTHING is connected, because
 * that is precisely when it is asked. No method may require authentication, and none
 * may return a WP_Error for the ordinary not-connected case — "not connected" is the
 * answer, not a failure.
 */
final class Status_Repository {

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Overall Site Kit state for the current user.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$auth = Site_Kit_Context::authentication();

		$setup_completed = self::probe( $auth, 'is_setup_completed' );
		$authenticated   = self::probe( $auth, 'is_authenticated' );

		$status = array(
			'site_kit_version'    => Site_Kit_Context::version(),
			'setup_completed'     => $setup_completed,
			'user_authenticated'  => $authenticated,
			'google_account'      => self::profile( $auth ),
			'site_verified'       => self::verified( $auth ),
			'connect_url'         => self::url( $auth, 'get_connect_url' ),
			'disconnect_url'      => self::url( $auth, 'get_disconnect_url' ),
			'dashboard_url'       => admin_url( 'admin.php?page=googlesitekit-dashboard' ),
			'settings_url'        => admin_url( 'admin.php?page=googlesitekit-settings' ),
			'current_user_id'     => get_current_user_id(),
		);

		$modules = Module_Repository::list_modules();
		if ( is_wp_error( $modules ) ) {
			$status['modules_error'] = $modules->get_error_message();
			$status['active_modules']    = array();
			$status['connected_modules'] = array();
		} else {
			$status['active_modules']    = array_values(
				array_map(
					static fn( array $m ): string => (string) $m['slug'],
					array_filter( $modules, static fn( array $m ): bool => (bool) $m['active'] )
				)
			);
			$status['connected_modules'] = array_values(
				array_map(
					static fn( array $m ): string => (string) $m['slug'],
					array_filter( $modules, static fn( array $m ): bool => (bool) $m['connected'] )
				)
			);
		}

		$status['next_step'] = self::next_step( $status );

		return $status;
	}

	/**
	 * The one thing to do next, in a sentence.
	 *
	 * Four different states all read as "no data" to a caller, and each needs a
	 * different person to do a different thing. Naming it here means every ability can
	 * point at the same answer rather than each inventing its own.
	 *
	 * @param array<string,mixed> $status Status payload built above.
	 * @return string
	 */
	private static function next_step( array $status ): string {
		if ( '' === $status['site_kit_version'] ) {
			return __( 'Site Kit is active but not reporting a version, which usually means another plugin loaded a conflicting copy.', 'acrossai-abilities-manager' );
		}
		if ( ! $status['setup_completed'] ) {
			return __( 'Nobody has set Site Kit up on this site yet. An administrator must complete the setup flow at Site Kit → Dashboard, which connects a Google account and verifies ownership of the site.', 'acrossai-abilities-manager' );
		}
		if ( ! $status['user_authenticated'] ) {
			return __( 'Site Kit is set up, but the WordPress user running this ability has not connected their own Google account. Site Kit stores one token per user, so this user must connect before any Google data can be read.', 'acrossai-abilities-manager' );
		}
		if ( array() === $status['connected_modules'] ) {
			return __( 'This user is connected to Google, but no Site Kit module is connected yet — so there is nothing to report on. Connect Search Console or Analytics at Site Kit → Settings.', 'acrossai-abilities-manager' );
		}

		return __( 'Site Kit is connected and ready. Read search data with site-kit/get-search-analytics and traffic with site-kit/get-analytics-report.', 'acrossai-abilities-manager' );
	}

	/**
	 * Call a boolean predicate on Authentication without letting it fatal.
	 *
	 * @param object|null $auth   Authentication instance.
	 * @param string      $method Method name.
	 * @return bool
	 */
	private static function probe( ?object $auth, string $method ): bool {
		if ( null === $auth || ! method_exists( $auth, $method ) ) {
			return false;
		}
		try {
			return (bool) $auth->$method();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * The connected Google account, or nulls when there is none.
	 *
	 * Only the email and picture are exposed. The token itself is never read here and
	 * must never be: an ability's job is to say whether a connection exists, not to
	 * hand out the credential behind it.
	 *
	 * @param object|null $auth Authentication instance.
	 * @return array{email:string|null,photo:string|null}
	 */
	private static function profile( ?object $auth ): array {
		$empty = array( 'email' => null, 'photo' => null );

		if ( null === $auth || ! method_exists( $auth, 'profile' ) ) {
			return $empty;
		}

		try {
			$profile = $auth->profile();
			if ( ! method_exists( $profile, 'has' ) || ! $profile->has() ) {
				return $empty;
			}
			$data = $profile->get();
		} catch ( \Throwable $e ) {
			return $empty;
		}

		if ( ! is_array( $data ) ) {
			return $empty;
		}

		return array(
			'email' => isset( $data['email'] ) ? (string) $data['email'] : null,
			'photo' => isset( $data['photo'] ) ? (string) $data['photo'] : null,
		);
	}

	/**
	 * Whether Google has verified this user's ownership of the site.
	 *
	 * @param object|null $auth Authentication instance.
	 * @return bool
	 */
	private static function verified( ?object $auth ): bool {
		if ( null === $auth || ! method_exists( $auth, 'verification' ) ) {
			return false;
		}
		try {
			return (bool) $auth->verification()->get();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * A Site Kit admin URL, or '' when this version does not provide it.
	 *
	 * These carry a nonce and are single-user, so they are returned for a human to
	 * click rather than for a client to fetch.
	 *
	 * @param object|null $auth   Authentication instance.
	 * @param string      $method Method name.
	 * @return string
	 */
	private static function url( ?object $auth, string $method ): string {
		if ( null === $auth || ! method_exists( $auth, $method ) ) {
			return '';
		}
		try {
			return (string) $auth->$method();
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
