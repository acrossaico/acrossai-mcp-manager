<?php
/**
 * Feature 135 — where a caller-supplied path is allowed to land.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.44
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an ability's `path` parameter against the directories WordPress actually uses.
 *
 * **The problem this solves.** Fourteen file-manager abilities each resolved `path` as
 * `realpath( ABSPATH ) . '/' . $path` and refused anything outside it. WordPress has never
 * required `wp-content` to live inside `ABSPATH`; `WP_CONTENT_DIR` is a constant precisely so a
 * host can move it. Measured on a split-docroot managed host:
 *
 *     ABSPATH        = /wordpress/core/7.1.4/
 *     WP_CONTENT_DIR = /srv/htdocs/wp-content
 *
 * there, `wp-content/themes/accel-main/functions.php` — the live active theme — was unreachable,
 * while `wp-content/themes/twentytwentyfive/style.css` returned a cheerful success from
 * `/wordpress/core/7.1.4/wp-content/`: the host's core bundle, which carries only Akismet and the
 * default themes. The caller cannot tell those two apart from the response, which is how an agent
 * comes to report that it read a theme file when it read host scaffolding.
 *
 * **Why roots rather than one root.** WordPress core never resolves caller paths from `ABSPATH`
 * either. It resolves per subsystem — `WP_PLUGIN_DIR . '/' . $file` for plugins,
 * `$theme->get_stylesheet_directory()` for themes, with `get_theme_root()` itself derived from
 * `WP_CONTENT_DIR` — and then validates against an enumerated list of the files that subsystem
 * really has (`validate_file( $file, get_plugin_files( $plugin ) )`). Enumeration is immune to disk
 * layout. We cannot enumerate, because these abilities are deliberately general, so we take the
 * other half of core's approach: ask WordPress for its roots and accept a path inside any of them.
 *
 * **The decoy rule is the point of the feature.** When `WP_CONTENT_DIR` sits outside `ABSPATH`,
 * `ABSPATH/wp-content` still exists on some hosts and is not the site's content directory. A path
 * that lands there is refused by {@see self::ERR_DECOY}, naming both candidates, rather than served.
 * A refusal is recoverable; a wrong file returned as a success is not.
 *
 * On a standard single-directory install every candidate below coincides, so nothing changes.
 *
 * @since 0.0.44
 */
final class Fs_Roots {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Resolved path lies outside every root WordPress knows about.
	 *
	 * @since 0.0.44
	 * @var   string
	 */
	public const ERR_OUTSIDE = 'path_outside_roots';

	/**
	 * Path resolved into an `ABSPATH/wp-content` that is not this site's content directory.
	 *
	 * @since 0.0.44
	 * @var   string
	 */
	public const ERR_DECOY = 'path_in_unused_core_content';

	/**
	 * Malformed input — empty, null byte, stream wrapper, or a traversal segment.
	 *
	 * @since 0.0.44
	 * @var   string
	 */
	public const ERR_INVALID = 'invalid_path';

	/**
	 * Nothing exists at any candidate, and existence was required.
	 *
	 * @since 0.0.44
	 * @var   string
	 */
	public const ERR_MISSING = 'file_not_found';

	/**
	 * Roots a path may legitimately land in, most specific first.
	 *
	 * Ordered so the reported label is the most informative one: uploads sits inside content and
	 * plugins usually does too, so an unordered match would label everything `content`.
	 *
	 * `core` is last for the same reason inverted — on a standard install ABSPATH contains all the
	 * others, and labelling a theme file `core` would be true but useless.
	 *
	 * @since  0.0.44
	 * @return array<string,string> Label => absolute real path, no trailing slash.
	 */
	public static function roots(): array {
		$candidates = array();

		$uploads = wp_get_upload_dir();
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$candidates['uploads'] = (string) $uploads['basedir'];
		}

		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$candidates['mu-plugins'] = (string) WPMU_PLUGIN_DIR;
		}

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$candidates['plugins'] = (string) WP_PLUGIN_DIR;
		}

		// $wp_theme_directories holds absolute paths; get_theme_roots() returns them relative to
		// WP_CONTENT_DIR, which would need re-joining and can be filtered to a bare '/themes'.
		$theme_dirs = $GLOBALS['wp_theme_directories'] ?? array();
		if ( is_array( $theme_dirs ) ) {
			$index = 0;
			foreach ( $theme_dirs as $dir ) {
				$label                = 0 === $index ? 'themes' : 'themes-' . $index;
				$candidates[ $label ] = (string) $dir;
				++$index;
			}
		}

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$candidates['content'] = (string) WP_CONTENT_DIR;
		}

		$candidates['core'] = ABSPATH;

		$roots = array();

		foreach ( $candidates as $label => $dir ) {
			$real = self::real( $dir );

			if ( '' !== $real && ! in_array( $real, $roots, true ) ) {
				$roots[ $label ] = $real;
			}
		}

		return $roots;
	}

	/**
	 * Resolve a caller-supplied path to an absolute one, or explain why not.
	 *
	 * @since  0.0.44
	 * @param  string             $path       Caller input — relative to a root, or already absolute.
	 * @param  bool               $must_exist Whether to fail when nothing exists at the resolved path.
	 * @param  array<string,string>|null $roots Root set to resolve against. Defaults to
	 *                                          {@see self::roots()}; injected by the tests so the
	 *                                          split-docroot layout can be exercised without
	 *                                          redefining WP_CONTENT_DIR.
	 * @return array{path:string,root:string,exists:bool}|\WP_Error
	 */
	public static function resolve( string $path, bool $must_exist = false, ?array $roots = null ) {
		$path = self::sanitize( $path );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$roots = null === $roots ? self::roots() : $roots;

		foreach ( self::candidates( $path, $roots ) as $candidate ) {
			$real = self::real( $candidate );

			if ( '' !== $real ) {
				$label = self::match( $real, $roots );

				if ( null === $label ) {
					continue;
				}

				$decoy = self::decoy_error( $real, $roots );

				if ( null !== $decoy ) {
					return $decoy;
				}

				return array(
					'path'   => $real,
					'root'   => $label,
					'exists' => true,
				);
			}
		}

		if ( $must_exist ) {
			return new \WP_Error(
				self::ERR_MISSING,
				sprintf(
					/* translators: %s: caller-supplied path. */
					__( 'Nothing exists at: %s', 'acrossai-abilities-manager' ),
					$path
				)
			);
		}

		return self::resolve_for_create( $path, $roots );
	}

	/**
	 * Reject malformed input and normalise what survives.
	 *
	 * Hardening borrowed from mcp-content-manager-lite, which we had no equivalent of: a null byte
	 * truncates the path inside PHP's filesystem calls, and a stream wrapper makes "filesystem path"
	 * mean something else entirely (`phar://` in particular is an RCE primitive).
	 *
	 * Traversal is refused outright rather than left to realpath containment. Containment does catch
	 * an escape, but only once the path exists; on a create, the old parent check ran against an
	 * unresolved string. Refusing the segment is cheaper and needs no reasoning about order.
	 *
	 * @since  0.0.44
	 * @param  string $path Caller input.
	 * @return string|\WP_Error Normalised path.
	 */
	private static function sanitize( string $path ) {
		$path = trim( $path );

		if ( '' === $path ) {
			return new \WP_Error( self::ERR_INVALID, __( 'No path was supplied.', 'acrossai-abilities-manager' ) );
		}

		if ( false !== strpos( $path, "\0" ) ) {
			return new \WP_Error( self::ERR_INVALID, __( 'Null bytes are not allowed in a path.', 'acrossai-abilities-manager' ) );
		}

		if ( 1 === preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $path ) ) {
			return new \WP_Error( self::ERR_INVALID, __( 'Stream wrappers are not allowed in a path.', 'acrossai-abilities-manager' ) );
		}

		$path = wp_normalize_path( $path );

		if ( self::has_traversal( $path ) ) {
			return new \WP_Error( self::ERR_INVALID, __( 'Directory traversal is not allowed in a path.', 'acrossai-abilities-manager' ) );
		}

		return $path;
	}

	/**
	 * Resolve a path that may be several directory levels deeper than anything on disk.
	 *
	 * {@see self::resolve()} contains the *immediate* parent, which is the right rule for a write
	 * into an existing directory. It is the wrong rule for `create-file` with `create_dirs=true` and
	 * for a recursive `create-directory`, where the caller legitimately names a path whose middle
	 * levels do not exist yet — `resolve()` would refuse those as outside every root.
	 *
	 * So this walks up to the nearest ancestor that does exist, contains *that*, and returns the
	 * intended absolute path. Containment is still decided against a real directory, never against
	 * a string, and the decoy rule still applies to the ancestor.
	 *
	 * @since  0.0.44
	 * @param  string                    $path  Caller input.
	 * @param  array<string,string>|null $roots Root set; defaults to {@see self::roots()}.
	 * @return array{path:string,root:string,exists:bool}|\WP_Error
	 */
	public static function resolve_deep( string $path, ?array $roots = null ) {
		$path = self::sanitize( $path );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$roots = null === $roots ? self::roots() : $roots;

		foreach ( self::candidates( $path, $roots ) as $candidate ) {
			$trail   = array();
			$walk    = $candidate;
			$ancestor = '';

			// Bounded by the path's own depth; each iteration drops one segment.
			while ( '' !== $walk && '/' !== $walk && '.' !== $walk ) {
				$real = self::real( $walk );

				if ( '' !== $real ) {
					$ancestor = $real;
					break;
				}

				$trail[] = basename( $walk );
				$walk    = dirname( $walk );
			}

			if ( '' === $ancestor ) {
				continue;
			}

			$label = self::match( $ancestor, $roots );

			if ( null === $label ) {
				continue;
			}

			$decoy = self::decoy_error( $ancestor, $roots );

			if ( null !== $decoy ) {
				return $decoy;
			}

			$remainder = implode( '/', array_reverse( $trail ) );

			return array(
				'path'   => '' === $remainder ? $ancestor : $ancestor . '/' . $remainder,
				'root'   => $label,
				'exists' => '' === $remainder,
			);
		}

		return new \WP_Error(
			self::ERR_OUTSIDE,
			sprintf(
				/* translators: %s: caller-supplied path. */
				__( 'Path is outside every WordPress directory: %s', 'acrossai-abilities-manager' ),
				$path
			)
		);
	}

	/**
	 * Resolve a path whose target does not exist yet, by containing its parent.
	 *
	 * @since  0.0.44
	 * @param  string               $path  Normalised caller path.
	 * @param  array<string,string> $roots Roots from {@see self::roots()}.
	 * @return array{path:string,root:string,exists:bool}|\WP_Error
	 */
	private static function resolve_for_create( string $path, array $roots ) {
		foreach ( self::candidates( $path, $roots ) as $candidate ) {
			$parent = self::real( dirname( $candidate ) );

			if ( '' === $parent ) {
				continue;
			}

			$label = self::match( $parent, $roots );

			if ( null === $label ) {
				continue;
			}

			$decoy = self::decoy_error( $parent, $roots );

			if ( null !== $decoy ) {
				return $decoy;
			}

			return array(
				'path'   => $parent . '/' . basename( $candidate ),
				'root'   => $label,
				'exists' => false,
			);
		}

		return new \WP_Error(
			self::ERR_OUTSIDE,
			sprintf(
				/* translators: %s: caller-supplied path. */
				__( 'Path is outside every WordPress directory: %s', 'acrossai-abilities-manager' ),
				$path
			)
		);
	}

	/**
	 * Absolute paths to try, in order, for one caller path.
	 *
	 * The content mapping comes first and is what makes the documented
	 * `wp-content/themes/<theme>/functions.php` reach the live theme: on a split-docroot host the
	 * ABSPATH join would land in the unused core copy instead, and that copy may well contain a
	 * same-named file.
	 *
	 * @since  0.0.44
	 * @param  string               $path  Normalised caller path.
	 * @param  array<string,string> $roots Roots from {@see self::roots()}.
	 * @return string[]
	 */
	private static function candidates( string $path, array $roots ): array {
		if ( 0 === strpos( $path, '/' ) ) {
			return array( $path );
		}

		$relative   = ltrim( $path, '/' );
		$candidates = array();
		$content    = $roots['content'] ?? '';

		if ( '' !== $content ) {
			$leaf  = basename( $content );
			$first = explode( '/', $relative )[0];

			// 'wp-content/themes/x' -> WP_CONTENT_DIR . '/themes/x'. Honours a renamed content
			// directory too, since the prefix is read off the constant rather than hardcoded.
			if ( $first === $leaf ) {
				$remainder = substr( $relative, strlen( $leaf ) );
				$remainder = ltrim( (string) $remainder, '/' );

				$candidates[] = '' === $remainder ? $content : $content . '/' . $remainder;
			}
		}

		$core = $roots['core'] ?? rtrim( self::real( ABSPATH ) ?: ABSPATH, '/' );

		$candidates[] = $core . '/' . $relative;

		// Bare 'themes/x' or 'plugins/x', which is what a caller writes when the content directory
		// is the root it is thinking in.
		if ( '' !== $content ) {
			$candidates[] = $content . '/' . $relative;
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Refusal when a path landed in an `ABSPATH/wp-content` that is not this site's.
	 *
	 * Returns null in the ordinary case, which includes every standard install: when
	 * `WP_CONTENT_DIR` is inside `ABSPATH` the two are the same directory and there is no decoy to
	 * confuse it with.
	 *
	 * @since  0.0.44
	 * @param  string               $real  Resolved real path.
	 * @param  array<string,string> $roots Roots from {@see self::roots()}.
	 * @return \WP_Error|null
	 */
	private static function decoy_error( string $real, array $roots ) {
		$content = $roots['content'] ?? '';
		$core    = $roots['core'] ?? '';

		if ( '' === $content || '' === $core ) {
			return null;
		}

		// Content inside core: ordinary layout, nothing to disambiguate.
		if ( self::inside( $content, $core ) ) {
			return null;
		}

		$decoy = self::real( $core . '/' . basename( $content ) );

		if ( '' === $decoy || ! self::inside( $real, $decoy ) ) {
			return null;
		}

		return new \WP_Error(
			self::ERR_DECOY,
			sprintf(
				/* translators: 1: path under ABSPATH, 2: the real content directory. */
				__( 'Refusing to read %1$s: this site\'s content directory is %2$s, and the path under ABSPATH is an unused copy shipped with WordPress core. Nothing there belongs to this site.', 'acrossai-abilities-manager' ),
				$real,
				$content
			)
		);
	}

	/**
	 * The prefix to strip from an absolute path to get one a caller can send back.
	 *
	 * `list-directory` reports each entry as a relative path, and those paths have to round-trip
	 * through {@see self::resolve()}. Stripping the matched root would not round-trip: an entry
	 * under the content directory would come back as `plugins/akismet`, losing the `wp-content/`
	 * prefix that the documented form carries.
	 *
	 * So the base is chosen to keep that prefix. On a standard install that is `ABSPATH`, which
	 * already contains the content directory. On a split-docroot host it is the *parent* of
	 * `WP_CONTENT_DIR`, which yields the same `wp-content/...` string from a completely different
	 * absolute path.
	 *
	 * @since  0.0.44
	 * @param  string                    $real  Absolute real path.
	 * @param  array<string,string>|null $roots Root set; defaults to {@see self::roots()}.
	 * @return string Absolute prefix, no trailing slash.
	 */
	public static function display_base( string $real, ?array $roots = null ): string {
		$roots   = null === $roots ? self::roots() : $roots;
		$content = $roots['content'] ?? '';
		$core    = $roots['core'] ?? '';

		// Split layout: keep the content directory's own name in the relative path.
		if ( '' !== $content && '' !== $core && ! self::inside( $content, $core ) && self::inside( $real, $content ) ) {
			return rtrim( dirname( $content ), '/' );
		}

		if ( '' !== $core && self::inside( $real, $core ) ) {
			return $core;
		}

		if ( '' !== $content && self::inside( $real, $content ) ) {
			return rtrim( dirname( $content ), '/' );
		}

		return rtrim( dirname( $real ), '/' );
	}

	/**
	 * Label of the first root containing a path, or null when none does.
	 *
	 * @since  0.0.44
	 * @param  string               $real  Resolved real path.
	 * @param  array<string,string> $roots Roots from {@see self::roots()}.
	 * @return string|null
	 */
	public static function match( string $real, ?array $roots = null ) {
		$roots = null === $roots ? self::roots() : $roots;

		foreach ( $roots as $label => $root ) {
			if ( self::inside( $real, $root ) ) {
				return (string) $label;
			}
		}

		return null;
	}

	/**
	 * Whether a path is a root or sits beneath it.
	 *
	 * The `'/'` in the prefix test is load-bearing: without it `/srv/htdocs/wp-content-backup`
	 * counts as inside `/srv/htdocs/wp-content`.
	 *
	 * @since  0.0.44
	 * @param  string $path Absolute path.
	 * @param  string $root Absolute root.
	 * @return bool
	 */
	public static function inside( string $path, string $root ): bool {
		if ( '' === $path || '' === $root ) {
			return false;
		}

		$path = rtrim( wp_normalize_path( $path ), '/' );
		$root = rtrim( wp_normalize_path( $root ), '/' );

		return $path === $root || 0 === strpos( $path, $root . '/' );
	}

	/**
	 * Whether a path contains a traversal segment.
	 *
	 * Segment-wise rather than a substring search, so a legitimate name containing two dots
	 * (`my..plugin`) is not refused.
	 *
	 * @since  0.0.44
	 * @param  string $path Normalised path.
	 * @return bool
	 */
	private static function has_traversal( string $path ): bool {
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `realpath()`, normalised, with failure expressed as an empty string.
	 *
	 * @since  0.0.44
	 * @param  string $path Path to resolve.
	 * @return string Empty when the path does not exist.
	 */
	private static function real( string $path ): string {
		if ( '' === $path ) {
			return '';
		}

		$real = realpath( $path );

		if ( false === $real ) {
			return '';
		}

		return rtrim( wp_normalize_path( $real ), '/' );
	}

	/**
	 * Turn a resolver WP_Error into the refusal shape the file-manager abilities return.
	 *
	 * Mirrors {@see Path_Allowlist_Guard::blocked_read_response()} so a caller sees one refusal
	 * vocabulary across both layers.
	 *
	 * @since  0.0.44
	 * @param  \WP_Error $error Error from {@see self::resolve()}.
	 * @return array<string,mixed>
	 */
	public static function blocked_response( \WP_Error $error ): array {
		return array(
			'success'        => false,
			'blocked_reason' => $error->get_error_code(),
			'message'        => $error->get_error_message(),
		);
	}
}
