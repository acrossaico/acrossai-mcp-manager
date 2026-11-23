<?php
/**
 * Gives an ability a toolset when its own author did not.
 *
 * `AcrossAI_Ability_Group::of()` reads `meta.acrossai.tab_group` and returns `''` when it is absent —
 * and an ability registered by another plugin has no reason to set a key in this plugin's namespace.
 * Such an ability then belongs to no group: no tab, no count, `—` in the Toolset column, and
 * unreachable through every MCP Toolset dispatcher. On one measured site that was 36 of 425 abilities,
 * with nothing anywhere reporting it (issue #184).
 *
 * The fix is applied at registration, through the core `wp_register_ability_args` filter, rather than
 * as a fallback inside the readers. Two reasons, both load-bearing:
 *
 * 1. There is more than one reader. `AcrossAI_Ability_Group::of()` and
 *    `AcrossAI_Ability_Merger::normalize_registry()` read the same meta path independently, so a
 *    fallback in one would group the ability in the tab strip while the Toolset column still showed a
 *    dash. Writing the value once means every reader — including the MCP dispatchers and anything
 *    added later — sees the same answer without knowing this class exists.
 * 2. The value becomes real. It is on the `WP_Ability`, not synthesised per read.
 *
 * **It only ever fills a gap.** An ability that already declares a group is returned untouched. That
 * single rule is what lets an integration be supplied entirely by its host plugin, entirely by us, or
 * by both at once, with no coordination between the halves.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

use AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\AcrossAI_Toolset_Integrations;
use AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\AcrossAI_Toolset_Sub_Groups;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns a `tab_group`, and where the integration declares how, a `sub_group`.
 */
class AcrossAI_Ability_Group_Tagger {

	/**
	 * Abilities placed individually, because their plugin does not warrant a toolset.
	 *
	 * WordPress core's abilities are placed here because a rule keyed on the name prefix would produce
	 * a `core` group, and Feature 101 retired `core` deliberately after it had accumulated 105
	 * abilities as a catch-all. Placing these three by hand is what keeps that decision from being
	 * undone by a general rule.
	 *
	 * Only genuinely first-party abilities belong in this list. An ability from a third-party plugin
	 * with no toolset of its own — MCP Tracker's, for instance — is left to fall through to the
	 * catch-all instead. Placing it in one of our curated groups would imply this plugin owns it, and
	 * would quietly grow Diagnostics into the same bucket `core` became.
	 *
	 * @since 0.0.34
	 * @var   array<string, string>
	 */
	private const ABILITY_GROUPS = array(
		'core/get-site-info'        => 'diagnostics',
		'core/get-environment-info' => 'diagnostics',
		'core/get-user-info'        => 'users',
	);

	/**
	 * Abilities whose sub-group came from `default_sub_group()` rather than a rule, keyed by name.
	 *
	 * Per request, like the rest of this class — registration happens once per request and the set
	 * depends on which plugins are active.
	 *
	 * @since 0.0.42
	 * @var   array<string, string> Ability name => group.
	 */
	private static $defaulted = array();

	/**
	 * Tag an ability's registration args when they carry no group.
	 *
	 * Hooked to `wp_register_ability_args` at priority 10 — long before
	 * `AcrossAI_Ability_Override_Processor` injects DB overrides at P100000. The two touch different
	 * keys, so the ordering is for clarity rather than correctness.
	 *
	 * @since  0.0.34
	 * @param  mixed  $args Registration args.
	 * @param  string $name Ability name, namespaced.
	 * @return mixed Args, with `meta.acrossai.tab_group` filled when it was missing.
	 */
	public static function tag( $args, $name = '' ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$name = (string) $name;

		if ( '' === $name ) {
			return $args;
		}

		// A Toolset dispatcher declares `meta.acrossai.toolset` and no group, on purpose: it must not
		// appear inside the group it serves. Tagging it would put it in its own toolset.
		$acrossai = ( isset( $args['meta']['acrossai'] ) && is_array( $args['meta']['acrossai'] ) )
			? $args['meta']['acrossai']
			: array();

		if ( ! empty( $acrossai['toolset'] ) ) {
			return $args;
		}

		// Gap-fill is per key, not per ability. An ability that declares a group but no sub-group
		// gets the half it is missing — returning early on a declared group, as this did before
		// sub-groups existed, would leave such an ability unreachable by `sub_group` forever.
		$declared = self::declared_group( $args );
		$group    = '' !== $declared ? $declared : self::resolve( $name );

		if ( '' === $group ) {
			return $args;
		}

		$changed = false;

		if ( '' === $declared ) {
			$acrossai['tab_group'] = $group;
			$changed               = true;
		}

		if ( ! isset( $acrossai['sub_group'] ) || '' === (string) $acrossai['sub_group'] ) {
			list( $sub_group, $label ) = self::resolve_sub_group( $name, $group, $args );

			if ( '' !== $sub_group ) {
				$acrossai['sub_group'] = $sub_group;
				$changed               = true;

				if ( '' !== $label && ( ! isset( $acrossai['sub_group_label'] ) || '' === (string) $acrossai['sub_group_label'] ) ) {
					$acrossai['sub_group_label'] = $label;
				}
			}
		}

		if ( ! $changed ) {
			return $args;
		}

		$args['meta']             = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$args['meta']['acrossai'] = $acrossai;

		return $args;
	}

	/**
	 * The sub-group an ability with none belongs to, and its label.
	 *
	 * Asks the integration that owns the group. Most do not implement
	 * {@see AcrossAI_Toolset_Sub_Groups} — including the catch-all, which is why nothing in `other`
	 * is ever given a sub-group — and those return no answer, leaving the key unset exactly as
	 * before.
	 *
	 * @since  0.0.42
	 * @param  string               $name  Ability name, namespaced.
	 * @param  string               $group Resolved group key.
	 * @param  array<string, mixed> $args  Registration args, for `meta.mcp.type`.
	 * @return array{0: string, 1: string} Sub-group key and label; both '' when unplaced.
	 */
	private static function resolve_sub_group( string $name, string $group, array $args ): array {
		$integration = AcrossAI_Toolset_Integrations::for_group( $group );
		$sub_group   = '';

		if ( $integration instanceof AcrossAI_Toolset_Sub_Groups ) {
			$overrides = $integration->sub_group_overrides();
			$suffix    = self::name_suffix( $name );

			if ( isset( $overrides[ $name ] ) ) {
				$sub_group = (string) $overrides[ $name ];
			} else {
				$sub_group = self::match_rules( $integration->sub_group_rules(), $suffix, $args );

				if ( '' === $sub_group ) {
					$sub_group = (string) $integration->default_sub_group();

					// Recorded, not just assigned. A rule that matched is a decision someone made;
					// the default is the absence of one, and the only signal that a host plugin has
					// shipped an ability nobody has classified yet. Read by
					// `diagnostics/list-unclassified-abilities`.
					if ( '' !== $sub_group ) {
						self::$defaulted[ $name ] = $group;
					}
				}
			}
		}

		/**
		 * Filters the sub-group assigned to an ability that declared none.
		 *
		 * Prefer implementing {@see AcrossAI_Toolset_Sub_Groups} on the integration — it keeps the
		 * rules next to the prefix claim and the labels. This exists for a sibling plugin that needs
		 * to correct one placement without owning the descriptor.
		 *
		 * @since 0.0.42
		 * @param string               $sub_group Sub-group key, or '' to leave unset.
		 * @param string               $name      Ability name.
		 * @param string               $group     Resolved group key.
		 * @param array<string, mixed> $args      Registration args.
		 */
		$sub_group = (string) apply_filters( 'acrossai_ability_sub_group_tagger', $sub_group, $name, $group, $args );

		if ( '' === $sub_group ) {
			return array( '', '' );
		}

		$labels = $integration instanceof AcrossAI_Toolset_Sub_Groups ? $integration->sub_group_labels() : array();
		$label  = isset( $labels[ $sub_group ] ) ? (string) $labels[ $sub_group ] : '';

		return array( $sub_group, $label );
	}

	/**
	 * First matching rule's sub-group, or ''.
	 *
	 * @since  0.0.42
	 * @param  array<int, array<string, mixed>> $rules  Ordered rules.
	 * @param  string                           $suffix Ability name after the namespace slash.
	 * @param  array<string, mixed>             $args   Registration args.
	 * @return string
	 */
	private static function match_rules( array $rules, string $suffix, array $args ): string {
		$is_resource = 'resource' === ( $args['meta']['mcp']['type'] ?? '' );

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['sub_group'] ) ) {
				continue;
			}

			if ( isset( $rule['resource'] ) && (bool) $rule['resource'] !== $is_resource ) {
				continue;
			}

			if ( ! self::matches_keywords( $rule, $suffix ) || ! self::matches_patterns( $rule, $suffix ) ) {
				continue;
			}

			return (string) $rule['sub_group'];
		}

		return '';
	}

	/**
	 * Whether a rule's keywords allow this name. A rule with none allows every name.
	 *
	 * @since  0.0.42
	 * @param  array<string, mixed> $rule   One rule.
	 * @param  string               $suffix Ability name after the slash.
	 * @return bool
	 */
	private static function matches_keywords( array $rule, string $suffix ): bool {
		if ( empty( $rule['keywords'] ) || ! is_array( $rule['keywords'] ) ) {
			return true;
		}

		foreach ( $rule['keywords'] as $keyword ) {
			if ( '' !== (string) $keyword && false !== strpos( $suffix, (string) $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a rule's patterns allow this name. A rule with none allows every name.
	 *
	 * @since  0.0.42
	 * @param  array<string, mixed> $rule   One rule.
	 * @param  string               $suffix Ability name after the slash.
	 * @return bool
	 */
	private static function matches_patterns( array $rule, string $suffix ): bool {
		if ( empty( $rule['patterns'] ) || ! is_array( $rule['patterns'] ) ) {
			return true;
		}

		foreach ( $rule['patterns'] as $pattern ) {
			$pattern = (string) $pattern;

			if ( '' === $pattern ) {
				continue;
			}

			if ( '*' === substr( $pattern, -1 ) ) {
				if ( 0 === strpos( $suffix, substr( $pattern, 0, -1 ) ) ) {
					return true;
				}

				continue;
			}

			if ( $pattern === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The part of an ability name after its namespace.
	 *
	 * Rules match on this rather than the full name so a rule reads the way the ability does —
	 * `manage-classes`, not `elementor/manage-classes`.
	 *
	 * @since  0.0.42
	 * @param  string $name Ability name.
	 * @return string
	 */
	private static function name_suffix( string $name ): string {
		$slash = strpos( $name, '/' );

		return false !== $slash ? substr( $name, $slash + 1 ) : $name;
	}

	/**
	 * Abilities placed by an integration's default rather than by one of its rules.
	 *
	 * An empty result is the healthy state: every foreign ability matched a rule someone wrote. A
	 * non-empty one names the abilities a host plugin has shipped that nobody has classified — which
	 * is the signal #184 and #255 both lacked.
	 *
	 * @since  0.0.42
	 * @return array<string, string> Ability name => group.
	 */
	public static function defaulted(): array {
		return self::$defaulted;
	}

	/**
	 * Forget what was recorded. For tests; registration is once per request in production.
	 *
	 * @since  0.0.42
	 * @return void
	 */
	public static function reset(): void {
		self::$defaulted = array();
	}

	/**
	 * The group an untagged ability belongs to.
	 *
	 * Order matters: a per-ability placement beats its plugin's prefix, so one ability can be pulled
	 * out of an integration's group without disturbing the rest.
	 *
	 * @since  0.0.34
	 * @param  string $name Ability name.
	 * @return string Group key, or '' to leave the ability ungrouped.
	 */
	public static function resolve( string $name ): string {
		$abilities = self::ability_map();

		if ( isset( $abilities[ $name ] ) ) {
			return (string) $abilities[ $name ];
		}

		$prefixes = self::prefix_map();
		$slash    = strpos( $name, '/' );
		$prefix   = false !== $slash ? substr( $name, 0, $slash ) : $name;

		if ( isset( $prefixes[ $prefix ] ) ) {
			return (string) $prefixes[ $prefix ];
		}

		return AcrossAI_Toolset_Integrations::CATCH_ALL_GROUP;
	}

	/**
	 * Per-ability placements, filterable.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	private static function ability_map(): array {
		/**
		 * Filters the per-ability toolset placements.
		 *
		 * Keyed by full ability name. Takes precedence over the prefix map, so this is how a single
		 * ability is moved out of the group its plugin otherwise owns.
		 *
		 * @since 0.0.34
		 * @param array<string, string> $map Ability name => group key.
		 */
		$map = apply_filters( 'acrossai_ability_group_tagger_map', self::ABILITY_GROUPS );

		return is_array( $map ) ? $map : self::ABILITY_GROUPS;
	}

	/**
	 * Prefix placements, from the registered integrations.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	private static function prefix_map(): array {
		/**
		 * Filters the ability-name-prefix toolset placements.
		 *
		 * Derived from every registered integration's `ability_prefixes()`. Prefer registering an
		 * integration over filtering this — an integration also supplies the label, the description
		 * and the dispatcher, where a bare prefix here would create a group with no MCP tool.
		 *
		 * @since 0.0.34
		 * @param array<string, string> $map Ability-name prefix => group key.
		 */
		$map = apply_filters(
			'acrossai_ability_group_tagger_prefix_map',
			AcrossAI_Toolset_Integrations::prefix_map()
		);

		return is_array( $map ) ? $map : array();
	}

	/**
	 * The group already declared in the args, if any.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $args Registration args.
	 * @return string
	 */
	private static function declared_group( array $args ): string {
		if ( ! isset( $args['meta']['acrossai']['tab_group'] ) ) {
			return '';
		}

		return (string) $args['meta']['acrossai']['tab_group'];
	}
}
