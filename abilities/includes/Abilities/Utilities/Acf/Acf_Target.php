<?php
/**
 * Feature 105 — resolves an ability's (target_type, target_id) pair into ACF's `$post_id` argument.
 *
 * ACF overloads one parameter to address five different things. `get_field( 'x', 123 )` reads a post,
 * `get_field( 'x', 'user_45' )` a user, `'term_12'` a term, `'comment_7'` a comment, and the bare
 * string `'option'` the options store. Every field and row ability in this suite takes the explicit
 * pair instead and resolves it here, for three reasons:
 *
 * 1. A caller passing `45` meaning "user 45" would silently read post 45 — wrong data, no error.
 * 2. The target is validated to exist, so a typo returns `unknown_target` rather than ACF quietly
 *    returning null and the ability reporting success on an empty read.
 * 3. It is one place to change if ACF adds a target kind.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only target resolver.
 */
final class Acf_Target {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The target kinds this suite addresses, as kind => how the id is interpreted.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			'post'    => __( 'A post, page or any custom post type. target_id is the post ID.', 'acrossai-abilities-manager' ),
			'user'    => __( 'A user. target_id is the user ID.', 'acrossai-abilities-manager' ),
			'term'    => __( 'A taxonomy term. target_id is the term ID.', 'acrossai-abilities-manager' ),
			'comment' => __( 'A comment. target_id is the comment ID.', 'acrossai-abilities-manager' ),
			'option'  => __( 'The options store. target_id is ignored, or a named options page.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * The shared input-schema fragment every field and row ability merges in.
	 *
	 * Declared once so all eleven describe the target identically — an AI client that learns the pair
	 * from one ability can use it on the rest without re-reading a schema.
	 *
	 * @since  0.0.34
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema_fragment(): array {
		$describes = array();

		foreach ( self::types() as $type => $describe ) {
			$describes[] = $type . ': ' . $describe;
		}

		return array(
			'target_type' => array(
				'type'        => 'string',
				'enum'        => array_keys( self::types() ),
				'default'     => 'post',
				'description' => __( 'What the field is attached to. ', 'acrossai-abilities-manager' ) . implode( ' ', $describes ),
			),
			'target_id'   => array(
				'type'        => array( 'integer', 'string' ),
				'description' => __( 'The ID of the target. Required for post, user, term and comment. For target_type "option" this is optional and names a specific options page; omit it for the default options store.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Resolve the pair into the value ACF expects, validating that the target exists.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Ability input.
	 * @return int|string|WP_Error
	 */
	public static function resolve( array $input ) {
		$type = isset( $input['target_type'] ) ? (string) $input['target_type'] : 'post';

		if ( ! isset( self::types()[ $type ] ) ) {
			return new WP_Error(
				'unknown_target_type',
				sprintf(
					/* translators: 1: requested type, 2: comma-separated known types */
					__( '"%1$s" is not a target type. Known types: %2$s.', 'acrossai-abilities-manager' ),
					$type,
					implode( ', ', array_keys( self::types() ) )
				)
			);
		}

		// The options store is the one target with no id to validate: ACF treats any unrecognised
		// string as an options-page name and creates it on write, so there is nothing to check.
		if ( 'option' === $type ) {
			$named = isset( $input['target_id'] ) ? sanitize_key( (string) $input['target_id'] ) : '';

			return '' !== $named ? $named : 'option';
		}

		$id = isset( $input['target_id'] ) ? (int) $input['target_id'] : 0;

		if ( $id < 1 ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: target type */
					__( 'target_id is required and must be a positive integer for target_type "%s".', 'acrossai-abilities-manager' ),
					$type
				)
			);
		}

		$exists = self::exists( $type, $id );

		if ( is_wp_error( $exists ) ) {
			return $exists;
		}

		switch ( $type ) {
			case 'user':
				return 'user_' . $id;

			case 'term':
				return 'term_' . $id;

			case 'comment':
				return 'comment_' . $id;

			default:
				return $id;
		}
	}

	/**
	 * Confirm the target actually exists.
	 *
	 * Without this an ability reports a clean success having read nothing, because ACF returns null
	 * for a target that is not there just as it does for a field that has no value.
	 *
	 * @since  0.0.34
	 * @param  string $type Target kind.
	 * @param  int    $id   Target ID.
	 * @return true|WP_Error
	 */
	private static function exists( string $type, int $id ) {
		switch ( $type ) {
			case 'user':
				$found = (bool) get_userdata( $id );
				break;

			case 'term':
				$found = null !== get_term( $id ) && ! is_wp_error( get_term( $id ) );
				break;

			case 'comment':
				$found = null !== get_comment( $id );
				break;

			default:
				$found = null !== get_post( $id );
				break;
		}

		if ( ! $found ) {
			return new WP_Error(
				'unknown_target',
				sprintf(
					/* translators: 1: target type, 2: target id */
					__( 'No %1$s with id %2$d.', 'acrossai-abilities-manager' ),
					$type,
					$id
				)
			);
		}

		return true;
	}

	/**
	 * A human-readable description of a resolved target, for success messages.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Ability input.
	 * @return string
	 */
	public static function describe( array $input ): string {
		$type = isset( $input['target_type'] ) ? (string) $input['target_type'] : 'post';

		if ( 'option' === $type ) {
			return __( 'the options store', 'acrossai-abilities-manager' );
		}

		return sprintf(
			/* translators: 1: target type, 2: target id */
			__( '%1$s %2$d', 'acrossai-abilities-manager' ),
			$type,
			isset( $input['target_id'] ) ? (int) $input['target_id'] : 0
		);
	}
}
