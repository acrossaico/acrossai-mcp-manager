<?php
/**
 * Feature 069 — the single typed write path into Rank Math's settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The ONLY place Option_Center::save_settings() is called.
 *
 * Centralising it is what makes the F2 guarantees hold: every write is validated
 * against Settings_Registry first, and every write carries an explicit per-field
 * type map, so no field can reach Rank Math's sanitizer untyped.
 *
 * Partial payloads are safe. Option_Center::get_changed_settings() is
 * array_merge( $current, $new ) despite its name
 * (class-option-center.php:557), so submitting only the fields we intend to
 * change leaves every other key untouched — no read-modify-write, no race.
 */
final class Settings_Writer {

	/**
	 * Option types Rank Math's save_settings() understands.
	 *
	 * Its internal $map has no default branch (class-option-center.php:419), so
	 * an unrecognised type would fatal on the argument spread. Never pass one.
	 */
	private const SAVEABLE_TYPES = array( 'general', 'titles', 'sitemap' );

	/**
	 * Option name for the Instant Indexing settings, which do NOT go through
	 * save_settings() — Rank Math special-cases them at
	 * includes/rest/class-admin.php:287-302.
	 */
	private const INSTANT_INDEXING_OPTION = 'rank-math-options-instant-indexing';

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Read a panel: its field specs plus current values.
	 *
	 * @param string $panel  Panel slug.
	 * @param string $object Post type or taxonomy name, for dynamic panels.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function read( string $panel, string $object = '' ) {
		$definition = Settings_Registry::panel( $panel );
		if ( null === $definition ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: panel slug */
					__( 'Unknown settings panel "%s".', 'acrossai-abilities-manager' ),
					$panel
				)
			);
		}
		if ( null !== $definition['dynamic'] && '' === $object ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: panel slug, 2: the kind of object required */
					__( 'Panel "%1$s" requires an object (%2$s).', 'acrossai-abilities-manager' ),
					$panel,
					$definition['dynamic']
				)
			);
		}

		$current = self::current_values( (string) $definition['option_type'] );
		$fields  = array();

		foreach ( Settings_Registry::fields_for( $panel, $object ) as $id => $spec ) {
			$legacy   = (string) $spec['type'];
			$emitted  = Settings_Registry::emitted_type( $legacy );
			$fields[] = array(
				'id'       => $id,
				'type'     => null === $emitted ? $legacy : $emitted,
				'enum'     => isset( $spec['enum'] ) ? array_values( (array) $spec['enum'] ) : null,
				'min'      => $spec['min'] ?? null,
				'max'      => $spec['max'] ?? null,
				'pattern'  => $spec['pattern'] ?? null,
				'readonly' => Settings_Registry::is_readonly_type( $legacy ),
				'current'  => $current[ $id ] ?? null,
			);
		}

		return array(
			'panel'       => $panel,
			'object'      => $object,
			'option_type' => $definition['option_type'],
			'source'      => $definition['source'],
			'fields'      => $fields,
		);
	}

	/**
	 * Validate and write a panel's settings.
	 *
	 * @param string              $panel  Panel slug.
	 * @param string              $object Post type or taxonomy name, for dynamic panels.
	 * @param array<string,mixed> $values Submitted values.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( string $panel, string $object, array $values ) {
		$definition = Settings_Registry::panel( $panel );
		if ( null === $definition ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: panel slug */
					__( 'Unknown settings panel "%s".', 'acrossai-abilities-manager' ),
					$panel
				)
			);
		}

		// 1. Validate. Rejects the whole payload on the first problem.
		$validated = Settings_Registry::validate( $panel, $object, $values );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// 2. Strip denied keys again. Belt and braces: save_settings() strips them
		// itself, but maybe_update_htaccess() and maybe_update_analytics() both
		// run BEFORE that strip (class-option-center.php:377-385), so merely
		// submitting one has side effects.
		foreach ( Settings_Registry::DENIED_KEYS as $denied ) {
			unset( $validated[ $denied ] );
		}
		if ( array() === $validated ) {
			return new WP_Error( 'invalid_input', __( 'No writable settings remained after validation.', 'acrossai-abilities-manager' ) );
		}

		// 3. Explicit per-field types. Never let Rank Math default to 'text'.
		$all_types   = Settings_Registry::field_types_for( $panel, $object );
		$field_types = array_intersect_key( $all_types, $validated );

		$option_type = (string) $definition['option_type'];

		if ( 'instant_indexing' === $option_type ) {
			return self::save_instant_indexing( $panel, $object, $validated );
		}

		if ( ! in_array( $option_type, self::SAVEABLE_TYPES, true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: option type */
					__( 'Option type "%s" cannot be written through Rank Math\'s settings API.', 'acrossai-abilities-manager' ),
					$option_type
				)
			);
		}

		if ( ! class_exists( '\RankMath\Admin\Option_Center' ) ) {
			return new WP_Error( 'rank_math_missing', __( 'Rank Math\'s settings API is unavailable.', 'acrossai-abilities-manager' ) );
		}

		// 4. $updated must be an array — check_updated_fields() does
		// in_array( $field_id, $updated, true ) and TypeErrors on null. Using the
		// submitted field ids is also what triggers the correct
		// rank_math/flush_fields rewrite flush.
		$updated = array_keys( $validated );

		// 5. Write. $is_reset stays false — true forces an unconditional rewrite
		// flush and is a reset semantic we deliberately do not expose.
		$result = \RankMath\Admin\Option_Center::save_settings( $option_type, $validated, $field_types, $updated, false );

		return self::result( $panel, $object, $option_type, $validated, $result );
	}

	/**
	 * Write the Instant Indexing option.
	 *
	 * The ONLY direct option write in this suite, and it matches what Rank Math
	 * itself does. This blob is not part of save_settings()'s $map, so there is no
	 * API to call: Rank Math writes it with a bare update_option() in
	 * Api::reset_key() (instant-indexing/class-api.php:379) and special-cases it in
	 * its own REST handler (includes/rest/class-admin.php:287-302). Going through
	 * save_settings() is not an option — its internal $map has no entry for this
	 * type and would fatal on the argument spread.
	 *
	 * Values are still validated and typed by Settings_Registry first, and are
	 * merged rather than replacing the blob, so a partial payload behaves exactly
	 * like the save_settings() path.
	 *
	 * @param string              $panel     Panel slug.
	 * @param string              $object    Object name.
	 * @param array<string,mixed> $validated Validated values.
	 * @return array<string,mixed>
	 */
	private static function save_instant_indexing( string $panel, string $object, array $validated ): array {
		$current = get_option( self::INSTANT_INDEXING_OPTION );
		$current = is_array( $current ) ? $current : array();

		update_option( self::INSTANT_INDEXING_OPTION, array_merge( $current, $validated ), false );

		return self::result(
			$panel,
			$object,
			'instant_indexing',
			$validated,
			array( 'notifications' => array(), 'settings' => array_merge( $current, $validated ) )
		);
	}

	/**
	 * Build the write result: only the fields we touched, plus Rank Math's own
	 * notifications.
	 *
	 * Returning only the touched fields is deliberate — an ability must not leak
	 * unrelated settings from the same option blob.
	 *
	 * @param string              $panel       Panel slug.
	 * @param string              $object      Object name.
	 * @param string              $option_type Option type written.
	 * @param array<string,mixed> $validated   Values we submitted.
	 * @param mixed               $result      Return value from Rank Math.
	 * @return array<string,mixed>
	 */
	private static function result( string $panel, string $object, string $option_type, array $validated, $result ): array {
		$stored        = is_array( $result ) && isset( $result['settings'] ) && is_array( $result['settings'] ) ? $result['settings'] : array();
		$notifications = is_array( $result ) && isset( $result['notifications'] ) && is_array( $result['notifications'] ) ? $result['notifications'] : array();

		$updated = array();
		foreach ( array_keys( $validated ) as $id ) {
			// Prefer the value Rank Math actually stored, so the caller sees the
			// post-sanitization truth rather than what was submitted.
			$updated[ $id ] = array_key_exists( $id, $stored ) ? $stored[ $id ] : $validated[ $id ];
		}

		return array(
			'panel'         => $panel,
			'object'        => $object,
			'option_type'   => $option_type,
			'updated'       => $updated,
			'notifications' => array_values( $notifications ),
		);
	}

	/**
	 * Current values for an option type.
	 *
	 * @param string $option_type One of general|titles|sitemap|instant_indexing.
	 * @return array<string,mixed>
	 */
	private static function current_values( string $option_type ): array {
		if ( 'instant_indexing' === $option_type ) {
			$stored = get_option( self::INSTANT_INDEXING_OPTION );
			return is_array( $stored ) ? $stored : array();
		}
		if ( ! class_exists( '\RankMath\Helper' ) ) {
			return array();
		}
		$settings = \RankMath\Helper::get_settings( $option_type );
		return is_array( $settings ) ? $settings : array();
	}
}
