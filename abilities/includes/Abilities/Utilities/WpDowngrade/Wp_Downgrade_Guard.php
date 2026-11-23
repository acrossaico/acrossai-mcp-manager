<?php
/**
 * Feature 136 — shared guard for the WP Downgrade core-pinning abilities.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\WpDowngrade
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpDowngrade;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the pinning abilities need to know about WP Downgrade, read from its released source.
 *
 * **What the plugin actually does.** It does not downgrade anything. It filters the core update
 * transient so WordPress believes the chosen version is the one on offer:
 *
 *     add_filter( 'pre_site_option_update_core', 'wpdg_specific_version' );
 *     add_filter( 'site_transient_update_core',  'wpdg_specific_version' );
 *
 * The move happens on the next core update run. Any ability that writes the pin and reports
 * "downgraded" is lying, which is why {@see self::PIN_OPTION} is described as a pin everywhere.
 *
 * **It defines no classes**, so there is no container and no service to resolve — the whole
 * integration is three options. Detection therefore keys on the plugin's own function rather than a
 * class, and never on the active-plugins option, which can disagree with what actually loaded.
 *
 * @since 0.0.45
 */
final class Wp_Downgrade_Guard {

	/**
	 * The target version. Empty string means no pin.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const PIN_OPTION = 'wpdg_specific_version_name';

	/**
	 * A caller-supplied package URL, used only when {@see self::CUSTOM_URL_TOGGLE} is truthy.
	 *
	 * This suite never writes either of these. Doing so would let an ability point WordPress core at
	 * an arbitrary archive, which is a different feature with a different security review.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const CUSTOM_URL_OPTION = 'wpdg_download_url';

	/**
	 * Whether the custom URL above is in use.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const CUSTOM_URL_TOGGLE = 'wpdg_edit_download_url';

	/**
	 * The plugin file, as registered with WordPress.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const PLUGIN_FILE = 'wp-downgrade/wp-downgrade.php';

	/**
	 * Its WordPress.org slug.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const PLUGIN_SLUG = 'wp-downgrade';

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether WP Downgrade has loaded this request.
	 *
	 * Keys on its own function because the plugin is procedural and defines no classes.
	 *
	 * @since  0.0.45
	 * @return bool
	 */
	public static function is_active(): bool {
		return function_exists( 'wpdg_get_url' );
	}

	/**
	 * The "WP Downgrade is not active" response shared by the pinning abilities.
	 *
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	public static function not_active(): array {
		return array(
			'success'        => false,
			'active'         => false,
			'blocked_reason' => 'wp_downgrade_not_active',
			'message'        => __( 'WP Downgrade is not active on this site, so there is nothing holding a core version. Install and activate wordpress.org/plugins/wp-downgrade/ to pin WordPress core to a chosen version. Note that core/rollback-wp-core can already move core down once without it — the difference is that a pin survives later update checks.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * The version core is currently pinned to, or '' when there is no pin.
	 *
	 * @since  0.0.45
	 * @return string
	 */
	public static function pinned_version(): string {
		$pinned = get_option( self::PIN_OPTION, '' );

		return is_scalar( $pinned ) ? trim( (string) $pinned ) : '';
	}

	/**
	 * Whether a caller-supplied package URL is in force.
	 *
	 * When this is on, `wpdg_get_url()` ignores the version entirely and returns the stored URL. A
	 * pin set while it is on therefore resolves through an archive this plugin did not choose, so
	 * the writes refuse rather than produce that state silently.
	 *
	 * @since  0.0.45
	 * @return bool
	 */
	public static function custom_url_enabled(): bool {
		$toggle = get_option( self::CUSTOM_URL_TOGGLE, '' );
		$url    = get_option( self::CUSTOM_URL_OPTION, '' );

		// Mirrors wpdg_get_url()'s own test: the toggle must be truthy AND the URL must validate,
		// or the plugin falls back to building a downloads.wordpress.org URL from the version.
		return ! empty( $toggle ) && is_string( $url ) && false !== filter_var( $url, FILTER_VALIDATE_URL );
	}

	/**
	 * The stored custom URL, for disclosure only.
	 *
	 * @since  0.0.45
	 * @return string
	 */
	public static function custom_url(): string {
		$url = get_option( self::CUSTOM_URL_OPTION, '' );

		return is_scalar( $url ) ? (string) $url : '';
	}

	/**
	 * The package URL WordPress would be pointed at for one version.
	 *
	 * Delegates to the plugin's own `wpdg_get_url()` when it is loaded, so the answer is whatever
	 * the plugin would really use — including the locale segment it adds for non-English sites.
	 * Falls back to the same construction when the plugin is absent, so the read abilities can
	 * still explain what a pin would do.
	 *
	 * @since  0.0.45
	 * @param  string $version Target version.
	 * @return string
	 */
	public static function package_url( string $version ): string {
		if ( '' === $version ) {
			return '';
		}

		if ( function_exists( 'wpdg_get_url' ) ) {
			return (string) wpdg_get_url( $version );
		}

		$locale = get_locale();
		$prefix = in_array( $locale, array( 'en_US', 'en' ), true ) ? '' : $locale . '/';

		return 'https://downloads.wordpress.org/release/' . $prefix . 'wordpress-' . $version . '.zip';
	}

	/**
	 * Whether a package exists at that URL.
	 *
	 * Uses the plugin's own `wpdg_urlcheck()` when available — it accepts 200, 301 and 302 — so our
	 * answer matches the warning its settings screen shows. Falls back to an equivalent HEAD.
	 *
	 * @since  0.0.45
	 * @param  string $url Package URL.
	 * @return bool
	 */
	public static function package_exists( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		if ( function_exists( 'wpdg_urlcheck' ) ) {
			return (bool) wpdg_urlcheck( $url );
		}

		$response = wp_remote_head( $url, array( 'timeout' => 5 ) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return in_array( (int) wp_remote_retrieve_response_code( $response ), array( 200, 301, 302 ), true );
	}

	/**
	 * Whether WP Downgrade's own settings screen would accept this version string.
	 *
	 * Its sanitiser is `/^[-+]?[0-9]*[.]?[0-9]?[.]?[0-9]+$/`, and the middle group allows a single
	 * digit — so `7.1.10` passes but **`6.10.2` is rejected**, even though WordPress 6.10 exists.
	 *
	 * This matters because `update_option()` does not run a `register_setting()` sanitise callback:
	 * that only fires for a Settings API POST. So this suite *can* store a value the plugin's own
	 * screen would refuse to re-save. The pin still works — the filter only does a `version_compare`
	 * and builds a URL — but an operator opening Settings → WP Downgrade could not save the form
	 * again without changing it. Reported as a warning rather than refused, because refusing would
	 * block a legitimate WordPress version on account of someone else's regex.
	 *
	 * @since  0.0.45
	 * @param  string $version Target version.
	 * @return bool
	 */
	public static function host_ui_accepts( string $version ): bool {
		return 1 === preg_match( '/^[-+]?[0-9]*[.]?[0-9]?[.]?[0-9]+$/', $version );
	}
}
