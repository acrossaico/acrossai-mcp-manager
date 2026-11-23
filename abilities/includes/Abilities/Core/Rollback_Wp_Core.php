<?php
/**
 * Rollback_Wp_Core ability (Feature 043).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Core
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Core;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\File_Mods_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Core_Php_Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Roll back WordPress core to an earlier version.
 *
 * Fetches the list of offered versions from the WP.org Core API
 * (`https://api.wordpress.org/core/version-check/1.7/`) via wp_remote_get(),
 * picks the offer matching the requested `version`, and hands it directly to
 * WP core's `Core_Upgrader::upgrade()` — the same class the built-in "Update
 * WordPress" screen uses. `Core_Upgrader::upgrade($offer)` does not care
 * whether $offer->version is older than the currently-installed version; it
 * simply installs whatever the offer describes.
 *
 * Uses only WordPress functions — no bundled updater code, no custom download,
 * no custom integrity verification. Inspired by Andy Fragen's core-rollback
 * plugin (`https://github.com/afragen/core-rollback`) but skips the transient
 * / http_request_args dance that plugin needs to funnel through the
 * `update-core.php?action=do-core-reinstall` UI — we invoke Core_Upgrader
 * directly, so we can pass a hand-constructed offer without touching WP core's
 * upgrade transient.
 */
class Rollback_Wp_Core extends Ability_Definition {

	/**
	 * WP.org Core API endpoint (1.7).
	 */
	private const CORE_API_URL = 'https://api.wordpress.org/core/version-check/1.7/';

	/**
	 * Per-locale offer cache TTL. One day matches the reference core-rollback
	 * plugin and the WP.org API's own cache posture.
	 */
	private const OFFER_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Site transient key prefix for the offer cache.
	 */
	private const OFFER_CACHE_PREFIX = 'acrossai_abilities_manager_core_offers_';

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'core/rollback-wp-core',
			'args' => array(
				'label'               => __( 'Rollback WordPress Core', 'acrossai-abilities-manager' ),
				'description'         => __( 'Roll back WordPress core to an earlier version offered by the WP.org Core API, by handing that offer to Core_Upgrader::upgrade() — the same class the dashboard uses. Only the latest patch of each release series is reachable, because that is all api.wordpress.org still offers: 7.0.8 and 6.8.12 yes, 6.8.1 no. Call core/check-wp-core-update for the running version, and note that this does not pin anything — WordPress will offer the newer version again at the next update check. Requires confirm: true, and refuses a target that predates support for the PHP this site runs, which would fatal on every request including wp-admin. Honours DISALLOW_FILE_MODS.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-core',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'update_core' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'version' => array(
							'type'        => 'string',
							'description' => __( 'Target WordPress version, strictly older than the installed one — use core/update-wp-core to move forward. Must be a version api.wordpress.org still offers, which is the latest patch of each series: "6.8.12", not "6.8.1". core/check-wp-core-update reports what is current.', 'acrossai-abilities-manager' ),
						),
						'locale'  => array(
							'type'        => 'string',
							'description' => __( 'Optional locale for the WP.org Core API offer (e.g. "en_US"). Defaults to get_locale().', 'acrossai-abilities-manager' ),
						),
						'confirm' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Must be true. Downgrading core replaces every WordPress file and cannot be undone automatically; the database is not downgraded with it.', 'acrossai-abilities-manager' ),
						),
					),
					// `confirm` is deliberately NOT required here: core validates input_schema
					// before execute() runs, so a schema-required flag fails as a generic
					// ability_invalid_input and the caller never sees which flag was missing or
					// why. Enforced in execute() instead, as the versions/ writes do.
					'required'             => array( 'version' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'        => array( 'type' => 'boolean' ),
						'updated'        => array( 'type' => 'boolean' ),
						'from_version'   => array( 'type' => 'string' ),
						'to_version'     => array( 'type' => 'string' ),
						'error_code'     => array( 'type' => 'string' ),
						'blocked_reason' => array( 'type' => 'string' ),
						'php_version'    => array( 'type' => 'string' ),
						'compatibility'  => array( 'type' => 'string' ),
						'warnings'       => array( 'type' => 'array' ),
						'message'        => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'updates',
						'sub_group'       => 'lifecycle',
						'sub_group_label' => __( 'Lifecycle', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
				),
			),
		);
	}

	/**
	 * The pinning ability covers what this one cannot: making the version stick.
	 *
	 * Feature 136. `only_if_registered` keeps the hint out of the payload on sites where the
	 * WP Downgrade suite is not present, so a caller is never pointed at an ability that does not
	 * exist. Held back from the suggestion PR for exactly that reason — the target had to exist
	 * first.
	 *
	 * @since  0.0.45
	 * @return array<int,array<string,string|bool>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'               => 'versions/set-core-pin',
				'reason'             => __( 'This moves core down once; WordPress offers the newer version again at the next update check. Pin the version first to make it hold.', 'acrossai-abilities-manager' ),
				'only_if_registered' => true,
			),
		);
	}

	/**
	 * WP Downgrade pins a core version; this ability does not.
	 *
	 * Feature 136. This ability rolls core back once; WordPress then re-offers the newer version on the next update check, and an auto-update can undo the rollback silently.
	 *
	 * WP Downgrade does not itself move core. It filters `site_transient_update_core` so WordPress
	 * offers the pinned version, and the move happens on the next core update run — which is why
	 * the suggestion is about persistence, not about downgrading.
	 *
	 * `plugin_provides_abilities` is false: it is a settings-page plugin and defines no classes at
	 * all, so installing it gives an agent nothing until this plugin's own pinning abilities exist.
	 *
	 * @since  0.0.45
	 * @return array<int,array<string,scalar>>
	 */
	protected function suggested_plugins(): array {
		return array(
			array(
				'slug'                          => 'wp-downgrade',
				'name'                          => 'WP Downgrade | Specific Core Version',
				'reason'                        => __( 'This rolls core back once. WordPress will offer the newer version again at the next update check, so an auto-update can undo it. WP Downgrade pins a chosen version until you clear it.', 'acrossai-abilities-manager' ),
				'covers'                        => __( 'Pinning WordPress core to a chosen version so update checks keep offering it. Core only; it does not touch plugins or themes.', 'acrossai-abilities-manager' ),
				'plugin_provides_abilities'     => false,
				'acrossai_provides_integration' => false,
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$blocked = File_Mods_Guard::blocked_response( 'install' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( is_multisite() && ! current_user_can( 'update_core' ) ) {
			return array(
				'success' => false,
				'updated' => false,
				'message' => __( 'Core rollback on multisite requires the update_core capability at the network level.', 'acrossai-abilities-manager' ),
			);
		}

		$version = isset( $input['version'] ) ? sanitize_text_field( (string) $input['version'] ) : '';
		if ( '' === $version ) {
			return array(
				'success' => false,
				'updated' => false,
				'message' => __( 'The "version" input is required (e.g. "6.8.1").', 'acrossai-abilities-manager' ),
			);
		}

		$locale = isset( $input['locale'] ) ? sanitize_text_field( (string) $input['locale'] ) : '';
		if ( '' === $locale ) {
			$locale = get_locale();
		}

		$from_version = (string) get_bloginfo( 'version' );

		if ( version_compare( $version, $from_version, '>=' ) ) {
			return array(
				'success'      => false,
				'updated'      => false,
				'error_code'   => 'not_older_than_installed',
				'from_version' => $from_version,
				'to_version'   => $from_version,
				'message'      => sprintf(
					/* translators: 1: requested version, 2: current version */
					__( 'Target version %1$s is not older than current version %2$s. Use core/update-wp-core for upgrades.', 'acrossai-abilities-manager' ),
					$version,
					$from_version
				),
			);
		}

		$offer = $this->fetch_offer( $version, $locale );
		if ( is_wp_error( $offer ) ) {
			return array(
				'success'      => false,
				'updated'      => false,
				'error_code'   => $offer->get_error_code(),
				'from_version' => $from_version,
				'to_version'   => $from_version,
				'message'      => $offer->get_error_message(),
			);
		}

		// The check WordPress's own update screen makes and Core_Upgrader does not. Calling the
		// upgrader directly skipped it entirely; it guards the forward direction, which this
		// ability can still hit when an old release demands a newer MySQL than the site has.
		global $wpdb;
		$mysql = isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '';

		$minimums = Core_Php_Compat::offer_minimums(
			isset( $offer->php_version ) ? (string) $offer->php_version : '',
			isset( $offer->mysql_version ) ? (string) $offer->mysql_version : '',
			PHP_VERSION,
			$mysql
		);

		if ( array() !== $minimums ) {
			return array(
				'success'      => false,
				'updated'      => false,
				'error_code'   => 'offer_requirements_not_met',
				'from_version' => $from_version,
				'to_version'   => $from_version,
				'php_version'  => PHP_VERSION,
				'message'      => implode( ' ', $minimums ),
			);
		}

		// And the check nothing makes, because WordPress.org publishes no maximum PHP per release.
		// This is the one that matters for a downgrade: install a release older than the PHP it
		// runs on and the site fatals on every request, wp-admin included.
		$compat = Core_Php_Compat::assess( $version, PHP_VERSION );

		if ( 'unsupported' === $compat['verdict'] ) {
			return array(
				'success'       => false,
				'updated'       => false,
				'error_code'    => 'php_incompatible',
				'from_version'  => $from_version,
				'to_version'    => $from_version,
				'php_version'   => PHP_VERSION,
				'compatibility' => $compat['verdict'],
				'message'       => $compat['message'],
			);
		}

		if ( empty( $input['confirm'] ) ) {
			return array(
				'success'        => false,
				'updated'        => false,
				'blocked_reason' => 'confirmation_required',
				'from_version'   => $from_version,
				'to_version'     => $from_version,
				'php_version'    => PHP_VERSION,
				'compatibility'  => $compat['verdict'],
				'message'        => sprintf(
					/* translators: 1: current version, 2: target version, 3: compatibility note */
					__( 'Rolling WordPress back from %1$s to %2$s replaces every core file and cannot be undone automatically. The database is not downgraded with it, and WordPress will offer the newer version again at the next update check. %3$s Pass confirm: true to proceed.', 'acrossai-abilities-manager' ),
					$from_version,
					$version,
					$compat['message']
				),
			);
		}

		$warnings = array();

		if ( 'unknown' === $compat['verdict'] ) {
			$warnings[] = $compat['message'];
		}

		$warnings[] = __( 'Core was downgraded but the database was not. WordPress does not reverse schema changes, so the stored db_version stays ahead of the installed release.', 'acrossai-abilities-manager' );
		$warnings[] = __( 'This does not pin anything. The next update check will offer the newer version again, and an automatic update can undo this — versions/set-core-pin with WP Downgrade is what makes it hold.', 'acrossai-abilities-manager' );

		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! class_exists( '\Core_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $offer );

		$to_version = (string) get_bloginfo( 'version' );

		if ( is_wp_error( $result ) ) {
			// False, not true. This branch means the rollback failed; reporting success true with
			// updated false made the only required output field say the opposite of what happened,
			// and the same ability already returned false for an unknown version — so the meaning
			// of `success` changed with how far execution got.
			return array(
				'success'      => false,
				'updated'      => false,
				'error_code'   => $result->get_error_code(),
				'from_version' => $from_version,
				'to_version'   => $to_version,
				'php_version'  => PHP_VERSION,
				'message'      => $result->get_error_message(),
			);
		}

		if ( null === $result || false === $result ) {
			$skin_errors = $skin->get_errors();
			$msg         = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
				? $skin_errors->get_error_message()
				: __( 'Core rollback did not report success.', 'acrossai-abilities-manager' );
			return array(
				'success'      => false,
				'updated'      => false,
				'error_code'   => 'rollback_failed',
				'from_version' => $from_version,
				'to_version'   => $to_version,
				'php_version'  => PHP_VERSION,
				'message'      => $msg,
			);
		}

		return array(
			'success'       => true,
			'updated'       => true,
			'from_version'  => $from_version,
			'to_version'    => $to_version,
			'php_version'   => PHP_VERSION,
			'compatibility' => $compat['verdict'],
			'warnings'      => $warnings,
			'message'       => sprintf(
				/* translators: 1: from version, 2: to version */
				__( 'WordPress rolled back from %1$s to %2$s.', 'acrossai-abilities-manager' ),
				$from_version,
				$to_version
			),
		);
	}

	/**
	 * Fetch the WP.org Core API offer matching $version. Cached per-locale in
	 * a site transient with a day-long TTL (matches the reference
	 * core-rollback plugin's cache posture).
	 *
	 * The returned offer has its `response` field forced to `upgrade` so
	 * `Core_Upgrader::upgrade()` proceeds regardless of the original API
	 * marking (the API marks offered versions as `latest`; `Core_Upgrader`
	 * itself only inspects `->download` / `->packages` / `->version`).
	 *
	 * @return \stdClass|\WP_Error
	 */
	private function fetch_offer( string $version, string $locale ) {
		$cache_key = self::OFFER_CACHE_PREFIX . sanitize_key( $locale );
		$cached    = get_site_transient( $cache_key );

		if ( ! is_array( $cached ) || ! isset( $cached[ $version ] ) ) {
			$fetched = $this->fetch_all_offers( $locale );
			if ( is_wp_error( $fetched ) ) {
				return $fetched;
			}
			set_site_transient( $cache_key, $fetched, self::OFFER_CACHE_TTL );
			$cached = $fetched;
		}

		if ( ! isset( $cached[ $version ] ) ) {
			// Name the version that would have worked. The API offers only the latest patch of
			// each release series — measured live: 26 offers, 6.8.12 present and 6.8.1 absent —
			// and "not found" alone reads as "that version never existed", which sends a caller
			// looking for the wrong thing. This ability's own documentation used 6.8.1 as its
			// example until a test tried it.
			$series = '';

			if ( 1 === preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ) {
				$series = $m[1] . '.' . $m[2];
			}

			$nearest = '';

			if ( '' !== $series ) {
				foreach ( array_keys( $cached ) as $offered ) {
					if ( 0 === strpos( (string) $offered, $series . '.' ) || (string) $offered === $series ) {
						$nearest = (string) $offered;
						break;
					}
				}
			}

			return new \WP_Error(
				'core_version_not_found',
				'' !== $nearest
					? sprintf(
						/* translators: 1: requested version, 2: the offered version in the same series */
						__( 'WordPress %1$s is not offered by api.wordpress.org. Only the latest patch of each release series is still offered, which for that series is %2$s — use that instead.', 'acrossai-abilities-manager' ),
						$version,
						$nearest
					)
					: sprintf(
						/* translators: 1: requested version, 2: comma-separated offered versions */
						__( 'WordPress %1$s is not offered by api.wordpress.org, which carries only the latest patch of each release series. Currently offered: %2$s.', 'acrossai-abilities-manager' ),
						$version,
						implode( ', ', array_slice( array_keys( $cached ), 0, 12 ) )
					)
			);
		}

		$offer = $cached[ $version ];

		if ( is_object( $offer ) ) {
			$offer->response = 'upgrade';
			if ( isset( $offer->version ) ) {
				$offer->current = $offer->version;
			}
		}

		return $offer;
	}

	/**
	 * Fetch every offer from the WP.org Core API for a given locale. Returns
	 * an array keyed by `version` string.
	 *
	 * @return array<string, \stdClass>|\WP_Error
	 */
	private function fetch_all_offers( string $locale ) {
		$url = self::CORE_API_URL . '?locale=' . rawurlencode( $locale );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'core_api_http_error',
				sprintf(
					/* translators: 1: HTTP code, 2: WP.org Core API URL */
					__( 'WP.org Core API returned HTTP %1$d for %2$s.', 'acrossai-abilities-manager' ),
					$code,
					$url
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );
		if ( ! is_object( $data ) || ! isset( $data->offers ) || ! is_array( $data->offers ) ) {
			return new \WP_Error(
				'core_api_malformed',
				__( 'WP.org Core API response is malformed (missing "offers" array).', 'acrossai-abilities-manager' )
			);
		}

		$offers = array();
		foreach ( $data->offers as $offer ) {
			if ( is_object( $offer ) && isset( $offer->version ) && version_compare( (string) $offer->version, '4.0', '>=' ) ) {
				$offers[ (string) $offer->version ] = $offer;
			}
		}

		if ( empty( $offers ) ) {
			return new \WP_Error(
				'core_api_no_offers',
				__( 'WP.org Core API returned no usable offers.', 'acrossai-abilities-manager' )
			);
		}

		return $offers;
	}
}
