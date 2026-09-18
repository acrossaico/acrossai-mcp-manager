<?php
/**
 * Plugin-owned replacement callbacks for `mcp-adapter/discover-abilities`.
 *
 * Bound to the ability at registration time via
 * `CallbackReplacer::replace_callbacks()` on `wp_register_ability_args`.
 * Reimplements vendor's iteration so the exposure filter can both
 * widen (add non-public abilities) and narrow (hide public ones) —
 * a post-hoc filter can only narrow.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use AcrossAI_MCP_Manager\Admin\Partials\SettingsMenu;
use AcrossAI_MCP_Manager\Includes\Compat;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-owned callbacks for the `mcp-adapter/discover-abilities` tool.
 *
 * @since 0.1.0
 */
final class Discover {
	use AbilityHelpers;

	/**
	 * Default page size (F089). Advertised in the input schema by
	 * `CallbackReplacer::discover_input_schema()`, which reads this constant so
	 * the documented default cannot drift from the enforced one.
	 */
	public const PER_PAGE_DEFAULT = 60;

	/**
	 * Hard ceiling on page size (F089). A caller asking for more is clamped, not
	 * rejected — the schema's `maximum` already refuses obviously bad input, and
	 * clamping keeps a filter-raised default from silently exceeding the cap.
	 */
	public const PER_PAGE_MAXIMUM = 200;

	/**
	 * Bound as `permission_callback` on `mcp-adapter/discover-abilities`.
	 *
	 * Vendor's original required auth + `mcp_adapter_discover_abilities_capability`
	 * (default 'read'). We preserve both — mirroring the vendor's own
	 * validate_user_access flow — because the tool-level capability
	 * gate is orthogonal to the per-ability exposure filter (see the
	 * "exposure ≠ authorization" security invariant).
	 *
	 * @param mixed $input Unused — discover takes no arguments.
	 * @return bool|\WP_Error
	 */
	public static function check_permission( $input = array() ) {
		unset( $input );

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'authentication_required',
				__( 'User must be authenticated to access this ability.', 'acrossai-mcp-manager' )
			);
		}

		/** This filter is fired by vendor too — reuse the same name for BC. */
		$required_capability = apply_filters( 'mcp_adapter_discover_abilities_capability', 'read' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- capability resolved via filter
		if ( ! current_user_can( $required_capability ) ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf(
					/* translators: %s: capability slug required by the tool */
					__( 'User lacks required capability: %s', 'acrossai-mcp-manager' ),
					$required_capability
				)
			);
		}

		return true;
	}

	/**
	 * Bound as `execute_callback` on `mcp-adapter/discover-abilities`.
	 *
	 * F089 — accepts search + pagination. The order here is load-bearing:
	 * collect → exposure gate → filter → count → slice. Filtering AFTER the
	 * gate is what stops `search` surfacing an ability the per-server F017/F020
	 * policy hides; `$total` counts matches before the slice so `has_more` is
	 * honest.
	 *
	 * @param mixed $input Optional search/pagination criteria; see
	 *                     `CallbackReplacer::discover_input_schema()`.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = array() ): array {
		$criteria = is_array( $input ) ? $input : array();

		$ability_list = self::filter_list( self::collect_visible_abilities(), $criteria );

		$total               = count( $ability_list );
		[ $page, $per_page ] = self::resolve_pagination( $criteria );
		$offset              = ( $page - 1 ) * $per_page;
		$page_items          = array_values( array_slice( $ability_list, $offset, $per_page ) );

		return array(
			'abilities' => $page_items,
			'total'     => $total,
			'returned'  => count( $page_items ),
			'page'      => $page,
			'per_page'  => $per_page,
			'has_more'  => ( $offset + count( $page_items ) ) < $total,
		);
	}

	/**
	 * Every tool-typed ability the current request may see.
	 *
	 * Extracted from `execute()` unchanged — the two gates below are the
	 * security boundary and must run before any caller-supplied filtering.
	 *
	 * PUBLIC so `ServerGuide` can build its inventory from the same list this
	 * tool paginates. A guide that counted the registry directly would report
	 * abilities the caller cannot actually reach, which is worse than no
	 * inventory: it would send a model looking for something the exposure gate
	 * will refuse.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function collect_visible_abilities(): array {
		$abilities    = function_exists( 'wp_get_abilities' ) ? \wp_get_abilities() : array();
		$ability_list = array();

		foreach ( $abilities as $ability ) {
			// Only surface tool-typed abilities — matches vendor's semantics.
			if ( 'tool' !== self::mcp_type( $ability ) ) {
				continue;
			}
			if ( ! self::apply_exposure_filter( $ability, 'discover' ) ) {
				continue;
			}

			$ability_list[] = array(
				'name'        => $ability->get_name(),
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
				// F089 — lets a model see which category values exist before
				// using the `category` filter, and gives `search` a fourth
				// field to match on.
				'category'    => $ability->get_category(),
				'tab_group'   => self::tab_group( $ability ),
			);
		}

		return $ability_list;
	}

	/**
	 * The ability's toolset group, or '' when it declares none.
	 *
	 * `meta.acrossai.tab_group` is how the AcrossAI Abilities Manager organises
	 * its library: one string that is simultaneously the group, the admin tab,
	 * that plugin's REST `?tab_group=` filter and the second half of its
	 * `toolset/<group>` tool name. It is the axis a model most often wants to
	 * slice on, and until now this tool could not.
	 *
	 * **This is the one place this plugin reads `meta.acrossai.*`.** Reading a
	 * generic meta KEY is not the same thing as hardcoding another plugin's
	 * vocabulary — no group name appears here, and the rule at
	 * `ToolAbilities.php:15` still holds. Do not read this as licence to name
	 * `toolset/*` slugs anywhere in this plugin.
	 *
	 * Returns '' when the sibling is absent, so every ability is ungrouped and
	 * a `tab_group` filter matches nothing. That is the honest answer: there
	 * are no groups on such a site.
	 *
	 * @param  \WP_Ability $ability Ability to read.
	 * @return string
	 */
	private static function tab_group( $ability ): string {
		if ( ! method_exists( $ability, 'get_meta' ) ) {
			return '';
		}

		$meta = $ability->get_meta();

		if ( ! is_array( $meta ) || ! isset( $meta['acrossai']['tab_group'] ) ) {
			return '';
		}

		return (string) $meta['acrossai']['tab_group'];
	}

	/**
	 * Apply `search`, `category`, `namespace` and `tab_group`. Criteria AND together;
	 * an absent or empty criterion is a no-op.
	 *
	 * @param array<int, array<string, string>> $entries  Visible abilities.
	 * @param array<string, mixed>              $criteria Raw caller input.
	 * @return array<int, array<string, string>>
	 */
	private static function filter_list( array $entries, array $criteria ): array {
		$search    = isset( $criteria['search'] ) ? strtolower( trim( (string) $criteria['search'] ) ) : '';
		$category  = isset( $criteria['category'] ) ? trim( (string) $criteria['category'] ) : '';
		$namespace = isset( $criteria['namespace'] ) ? trim( (string) $criteria['namespace'], " \t\n\r\0\x0B/" ) : '';
		$tab_group = isset( $criteria['tab_group'] ) ? trim( (string) $criteria['tab_group'] ) : '';

		if ( '' === $search && '' === $category && '' === $namespace && '' === $tab_group ) {
			return $entries;
		}

		$matches = array();
		foreach ( $entries as $entry ) {
			if ( '' !== $tab_group && ( $entry['tab_group'] ?? '' ) !== $tab_group ) {
				continue;
			}
			if ( '' !== $category && $entry['category'] !== $category ) {
				continue;
			}
			if ( '' !== $namespace && ! Compat::str_starts_with( $entry['name'], $namespace . '/' ) ) {
				continue;
			}
			if ( '' !== $search && ! self::matches_search( $entry, $search ) ) {
				continue;
			}
			$matches[] = $entry;
		}

		return $matches;
	}

	/**
	 * Case-insensitive substring match across the four text fields.
	 *
	 * @param array<string, string> $entry  One ability entry.
	 * @param string                $search Already lower-cased needle.
	 */
	private static function matches_search( array $entry, string $search ): bool {
		foreach ( array( 'name', 'label', 'description', 'category' ) as $field ) {
			if ( Compat::str_contains( strtolower( $entry[ $field ] ?? '' ), $search ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve `page` and `per_page`, applying defaults and clamping.
	 *
	 * Core does not fill per-property schema defaults — it only applies the
	 * top-level one — so the defaults are applied here.
	 *
	 * @param array<string, mixed> $criteria Raw caller input.
	 * @return array{0:int, 1:int} [ page, per_page ]
	 */
	private static function resolve_pagination( array $criteria ): array {
		// Operator setting (MCP → Settings → Ability Discovery) is the base
		// default; the filter below still wins so programmatic control is not
		// locked out by a saved option.
		$default = (int) get_option( SettingsMenu::DISCOVER_PER_PAGE_OPTION, self::PER_PAGE_DEFAULT );
		if ( $default < 1 ) {
			$default = self::PER_PAGE_DEFAULT;
		}

		/**
		 * Filter the default page size for `discover-abilities`.
		 *
		 * Receives the operator's saved setting, not the constant, so a
		 * callback can adjust relative to what the site chose.
		 *
		 * @since 0.3.4 (Feature 089)
		 *
		 * @param int $per_page Default page size.
		 */
		$default = (int) apply_filters( 'acrossai_mcp_discover_abilities_default_per_page', $default );

		/**
		 * Filter the maximum page size for `discover-abilities`.
		 *
		 * @since 0.3.4 (Feature 089)
		 *
		 * @param int $maximum Maximum page size.
		 */
		$maximum = (int) apply_filters( 'acrossai_mcp_discover_abilities_max_per_page', self::PER_PAGE_MAXIMUM );
		$maximum = max( 1, $maximum );

		$per_page = isset( $criteria['per_page'] ) ? (int) $criteria['per_page'] : $default;
		$per_page = max( 1, min( $maximum, $per_page ) );

		$page = isset( $criteria['page'] ) ? (int) $criteria['page'] : 1;
		$page = max( 1, $page );

		return array( $page, $per_page );
	}
}
