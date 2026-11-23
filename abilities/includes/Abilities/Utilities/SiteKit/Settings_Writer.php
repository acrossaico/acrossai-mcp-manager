<?php
/**
 * Feature 120 — writes to Site Kit's module settings.
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
 * Static-only writer for module settings.
 *
 * Writes go through Site Kit's own Module_Settings::merge(), which is what its REST
 * route uses (REST_Modules_Controller line 526). merge() is safe by construction —
 * array_intersect_key against the existing settings means an unknown key cannot
 * introduce itself — and the value passes through each module's registered
 * sanitize_callback on the way to the option.
 *
 * merge() drops unknown keys SILENTLY, though, which is the wrong answer for an
 * ability: a caller misspelling propertyId would be told the write succeeded and see
 * nothing change. So the keys are compared against the stored settings first and the
 * ignored ones are named in the response.
 */
final class Settings_Writer {

	/**
	 * Keys this ability refuses to write.
	 *
	 * ownerID is the important one. Site Kit assigns it itself, and it decides whose
	 * Google credentials serve this module's data to everyone reading a shared
	 * dashboard — writing it by hand would hand that over to an arbitrary user id
	 * without their account being involved at all.
	 *
	 * The credential keys are refused because they are also redacted on read: an
	 * ability that will not show you a secret should not let you set one either.
	 */
	private const PROTECTED_KEYS = array(
		'ownerID',
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
	 * Merge a partial settings array into one module's settings.
	 *
	 * @param string              $slug     Module slug.
	 * @param array<string,mixed> $settings Partial settings to apply.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( string $slug, array $settings ) {
		if ( array() === $settings ) {
			return new WP_Error( 'invalid_input', __( 'settings is empty. Nothing to write.', 'acrossai-abilities-manager' ) );
		}

		$protected = array_values( array_intersect( array_keys( $settings ), self::PROTECTED_KEYS ) );
		if ( array() !== $protected ) {
			return new WP_Error(
				'site_kit_protected_setting',
				sprintf(
					/* translators: %s: comma-separated list of refused setting keys */
					__( 'These settings cannot be written through this ability: %s. Site Kit assigns ownerID itself when a connection setting changes, and the credential keys are refused because they are also hidden on read. Nothing was changed.', 'acrossai-abilities-manager' ),
					implode( ', ', $protected )
				)
			);
		}

		$module = Module_Repository::module( $slug );
		if ( is_wp_error( $module ) ) {
			return $module;
		}

		if ( ! method_exists( $module, 'get_settings' ) ) {
			return new WP_Error(
				'site_kit_no_settings',
				sprintf(
					/* translators: %s: Site Kit module slug */
					__( 'The Site Kit "%s" module stores no settings, so there is nothing to write.', 'acrossai-abilities-manager' ),
					$slug
				)
			);
		}

		try {
			$store  = $module->get_settings();
			$before = $store->get();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_request_failed',
				sprintf(
					/* translators: 1: module slug, 2: error message */
					__( 'Site Kit could not read the "%1$s" settings before writing: %2$s', 'acrossai-abilities-manager' ),
					$slug,
					$e->getMessage()
				)
			);
		}

		$before = is_array( $before ) ? $before : array();

		// merge() would drop these without a word. Name them instead.
		$ignored = array_values( array_diff( array_keys( $settings ), array_keys( $before ) ) );
		$known   = array_intersect_key( $settings, $before );

		if ( array() === $known ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: module slug, 2: comma-separated list of valid keys */
					__( 'None of those keys exist on the Site Kit "%1$s" module, so nothing would have changed. Writable keys are: %2$s. Read the current values with site-kit/get-module-settings.', 'acrossai-abilities-manager' ),
					$slug,
					implode( ', ', self::writable_keys( $before ) )
				)
			);
		}

		$owner_before = self::owner_id( $module );

		try {
			$store->merge( $known );
			$after = $store->get();
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_request_failed',
				sprintf(
					/* translators: 1: module slug, 2: error message */
					__( 'Site Kit refused the write to the "%1$s" module: %2$s', 'acrossai-abilities-manager' ),
					$slug,
					$e->getMessage()
				)
			);
		}

		$after = is_array( $after ) ? $after : array();

		// Reported from a re-read rather than from the input: each module runs its own
		// sanitize_callback, so what was asked for and what was stored can differ.
		$changed   = array();
		$unchanged = array();
		foreach ( array_keys( $known ) as $key ) {
			$was = $before[ $key ] ?? null;
			$now = $after[ $key ] ?? null;

			if ( $was === $now ) {
				$unchanged[] = (string) $key;
				continue;
			}

			// A LIST, not a map keyed by setting name. An empty PHP map encodes as []
			// while a populated one encodes as {}, so a client would see the type of
			// this field change depending on whether anything happened.
			$changed[] = array(
				'setting' => (string) $key,
				'from'    => $was,
				'to'      => $now,
			);
		}

		$owner_after = self::owner_id( $module );

		return array(
			'slug'          => $slug,
			'changed'       => $changed,
			'unchanged'     => $unchanged,
			'ignored'       => $ignored,
			// Site Kit reassigns ownership whenever a connection setting changes, via a
			// pre_update_option filter. It is silent, and it decides whose credentials
			// serve shared dashboards, so it is surfaced rather than left to be noticed.
			'owner_changed' => $owner_before !== $owner_after,
			'owner_id'      => $owner_after,
			'settings'      => self::redact( $after ),
		);
	}

	/**
	 * The keys a caller may write on a module, given its stored settings.
	 *
	 * @param array<string,mixed> $settings Stored settings.
	 * @return string[]
	 */
	public static function writable_keys( array $settings ): array {
		return array_values( array_diff( array_keys( $settings ), self::PROTECTED_KEYS ) );
	}

	/**
	 * Keys this writer refuses.
	 *
	 * @return string[]
	 */
	public static function protected_keys(): array {
		return self::PROTECTED_KEYS;
	}

	/**
	 * Drop credential-bearing keys from a settings blob.
	 *
	 * @param array<string,mixed> $settings Raw settings.
	 * @return array<string,mixed>
	 */
	private static function redact( array $settings ): array {
		foreach ( self::PROTECTED_KEYS as $key ) {
			if ( 'ownerID' === $key ) {
				// ownerID is refused as a WRITE but is useful to read back: it is how a
				// caller sees that the write moved ownership.
				continue;
			}
			unset( $settings[ $key ] );
		}
		return $settings;
	}

	/**
	 * Owner id for a module, or null when it tracks none.
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
}
