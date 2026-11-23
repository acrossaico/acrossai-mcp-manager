<?php
/**
 * Which caller-supplied post-meta keys a generic post writer may set, and why it refused the rest.
 *
 * `content/update-post` is the "safe editor": a bulk field update where an agent may sweep in meta
 * it did not mean to touch. Feature 065 (FR-009) therefore had it strip protected meta. That strip
 * was two rules OR-ed together, and the second was too broad:
 *
 *   1. `_`-prefixed keys — WordPress's convention for internal meta (`_edit_lock`, `_wp_*`,
 *      serialized builder state). Writing these through a bulk update is a genuine footgun.
 *   2. `is_protected_meta()` — but that function guards the custom-fields UI and the REST meta
 *      endpoint, NOT programmatic `update_post_meta()`. A plugin marks its keys protected only to
 *      hide them from that box; the keys are ordinary writable data. Rank Math registers
 *      `rank_math_title`, `rank_math_description` and friends this way.
 *
 * So rule 2 dropped legitimate SEO meta that `content/create-post` and the dedicated
 * `content/update-post-meta` both write happily — `update-post-meta`'s own description is "Works
 * for ANY meta key, including protected keys." The result (#274): a create-then-refine workflow
 * saved its Rank Math meta on create and silently lost it on update, while the response reported
 * success. This keeps rule 1, drops rule 2.
 *
 * The `acrossai_allowed_protected_meta` filter still lets an operator opt a `_`-prefixed key back
 * in, exactly as FR-009 intended, and the same filter still governs what `content/get-post`
 * returns on the read side.
 *
 * Only `content/update-post` routes through this. `content/create-post` is left permissive on
 * purpose: it is the initial-write path and already writes `_`-prefixed keys such as
 * `_thumbnail_id` (the featured image), so applying this guard there would trade one data-loss bug
 * for another. `content/update-post-meta` is the escape hatch for writing a `_`-prefixed key
 * through an editor flow.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Utilities
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * The protected-meta policy for the generic post writers.
 */
final class Post_Meta_Policy {

	/**
	 * Split caller meta into what may be written and what was refused.
	 *
	 * Refuses only `_`-prefixed keys, and only when they are not in the
	 * `acrossai_allowed_protected_meta` allowlist. Everything else — including plugin-registered
	 * keys like `rank_math_*` — is returned as writable, because `update_post_meta()` handles it
	 * and the caller is already capability-gated by the ability.
	 *
	 * @since  0.0.42
	 * @param  array<string, mixed> $meta Column/key → value map from the caller.
	 * @return array{write: array<string, mixed>, dropped: array<int, string>}
	 */
	public static function partition( array $meta ): array {
		/**
		 * Keys that may be written even though they are `_`-prefixed internal meta.
		 *
		 * Published since Feature 065 and shared with `content/get-post`'s read filter. A site that
		 * deliberately manages a `_`-prefixed key through these abilities adds it here.
		 *
		 * @since 0.0.42 (behaviour since Feature 065)
		 * @param string[] $allowed Allow-listed protected keys.
		 */
		$allowed = (array) apply_filters( 'acrossai_allowed_protected_meta', array() );

		$write   = array();
		$dropped = array();

		foreach ( $meta as $key => $value ) {
			$key_str = (string) $key;

			if ( str_starts_with( $key_str, '_' ) && ! in_array( $key_str, $allowed, true ) ) {
				$dropped[] = $key_str;
				continue;
			}

			$write[ $key_str ] = $value;
		}

		return array(
			'write'   => $write,
			'dropped' => $dropped,
		);
	}

	/**
	 * A per-key warning explaining why a key was refused and where to write it instead.
	 *
	 * The honest-reporting half of #274: a caller that only checks `success` must still have a
	 * reason in `warnings`, not an empty array beside a populated `dropped_meta_keys`.
	 *
	 * @since  0.0.42
	 * @param  string $key The refused meta key.
	 * @return string
	 */
	public static function drop_warning( string $key ): string {
		return sprintf(
			/* translators: %s: the refused meta key. */
			__( 'Meta key "%s" was not written: it is an internal (underscore-prefixed) key this bulk update refuses. Use content/update-post-meta to set it deliberately, or allow it site-wide via the acrossai_allowed_protected_meta filter.', 'acrossai-abilities-manager' ),
			$key
		);
	}
}
