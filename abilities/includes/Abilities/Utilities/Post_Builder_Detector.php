<?php
/**
 * Feature 108 — works out how a post's content is actually stored and rendered.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies where a post's rendered content really comes from.
 *
 * The question this answers is not cosmetic. A page builder that owns a post keeps the real content
 * somewhere other than `post_content`, and every content writer in this plugin writes
 * `post_content`. On an Elementor post that combination fails three ways at once, and all three are
 * silent:
 *
 *   1. `Elementor\Frontend::apply_builder_in_content()` replaces `$content` wholesale on
 *      `the_content`, so the write changes nothing a visitor sees.
 *   2. `Elementor\Db::save_plain_text()` rewrites `post_content` from the widget tree on every save
 *      in the editor, so the write is reverted the next time anyone touches the page.
 *   3. The ability still reports success, because `wp_update_post()` genuinely succeeded.
 *
 * Two builders inverse the risk instead: WPBakery and Fusion render *from* `post_content` as
 * shortcodes, so a naive rewrite lands and corrupts the shortcode tree rather than doing nothing.
 * That is why `writes_to_post_content()` returns a three-way answer and not a boolean.
 *
 * Detection order matters: a builder flag wins over block markup, because Elementor also leaves
 * derived plain text in `post_content` and a Divi post keeps `[et_pb_*]` shortcodes there. Checking
 * `has_blocks()` first would mislabel both.
 *
 * @since 0.0.34
 */
final class Post_Builder_Detector {

	/**
	 * Rendered content comes from post_content; writing it works normally.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const WRITES_APPLY = 'applies';

	/**
	 * Rendered content comes from elsewhere; writing post_content is a silent no-op.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const WRITES_IGNORED = 'ignored';

	/**
	 * Rendered content comes from post_content but in a builder's own markup language, so a
	 * rewrite lands and damages it.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const WRITES_DESTRUCTIVE = 'destructive';

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The builders this can recognise, in detection order.
	 *
	 * Each entry: meta key that flags ownership, the value that counts as "yes" ('' = any truthy
	 * value), where the real content lives, how post_content behaves, and the plugin constant or
	 * class that proves the builder is still installed.
	 *
	 * Ordered deliberately. `wpbakery` and `fusion` are last among the flagged builders because
	 * they are matched on content markers rather than an ownership flag, which is weaker evidence.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	private static function builders(): array {
		return array(
			array(
				'slug'      => 'elementor',
				'label'     => 'Elementor',
				'meta_key'  => '_elementor_edit_mode',
				'meta_is'   => 'builder',
				'stored_in' => 'post meta _elementor_data (JSON)',
				'writes'    => self::WRITES_IGNORED,
				'active_if' => array( 'class' => '\\Elementor\\Plugin' ),
			),
			array(
				'slug'      => 'bricks',
				'label'     => 'Bricks',
				'meta_key'  => '_bricks_editor_mode',
				'meta_is'   => 'bricks',
				'stored_in' => 'post meta _bricks_page_content_2 (serialized)',
				'writes'    => self::WRITES_IGNORED,
				'active_if' => array( 'const' => 'BRICKS_VERSION' ),
			),
			array(
				'slug'      => 'divi',
				'label'     => 'Divi',
				'meta_key'  => '_et_pb_use_builder',
				'meta_is'   => 'on',
				'stored_in' => 'post_content as [et_pb_*] shortcodes',
				'writes'    => self::WRITES_DESTRUCTIVE,
				'active_if' => array( 'const' => 'ET_BUILDER_VERSION' ),
			),
			array(
				'slug'      => 'beaver-builder',
				'label'     => 'Beaver Builder',
				'meta_key'  => '_fl_builder_enabled',
				'meta_is'   => '',
				'stored_in' => 'post meta _fl_builder_data (serialized)',
				'writes'    => self::WRITES_IGNORED,
				'active_if' => array( 'class' => 'FLBuilderLoader' ),
			),
			array(
				'slug'      => 'oxygen',
				'label'     => 'Oxygen',
				'meta_key'  => 'ct_builder_shortcodes',
				'meta_is'   => '',
				'stored_in' => 'post meta ct_builder_shortcodes',
				'writes'    => self::WRITES_IGNORED,
				'active_if' => array( 'const' => 'CT_VERSION' ),
			),
			array(
				'slug'      => 'breakdance',
				'label'     => 'Breakdance',
				'meta_key'  => '_breakdance_data',
				'meta_is'   => '',
				'stored_in' => 'post meta _breakdance_data (JSON)',
				'writes'    => self::WRITES_IGNORED,
				'active_if' => array( 'const' => '__BREAKDANCE_VERSION' ),
			),
			array(
				'slug'      => 'zion-builder',
				'label'     => 'Zion Builder',
				'meta_key'  => '_zionbuilder_page_elements',
				'meta_is'   => '',
				'stored_in' => 'post meta _zionbuilder_page_elements (JSON)',
				'writes'    => self::WRITES_IGNORED,
				'active_if' => array( 'const' => 'ZIONBUILDER_VERSION' ),
			),
			array(
				'slug'      => 'seedprod',
				'label'     => 'SeedProd',
				'meta_key'  => '_seedprod_page',
				'meta_is'   => '',
				'stored_in' => 'post meta _seedprod_page (JSON)',
				'writes'    => self::WRITES_IGNORED,
				'active_if' => array( 'const' => 'SEEDPROD_VERSION' ),
			),
			array(
				'slug'      => 'siteorigin',
				'label'     => 'SiteOrigin Page Builder',
				'meta_key'  => 'panels_data',
				'meta_is'   => '',
				'stored_in' => 'post meta panels_data (serialized)',
				'writes'    => self::WRITES_IGNORED,
				'active_if' => array( 'const' => 'SITEORIGIN_PANELS_VERSION' ),
			),
			array(
				'slug'      => 'fusion',
				'label'     => 'Avada / Fusion Builder',
				'meta_key'  => 'fusion_builder_status',
				'meta_is'   => 'active',
				'stored_in' => 'post_content as [fusion_*] shortcodes',
				'writes'    => self::WRITES_DESTRUCTIVE,
				'active_if' => array( 'const' => 'FUSION_BUILDER_VERSION' ),
			),
		);
	}

	/**
	 * Builders identified by a marker in post_content rather than an ownership flag.
	 *
	 * Weaker evidence than a meta flag, so these run only after every flagged builder has been
	 * ruled out — and before the block check, because their markup is not block markup.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	private static function content_markers(): array {
		return array(
			array(
				'slug'      => 'wpbakery',
				'label'     => 'WPBakery Page Builder',
				'marker'    => '[vc_row',
				'stored_in' => 'post_content as [vc_*] shortcodes',
				'writes'    => self::WRITES_DESTRUCTIVE,
				'active_if' => array( 'const' => 'WPB_VC_VERSION' ),
			),
			array(
				'slug'      => 'divi',
				'label'     => 'Divi',
				'marker'    => '[et_pb_section',
				'stored_in' => 'post_content as [et_pb_*] shortcodes',
				'writes'    => self::WRITES_DESTRUCTIVE,
				'active_if' => array( 'const' => 'ET_BUILDER_VERSION' ),
			),
			array(
				'slug'      => 'fusion',
				'label'     => 'Avada / Fusion Builder',
				'marker'    => '[fusion_builder_container',
				'stored_in' => 'post_content as [fusion_*] shortcodes',
				'writes'    => self::WRITES_DESTRUCTIVE,
				'active_if' => array( 'const' => 'FUSION_BUILDER_VERSION' ),
			),
		);
	}

	/**
	 * Classify one post.
	 *
	 * @since  0.0.34
	 * @param  int $post_id Post ID.
	 * @return array<string, mixed>|null Null when the post does not exist.
	 */
	public static function detect( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$signals = array();

		foreach ( self::builders() as $builder ) {
			$api = self::ask_builder( (string) $builder['slug'], $post_id );

			if ( is_bool( $api ) ) {
				// The builder answered for itself. Trust it over the meta key and stop.
				if ( ! $api ) {
					continue;
				}

				$signals[] = self::signal( $builder['slug'] . ' API', 'true', 'builder confirmed ownership' );

				return self::result( $post, $builder, $signals );
			}

			$value = get_post_meta( $post_id, $builder['meta_key'], true );

			if ( ! self::flag_matches( $value, (string) $builder['meta_is'] ) ) {
				continue;
			}

			$signals[] = self::signal( $builder['meta_key'], $value, 'post meta' );

			return self::result( $post, $builder, $signals );
		}

		foreach ( self::content_markers() as $builder ) {
			if ( false === strpos( (string) $post->post_content, (string) $builder['marker'] ) ) {
				continue;
			}

			$signals[] = self::signal( 'post_content', $builder['marker'], 'content marker' );

			return self::result( $post, $builder, $signals );
		}

		return self::result( $post, self::core_editor( $post, $signals ), $signals );
	}

	/**
	 * Let a builder answer for itself, when it exposes a public way to.
	 *
	 * Prefer the builder's own API over reading its meta key: the meta key is an implementation
	 * detail the builder may change, and its API will change with it. The meta fallback still has
	 * to exist, though — a post stays owned by a builder after the plugin is deactivated, which is
	 * exactly the case where the answer matters most and the API is unreachable.
	 *
	 * Elementor's `Db::is_built_with_elementor()` is deprecated as of 3.2.0 in favour of the
	 * document; only the document form is used here.
	 *
	 * @since  0.0.34
	 * @param  string $slug    Builder slug.
	 * @param  int    $post_id Post ID.
	 * @return bool|null Null when the builder cannot be asked.
	 */
	private static function ask_builder( string $slug, int $post_id ): ?bool {
		if ( 'elementor' !== $slug || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return null;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( ! isset( $plugin->documents ) ) {
			return null;
		}

		// Returns false — not null — for an unknown or unsupported post.
		$document = $plugin->documents->get( $post_id );

		if ( ! $document || ! method_exists( $document, 'is_built_with_elementor' ) ) {
			return null;
		}

		return (bool) $document->is_built_with_elementor();
	}

	/**
	 * Whether a stored flag counts as ownership.
	 *
	 * `$expected` of '' means any truthy value, which is how the builders that store data rather
	 * than a mode string signal ownership.
	 *
	 * @since  0.0.34
	 * @param  mixed  $value    Stored meta value.
	 * @param  string $expected Required value, or '' for any truthy one.
	 * @return bool
	 */
	private static function flag_matches( $value, string $expected ): bool {
		if ( '' !== $expected ) {
			return is_scalar( $value ) && (string) $value === $expected;
		}

		if ( is_array( $value ) ) {
			return array() !== $value;
		}

		return is_scalar( $value ) && '' !== (string) $value && '0' !== (string) $value;
	}

	/**
	 * Classify a post no builder claimed: block editor, classic, or empty.
	 *
	 * `has_blocks()` is a substring test for `<!-- wp:` and cannot tell an empty post from a
	 * classic one, so emptiness is checked first. That distinction matters to a caller: writing to
	 * an empty post is always safe, writing over classic content replaces someone's markup.
	 *
	 * @since  0.0.34
	 * @param  WP_Post                          $post    Post.
	 * @param  array<int, array<string, mixed>> $signals Collected signals, by reference.
	 * @return array<string, mixed>
	 */
	private static function core_editor( WP_Post $post, array &$signals ): array {
		if ( '' === trim( (string) $post->post_content ) ) {
			$signals[] = self::signal( 'post_content', '', 'empty' );

			return array(
				'slug'      => 'empty',
				'label'     => __( 'Empty', 'acrossai-abilities-manager' ),
				'stored_in' => 'post_content (currently empty)',
				'writes'    => self::WRITES_APPLY,
				'active_if' => array(),
			);
		}

		if ( has_blocks( $post ) ) {
			$signals[] = self::signal( 'post_content', '<!-- wp:', 'block delimiter' );

			return array(
				'slug'      => 'block-editor',
				'label'     => __( 'Block editor', 'acrossai-abilities-manager' ),
				'stored_in' => 'post_content as block markup',
				'writes'    => self::WRITES_APPLY,
				'active_if' => array(),
			);
		}

		$signals[] = self::signal( 'post_content', '', 'no block delimiter and no builder flag' );

		return array(
			'slug'      => 'classic',
			'label'     => __( 'Classic editor', 'acrossai-abilities-manager' ),
			'stored_in' => 'post_content as raw HTML',
			'writes'    => self::WRITES_APPLY,
			'active_if' => array(),
		);
	}

	/**
	 * One evidence row.
	 *
	 * Rows, not a map — an `array`-typed output property fed an associative array encodes as a
	 * JSON object and fails the ability's own schema (BUG-ARRAY-TYPED-OUTPUT-IS-A-JSON-OBJECT).
	 *
	 * @since  0.0.34
	 * @param  string $source Where the signal was read.
	 * @param  mixed  $value  What was found.
	 * @param  string $kind   How to read it.
	 * @return array<string, string>
	 */
	private static function signal( string $source, $value, string $kind ): array {
		return array(
			'source' => $source,
			'value'  => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			'kind'   => $kind,
		);
	}

	/**
	 * Assemble the answer.
	 *
	 * @since  0.0.34
	 * @param  WP_Post                          $post    Post.
	 * @param  array<string, mixed>             $builder Matched builder descriptor.
	 * @param  array<int, array<string, mixed>> $signals Evidence.
	 * @return array<string, mixed>
	 */
	private static function result( WP_Post $post, array $builder, array $signals ): array {
		$active = self::is_active( (array) $builder['active_if'] );

		return array(
			'post_id'             => (int) $post->ID,
			'post_type'           => (string) $post->post_type,
			'builder'             => (string) $builder['slug'],
			'builder_label'       => (string) $builder['label'],
			'builder_active'      => $active,
			'content_stored_in'   => (string) $builder['stored_in'],
			'post_content_writes' => (string) $builder['writes'],
			'post_content_bytes'  => strlen( (string) $post->post_content ),
			'signals'             => $signals,
			'guidance'            => self::guidance( $builder, $active ),
		);
	}

	/**
	 * Whether the builder that owns this post is still installed.
	 *
	 * Worth reporting separately from ownership, because the two come apart and the advice differs.
	 * A post flagged for a builder whose plugin has been removed renders whatever is in
	 * `post_content` — usually the builder's stale derived text — so a write to it DOES take effect
	 * now and will be reverted if the builder ever comes back.
	 *
	 * @since  0.0.34
	 * @param  array<string, string> $test Class or constant to probe.
	 * @return bool
	 */
	private static function is_active( array $test ): bool {
		if ( isset( $test['class'] ) ) {
			return class_exists( $test['class'] );
		}

		if ( isset( $test['const'] ) ) {
			return defined( $test['const'] );
		}

		return true;
	}

	/**
	 * The sentence a caller should act on.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $builder Matched builder descriptor.
	 * @param  bool                 $active  Whether the builder is installed.
	 * @return string
	 */
	private static function guidance( array $builder, bool $active ): string {
		$label = (string) $builder['label'];

		if ( self::WRITES_APPLY === $builder['writes'] ) {
			if ( 'empty' === $builder['slug'] ) {
				return __( 'This post has no content yet. Writing post_content is safe.', 'acrossai-abilities-manager' );
			}

			if ( 'block-editor' === $builder['slug'] ) {
				return __( 'Block editor content. Writing post_content works — prefer the blocks/* abilities for narrow edits so the rest of the block tree is left alone.', 'acrossai-abilities-manager' );
			}

			return __( 'Classic HTML in post_content. Writing post_content works and replaces the whole body.', 'acrossai-abilities-manager' );
		}

		if ( self::WRITES_DESTRUCTIVE === $builder['writes'] ) {
			return sprintf(
				/* translators: %s: builder name */
				__( 'Built with %s, which keeps its layout in post_content as its own shortcodes. Writing post_content DOES take effect, which is the danger — replacing the body destroys the layout, and there is no undo. Edit the existing shortcodes rather than replacing them.', 'acrossai-abilities-manager' ),
				$label
			);
		}

		if ( ! $active ) {
			return sprintf(
				/* translators: 1: builder name, 2: where the content lives */
				__( 'Built with %1$s, but %1$s is not active on this site, so post_content is what renders right now. Its real content is still in %2$s and will take over again if the plugin is reactivated — any edit made now would then disappear.', 'acrossai-abilities-manager' ),
				$label,
				(string) $builder['stored_in']
			);
		}

		return sprintf(
			/* translators: 1: builder name, 2: where the content lives */
			__( 'Built with %1$s. Its content lives in %2$s, and %1$s replaces post_content when the page renders. Writing post_content will report success, change nothing a visitor sees, and be overwritten the next time the page is saved in %1$s. Use the %1$s abilities instead.', 'acrossai-abilities-manager' ),
			$label,
			(string) $builder['stored_in']
		);
	}
}
