<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Comments
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Comments;

use WP_Comment;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helper for the Comments abilities — converts a WP_Comment into the same
 * array shape WP_REST_Comments_Controller::prepare_item_for_response() emits in
 * the "edit" context (minus _links/_embedded), builds the REST-equivalent meta
 * map, and normalizes WP_Error / false / null into the abilities' error shape.
 */
final class Comment_Formatter {

	/**
	 * Convert a WP_Comment object into the abilities' canonical comment array.
	 *
	 * @param WP_Comment $comment Comment to format.
	 * @return array<string, mixed>
	 */
	public static function to_array( WP_Comment $comment ): array {
		$comment_id = (int) $comment->comment_ID;

		return array(
			'id'                 => $comment_id,
			'post'               => (int) $comment->comment_post_ID,
			'parent'             => (int) $comment->comment_parent,
			'author'             => (int) $comment->user_id,
			'author_name'        => (string) $comment->comment_author,
			'author_email'       => (string) $comment->comment_author_email,
			'author_url'         => (string) $comment->comment_author_url,
			'author_ip'          => (string) $comment->comment_author_IP, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			'author_user_agent'  => (string) $comment->comment_agent,
			'date'               => mysql_to_rfc3339( $comment->comment_date ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core function, false positive
			'date_gmt'           => mysql_to_rfc3339( $comment->comment_date_gmt ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core function, false positive
			'content'            => array(
				'rendered' => (string) apply_filters( 'comment_text', $comment->comment_content, $comment, array() ),
				'raw'      => (string) $comment->comment_content,
			),
			'link'               => (string) get_comment_link( $comment ),
			'status'             => self::map_status( (string) $comment->comment_approved ),
			'type'               => (string) get_comment_type( $comment_id ),
			'author_avatar_urls' => function_exists( 'rest_get_avatar_urls' ) ? rest_get_avatar_urls( $comment ) : array(),
			'meta'               => self::build_meta_map( $comment_id ),
		);
	}

	/**
	 * Build the REST-equivalent meta map for a comment — only includes meta keys
	 * registered with show_in_rest, honoring each key's `single` flag.
	 *
	 * @param int $comment_id Comment ID.
	 * @return array<string, mixed>
	 */
	public static function build_meta_map( int $comment_id ): array {
		if ( $comment_id <= 0 || ! function_exists( 'get_registered_meta_keys' ) ) {
			return array();
		}

		$out  = array();
		$keys = get_registered_meta_keys( 'comment' );
		foreach ( $keys as $key => $args ) {
			if ( empty( $args['show_in_rest'] ) ) {
				continue;
			}
			$single      = ! empty( $args['single'] );
			$out[ $key ] = get_comment_meta( $comment_id, $key, $single );
		}
		return $out;
	}

	/**
	 * Return true if a meta key is registered for comments AND exposed via REST.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_meta_key_writable( string $key ): bool {
		if ( '' === $key || ! function_exists( 'get_registered_meta_keys' ) ) {
			return false;
		}
		$keys = get_registered_meta_keys( 'comment' );
		return isset( $keys[ $key ] ) && ! empty( $keys[ $key ]['show_in_rest'] );
	}

	/**
	 * Normalize a failure result (WP_Error / false / null) into the abilities
	 * error envelope. The ability output_schemas declare `additionalProperties:false`
	 * with only success/message/<payload> exposed, so we deliberately do NOT add
	 * the WP_Error code as a top-level field — we keep `success`+`message` only.
	 *
	 * @param mixed  $result   Original return value (typically WP_Error or false).
	 * @param string $fallback Fallback message used when $result is not WP_Error.
	 * @return array{success: bool, message: string}
	 */
	public static function error_from( $result, string $fallback ): array {
		if ( $result instanceof WP_Error ) {
			$message = $result->get_error_message();
			return array(
				'success' => false,
				'message' => '' !== $message ? $message : $fallback,
			);
		}
		return array(
			'success' => false,
			'message' => $fallback,
		);
	}

	/**
	 * Map the raw `comment_approved` DB value to the REST-style status string.
	 *
	 * @param string $comment_approved Raw value from the comments table.
	 * @return string
	 */
	private static function map_status( string $comment_approved ): string {
		switch ( $comment_approved ) {
			case 'hold':
			case '0':
				return 'hold';
			case 'approve':
			case '1':
				return 'approved';
			default:
				return $comment_approved;
		}
	}
}
