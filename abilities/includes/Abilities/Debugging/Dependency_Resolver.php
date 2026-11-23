<?php
/**
 * Dependency_Resolver — transitive closure over the WP 6.5+ `Requires Plugins:` header.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.21
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

defined( 'ABSPATH' ) || exit;

/**
 * Dependency_Resolver — walks the plugin-dependency graph in either direction.
 *
 * `dependents_of( X )` returns every plugin that transitively declares X in its
 * `Requires Plugins:` header — the set that must also be effectively-inactive
 * if X is toggled OFF, so the site does not throw "requires X" notices on the
 * next request.
 *
 * `requirements_of( X )` returns every plugin X transitively declares — the set
 * that must also be effectively-active if X is toggled ON.
 *
 * Both traversals use breadth-first search with a visited set to guard against
 * `Requires Plugins:` cycles (which WP core should prevent, but defence in
 * depth costs nothing).
 */
final class Dependency_Resolver {

	/** @var self|null */
	protected static $instance = null;

	/**
	 * Cached parsed plugin metadata, keyed by plugin_file → array<requires plugin_file>.
	 *
	 * @var array<string,list<string>>|null
	 */
	private ?array $requires_map_cache = null;

	/**
	 * Private constructor — access via instance().
	 */
	private function __construct() {}

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Return every plugin that transitively declares `$plugin_file` as a required plugin.
	 *
	 * Excludes `$plugin_file` itself. Order is BFS visit order.
	 *
	 * @param string $plugin_file Plugin identifier.
	 * @return list<string>
	 */
	public function dependents_of( string $plugin_file ): array {
		$requires_map = $this->requires_map();

		// Build a reverse index: plugin_file → list of plugins that declare it as required.
		$dependents_index = array();
		foreach ( $requires_map as $file => $required_by_file ) {
			foreach ( $required_by_file as $required_file ) {
				$dependents_index[ $required_file ][] = $file;
			}
		}

		return $this->bfs_closure( $plugin_file, $dependents_index );
	}

	/**
	 * Return every plugin `$plugin_file` transitively declares as a required plugin.
	 *
	 * Excludes `$plugin_file` itself. Order is BFS visit order.
	 *
	 * @param string $plugin_file Plugin identifier.
	 * @return list<string>
	 */
	public function requirements_of( string $plugin_file ): array {
		return $this->bfs_closure( $plugin_file, $this->requires_map() );
	}

	/**
	 * BFS transitive closure from a root over an adjacency map.
	 *
	 * @param string                      $root      Starting plugin identifier.
	 * @param array<string,list<string>>  $adjacency Node → list<neighbour>.
	 * @return list<string>
	 */
	private function bfs_closure( string $root, array $adjacency ): array {
		$visited = array( $root => true );
		$queue   = array( $root );
		$result  = array();

		while ( ! empty( $queue ) ) {
			$current = array_shift( $queue );
			$neighbours = $adjacency[ $current ] ?? array();
			foreach ( $neighbours as $neighbour ) {
				if ( isset( $visited[ $neighbour ] ) ) {
					continue;
				}
				$visited[ $neighbour ] = true;
				$queue[]               = $neighbour;
				$result[]              = $neighbour;
			}
		}

		return $result;
	}

	/**
	 * Build a plugin_file → list<required plugin_file> map from get_plugins().
	 *
	 * Cached per request. Test seams can reset it via reset() below.
	 *
	 * @return array<string,list<string>>
	 */
	private function requires_map(): array {
		if ( null !== $this->requires_map_cache ) {
			return $this->requires_map_cache;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$map     = array();

		foreach ( $plugins as $plugin_file => $data ) {
			$required = $this->parse_requires_header( $data['RequiresPlugins'] ?? '', $plugins );
			$map[ $plugin_file ] = $required;
		}

		$this->requires_map_cache = $map;
		return $this->requires_map_cache;
	}

	/**
	 * Parse a comma-separated `Requires Plugins:` header into a list of full
	 * plugin_file identifiers.
	 *
	 * The header value is a comma-separated list of plugin **slugs** — the
	 * folder name — not full paths. We resolve each slug to its plugin_file
	 * by scanning $plugins keys and picking the first entry whose folder
	 * matches. If no match, the entry is dropped.
	 *
	 * @param string                   $header  Raw header value.
	 * @param array<string,array>      $plugins Result of get_plugins().
	 * @return list<string>
	 */
	private function parse_requires_header( string $header, array $plugins ): array {
		if ( '' === $header ) {
			return array();
		}

		$slugs = array_filter( array_map( 'trim', explode( ',', $header ) ) );

		$resolved = array();
		foreach ( $slugs as $slug ) {
			foreach ( array_keys( $plugins ) as $plugin_file ) {
				if ( $slug === strtok( $plugin_file, '/' ) ) {
					$resolved[] = $plugin_file;
					break;
				}
			}
		}

		return $resolved;
	}

	/**
	 * Reset the per-request cache. Test fixtures need this when the
	 * simulated get_plugins() return value changes mid-test.
	 *
	 * @return void
	 */
	public function reset_cache(): void {
		$this->requires_map_cache = null;
	}
}
