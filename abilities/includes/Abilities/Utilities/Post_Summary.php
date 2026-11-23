<?php
/**
 * Feature 128 — one shape for "list me posts without the bodies".
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.36
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Trims a WP_Post down to what a browsing caller actually needs.
 *
 * The three list abilities each returned `(array) $post` — every one of WP_Post's 24 fields,
 * including `post_content` and `post_content_filtered`. Measured against the live site: ten posts
 * from `content/list-posts` came to roughly 243 KB, almost all of it bodies nobody asked for. An
 * AI caller browsing a site pays for every one of those tokens before it knows which post it wants.
 *
 * `summary` answers "which post is it", not "what does it say". `content_bytes` is kept so the
 * caller can tell a stub from a long essay before deciding to fetch one — the number that makes
 * the follow-up read predictable.
 *
 * The default stays `full` everywhere, so a caller that passes nothing gets exactly what it got
 * before, byte for byte.
 *
 * @since 0.0.36
 */
final class Post_Summary {

	/**
	 * How much of the excerpt a summary row carries.
	 *
	 * Enough to recognise a post, short enough that a hundred rows stay cheap.
	 *
	 * @since 0.0.36
	 * @var   int
	 */
	public const EXCERPT_CHARS = 160;

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The `fields` input property, shared by every list ability that offers it.
	 *
	 * Declared here so the three cannot drift apart: a caller that learns `summary` on one should
	 * not find it spelled differently on the next.
	 *
	 * @since  0.0.36
	 * @return array<string, mixed>
	 */
	public static function input_property(): array {
		return array(
			'type'        => 'string',
			'enum'        => array( 'full', 'summary' ),
			'default'     => 'full',
			'description' => __( 'How much of each item to return. "full" (the default) returns every post field including the whole post_content. "summary" returns only what is needed to recognise an item — title, status, dates, slug, author, a trimmed excerpt and content_bytes — and is dramatically cheaper when browsing.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Whether the caller asked for summaries.
	 *
	 * Anything other than the exact string `summary` means full, so an unrecognised value fails
	 * towards today's behaviour rather than silently dropping content the caller expected.
	 *
	 * @since  0.0.36
	 * @param  array<string, mixed> $input Ability input.
	 * @return bool
	 */
	public static function wants_summary( array $input ): bool {
		return isset( $input['fields'] ) && 'summary' === $input['fields'];
	}

	/**
	 * Shape one row.
	 *
	 * @since  0.0.36
	 * @param  \WP_Post $post    Post object.
	 * @param  bool     $summary Whether to summarise.
	 * @return array<string, mixed>
	 */
	public static function row( \WP_Post $post, bool $summary ): array {
		if ( ! $summary ) {
			return (array) $post;
		}

		return array(
			'ID'            => (int) $post->ID,
			'post_title'    => (string) $post->post_title,
			'post_status'   => (string) $post->post_status,
			'post_type'     => (string) $post->post_type,
			'post_date'     => (string) $post->post_date,
			'post_modified' => (string) $post->post_modified,
			'post_name'     => (string) $post->post_name,
			'post_excerpt'  => self::excerpt( $post ),
			'post_author'   => (int) $post->post_author,
			'content_bytes' => strlen( (string) $post->post_content ),
		);
	}

	/**
	 * Shape a list of rows.
	 *
	 * @since  0.0.36
	 * @param  array<int, mixed> $posts   Post objects.
	 * @param  bool              $summary Whether to summarise.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows( array $posts, bool $summary ): array {
		$out = array();

		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$out[] = self::row( $post, $summary );
			}
		}

		return $out;
	}

	/**
	 * A short excerpt, falling back to the body when the author wrote none.
	 *
	 * Most posts have an empty `post_excerpt`, so returning it verbatim would make the summary
	 * useless on exactly the sites that need it most. The body is stripped of tags and shortcodes
	 * first, because a summary carrying raw markup defeats the point.
	 *
	 * @since  0.0.36
	 * @param  \WP_Post $post Post object.
	 * @return string
	 */
	private static function excerpt( \WP_Post $post ): string {
		$text = trim( (string) $post->post_excerpt );

		if ( '' === $text ) {
			$text = (string) $post->post_content;
			$text = strip_shortcodes( $text );
			$text = wp_strip_all_tags( $text );
		}

		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );

		if ( '' === $text ) {
			return '';
		}

		// mb_strimwidth keeps the cut inside a character rather than splitting a multibyte one.
		return function_exists( 'mb_strimwidth' )
			? mb_strimwidth( $text, 0, self::EXCERPT_CHARS, '…' )
			: substr( $text, 0, self::EXCERPT_CHARS );
	}

	/**
	 * The output shape of a summary row, for an ability's output_schema.
	 *
	 * @since  0.0.36
	 * @return array<string, mixed>
	 */
	public static function output_item_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => true,
			'description'          => __( 'With fields: "full" this is the complete post record. With fields: "summary" it is ID, post_title, post_status, post_type, post_date, post_modified, post_name, post_excerpt, post_author and content_bytes.', 'acrossai-abilities-manager' ),
		);
	}
}
