<?php
/**
 * Feature 136 — shared guard and service resolver for the WP Rollback ability suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\WpRollback
 * @since      0.0.45
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps every WP Rollback ability honest when the plugin is missing, off, or restructures.
 *
 * **Why the container and not the REST routes.** WP Rollback exposes `wp-rollback/v1` with
 * `/process-rollback/`, `/rollback-steps/` and `/fetch-info/`, and every one is behind
 * `permission_callback => [$this, 'permissionValidation']`. Dispatching to them internally means
 * either satisfying a nonce we do not have or `rest_do_request()` with its own auth question. The
 * plugin already has a DI container — `SharedCore::container()->make( BackupService::class )` is
 * how its own `FetchInfoApiRoute` resolves services — so the suite resolves the same way.
 *
 * **What this guard does not claim.** WP Rollback's service classes are not a published contract.
 * Each reader is wrapped and degrades to null rather than fatally dereferencing a class that moved
 * between releases, exactly as the Query Monitor guard does for its collectors.
 *
 * @since 0.0.45
 */
final class Wp_Rollback_Guard {

	/**
	 * The wp-rollback plugin file, as registered with WordPress.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const PLUGIN_FILE = 'wp-rollback/wp-rollback.php';

	/**
	 * Its WordPress.org slug, for the install suggestion.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const PLUGIN_SLUG = 'wp-rollback';

	/**
	 * The container façade.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	private const SHARED_CORE = '\WpRollback\SharedCore\Core\SharedCore';

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether WP Rollback has booted this request.
	 *
	 * Detected by its own container being initialised, not by the plugin-active option: the option
	 * can say yes while the plugin failed to load, and these abilities are registered
	 * unconditionally so they must cope with being called either way.
	 *
	 * @since  0.0.45
	 * @return bool
	 */
	public static function is_active(): bool {
		if ( ! class_exists( self::SHARED_CORE ) ) {
			return false;
		}

		try {
			return (bool) call_user_func( array( self::SHARED_CORE, 'isInitialized' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * The "WP Rollback is not active" response shared by every ability in the suite.
	 *
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	public static function not_active(): array {
		return array(
			'success'        => false,
			'active'         => false,
			'blocked_reason' => 'wp_rollback_not_active',
			'message'        => __( 'WP Rollback is not active on this site. Version moves themselves do not need it — versions/rollback-plugin and versions/rollback-theme use WordPress.org packages through core\'s own upgrader. Install and activate WP Rollback (wordpress.org/plugins/wp-rollback/) for its local archive of previously installed versions, which is the only source for an asset the directory does not host.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Resolve one of WP Rollback's services, or null when it cannot be had.
	 *
	 * @since  0.0.45
	 * @param  string $class Fully-qualified service class name.
	 * @return object|null
	 */
	public static function service( string $class ) {
		if ( ! self::is_active() || ! class_exists( $class ) ) {
			return null;
		}

		try {
			$container = call_user_func( array( self::SHARED_CORE, 'container' ) );

			if ( ! is_object( $container ) || ! method_exists( $container, 'make' ) ) {
				return null;
			}

			$service = $container->make( $class );

			return is_object( $service ) ? $service : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The capability WP Rollback itself requires for one asset type.
	 *
	 * Mirrors `PluginUtility::currentUserCanRollback()` rather than settling for `manage_options`,
	 * so an ability never permits what the host plugin's own REST route would refuse. Its rule:
	 * `update_themes` for a theme, `update_plugins` for a plugin.
	 *
	 * @since  0.0.45
	 * @param  string $type `plugin` or `theme`.
	 * @return bool
	 */
	public static function current_user_can_rollback( string $type ): bool {
		if ( 'theme' === $type ) {
			return current_user_can( 'update_themes' );
		}

		if ( 'plugin' === $type ) {
			return current_user_can( 'update_plugins' );
		}

		return current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' );
	}

	/**
	 * Whether a network-activated plugin or theme is in use by other sites on this multisite.
	 *
	 * This is the check a human forgets. Rolling back a network-activated plugin affects every site
	 * on the network, and `NetworkAssetUsageService` already knows the answer — it is bound as a
	 * singleton with its constructor argument resolved, so the container can hand it over.
	 *
	 * Returns null, not false, when the answer cannot be had: on single-site, or when the service is
	 * unavailable. False would assert "no other sites use it", which is a different claim from "we
	 * could not ask".
	 *
	 * @since  0.0.45
	 * @param  string $type `plugin` or `theme`.
	 * @param  string $slug Asset slug or stylesheet.
	 * @return bool|null
	 */
	public static function used_on_other_sites( string $type, string $slug ): ?bool {
		if ( ! is_multisite() ) {
			return null;
		}

		$service = self::service( '\WpRollback\SharedCore\Rollbacks\Services\NetworkAssetUsageService' );

		if ( null === $service ) {
			return null;
		}

		try {
			if ( 'theme' === $type && method_exists( $service, 'isThemeUsedOnOtherSites' ) ) {
				return (bool) $service->isThemeUsedOnOtherSites( $slug );
			}

			if ( 'plugin' === $type && method_exists( $service, 'isPluginActiveOnOtherSites' ) ) {
				return (bool) $service->isPluginActiveOnOtherSites( $slug );
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Versions WP Rollback holds a local archive for.
	 *
	 * This is the one route by which a plugin WordPress.org does not publish can still be rolled
	 * back: `BackupService::getArchivesForSlug()` lists what has been archived on this site. Returns
	 * an empty array when the service is unavailable, which is the honest answer — not a claim that
	 * no archives exist.
	 *
	 * @since  0.0.45
	 * @param  string $slug Asset slug.
	 * @return array<string,string> version => archive file.
	 */
	public static function archived_versions( string $slug ): array {
		$service = self::service( '\WpRollback\SharedCore\Rollbacks\Services\BackupService' );

		if ( null === $service || ! method_exists( $service, 'getArchivesForSlug' ) ) {
			return array();
		}

		try {
			$archives = (array) $service->getArchivesForSlug( $slug );
		} catch ( \Throwable $e ) {
			return array();
		}

		$versions = array();

		// getArchivesForSlug() returns file => version; invert it so the version is the key, which
		// is how every caller wants to ask the question.
		foreach ( $archives as $file => $version ) {
			if ( is_string( $version ) && '' !== $version ) {
				$versions[ $version ] = (string) $file;
			}
		}

		return $versions;
	}
}
