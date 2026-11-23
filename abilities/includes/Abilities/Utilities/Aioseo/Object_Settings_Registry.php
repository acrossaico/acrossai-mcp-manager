<?php
/**
 * Feature 132 — the typed field specification for AIOSEO's per-object search appearance.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The second of AIOSEO's two option stores: settings that exist once per post type, taxonomy or
 * archive.
 *
 * `aioseo()->dynamicOptions` is a separate option row from `aioseo()->options`, with its own defaults
 * and its own save path. This is where a post type's global title template, meta description, schema
 * type and robots directives live — `searchAppearance.postTypes.post.title` and the like — and none
 * of it is reachable through the site-wide panels.
 *
 * The defaults tree is keyed by the OBJECTS that exist on this site, not by a generic template:
 * `getDefaults()['searchAppearance']['postTypes']` has a `post`, `page` and `attachment` entry on a
 * stock install and gains one per registered public post type. So the valid object names are read
 * from AIOSEO rather than from WordPress — a post type WordPress knows and AIOSEO has not picked up
 * yet has nowhere to store a value, and naming it should fail loudly instead of writing into a key
 * AIOSEO will discard on its next save.
 *
 * @since 0.0.44
 */
final class Object_Settings_Registry {

	/**
	 * Caller-facing scope => the dynamic store's group name.
	 *
	 * @since 0.0.44
	 * @var   array<string, string>
	 */
	private const SCOPES = array(
		'post-type' => 'postTypes',
		'taxonomy'  => 'taxonomies',
		'archive'   => 'archives',
	);

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * @since  0.0.44
	 * @return string[]
	 */
	public static function scopes(): array {
		return array_keys( self::SCOPES );
	}

	/**
	 * AIOSEO's whole dynamic defaults tree.
	 *
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	public static function all_defaults(): array {
		if ( ! Aioseo_Guard::is_available() || ! isset( aioseo()->dynamicOptions ) ) {
			return array();
		}

		$defaults = aioseo()->dynamicOptions->getDefaults();

		return is_array( $defaults ) ? $defaults : array();
	}

	/**
	 * The current per-object settings, as a plain array.
	 *
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	public static function all_values(): array {
		if ( ! Aioseo_Guard::is_available() || ! isset( aioseo()->dynamicOptions ) ) {
			return array();
		}

		$all = aioseo()->dynamicOptions->all();

		return is_array( $all ) ? $all : array();
	}

	/**
	 * Object names AIOSEO holds settings for, in one scope.
	 *
	 * @since  0.0.44
	 * @param  string $scope Caller-facing scope.
	 * @return string[]
	 */
	public static function objects( string $scope ): array {
		$group = self::SCOPES[ $scope ] ?? '';

		if ( '' === $group ) {
			return array();
		}

		$node = self::all_defaults()['searchAppearance'][ $group ] ?? array();

		return is_array( $node ) ? array_keys( $node ) : array();
	}

	/**
	 * The path into the dynamic store for one object.
	 *
	 * @since  0.0.44
	 * @param  string $scope  Caller-facing scope.
	 * @param  string $object Object name.
	 * @return string[]|WP_Error
	 */
	public static function path( string $scope, string $object ) {
		$group = self::SCOPES[ $scope ] ?? '';

		if ( '' === $group ) {
			return new WP_Error(
				'unknown_scope',
				sprintf(
					/* translators: 1: requested scope, 2: comma-separated valid scopes */
					__( 'There is no AIOSEO object scope called "%1$s". The scopes are: %2$s.', 'acrossai-abilities-manager' ),
					$scope,
					implode( ', ', self::scopes() )
				)
			);
		}

		$objects = self::objects( $scope );

		if ( ! in_array( $object, $objects, true ) ) {
			return new WP_Error(
				'unknown_object',
				array() === $objects
					? sprintf(
						/* translators: %s: scope name */
						__( 'All in One SEO holds no %s settings on this site, so there is nothing to read or write there.', 'acrossai-abilities-manager' ),
						$scope
					)
					: sprintf(
						/* translators: 1: object name, 2: scope, 3: comma-separated object names */
						__( 'All in One SEO holds no settings for the %2$s "%1$s". It knows about: %3$s. A post type or taxonomy registered after AIOSEO last rebuilt its options will not appear until it does.', 'acrossai-abilities-manager' ),
						$object,
						$scope,
						implode( ', ', $objects )
					)
			);
		}

		return array( 'searchAppearance', $group, $object );
	}

	/**
	 * The field specification for one object, flattened to dotted ids.
	 *
	 * @since  0.0.44
	 * @param  string $scope  Caller-facing scope.
	 * @param  string $object Object name.
	 * @return array<string, array<string, mixed>>|WP_Error
	 */
	public static function fields( string $scope, string $object ) {
		$path = self::path( $scope, $object );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$defaults = self::node( self::all_defaults(), $path );
		$values   = self::node( self::all_values(), $path );

		if ( ! is_array( $defaults ) ) {
			return new WP_Error(
				'object_unavailable',
				sprintf(
					/* translators: 1: object name, 2: scope */
					__( 'All in One SEO declares no fields for the %2$s "%1$s".', 'acrossai-abilities-manager' ),
					$object,
					$scope
				)
			);
		}

		return self::flatten( $defaults, is_array( $values ) ? $values : array() );
	}

	/**
	 * Current stored values for one object, keyed by the same dotted ids as the spec.
	 *
	 * @since  0.0.44
	 * @param  string $scope  Caller-facing scope.
	 * @param  string $object Object name.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function values( string $scope, string $object ) {
		$spec = self::fields( $scope, $object );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		$values = array();
		foreach ( $spec as $id => $field ) {
			$values[ $id ] = $field['current'];
		}

		return $values;
	}

	/**
	 * Validate a caller's field map against one object's specification.
	 *
	 * @since  0.0.44
	 * @param  string               $scope  Caller-facing scope.
	 * @param  string               $object Object name.
	 * @param  array<string, mixed> $fields Field id => value.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function validate( string $scope, string $object, array $fields ) {
		$spec = self::fields( $scope, $object );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		if ( array() === $fields ) {
			return new WP_Error(
				'no_fields',
				__( 'No fields were given, so there is nothing to write. Read the object first to see its field ids.', 'acrossai-abilities-manager' )
			);
		}

		foreach ( $fields as $id => $value ) {
			$id = (string) $id;

			if ( ! isset( $spec[ $id ] ) ) {
				return new WP_Error(
					'unknown_field',
					sprintf(
						/* translators: 1: field id, 2: object name, 3: comma-separated field ids */
						__( 'The field "%1$s" does not belong to "%2$s", so nothing was written. Its fields are: %3$s.', 'acrossai-abilities-manager' ),
						$id,
						$object,
						implode( ', ', array_keys( $spec ) )
					)
				);
			}
		}

		return $fields;
	}

	/**
	 * Walk a path into a tree.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $tree Tree.
	 * @param  string[]             $path Path segments.
	 * @return mixed
	 */
	private static function node( array $tree, array $path ) {
		$cursor = $tree;

		foreach ( $path as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return null;
			}

			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}

	/**
	 * Flatten a defaults subtree into dotted field specs. Leaf rule as in {@see Settings_Registry}.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $defaults Defaults subtree.
	 * @param  array<string, mixed> $values   Stored values subtree.
	 * @param  string               $prefix   Dotted prefix.
	 * @return array<string, array<string, mixed>>
	 */
	private static function flatten( array $defaults, array $values, string $prefix = '' ): array {
		$fields = array();

		foreach ( $defaults as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$id = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( isset( $node['type'] ) && is_string( $node['type'] ) ) {
				$fields[ $id ] = array(
					'type'      => $node['type'],
					'default'   => $node['default'] ?? null,
					'current'   => array_key_exists( $key, $values ) ? $values[ $key ] : ( $node['default'] ?? null ),
					'localized' => ! empty( $node['localized'] ),
				);

				continue;
			}

			$child_values = ( isset( $values[ $key ] ) && is_array( $values[ $key ] ) ) ? $values[ $key ] : array();

			$fields = array_merge( $fields, self::flatten( $node, $child_values, $id ) );
		}

		return $fields;
	}
}
