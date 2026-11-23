<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Media
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Media;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors WP_REST_Attachments_Controller::prepare_item_for_response() in the
 * edit context (minus _links/_embedded). Used by every Media ability so they
 * can drop their rest_do_request() calls without breaking the output shape.
 */
final class Media_Formatter {

	/**
	 * Map an attachment WP_Post into the abilities' canonical media array.
	 *
	 * @param WP_Post $post Attachment post.
	 * @return array<string, mixed>
	 */
	public static function to_array( WP_Post $post ): array {
		$id = (int) $post->ID;

		$caption_raw      = (string) $post->post_excerpt;
		$caption_rendered = apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', $caption_raw, $post ) );

		$description_raw      = (string) $post->post_content;
		$description_rendered = apply_filters( 'the_content', $description_raw );

		$title_raw      = (string) $post->post_title;
		$title_rendered = get_the_title( $post );

		$metadata = wp_get_attachment_metadata( $id );

		return array(
			'id'                  => $id,
			'date'                => mysql_to_rfc3339( $post->post_date ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core function, false positive
			'date_gmt'            => mysql_to_rfc3339( $post->post_date_gmt ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core function, false positive
			'guid'                => array(
				'rendered' => (string) apply_filters( 'get_the_guid', $post->guid, $id ),
				'raw'      => (string) $post->guid,
			),
			'modified'            => mysql_to_rfc3339( $post->post_modified ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core function, false positive
			'modified_gmt'        => mysql_to_rfc3339( $post->post_modified_gmt ), // phpcs:ignore PHPCompatibility.Extensions.RemovedExtensions.mysql_DeprecatedRemoved -- WordPress core function, false positive
			'slug'                => (string) $post->post_name,
			'status'              => (string) $post->post_status,
			'type'                => 'attachment',
			'link'                => (string) get_permalink( $post ),
			'title'               => array(
				'raw'      => $title_raw,
				'rendered' => (string) $title_rendered,
			),
			'author'              => (int) $post->post_author,
			'comment_status'      => (string) $post->comment_status,
			'ping_status'         => (string) $post->ping_status,
			'meta'                => self::build_meta_map( $id ),
			'alt_text'            => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'             => array(
				'raw'      => $caption_raw,
				'rendered' => (string) $caption_rendered,
			),
			'description'         => array(
				'raw'      => $description_raw,
				'rendered' => (string) $description_rendered,
			),
			'media_type'          => wp_attachment_is_image( $post ) ? 'image' : 'file',
			'mime_type'           => (string) $post->post_mime_type,
			'media_details'       => is_array( $metadata ) ? $metadata : array(),
			'post'                => (int) $post->post_parent,
			'source_url'          => (string) wp_get_attachment_url( $id ),
			'missing_image_sizes' => array(),
		);
	}

	/**
	 * Build the REST-equivalent meta map for an attachment — registered post-type
	 * meta with show_in_rest, scoped via subtype `attachment` when the helper exists.
	 *
	 * @param int $post_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public static function build_meta_map( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_registered_meta_keys' ) ) {
			return array();
		}

		$keys = get_registered_meta_keys( 'post', 'attachment' );
		if ( empty( $keys ) ) {
			$keys = get_registered_meta_keys( 'post' );
		}

		$out = array();
		foreach ( $keys as $key => $args ) {
			if ( empty( $args['show_in_rest'] ) ) {
				continue;
			}
			$single      = ! empty( $args['single'] );
			$out[ $key ] = get_post_meta( $post_id, $key, $single );
		}
		return $out;
	}

	/**
	 * Return true if a meta key is registered for `post` (or `attachment` subtype)
	 * AND exposed via REST. Mirrors the writable subset for Update_Media_Meta.
	 */
	public static function is_meta_key_writable( string $key ): bool {
		if ( '' === $key || ! function_exists( 'get_registered_meta_keys' ) ) {
			return false;
		}
		$keys = get_registered_meta_keys( 'post', 'attachment' );
		if ( isset( $keys[ $key ] ) && ! empty( $keys[ $key ]['show_in_rest'] ) ) {
			return true;
		}
		$keys = get_registered_meta_keys( 'post' );
		return isset( $keys[ $key ] ) && ! empty( $keys[ $key ]['show_in_rest'] );
	}

	/**
	 * Normalize a failure result into the abilities' error envelope.
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
}
