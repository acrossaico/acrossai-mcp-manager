<?php
/**
 * Static validation helpers for the Abilities module.
 *
 * All methods are stateless and return true on pass or a WP_Error on failure.
 * JSON payload size/depth limits match the 64 KB DB-layer guard (DEC-JSON-SIZE-GUARD).
 *
 * php_code validation:
 *   - token_get_all() syntax check.
 *   - Blocked function scan (PD-002 hardening list).
 *   - 64 KB size limit.
 *
 * wp_remote_post validation:
 *   - `url` field required and must pass FILTER_VALIDATE_URL + https:// only.
 *   - Optional `timeout` must be integer.
 *   - `headers` key is rejected (no caller header propagation, PD-002).
 *   - Unknown keys are rejected.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Static validators for ability fields.
 *
 * @since 0.1.0
 */
class AcrossAI_Abilities_Validator {

	/**
	 * Maximum allowed length for an ability slug (characters).
	 *
	 * @var int
	 */
	const SLUG_MAX_LENGTH = 255;

	/**
	 * Maximum allowed length for a label (characters).
	 *
	 * @var int
	 */
	const LABEL_MAX_LENGTH = 255;

	/**
	 * Maximum allowed length for a category slug (characters).
	 *
	 * @var int
	 */
	const CATEGORY_MAX_LENGTH = 100;

	/**
	 * The maximum allowed length for a description (characters).
	 *
	 * @var int
	 */
	const DESCRIPTION_MAX_LENGTH = 1000;

	/**
	 * Maximum JSON payload size in bytes (64 KB).
	 *
	 * @var int
	 */
	const JSON_MAX_BYTES = 65536;

	/**
	 * Maximum JSON nesting depth for schema payloads.
	 *
	 * @var int
	 */
	const JSON_MAX_DEPTH = 10;

	/**
	 * Allowed callback_type values.
	 *
	 * @var string[]
	 */
	const CALLBACK_TYPES = array( 'noop', 'filter_hook', 'wp_remote_post', 'registered_callback' );

	/**
	 * Allowed status values.
	 *
	 * @var string[]
	 */
	const STATUS_VALUES = array( 'draft', 'publish' );

	/**
	 * Allowed source values.
	 *
	 * @var string[]
	 */
	const SOURCE_VALUES = array( 'db', 'plugin', 'theme', 'core' );

	/**
	 * Allowed mcp_type values.
	 *
	 * @var string[]
	 */
	const MCP_TYPES = array( 'tool', 'resource', 'prompt' );

	/**
	 * Allowed keys in filter_hook callback_config.
	 *
	 * @var string[]
	 */
	const FILTER_HOOK_CONFIG_KEYS = array( 'hook_name' );

	/**
	 * Allowed keys in wp_remote_post callback_config.
	 *
	 * @var string[]
	 */
	const WP_REMOTE_POST_CONFIG_KEYS = array( 'url', 'timeout' );

	// -------------------------------------------------------------------------
	// Field validators
	// -------------------------------------------------------------------------

	/**
	 * Validate a full ability slug (must include `acrossai/` prefix for db rows).
	 *
	 * @since  0.1.0
	 * @param  string $slug Slug to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_slug( string $slug ) {
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_slug', __( 'Ability slug cannot be empty.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $slug ) > self::SLUG_MAX_LENGTH ) {
			return new \WP_Error( 'invalid_slug', __( 'Ability slug must not exceed 255 characters.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[a-zA-Z0-9\-_\/]+$/', $slug ) ) {
			return new \WP_Error( 'invalid_slug', __( 'Ability slug contains invalid characters.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( substr_count( $slug, '/' ) > 1 ) {
			return new \WP_Error( 'invalid_slug', __( 'Ability slug must have exactly one namespace separator.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Validate a slug suffix (user-supplied part, without the prefix).
	 *
	 * @since  0.1.0
	 * @param  string $suffix Suffix to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_slug_suffix( string $suffix ) {
		if ( '' === $suffix ) {
			return new \WP_Error( 'invalid_slug', __( 'Ability slug suffix cannot be empty.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		// Suffix must not contain a forward slash.
		if ( strpos( $suffix, '/' ) !== false ) {
			return new \WP_Error( 'invalid_slug', __( 'Slug suffix must not contain a namespace separator.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[a-zA-Z0-9\-_]+$/', $suffix ) ) {
			return new \WP_Error( 'invalid_slug', __( 'Ability slug suffix contains invalid characters.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		// Total slug with prefix must not exceed 255 chars.
		$full = 'acrossai/' . $suffix;
		if ( mb_strlen( $full ) > self::SLUG_MAX_LENGTH ) {
			return new \WP_Error( 'invalid_slug', __( 'Ability slug suffix produces a slug that exceeds the maximum length.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Validate a display label.
	 *
	 * @since  0.1.0
	 * @param  mixed $label Value to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_label( $label ) {
		if ( null === $label ) {
			return true; // nullable — override rows carry no label.
		}
		if ( ! is_string( $label ) ) {
			return new \WP_Error( 'invalid_label', __( 'Ability label must be a string.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( '' === trim( $label ) ) {
			return new \WP_Error( 'invalid_label', __( 'Ability label cannot be empty.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $label ) > self::LABEL_MAX_LENGTH ) {
			return new \WP_Error( 'invalid_label', __( 'Ability label must not exceed 255 characters.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Validate a category slug.
	 *
	 * @since  0.1.0
	 * @param  mixed $category Value to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_category( $category ) {
		if ( null === $category ) {
			return true;
		}
		if ( ! is_string( $category ) ) {
			return new \WP_Error( 'invalid_category', __( 'Ability category must be a string.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( '' === trim( $category ) ) {
			return new \WP_Error( 'invalid_category', __( 'Ability category cannot be empty.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $category ) > self::CATEGORY_MAX_LENGTH ) {
			return new \WP_Error( 'invalid_category', __( 'Ability category must not exceed 100 characters.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/^[a-zA-Z0-9\-_]+$/', $category ) ) {
			return new \WP_Error( 'invalid_category', __( 'Ability category contains invalid characters.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Validate a description string.
	 *
	 * The description is optional on update (null passes) but required on create.
	 * An empty or whitespace-only string is rejected. Maximum length is enforced via
	 * DESCRIPTION_MAX_LENGTH.
	 *
	 * @since  0.1.0
	 * @param  mixed $description Value to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_description( $description ) {
		if ( null === $description ) {
			return true; // nullable — PATCH flows omit description when not updating it.
		}
		if ( ! is_string( $description ) ) {
			return new \WP_Error( 'invalid_description', __( 'Ability description must be a string.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( '' === trim( $description ) ) {
			return new \WP_Error( 'invalid_description', __( 'Ability description cannot be empty.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $description ) > self::DESCRIPTION_MAX_LENGTH ) {
			return new \WP_Error( 'invalid_description', __( 'Ability description must not exceed 1000 characters.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Validate a status value.
	 *
	 * @since  0.1.0
	 * @param  mixed $status Value to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_status( $status ) {
		if ( null === $status ) {
			return true;
		}
		if ( ! in_array( $status, self::STATUS_VALUES, true ) ) {
			return new \WP_Error(
				'invalid_status',
				/* translators: %s: allowed values */
				sprintf( __( 'Ability status must be one of: %s.', 'acrossai-abilities-manager' ), implode( ', ', self::STATUS_VALUES ) ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Validate a source value.
	 *
	 * @since  0.1.0
	 * @param  mixed $source Value to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_source( $source ) {
		if ( null === $source ) {
			return true;
		}
		if ( ! in_array( $source, self::SOURCE_VALUES, true ) ) {
			return new \WP_Error(
				'invalid_source',
				/* translators: %s: allowed values */
				sprintf( __( 'Ability source must be one of: %s.', 'acrossai-abilities-manager' ), implode( ', ', self::SOURCE_VALUES ) ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Validate a callback_type value.
	 *
	 * @since  0.1.0
	 * @param  mixed $callback_type Value to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_callback_type( $callback_type ) {
		if ( null === $callback_type ) {
			return true;
		}
		if ( ! in_array( $callback_type, self::CALLBACK_TYPES, true ) ) {
			return new \WP_Error(
				'invalid_callback_type',
				/* translators: %s: allowed values */
				sprintf( __( 'Callback type must be one of: %s.', 'acrossai-abilities-manager' ), implode( ', ', self::CALLBACK_TYPES ) ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Validate a mcp_type value.
	 *
	 * @since  0.1.0
	 * @param  mixed $mcp_type Value to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_mcp_type( $mcp_type ) {
		if ( null === $mcp_type ) {
			return true;
		}
		if ( ! in_array( $mcp_type, self::MCP_TYPES, true ) ) {
			return new \WP_Error(
				'invalid_mcp_type',
				/* translators: %s: allowed values */
				sprintf( __( 'MCP type must be one of: %s.', 'acrossai-abilities-manager' ), implode( ', ', self::MCP_TYPES ) ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Validate a callback_config array for the given callback_type.
	 *
	 * Rejects unknown keys (PD-002: reject-unknown-key rule).
	 *
	 * @since  0.1.0
	 * @param  string     $callback_type Type that governs config shape.
	 * @param  array|null $config        Config array to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_callback_config( string $callback_type, $config ) {
		if ( 'noop' === $callback_type ) {
			return true; // noop has no required config.
		}

		if ( null === $config || ! is_array( $config ) ) {
			return new \WP_Error( 'invalid_callback_config', __( 'Callback configuration must be an object.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}

		// Size guard.
		$encoded = wp_json_encode( $config );
		if ( false === $encoded || strlen( $encoded ) > self::JSON_MAX_BYTES ) {
			return new \WP_Error( 'invalid_callback_config', __( 'Callback configuration exceeds maximum allowed size.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}

		switch ( $callback_type ) {
			case 'filter_hook':
				return self::validate_filter_hook_config( $config );
			case 'wp_remote_post':
				return self::validate_wp_remote_post_config( $config );
			case 'registered_callback':
				return self::validate_registered_callback_config( $config );
		}

		return true;
	}

	/**
	 * Validate a JSON Schema payload (input_schema or output_schema).
	 *
	 * @since  0.1.0
	 * @param  mixed $schema Value to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_schema( $schema ) {
		if ( null === $schema ) {
			return true;
		}
		if ( ! is_array( $schema ) ) {
			return new \WP_Error( 'invalid_schema', __( 'Schema must be a JSON object.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		// Size guard.
		$encoded = wp_json_encode( $schema );
		if ( false === $encoded || strlen( $encoded ) > self::JSON_MAX_BYTES ) {
			return new \WP_Error( 'invalid_schema', __( 'Schema exceeds maximum allowed size.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		// Depth guard.
		if ( self::array_depth( $schema ) > self::JSON_MAX_DEPTH ) {
			return new \WP_Error( 'invalid_schema', __( 'Schema nesting depth exceeds the maximum allowed.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Aggregate validator for a full create/update payload.
	 *
	 * Returns a WP_Error on the first invalid field, or true if all valid.
	 *
	 * @since  0.1.0
	 * @param  array $fields       Field values to validate.
	 * @param  bool  $is_create    True for INSERT, false for UPDATE.
	 * @return true|\WP_Error
	 */
	public static function validate_ability( array $fields, bool $is_create = false ) {
		// slug_suffix is required on create.
		if ( $is_create ) {
			if ( empty( $fields['slug_suffix'] ) ) {
				return new \WP_Error( 'invalid_slug', __( 'Slug suffix is required when creating an ability.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
			}
			$result = self::validate_slug_suffix( (string) $fields['slug_suffix'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$simple_validators = array(
			'label'         => array( self::class, 'validate_label' ),
			'description'   => array( self::class, 'validate_description' ),
			'category'      => array( self::class, 'validate_category' ),
			'status'        => array( self::class, 'validate_status' ),
			'source'        => array( self::class, 'validate_source' ),
			'callback_type' => array( self::class, 'validate_callback_type' ),
			'mcp_type'      => array( self::class, 'validate_mcp_type' ),
			'input_schema'  => array( self::class, 'validate_schema' ),
			'output_schema' => array( self::class, 'validate_schema' ),
		);

		foreach ( $simple_validators as $field => $validator ) {
			if ( array_key_exists( $field, $fields ) ) {
				$result = call_user_func( $validator, $fields[ $field ] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		// callback_config depends on callback_type.
		if ( array_key_exists( 'callback_config', $fields ) ) {
			$type   = (string) ( $fields['callback_type'] ?? 'noop' );
			$result = self::validate_callback_config( $type, $fields['callback_config'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Internal helpers — config validators
	// -------------------------------------------------------------------------

	/**
	 * Validate filter_hook callback_config.
	 *
	 * @since  0.1.0
	 * @param  array $config Config array.
	 * @return true|\WP_Error
	 */
	private static function validate_filter_hook_config( array $config ) {
		// Reject unknown keys.
		$unknown = array_diff( array_keys( $config ), self::FILTER_HOOK_CONFIG_KEYS );
		if ( ! empty( $unknown ) ) {
			return new \WP_Error( 'invalid_callback_config', __( 'filter_hook config contains unknown keys.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( empty( $config['hook_name'] ) || ! is_string( $config['hook_name'] ) ) {
			return new \WP_Error( 'invalid_callback_config', __( 'filter_hook config requires a non-empty hook_name string.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Validate wp_remote_post callback_config (PD-002 hardening rules).
	 *
	 * @since  0.1.0
	 * @param  array $config Config array.
	 * @return true|\WP_Error
	 */
	private static function validate_wp_remote_post_config( array $config ) {
		// Reject unknown keys (e.g. 'headers' is explicitly disallowed — PD-002).
		$unknown = array_diff( array_keys( $config ), self::WP_REMOTE_POST_CONFIG_KEYS );
		if ( ! empty( $unknown ) ) {
			return new \WP_Error(
				'invalid_callback_config',
				/* translators: %s: comma-separated unknown key names */
				sprintf( __( 'wp_remote_post config contains unknown or disallowed keys: %s.', 'acrossai-abilities-manager' ), implode( ', ', $unknown ) ),
				array( 'status' => 400 )
			);
		}
		if ( empty( $config['url'] ) || ! is_string( $config['url'] ) ) {
			return new \WP_Error( 'invalid_callback_config', __( 'wp_remote_post config requires a non-empty url string.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		// HTTPS-only (PD-002).
		if ( false === filter_var( $config['url'], FILTER_VALIDATE_URL ) || strpos( $config['url'], 'https://' ) !== 0 ) {
			return new \WP_Error( 'invalid_callback_config', __( 'wp_remote_post url must be a valid https:// URL.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( isset( $config['timeout'] ) && ! is_int( $config['timeout'] ) ) {
			return new \WP_Error( 'invalid_callback_config', __( 'wp_remote_post timeout must be an integer.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Validate registered_callback callback_config.
	 *
	 * @since  0.1.0
	 * @param  array $config Config array.
	 * @return true|\WP_Error
	 */
	private static function validate_registered_callback_config( array $config ) {
		$allowed_keys = array( 'callback' );
		$unknown      = array_diff( array_keys( $config ), $allowed_keys );
		if ( ! empty( $unknown ) ) {
			return new \WP_Error( 'invalid_callback_config', __( 'registered_callback config contains unknown keys.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		if ( empty( $config['callback'] ) || ! is_string( $config['callback'] ) ) {
			return new \WP_Error( 'invalid_callback_config', __( 'registered_callback config requires a non-empty callback key string.', 'acrossai-abilities-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Internal helpers — utilities
	// -------------------------------------------------------------------------

	/**
	 * Calculate the nesting depth of an array.
	 *
	 * @since  0.1.0
	 * @param  array $items Input array.
	 * @return int
	 */
	private static function array_depth( array $items ): int {
		$max_depth = 1;
		foreach ( $items as $value ) {
			if ( is_array( $value ) ) {
				$depth     = self::array_depth( $value ) + 1;
				$max_depth = max( $max_depth, $depth );
			}
		}
		return $max_depth;
	}
}
