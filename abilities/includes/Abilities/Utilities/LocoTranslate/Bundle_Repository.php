<?php
/**
 * Feature 116 — discovers what Loco knows about: bundles, projects and their translation files.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Bundle and project discovery, through Loco's own configuration.
 *
 * Not derivable from the filesystem: a bundle's text domains, where its POT lives and where its
 * translations are expected to be written all come from Loco's bundle configuration, which reads
 * plugin headers, `loco.xml` files and its own database of known layouts. Listing `wp-content/
 * languages` would find the files a site happens to have and miss every bundle that has none — which
 * is precisely the set a caller wants to know about.
 *
 * @since 0.0.34
 */
final class Bundle_Repository {

	/**
	 * Bundle types this suite exposes.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const TYPES = array( 'plugin', 'theme', 'core' );

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Every bundle Loco can see, optionally narrowed to one type.
	 *
	 * @since  0.0.34
	 * @param  string $type One of self::TYPES, or '' for all.
	 * @return array<int, object>|WP_Error Loco_package_Bundle list.
	 */
	public static function all( string $type = '' ) {
		if ( '' !== $type && ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: supplied type, 2: accepted list. */
					__( '"%1$s" is not a bundle type. Accepted values are: %2$s.', 'acrossai-abilities-manager' ),
					$type,
					implode( ', ', self::TYPES )
				)
			);
		}

		$bundles = array();

		if ( '' === $type || 'plugin' === $type ) {
			foreach ( \Loco_package_Plugin::getAll() as $bundle ) {
				$bundles[] = $bundle;
			}
		}

		if ( '' === $type || 'theme' === $type ) {
			foreach ( \Loco_package_Theme::getAll() as $bundle ) {
				$bundles[] = $bundle;
			}
		}

		if ( '' === $type || 'core' === $type ) {
			$bundles[] = \Loco_package_Core::create();
		}

		return $bundles;
	}

	/**
	 * One bundle by its Loco id, for example `plugin:loco-translate`.
	 *
	 * Matched on id, handle and slug because all three appear in Loco's own UI and a caller has no
	 * way to know which one it was given. Returning "not found" for a name the operator can see on
	 * screen would be the wrong answer to a reasonable question.
	 *
	 * @since  0.0.34
	 * @param  string $id Bundle id, handle or slug.
	 * @return object|WP_Error Loco_package_Bundle on success.
	 */
	public static function find( string $id ) {
		$id = trim( $id );

		if ( '' === $id ) {
			return new WP_Error(
				'invalid_input',
				__( 'A bundle id is required. Call translations/list-bundles to see what exists.', 'acrossai-abilities-manager' )
			);
		}

		$all = self::all();

		if ( is_wp_error( $all ) ) {
			return $all;
		}

		foreach ( $all as $bundle ) {
			foreach ( array( $bundle->getId(), $bundle->getHandle(), $bundle->getSlug() ) as $candidate ) {
				if ( '' !== (string) $candidate && (string) $candidate === $id ) {
					return $bundle;
				}
			}
		}

		return new WP_Error(
			'unknown_bundle',
			sprintf(
				/* translators: %s: bundle id. */
				__( 'No bundle matching "%s". Call translations/list-bundles for the ids, handles and slugs Loco recognises.', 'acrossai-abilities-manager' ),
				$id
			)
		);
	}

	/**
	 * Shape one bundle for output. Rows, never maps.
	 *
	 * @since  0.0.34
	 * @param  object $bundle Loco_package_Bundle.
	 * @param  bool   $deep   Include per-project file inventory.
	 * @return array<string, mixed>
	 */
	public static function shape( $bundle, bool $deep = false ): array {
		$row = array(
			'id'        => (string) $bundle->getId(),
			'name'      => (string) $bundle->getName(),
			'handle'    => (string) $bundle->getHandle(),
			'slug'      => (string) $bundle->getSlug(),
			'type'      => strtolower( (string) $bundle->getType() ),
			'directory' => (string) $bundle->getDirectoryPath(),
			'domains'   => array_map( 'strval', array_keys( (array) $bundle->getDomains() ) ),
		);

		if ( ! $deep ) {
			$row['project_count'] = count( (array) $bundle->getDomains() );

			return $row;
		}

		$projects = array();

		foreach ( $bundle as $project ) {
			$projects[] = self::shape_project( $project );
		}

		$row['projects'] = $projects;

		return $row;
	}

	/**
	 * Shape one project, including what translation files it actually has.
	 *
	 * @since  0.0.34
	 * @param  object $project Loco_package_Project.
	 * @return array<string, mixed>
	 */
	public static function shape_project( $project ): array {
		$pot = $project->getPot();

		$po = array();

		foreach ( (array) $project->findLocaleFiles( 'po' ) as $file ) {
			$path = (string) $file->getPath();

			$po[] = array(
				'path'   => $path,
				'locale' => self::locale_from_path( $path ),
			);
		}

		return array(
			'id'          => rtrim( (string) $project->getId(), '.' ),
			'name'        => (string) $project->getName(),
			'domain'      => (string) $project->getDomain(),
			'pot'         => ( $pot && $pot->exists() ) ? (string) $pot->getPath() : '',
			'has_pot'     => (bool) ( $pot && $pot->exists() ),
			'po_files'    => $po,
			'locale_count' => count( $po ),
		);
	}

	/**
	 * The locale suffix of a PO filename, when it has one.
	 *
	 * @since  0.0.34
	 * @param  string $path Absolute or relative path.
	 * @return string
	 */
	public static function locale_from_path( string $path ): string {
		$name = basename( $path, '.po' );

		if ( preg_match( '/([a-z]{2,3}(?:_[A-Za-z0-9_]+)?)$/', $name, $m ) ) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Find one project inside a bundle by text domain.
	 *
	 * @since  0.0.34
	 * @param  object $bundle Loco_package_Bundle.
	 * @param  string $domain Text domain, or '' for the bundle default.
	 * @return object|WP_Error Loco_package_Project on success.
	 */
	public static function project( $bundle, string $domain = '' ) {
		$project = ( '' === $domain )
			? $bundle->getDefaultProject()
			: $bundle->getProject( $domain );

		if ( ! $project ) {
			return new WP_Error(
				'unknown_text_domain',
				sprintf(
					/* translators: 1: text domain, 2: bundle name. */
					__( 'The bundle "%2$s" has no text domain "%1$s". Call translations/get-bundle to see which domains it defines.', 'acrossai-abilities-manager' ),
					$domain,
					(string) $bundle->getName()
				)
			);
		}

		return $project;
	}
}
