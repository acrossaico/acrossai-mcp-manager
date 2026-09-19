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
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes/Utilities
 * @since      0.0.34
 */

namespace AcrossAI_MCP_Manager\Includes\Abilities\Toolset;

use AcrossAI_MCP_Manager\Includes\Abilities\ToolAbilities;
use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Answers which abilities belong to a group.
 */
class AbilityGroup {
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

		// Tool-level abilities are never MEMBERS of a group — a dispatcher must
		// not appear inside the group it serves.
		//
		// `ToolAbilities::get_slugs()` is already exactly that list, from BOTH
		// plugins: every Toolset declares its own slug into
		// `acrossai_mcp_manager_tool_abilities`, so the add-on's integration
		// dispatchers are in here too without this plugin naming one of them.
		$protected = ToolAbilities::get_slugs();

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			if ( in_array( $ability->get_name(), $protected, true ) ) {
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
