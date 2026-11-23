<?php
/**
 * Feature 132 — read AIOSEO's per-object search appearance settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Object_Settings_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability — aioseo/get-object-settings.
 *
 * The GLOBAL template layer for a post type, taxonomy or archive — the title and meta description
 * templates, schema type, article type and robots directives that apply to every object of that kind
 * — not one post's override. AIOSEO's `aioseo-posts/seo-data-get` covers the single post; this covers
 * the defaults it inherits from, and nothing in AIOSEO's own set reaches them.
 *
 * Lives in a separate option store from the site-wide panels, which is why it is a separate ability
 * rather than another panel: `aioseo()->dynamicOptions` is its own option row with its own defaults
 * and its own save path.
 *
 * Read-only, idempotent.
 *
 * @since 0.0.44
 */
class Get_Object_Settings extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'get-object-settings';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get All in One SEO Object Settings', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read the global All in One SEO search-appearance settings for one post type, taxonomy or archive: the title and meta description templates every object of that kind inherits, its default schema and article type, and its robots directives — each with its field id, type, default and current value. This is the template layer, not a single post\'s override; for one post use AIOSEO\'s own aioseo-posts/seo-data-get. Omit object to list the post types and taxonomies AIOSEO holds settings for.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function sub_group(): string {
		return 'aioseo-settings';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function aioseo_cap(): string {
		return 'aioseo_search_appearance_settings';
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'scope'  => array(
				'type'        => 'string',
				'enum'        => Object_Settings_Registry::scopes(),
				'description' => __( 'Which kind of object.', 'acrossai-abilities-manager' ),
			),
			'object' => array(
				'type'        => 'string',
				'description' => __( 'Post type, taxonomy or archive name, e.g. post, page or category. Omit to list the names available in this scope.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'scope'   => array( 'type' => 'string' ),
			'object'  => array( 'type' => array( 'string', 'null' ) ),
			'objects' => array( 'type' => array( 'array', 'null' ) ),
			'fields'  => array( 'type' => array( 'object', 'null' ) ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'scope' );
	}

	/**
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$scope  = self::scalar( $input['scope'] ?? '' );
		$object = self::scalar( $input['object'] ?? '' );

		if ( ! in_array( $scope, Object_Settings_Registry::scopes(), true ) ) {
			return new WP_Error(
				'unknown_scope',
				sprintf(
					/* translators: 1: requested scope, 2: comma-separated valid scopes */
					__( 'There is no AIOSEO object scope called "%1$s". The scopes are: %2$s.', 'acrossai-abilities-manager' ),
					$scope,
					implode( ', ', Object_Settings_Registry::scopes() )
				)
			);
		}

		if ( '' === $object ) {
			$objects = Object_Settings_Registry::objects( $scope );

			return array(
				'scope'   => $scope,
				'object'  => null,
				'objects' => $objects,
				'fields'  => null,
				'message' => array() === $objects
					? sprintf(
						/* translators: %s: scope */
						__( 'All in One SEO holds no %s settings on this site.', 'acrossai-abilities-manager' ),
						$scope
					)
					: sprintf(
						/* translators: 1: comma-separated names, 2: scope */
						__( 'AIOSEO holds %2$s settings for: %1$s. Call again with one to read its fields.', 'acrossai-abilities-manager' ),
						implode( ', ', $objects ),
						$scope
					),
			);
		}

		$fields = Object_Settings_Registry::fields( $scope, $object );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		return array(
			'scope'   => $scope,
			'object'  => $object,
			'objects' => null,
			'fields'  => $fields,
			'message' => sprintf(
				/* translators: 1: number of fields, 2: object name */
				_n( '%1$d field for "%2$s".', '%1$d fields for "%2$s".', count( $fields ), 'acrossai-abilities-manager' ),
				count( $fields ),
				$object
			),
		);
	}
}
