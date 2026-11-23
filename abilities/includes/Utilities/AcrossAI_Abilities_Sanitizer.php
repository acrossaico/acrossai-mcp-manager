<?php
/**
 * Static sanitization helpers for the Abilities module.
 *
 * Delegates to AcrossAI_Sanitizer for shared sanitizers (tri-state, mcp_type)
 * rather than duplicating them (DRY / Principle VI).
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Static sanitization helpers for ability fields.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Sanitizer {

	/**
	 * Sanitize a slug suffix (user-supplied part before prefix injection).
	 *
	 * @since  0.1.0
	 * @param  string $suffix Raw value.
	 * @return string
	 */
	public static function sanitize_slug_suffix( string $suffix ): string {
		$suffix = sanitize_text_field( $suffix );
		$suffix = preg_replace( '/[^a-zA-Z0-9\-_]/', '', $suffix );
		return substr( $suffix, 0, 246 ); // 255 - len('acrossai/') = 246.
	}

	/**
	 * Sanitize a full ability slug.
	 *
	 * Reuses AcrossAI_Sanitizer::sanitize_ability_slug() — single source of truth (DRY).
	 *
	 * @since  0.1.0
	 * @param  string $slug Raw value.
	 * @return string
	 */
	public static function sanitize_slug( string $slug ): string {
		return AcrossAI_Sanitizer::sanitize_ability_slug( $slug );
	}

	/**
	 * Sanitize a display label.
	 *
	 * @since  0.1.0
	 * @param  mixed $label Raw value.
	 * @return string|null
	 */
	public static function sanitize_label( $label ): ?string {
		if ( null === $label ) {
			return null;
		}
		$clean = sanitize_text_field( (string) $label );
		return '' === $clean ? null : substr( $clean, 0, 255 );
	}

	/**
	 * Sanitize a description (may contain basic formatting).
	 *
	 * @since  0.1.0
	 * @param  mixed $description Raw value.
	 * @return string|null
	 */
	public static function sanitize_description( $description ): ?string {
		if ( null === $description ) {
			return null;
		}
		$clean = wp_kses_post( (string) $description );
		return '' === $clean ? null : $clean;
	}

	/**
	 * Sanitize a category slug.
	 *
	 * @since  0.1.0
	 * @param  mixed $category Raw value.
	 * @return string|null
	 */
	public static function sanitize_category( $category ): ?string {
		if ( null === $category ) {
			return null;
		}
		$clean = sanitize_key( (string) $category );
		return '' === $clean ? null : substr( $clean, 0, 100 );
	}

	/**
	 * Sanitize a status value.
	 *
	 * @since  0.1.0
	 * @param  mixed $status Raw value.
	 * @return string|null
	 */
	public static function sanitize_status( $status ): ?string {
		if ( null === $status ) {
			return null;
		}
		$clean = sanitize_key( (string) $status );
		return in_array( $clean, array( 'draft', 'publish' ), true ) ? $clean : null;
	}

	/**
	 * Sanitize a source value.
	 *
	 * @since  0.1.0
	 * @param  mixed $source Raw value.
	 * @return string|null
	 */
	public static function sanitize_source( $source ): ?string {
		if ( null === $source ) {
			return null;
		}
		$clean = sanitize_key( (string) $source );
		return in_array( $clean, array( 'db', 'plugin', 'theme', 'core' ), true ) ? $clean : null;
	}

	/**
	 * Sanitize a provider string.
	 *
	 * @since  0.1.0
	 * @param  mixed $provider Raw value.
	 * @return string|null
	 */
	public static function sanitize_provider( $provider ): ?string {
		if ( null === $provider ) {
			return null;
		}
		$clean = sanitize_text_field( (string) $provider );
		return '' === $clean ? null : substr( $clean, 0, 100 );
	}

	/**
	 * Sanitize a callback_type value.
	 *
	 * @since  0.1.0
	 * @param  mixed $callback_type Raw value.
	 * @return string|null
	 */
	public static function sanitize_callback_type( $callback_type ): ?string {
		if ( null === $callback_type ) {
			return null;
		}
		$clean = sanitize_key( (string) $callback_type );
		return in_array( $clean, array( 'noop', 'filter_hook', 'wp_remote_post', 'registered_callback' ), true ) ? $clean : null;
	}

	/**
	 * Sanitize a callback_config array.
	 *
	 * Performs mode-specific key sanitization. Unknown keys are stripped.
	 *
	 * @since  0.1.0
	 * @param  string $callback_type The callback type that governs config shape.
	 * @param  mixed  $config        Raw config value.
	 * @return array|null
	 */
	public static function sanitize_callback_config( string $callback_type, $config ): ?array {
		if ( 'noop' === $callback_type ) {
			return null;
		}
		if ( ! is_array( $config ) ) {
			return null;
		}
		switch ( $callback_type ) {
			case 'filter_hook':
				$clean = array();
				if ( isset( $config['hook_name'] ) ) {
					$clean['hook_name'] = sanitize_key( (string) $config['hook_name'] );
				}
				return empty( $clean['hook_name'] ) ? null : $clean;

			case 'wp_remote_post':
				$clean = array();
				if ( isset( $config['url'] ) ) {
					$clean['url'] = esc_url_raw( (string) $config['url'] );
				}
				if ( isset( $config['timeout'] ) ) {
					$clean['timeout'] = min( 30, max( 1, (int) $config['timeout'] ) );
				}
				return empty( $clean['url'] ) ? null : $clean;

			case 'registered_callback':
				$clean = array();
				if ( isset( $config['callback'] ) ) {
					$clean['callback'] = sanitize_key( (string) $config['callback'] );
				}
				return ! empty( $clean['callback'] ) ? $clean : null;
		}

		return null;
	}



	/**
	 * Sanitize a JSON Schema payload.
	 *
	 * Returns null for non-array input; does not perform deep key sanitization
	 * since JSON Schema is structured data with arbitrary key shapes.
	 *
	 * @since  0.1.0
	 * @param  mixed $schema Raw value.
	 * @return array|null
	 */
	public static function sanitize_schema( $schema ): ?array {
		if ( ! is_array( $schema ) ) {
			return null;
		}
		return $schema; // structure preserved; size/depth validated upstream.
	}

	/**
	 * Sanitize MCP type. Delegates to shared utility.
	 *
	 * @since  0.1.0
	 * @param  mixed $value Raw value.
	 * @return string|null
	 */
	public static function sanitize_mcp_type( $value ): ?string {
		return AcrossAI_Sanitizer::sanitize_mcp_type( $value );
	}

	/**
	 * Sanitize a tri-state field. Delegates to shared utility.
	 *
	 * @since  0.1.0
	 * @param  mixed $value Raw value.
	 * @return bool|null
	 */
	public static function sanitize_tri_state( $value ): ?bool {
		return AcrossAI_Sanitizer::sanitize_tri_state( $value );
	}

	/**
	 * Build a sanitized field map from a REST request for a CREATE operation.
	 *
	 * Applies field-specific sanitizers to every submitted parameter.
	 * Only fields present in the request (has_param check) are included.
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request Incoming REST request.
	 * @return array
	 */
	public static function sanitize_create_request( \WP_REST_Request $request ): array {
		$fields = array();

		// Required slug suffix — controller injects prefix.
		if ( $request->has_param( 'slug_suffix' ) ) {
			$fields['slug_suffix'] = self::sanitize_slug_suffix( (string) $request->get_param( 'slug_suffix' ) );
		}

		$text_fields = array( 'label', 'description', 'category', 'status', 'provider' );
		foreach ( $text_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$method           = 'sanitize_' . $field;
				$fields[ $field ] = method_exists( self::class, $method )
					? self::$method( $request->get_param( $field ) )
					: sanitize_text_field( (string) $request->get_param( $field ) );
			}
		}

		if ( $request->has_param( 'callback_type' ) ) {
			$fields['callback_type'] = self::sanitize_callback_type( $request->get_param( 'callback_type' ) );
		}
		if ( $request->has_param( 'callback_config' ) ) {
			$type                      = (string) ( $fields['callback_type'] ?? $request->get_param( 'callback_type' ) ?? 'noop' );
			$fields['callback_config'] = self::sanitize_callback_config( $type, $request->get_param( 'callback_config' ) );
		}
		if ( $request->has_param( 'input_schema' ) ) {
			$fields['input_schema'] = self::sanitize_schema( $request->get_param( 'input_schema' ) );
		}
		if ( $request->has_param( 'output_schema' ) ) {
			$fields['output_schema'] = self::sanitize_schema( $request->get_param( 'output_schema' ) );
		}

		$tri_state_fields = array( 'site_allowed', 'readonly', 'destructive', 'idempotent', 'show_in_rest', 'show_in_mcp' );
		foreach ( $tri_state_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$fields[ $field ] = self::sanitize_tri_state( $request->get_param( $field ) );
			}
		}

		if ( $request->has_param( 'mcp_type' ) ) {
			$fields['mcp_type'] = self::sanitize_mcp_type( $request->get_param( 'mcp_type' ) );
		}

		return $fields;
	}

	/**
	 * Build a sanitized field map from a REST request for an UPDATE operation.
	 *
	 * Same as create but never includes slug_suffix or source (server-controlled).
	 *
	 * @since  0.1.0
	 * @param  \WP_REST_Request $request Incoming REST request.
	 * @return array
	 */
	public static function sanitize_update_request( \WP_REST_Request $request ): array {
		$fields = self::sanitize_create_request( $request );
		// source and ability_slug are immutable — strip from updates.
		unset( $fields['slug_suffix'], $fields['source'], $fields['ability_slug'] );
		return $fields;
	}

	/**
	 * Strip protected identity/execution fields for source≠db rows.
	 *
	 * For plugin/theme/core rows the Write controller calls this to prevent
	 * overwriting identity, descriptive, and execution fields.
	 *
	 * @since  0.1.0
	 * @param  array $fields Sanitized update fields.
	 * @return array
	 */
	public static function strip_protected_fields_for_non_db( array $fields ): array {
		$protected = array(
			'input_schema',
			'output_schema',
			'status',
			'ability_slug',
			'slug_suffix',
			'source',
		);
		foreach ( $protected as $field ) {
			unset( $fields[ $field ] );
		}
		return $fields;
	}
}
