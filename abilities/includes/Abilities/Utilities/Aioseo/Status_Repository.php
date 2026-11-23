<?php
/**
 * Feature 132 — AIOSEO install state, backups and notifications.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo;

use AIOSEO\Plugin\Common\Models\Notification;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The reads and writes that are about AIOSEO itself rather than about SEO.
 *
 * Kept together because they share one property: each is a question whose answer decides whether
 * another ability in this suite can work at all. Which edition is this? Which addons are present, so
 * which abilities will report "Pro required"? Which AIOSEO capabilities does the caller hold, so
 * which panels will refuse? Is there a settings backup to fall back on before a bulk write?
 *
 * @since 0.0.44
 */
final class Status_Repository {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Install state.
	 *
	 * @since  0.0.44
	 * @return array<string, mixed>|WP_Error
	 */
	public static function status() {
		$available = Aioseo_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		return array(
			'version'      => defined( 'AIOSEO_VERSION' ) ? (string) AIOSEO_VERSION : '',
			'edition'      => empty( aioseo()->pro ) ? 'free' : 'pro',
			'addons'       => self::addons(),
			'capabilities' => self::capabilities(),
			'panels'       => self::panels(),
			'sitemap_on'   => (bool) aioseo()->options->sitemap->general->enable,
			'backups'      => count( self::backups() ),
			'message'      => sprintf(
				/* translators: 1: AIOSEO version, 2: edition */
				__( 'All in One SEO %1$s (%2$s edition).', 'acrossai-abilities-manager' ),
				defined( 'AIOSEO_VERSION' ) ? (string) AIOSEO_VERSION : '?',
				empty( aioseo()->pro ) ? __( 'free', 'acrossai-abilities-manager' ) : __( 'Pro', 'acrossai-abilities-manager' )
			),
		);
	}

	/**
	 * AIOSEO's addons, with whether each is installed and active.
	 *
	 * Reported because several AIOSEO features people ask for — local business schema, link
	 * assistant, video and news sitemaps, image SEO, IndexNow — are addons rather than core, so "that
	 * is not available on this site" is a different answer from "that does not exist".
	 *
	 * @since  0.0.44
	 * @return array<int, array<string, mixed>>
	 */
	public static function addons(): array {
		if ( ! isset( aioseo()->addons ) || ! is_object( aioseo()->addons ) ) {
			return array();
		}

		$addons = aioseo()->addons->getAddons();

		if ( ! is_array( $addons ) ) {
			return array();
		}

		$rows = array();

		foreach ( $addons as $addon ) {
			$addon = (array) $addon;

			$rows[] = array(
				'slug'      => (string) ( $addon['sluggedName'] ?? ( $addon['basename'] ?? '' ) ),
				'name'      => (string) ( $addon['name'] ?? '' ),
				'installed' => ! empty( $addon['installed'] ),
				'active'    => ! empty( $addon['isActive'] ),
			);
		}

		return $rows;
	}

	/**
	 * Which AIOSEO capabilities the current user holds.
	 *
	 * AIOSEO keeps its own role matrix, so a WordPress administrator can still be refused a panel if
	 * that matrix was edited. This is the read that explains an `aioseo_insufficient_capability`
	 * without the caller guessing.
	 *
	 * @since  0.0.44
	 * @return array<string, bool>
	 */
	public static function capabilities(): array {
		if ( ! isset( aioseo()->access ) || ! is_object( aioseo()->access ) ) {
			return array();
		}

		$list = aioseo()->access->getCapabilityList();
		$held = array();

		foreach ( (array) $list as $capability ) {
			if ( ! is_string( $capability ) || '' === $capability ) {
				continue;
			}

			$held[ $capability ] = Aioseo_Guard::has_capability( $capability );
		}

		return $held;
	}

	/**
	 * Every settings panel, with whether it is writable and whether this caller may write it.
	 *
	 * @since  0.0.44
	 * @return array<int, array<string, mixed>>
	 */
	public static function panels(): array {
		$rows = array();

		foreach ( Settings_Registry::panels() as $slug => $panel ) {
			$rows[] = array(
				'panel'      => $slug,
				'label'      => (string) $panel['label'],
				'capability' => (string) $panel['capability'],
				'writable'   => Settings_Registry::is_writable( $slug ),
				'permitted'  => Aioseo_Guard::has_capability( (string) $panel['capability'] ),
			);
		}

		return $rows;
	}

	/**
	 * AIOSEO's raw backup index, exactly as stored.
	 *
	 * The NATIVE value matters and this is the only place that keeps it. AIOSEO keys a backup with
	 * `time()`, so the index holds integers, and `Backup::delete()` removes an entry with
	 * `$backup === $backupTime` — a strict comparison (`Backup.php:72`). Hand it the string
	 * "1793202117" and the backup's own option row is deleted while its entry stays in the index:
	 * the listing still shows a backup that no longer exists, and restoring it would read null. So
	 * every call into AIOSEO's backup utility passes the value from here, never a caller's string.
	 *
	 * @since  0.0.44
	 * @return array<int, int|string>
	 */
	private static function raw_backup_keys(): array {
		if ( ! isset( aioseo()->backup ) || ! is_object( aioseo()->backup ) ) {
			return array();
		}

		$backups = aioseo()->backup->all();

		return is_array( $backups ) ? array_values( $backups ) : array();
	}

	/**
	 * Resolve a caller's key to the native value AIOSEO stored.
	 *
	 * @since  0.0.44
	 * @param  string $key Caller's key.
	 * @return int|string|null Null when no backup matches.
	 */
	private static function resolve_backup_key( string $key ) {
		foreach ( self::raw_backup_keys() as $raw ) {
			if ( is_scalar( $raw ) && (string) $raw === $key ) {
				return $raw;
			}
		}

		return null;
	}

	/**
	 * AIOSEO's settings backups, newest first.
	 *
	 * @since  0.0.44
	 * @return array<int, array<string, mixed>>
	 */
	public static function backups(): array {
		$backups = self::raw_backup_keys();

		if ( array() === $backups ) {
			return array();
		}

		$rows = array();

		foreach ( $backups as $backup ) {
			$key = is_scalar( $backup ) ? (string) $backup : '';

			if ( '' === $key ) {
				continue;
			}

			$rows[] = array(
				'key'       => $key,
				'created'   => self::format_backup_time( $key ),
				'timestamp' => $key,
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcmp( (string) $b['timestamp'], (string) $a['timestamp'] );
			}
		);

		return $rows;
	}

	/**
	 * Create a settings backup.
	 *
	 * @since  0.0.44
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_backup() {
		$available = Aioseo_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		if ( ! isset( aioseo()->backup ) || ! is_object( aioseo()->backup ) ) {
			return new WP_Error(
				'aioseo_backup_unavailable',
				__( 'This AIOSEO version does not expose its settings backup utility.', 'acrossai-abilities-manager' )
			);
		}

		$before = self::backups();

		aioseo()->backup->create();

		$after = self::backups();
		$keys  = array_column( $before, 'key' );
		$fresh = array_values(
			array_filter(
				$after,
				static function ( array $row ) use ( $keys ): bool {
					return ! in_array( $row['key'], $keys, true );
				}
			)
		);

		if ( array() === $fresh ) {
			return new WP_Error(
				'aioseo_backup_not_created',
				__( 'AIOSEO reported no new backup after the request. It keeps one backup per second, so a backup taken in the same second as an existing one is not added.', 'acrossai-abilities-manager' )
			);
		}

		return array(
			'backup'  => $fresh[0],
			'total'   => count( $after ),
			'message' => sprintf(
				/* translators: %s: backup key */
				__( 'Settings backup "%s" created. Restore it with aioseo/manage-backup.', 'acrossai-abilities-manager' ),
				(string) $fresh[0]['key']
			),
		);
	}

	/**
	 * Restore or delete one settings backup.
	 *
	 * @since  0.0.44
	 * @param  string $key    Backup key, as listed by {@see self::backups()}.
	 * @param  string $action 'restore' or 'delete'.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function manage_backup( string $key, string $action ) {
		$available = Aioseo_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		if ( ! isset( aioseo()->backup ) || ! is_object( aioseo()->backup ) ) {
			return new WP_Error(
				'aioseo_backup_unavailable',
				__( 'This AIOSEO version does not expose its settings backup utility.', 'acrossai-abilities-manager' )
			);
		}

		$native = self::resolve_backup_key( $key );
		$keys   = array_column( self::backups(), 'key' );

		if ( null === $native ) {
			return new WP_Error(
				'aioseo_backup_not_found',
				array() === $keys
					? __( 'There are no AIOSEO settings backups on this site. Take one with aioseo/create-backup.', 'acrossai-abilities-manager' )
					: sprintf(
						/* translators: 1: requested key, 2: comma-separated available keys */
						__( 'There is no AIOSEO settings backup "%1$s". The backups are: %2$s.', 'acrossai-abilities-manager' ),
						$key,
						implode( ', ', $keys )
					)
			);
		}

		if ( 'delete' === $action ) {
			aioseo()->backup->delete( $native );

			return array(
				'key'       => $key,
				'action'    => 'delete',
				'remaining' => count( self::backups() ),
				'message'   => sprintf(
					/* translators: %s: backup key */
					__( 'Settings backup "%s" was deleted permanently.', 'acrossai-abilities-manager' ),
					$key
				),
			);
		}

		aioseo()->backup->restore( $native );

		return array(
			'key'       => $key,
			'action'    => 'restore',
			'remaining' => count( self::backups() ),
			'message'   => sprintf(
				/* translators: %s: backup key */
				__( 'Settings backup "%s" was restored, replacing every AIOSEO setting on this site. Read any panel back to confirm what it now holds.', 'acrossai-abilities-manager' ),
				$key
			),
		);
	}

	/**
	 * Dismiss one AIOSEO notification.
	 *
	 * AIOSEO can list its notifications (`aioseo-notifications/list`) but not clear one, so a
	 * resolved alert stays in every listing and keeps reading as outstanding work.
	 *
	 * @since  0.0.44
	 * @param  string $name Notification name, as reported in a listing.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function dismiss_notification( string $name ) {
		$available = Aioseo_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		if ( ! class_exists( Notification::class ) ) {
			return new WP_Error(
				'aioseo_notifications_unavailable',
				__( 'This AIOSEO version does not expose its notification model.', 'acrossai-abilities-manager' )
			);
		}

		$notification = Notification::getNotificationByName( $name );

		if ( ! is_object( $notification ) || ! $notification->exists() ) {
			return new WP_Error(
				'aioseo_notification_not_found',
				sprintf(
					/* translators: %s: notification name */
					__( 'All in One SEO has no notification named "%s". List them with aioseo-notifications/list, which reports the name of each.', 'acrossai-abilities-manager' ),
					$name
				)
			);
		}

		if ( ! empty( $notification->dismissed ) ) {
			return array(
				'notification' => $name,
				'dismissed'    => true,
				'was_dismissed' => true,
				'message'      => sprintf(
					/* translators: %s: notification name */
					__( 'The notification "%s" was already dismissed; nothing changed.', 'acrossai-abilities-manager' ),
					$name
				),
			);
		}

		$notification->dismissed = true;
		$notification->save();

		return array(
			'notification'  => $name,
			'dismissed'     => true,
			'was_dismissed' => false,
			'message'       => sprintf(
				/* translators: %s: notification name */
				__( 'The notification "%s" was dismissed. AIOSEO can re-raise it if the condition behind it recurs.', 'acrossai-abilities-manager' ),
				$name
			),
		);
	}

	/**
	 * Render a backup key as a readable date.
	 *
	 * AIOSEO keys a backup by a timestamp string. Formatting is best-effort: a key that is not a
	 * timestamp is returned as-is rather than rendered as 1970.
	 *
	 * @since  0.0.44
	 * @param  string $key Backup key.
	 * @return string
	 */
	private static function format_backup_time( string $key ): string {
		// Numeric first: AIOSEO keys a backup with `time()`, and strtotime() refuses a bare
		// unix timestamp, so asking it first would discard every real key.
		$time = is_numeric( $key ) ? (int) $key : (int) strtotime( $key );

		if ( $time <= 0 ) {
			return $key;
		}

		return (string) wp_date( 'Y-m-d H:i:s', $time );
	}
}
