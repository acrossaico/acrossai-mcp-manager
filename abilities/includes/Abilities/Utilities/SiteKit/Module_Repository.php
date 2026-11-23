<?php
/**
 * Feature 120 — Site Kit modules, their settings, state and sharing.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only accessor for Site Kit's module registry.
 *
 * Reads go through the Modules registry rather than the googlesitekit_*_settings
 * options directly. The options are the storage, not the contract: Site Kit normalises
 * defaults, merges owner IDs and applies per-module getters on the way out, so reading
 * the raw option returns something subtly different from what Site Kit itself sees.
 */
final class Module_Repository {

	/**
	 * Settings keys redacted from every module settings read.
	 *
	 * Site Kit stores the ad-blocking recovery snippets and the Sign in with Google
	 * client secret alongside ordinary configuration. Neither is needed to answer a
	 * question about how the site is set up, and both are credentials.
	 */
	private const REDACTED_KEYS = array(
		'clientSecret',
		'client_secret',
		'adBlockingRecoverySetupStatus',
		'webTagRecoverySnippet',
	);

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Every module Site Kit knows about, with its state.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function list_modules() {
		$modules = Site_Kit_Context::modules();
		if ( null === $modules ) {
			return new WP_Error( 'site_kit_missing', __( 'Site Kit\'s module registry is unavailable.', 'acrossai-abilities-manager' ) );
		}

		try {
			$available = $modules->get_available_modules();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_module_unknown',
				sprintf(
					/* translators: %s: error message from Site Kit */
					__( 'Site Kit could not list its modules: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		$out = array();
		foreach ( $available as $slug => $module ) {
			$slug  = (string) $slug;
			$out[] = array(
				'slug'          => $slug,
				'name'          => self::prop( $module, 'name' ),
				'description'   => self::prop( $module, 'description' ),
				'homepage'      => self::prop( $module, 'homepage' ),
				'active'        => self::bool_call( $modules, 'is_module_active', $slug ),
				'connected'     => self::bool_call( $modules, 'is_module_connected', $slug ),
				// force_active modules cannot be switched off — Search Console is one —
				// so a caller knows not to offer it as a toggle.
				'force_active'  => (bool) self::prop( $module, 'force_active' ),
				'internal'      => (bool) self::prop( $module, 'internal' ),
				'shareable'     => self::bool_call( $modules, 'is_module_shareable', $slug ),
				'recoverable'   => self::bool_call( $modules, 'is_module_recoverable', $slug ),
				'depends_on'    => array_values( (array) self::prop( $module, 'depends_on' ) ),
				'owner_id'      => self::owner_id( $module ),
				'has_settings'  => method_exists( $module, 'get_settings' ),
			);
		}

		return $out;
	}

	/**
	 * Settings for one module, credentials removed.
	 *
	 * @param string $slug Module slug.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_module_settings( string $slug ) {
		$module = self::module( $slug );
		if ( is_wp_error( $module ) ) {
			return $module;
		}

		if ( ! method_exists( $module, 'get_settings' ) ) {
			return new WP_Error(
				'site_kit_no_settings',
				sprintf(
					/* translators: %s: Site Kit module slug */
					__( 'The Site Kit "%s" module stores no settings.', 'acrossai-abilities-manager' ),
					$slug
				)
			);
		}

		try {
			$settings = $module->get_settings()->get();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_request_failed',
				sprintf(
					/* translators: 1: module slug, 2: error message */
					__( 'Site Kit could not read the "%1$s" settings: %2$s', 'acrossai-abilities-manager' ),
					$slug,
					$e->getMessage()
				)
			);
		}

		return array(
			'slug'     => $slug,
			'settings' => self::redact( is_array( $settings ) ? $settings : array() ),
			'redacted' => self::REDACTED_KEYS,
		);
	}

	/**
	 * Activate or deactivate a module.
	 *
	 * @param string $slug   Module slug.
	 * @param bool   $active Desired state.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function set_module_state( string $slug, bool $active ) {
		$modules = Site_Kit_Context::modules();
		if ( null === $modules ) {
			return new WP_Error( 'site_kit_missing', __( 'Site Kit\'s module registry is unavailable.', 'acrossai-abilities-manager' ) );
		}

		if ( ! $modules->module_exists( $slug ) ) {
			return new WP_Error(
				'site_kit_module_unknown',
				sprintf(
					/* translators: %s: Site Kit module slug */
					__( 'This Site Kit version has no "%s" module. List what it does have with site-kit/list-modules.', 'acrossai-abilities-manager' ),
					$slug
				)
			);
		}

		$was = self::bool_call( $modules, 'is_module_active', $slug );

		// Already in the requested state: report it and touch nothing. Site Kit's own
		// activate_module() returns a WP_Error for an already-active module, which
		// would turn a no-op into a failure and break idempotency.
		if ( $was === $active ) {
			return array(
				'slug'      => $slug,
				'active'    => $was,
				'changed'   => false,
				'connected' => self::bool_call( $modules, 'is_module_connected', $slug ),
			);
		}

		try {
			$result = $active ? $modules->activate_module( $slug ) : $modules->deactivate_module( $slug );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_request_failed',
				sprintf(
					/* translators: 1: module slug, 2: error message */
					__( 'Site Kit could not change the "%1$s" module: %2$s', 'acrossai-abilities-manager' ),
					$slug,
					$e->getMessage()
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new WP_Error(
				'site_kit_request_failed',
				sprintf(
					/* translators: %s: Site Kit module slug */
					__( 'Site Kit refused to change the "%s" module and gave no reason. A force-active module cannot be deactivated.', 'acrossai-abilities-manager' ),
					$slug
				)
			);
		}

		return array(
			'slug'      => $slug,
			'active'    => $active,
			'changed'   => true,
			'connected' => self::bool_call( $modules, 'is_module_connected', $slug ),
		);
	}

	/**
	 * Dashboard sharing configuration.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_sharing_settings() {
		$modules = Site_Kit_Context::modules();
		if ( null === $modules ) {
			return new WP_Error( 'site_kit_missing', __( 'Site Kit\'s module registry is unavailable.', 'acrossai-abilities-manager' ) );
		}

		try {
			$settings   = $modules->get_module_sharing_settings()->get();
			$shareable  = array_keys( $modules->get_shareable_modules() );
			$shared     = $modules->list_shared_modules();
			$owners     = $modules->get_shareable_modules_owners();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_request_failed',
				sprintf(
					/* translators: %s: error message from Site Kit */
					__( 'Site Kit could not read its dashboard sharing settings: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				)
			);
		}

		return array(
			'per_module'       => is_array( $settings ) ? $settings : array(),
			'shareable_slugs'  => array_values( (array) $shareable ),
			'shared_slugs'     => array_values( (array) $shared ),
			'owners_by_module' => is_array( $owners ) ? $owners : array(),
		);
	}

	/**
	 * The GET datapoints one module exposes.
	 *
	 * @param string $slug Module slug.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function list_datapoints( string $slug ) {
		$module = self::module( $slug );
		if ( is_wp_error( $module ) ) {
			return $module;
		}

		try {
			$datapoints = $module->get_datapoints();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_request_failed',
				sprintf(
					/* translators: 1: module slug, 2: error message */
					__( 'Site Kit could not list the "%1$s" datapoints: %2$s', 'acrossai-abilities-manager' ),
					$slug,
					$e->getMessage()
				)
			);
		}

		return array(
			'slug'       => $slug,
			'datapoints' => array_values( (array) $datapoints ),
		);
	}

	/**
	 * Resolve a module instance.
	 *
	 * @param string $slug Module slug.
	 * @return object|WP_Error
	 */
	public static function module( string $slug ) {
		$modules = Site_Kit_Context::modules();
		if ( null === $modules ) {
			return new WP_Error( 'site_kit_missing', __( 'Site Kit\'s module registry is unavailable.', 'acrossai-abilities-manager' ) );
		}

		try {
			$module = $modules->get_module( $slug );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_module_unknown',
				sprintf(
					/* translators: 1: module slug, 2: error message */
					__( 'This Site Kit version has no "%1$s" module (%2$s). List what it does have with site-kit/list-modules.', 'acrossai-abilities-manager' ),
					$slug,
					$e->getMessage()
				)
			);
		}

		if ( is_wp_error( $module ) ) {
			return $module;
		}

		return $module;
	}

	/**
	 * Drop credential-bearing keys from a settings blob.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	private static function redact( array $settings ): array {
		foreach ( self::REDACTED_KEYS as $key ) {
			unset( $settings[ $key ] );
		}
		return $settings;
	}

	/**
	 * Read a Module info property through its magic getter.
	 *
	 * Module::__get() throws for an unknown key rather than warning, so every read is
	 * guarded — the info array's shape has changed between Site Kit versions.
	 *
	 * @param object $module Module instance.
	 * @param string $key    Property name.
	 * @return mixed
	 */
	private static function prop( object $module, string $key ) {
		try {
			return isset( $module->$key ) ? $module->$key : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Owner id for a module, or null when it tracks no owner.
	 *
	 * @param object $module Module instance.
	 * @return int|null
	 */
	private static function owner_id( object $module ): ?int {
		if ( ! method_exists( $module, 'get_owner_id' ) ) {
			return null;
		}
		try {
			$id = (int) $module->get_owner_id();
		} catch ( \Throwable $e ) {
			return null;
		}
		return $id > 0 ? $id : null;
	}

	/**
	 * Call a Modules predicate without letting it fatal.
	 *
	 * @param object $modules Modules registry.
	 * @param string $method  Method name.
	 * @param string $slug    Module slug.
	 * @return bool
	 */
	private static function bool_call( object $modules, string $method, string $slug ): bool {
		if ( ! method_exists( $modules, $method ) ) {
			return false;
		}
		try {
			return (bool) $modules->$method( $slug );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
