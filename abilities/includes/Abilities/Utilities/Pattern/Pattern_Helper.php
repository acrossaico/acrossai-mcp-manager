<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Pattern
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Pattern;

defined( 'ABSPATH' ) || exit;

/**
 * File-based block-pattern utilities shared by every Block ability:
 *  - theme + plugin /patterns directory resolution
 *  - filename normalisation + path-traversal guards
 *  - PHP doc-block header parse / build
 *  - slug + content validation
 *
 * Spec: https://developer.wordpress.org/themes/patterns/introduction-to-patterns/
 */
final class Pattern_Helper {

	/**
	 * Headers recognised by core when scanning theme /patterns/*.php files.
	 * Order is preserved when generating new files.
	 *
	 * @var string[]
	 */
	private const HEADERS = array(
		'Title',
		'Slug',
		'Description',
		'Viewport Width',
		'Inserter',
		'Categories',
		'Keywords',
		'Block Types',
		'Post Types',
		'Template Types',
	);

	// -------------------------------------------------------------------------
	// Theme directory resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolves and validates a theme directory.
	 *
	 * @return string|\WP_Error Absolute theme directory path, or WP_Error on failure.
	 */
	public static function resolve_theme_dir( string $slug ) {
		$themes_dir = rtrim( get_theme_root(), '/' );
		$theme_dir  = '' !== $slug
			? realpath( $themes_dir . '/' . $slug )
			: realpath( get_stylesheet_directory() );

		if ( false === $theme_dir || 0 !== strpos( $theme_dir, $themes_dir ) || ! is_dir( $theme_dir ) ) {
			return new \WP_Error( 'theme_not_found', __( 'Theme directory not found.', 'acrossai-abilities-manager' ) );
		}

		return $theme_dir;
	}

	/**
	 * Returns the active child theme's absolute path, or null when the active
	 * theme is not a child theme (parent and child stylesheet are the same).
	 */
	public static function get_child_theme_dir(): ?string {
		if ( get_template() === get_stylesheet() ) {
			return null;
		}
		$path = realpath( get_stylesheet_directory() );
		return $path ? $path : null;
	}

	/**
	 * Get parent theme dir.
	 *
	 * @return string
	 */
	public static function get_parent_theme_dir(): string {
		return rtrim( get_template_directory(), '/' );
	}

	// -------------------------------------------------------------------------
	// Plugin directory resolution
	// -------------------------------------------------------------------------

	/**
	 * Returns the absolute /patterns directory for a plugin by its slug
	 * (the folder name under WP_PLUGIN_DIR). Null if not a real plugin.
	 *
	 * @return array{path: string, active: bool, plugin_file: string}|\WP_Error
	 */
	public static function resolve_plugin_dir( string $slug ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_plugin_slug', __( 'Plugin slug is required.', 'acrossai-abilities-manager' ) );
		}

		$plugins_dir = rtrim( WP_PLUGIN_DIR, '/' );
		$plugin_dir  = realpath( $plugins_dir . '/' . $slug );

		if ( false === $plugin_dir || 0 !== strpos( $plugin_dir, $plugins_dir ) || ! is_dir( $plugin_dir ) ) {
			return new \WP_Error( 'plugin_not_found', __( 'Plugin directory not found.', 'acrossai-abilities-manager' ) );
		}

		// Find this plugin's main bootstrap file so we can probe activation status.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_file = '';
		$active      = false;
		foreach ( array_keys( get_plugins() ) as $rel ) {
			if ( 0 === strpos( $rel, $slug . '/' ) ) {
				$plugin_file = $rel;
				$active      = is_plugin_active( $rel );
				break;
			}
		}

		return array(
			'path'        => $plugin_dir,
			'active'      => $active,
			'plugin_file' => $plugin_file,
		);
	}

	/**
	 * Returns every plugin slug that has a /patterns directory.
	 *
	 * @return array<int, array{slug:string, path:string, active:bool}>
	 */
	public static function scan_plugins_with_patterns(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$found = array();
		foreach ( array_keys( get_plugins() ) as $rel ) {
			$parts = explode( '/', $rel );
			$slug  = $parts[0] ?? '';
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;

			$patterns_dir = WP_PLUGIN_DIR . '/' . $slug . '/patterns';
			if ( ! is_dir( $patterns_dir ) ) {
				continue;
			}
			$found[] = array(
				'slug'   => $slug,
				'path'   => $patterns_dir,
				'active' => is_plugin_active( $rel ),
			);
		}
		return $found;
	}

	// -------------------------------------------------------------------------
	// Pattern file path within /patterns
	// -------------------------------------------------------------------------

	/**
	 * Resolves the absolute path to a pattern file inside the given
	 * container directory's /patterns dir, refusing path escapes.
	 *
	 * @return string|\WP_Error
	 */
	public static function resolve_pattern_path( string $container_dir, string $filename_or_slug ) {
		$filename = self::sanitize_filename( $filename_or_slug );
		if ( '' === $filename ) {
			return new \WP_Error( 'invalid_filename', __( 'Invalid pattern filename.', 'acrossai-abilities-manager' ) );
		}

		$patterns_dir = $container_dir . '/patterns';
		$abs_path     = $patterns_dir . '/' . $filename;

		$resolved_dir = realpath( $patterns_dir );
		if ( false !== $resolved_dir ) {
			$candidate = realpath( $abs_path );
			if ( false !== $candidate && 0 !== strpos( $candidate, $resolved_dir ) ) {
				return new \WP_Error( 'path_escape', __( 'Pattern path escapes the /patterns directory.', 'acrossai-abilities-manager' ) );
			}
		}

		return $abs_path;
	}

	/**
	 * Normalises a value (filename OR bare slug) to a safe `slug.php` form.
	 */
	public static function sanitize_filename( string $value ): string {
		$value = basename( $value );
		if ( ! preg_match( '/\.php$/i', $value ) ) {
			$value .= '.php';
		}
		$base = preg_replace( '/\.php$/i', '', $value );
		$base = sanitize_title( $base );
		if ( '' === $base ) {
			return '';
		}
		return $base . '.php';
	}

	// -------------------------------------------------------------------------
	// Slug validation
	// -------------------------------------------------------------------------

	/**
	 * Validates a bare pattern slug (the part after "/").
	 * Used for DB post_name and file basename.
	 */
	public static function is_valid_bare_slug( string $slug ): bool {
		return (bool) preg_match( '#^[a-z0-9_-]+$#', $slug );
	}

	/**
	 * Validates a fully-qualified slug of the form "prefix/pattern-name".
	 * Used for the file header "Slug:" field.
	 */
	public static function is_valid_full_slug( string $slug ): bool {
		return (bool) preg_match( '#^[a-z0-9_-]+/[a-z0-9_-]+$#', $slug );
	}

	/**
	 * Builds the canonical full slug for a file-based pattern given the
	 * container slug (theme folder or plugin folder) and the bare slug.
	 */
	public static function build_full_slug( string $container_slug, string $bare_slug ): string {
		return sanitize_key( $container_slug ) . '/' . sanitize_key( $bare_slug );
	}

	// -------------------------------------------------------------------------
	// Content validation
	// -------------------------------------------------------------------------

	/**
	 * Empty or whitespace-only content is rejected per Scenario 15.
	 */
	public static function is_valid_content( string $content ): bool {
		return '' !== trim( $content );
	}

	// -------------------------------------------------------------------------
	// File header parse / build
	// -------------------------------------------------------------------------

	/**
	 * Builds the PHP header comment block for a new pattern file.
	 *
	 * @param array<string, string> $headers Header name => value.
	 */
	public static function build_file( array $headers, string $body ): string {
		$lines = array( '<?php', '/**' );
		foreach ( self::HEADERS as $name ) {
			if ( isset( $headers[ $name ] ) && '' !== $headers[ $name ] ) {
				$lines[] = ' * ' . $name . ': ' . self::sanitize_header_value( (string) $headers[ $name ] );
			}
		}
		$lines[] = ' */';
		$lines[] = '?>';

		return implode( "\n", $lines ) . "\n" . $body;
	}

	/**
	 * Parses the doc-block headers from an existing pattern file.
	 *
	 * @return array{headers: array<string, string>, body: string}
	 */
	public static function parse_file( string $contents ): array {
		$headers = array();
		$body    = $contents;

		if ( preg_match( '#^\s*<\?php\s*/\*\*(.*?)\*/\s*\?>\s*#s', $contents, $m, PREG_OFFSET_CAPTURE ) ) {
			$header_block = $m[1][0];
			$body         = substr( $contents, $m[0][1] + strlen( $m[0][0] ) );

			foreach ( self::HEADERS as $name ) {
				if ( preg_match( '/^\s*\*?\s*' . preg_quote( $name, '/' ) . '\s*:\s*(.+)$/mi', $header_block, $hm ) ) {
					$headers[ $name ] = trim( $hm[1] );
				}
			}
		}

		return array(
			'headers' => $headers,
			'body'    => ltrim( $body, "\n" ),
		);
	}

	/**
	 * Sanitize header value.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function sanitize_header_value( string $value ): string {
		$value = (string) preg_replace( '/\s+/', ' ', $value );
		return trim( $value );
	}

	/**
	 * Maps the create/update input keys to header field names.
	 *
	 * @return array<string, string>
	 */
	public static function input_to_header_map(): array {
		return array(
			'title'          => 'Title',
			'slug_full'      => 'Slug',
			'description'    => 'Description',
			'viewport_width' => 'Viewport Width',
			'inserter'       => 'Inserter',
			'categories'     => 'Categories',
			'keywords'       => 'Keywords',
			'block_types'    => 'Block Types',
			'post_types'     => 'Post Types',
			'template_types' => 'Template Types',
		);
	}

	/**
	 * Header fields.
	 *
	 * @return array
	 */
	public static function header_fields(): array {
		return self::HEADERS;
	}
}
