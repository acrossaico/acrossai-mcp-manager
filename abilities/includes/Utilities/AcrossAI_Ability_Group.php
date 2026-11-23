<?php
/**
 * Group membership lookup — which abilities belong to a given group.
 *
 * A group is the task-shaped grouping introduced by Feature 101 and shown as
 * the Integrations screen's tabs. It is declared per ability at
 * `meta.acrossai.tab_group`, so answering "what is in the Content group"
 * means walking the registered abilities and reading that value. Doing that
 * inline in every caller is how two callers end up disagreeing, so it lives
 * here and only here.
 *
 * **This returns registration facts only.** It does not apply visibility or
 * permission decisions, and it must not: both vary between requests. The
 * equivalent extension point already shipped in the connected transport
 * resolves from the identifier of the connection handling the current
 * request, so a caller that stores a filtered list would serve one
 * connection's view of the catalogue to another. Filter after calling this,
 * never inside it.
 *
 * **Storing the answer between requests is deliberately not implemented.**
 * Deriving a group filters a few hundred already-loaded objects, which is
 * cheap, and the hard part of caching is invalidation rather than expiry —
 * what registers changes when this plugin is activated or deactivated, when
 * any plugin is, when an update completes, when the Integrations settings are
 * saved, when an ability is created or deleted, when an override changes, and
 * when the active site changes. `resolve()` is the single seam where such a
 * cache would go, so adding it later changes nothing for any caller.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Protected_Abilities;
use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Answers which abilities belong to a group.
 */
class AcrossAI_Ability_Group {
	/*
	 * Moved here from Includes\Modules\Library in Feature 102.
	 *
	 * Three areas now resolve toolset membership through this class — Modules\Library,
	 * Includes\Abilities\Toolset, and Modules\Abilities (the REST toolset filter and counts
	 * route). Constitution VI requires shared logic to move to includes/Utilities/ before its
	 * second consumer, and Module Contract #3 forbids a module depending on a sibling module
	 * directly, which is what keeping it under Modules\Library would have forced.
	 *
	 * This class remains the single seam for group resolution: it owns the meta.acrossai.tab_group
	 * read and the protected-slug exclusion that keeps toolset dispatchers out of their own
	 * toolsets. Callers must not re-implement either (DEC-PROTECTED-SLUGS-PATTERN).
	 */

	/**
	 * Per-request memo, keyed by group. Not persisted — see the class docblock.
	 *
	 * @var array<string, array<int, WP_Ability>>|null
	 */
	private static ?array $memo = null;

	/**
	 * Every registered ability in a group, in a stable order.
	 *
	 * Ordered by ability name so two calls in one request, and two requests on
	 * one site, return the same sequence. Callers that page a result depend on
	 * that; a registration-order result would shift under them.
	 *
	 * @since  0.0.34
	 * @param  string $group Group identifier, e.g. `content`.
	 * @return array<int, WP_Ability> Matching abilities, or an empty array.
	 */
	public static function members( string $group ): array {
		if ( '' === $group ) {
			return array();
		}

		return self::resolve()[ $group ] ?? array();
	}

	/**
	 * The ability names in a group, in the same order as members().
	 *
	 * @since  0.0.34
	 * @param  string $group Group identifier.
	 * @return string[] Ability names.
	 */
	public static function member_names( string $group ): array {
		return array_map(
			static fn( WP_Ability $ability ): string => $ability->get_name(),
			self::members( $group )
		);
	}

	/**
	 * The group an ability belongs to.
	 *
	 * @since  0.0.34
	 * @param  WP_Ability $ability The ability.
	 * @return string Group identifier, or '' when it declares none.
	 */
	public static function of( WP_Ability $ability ): string {
		$meta = $ability->get_meta_item( 'acrossai' );

		if ( ! is_array( $meta ) || ! isset( $meta['tab_group'] ) ) {
			return '';
		}

		return (string) $meta['tab_group'];
	}

	/**
	 * Every group present on this site, with its member count.
	 *
	 * Sorted by count descending then name ascending, matching the ordering the
	 * Integrations screen's own summary uses.
	 *
	 * @since  0.0.34
	 * @return array<string, int> Group identifier => member count.
	 */
	public static function counts(): array {
		$counts = array();

		foreach ( self::resolve() as $group => $abilities ) {
			$counts[ $group ] = count( $abilities );
		}

		uksort(
			$counts,
			static function ( string $a, string $b ) use ( $counts ): int {
				return $counts[ $b ] === $counts[ $a ]
					? strcmp( $a, $b )
					: $counts[ $b ] <=> $counts[ $a ];
			}
		);

		return $counts;
	}

	/**
	 * Discard the per-request memo.
	 *
	 * Only needed by tests, and by any caller that registers or unregisters an
	 * ability mid-request. Not a cache invalidation hook — nothing is stored
	 * beyond the current request.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = null;
	}

	/**
	 * Build the group => abilities map from the registered abilities.
	 *
	 * The single seam. Everything above reads this, and a future cache would
	 * wrap this and nothing else.
	 *
	 * Excludes abilities that declare no group, and those marked as protected
	 * system infrastructure — the latter are dispatchers and diagnostics rather
	 * than things a group is meant to contain.
	 *
	 * @since  0.0.34
	 * @return array<string, array<int, WP_Ability>>
	 */
	private static function resolve(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$map = array();

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			self::$memo = $map;
			return $map;
		}

		$protected = class_exists( AcrossAI_Protected_Abilities::class )
			? AcrossAI_Protected_Abilities::get_protected_slugs()
			: array();

		/**
		 * Protected slugs that may nonetheless be a member of their OWN group.
		 *
		 * Protection keeps dispatchers out of groups because they kept turning
		 * up in groups they did not belong to — `toolset/other` once listed the
		 * server guide that describes how to call a Toolset, because the guide
		 * carried no `tab_group` and the tagger filed it in the catch-all.
		 *
		 * That is an argument against the CATCH-ALL, not against a dispatcher
		 * ever being grouped. One that declares a `tab_group` of its own, and
		 * is the only thing in it, is not being mis-filed — it is asking for a
		 * tab, which is the only way an operator can find it on a screen with
		 * thirty of them.
		 *
		 * Opt-in rather than a rule, so the default stays exactly as it was:
		 * nothing is grouped unless it names itself here, so the bug above
		 * cannot come back by accident.
		 *
		 * @since 0.0.45
		 *
		 * @param string[] $slugs Protected slugs allowed to own a group.
		 */
		$groupable = (array) apply_filters( 'acrossai_abilities_manager_groupable_protected_slugs', array() );

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			$name = $ability->get_name();

			if ( in_array( $name, $protected, true ) && ! in_array( $name, $groupable, true ) ) {
				continue;
			}

			$group = self::of( $ability );

			if ( '' === $group ) {
				continue;
			}

			$map[ $group ][] = $ability;
		}

		foreach ( $map as $group => $abilities ) {
			usort(
				$abilities,
				static fn( WP_Ability $a, WP_Ability $b ): int => strcmp( $a->get_name(), $b->get_name() )
			);
			$map[ $group ] = $abilities;
		}

		self::$memo = $map;

		return $map;
	}
}
