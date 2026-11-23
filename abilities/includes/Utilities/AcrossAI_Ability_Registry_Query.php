<?php
/**
 * Registry query utility — filters, sorts, paginates, and merges abilities.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Utilities;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Query;

// Self-import: AcrossAI_Protected_Abilities is in the same namespace.

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Canonical filter/sort/paginate logic for the abilities registry list.
 *
 * The REST controller MUST NOT inline this logic (RF-03).
 *
 * @since 0.1.0
 */
class AcrossAI_Ability_Registry_Query {

	/**
	 * Query, filter, sort, and paginate abilities from the registry.
	 *
	 * @since  0.1.0
	 * @param  array                    $params   Query parameters.
	 *   - string      $search      Optional full-text search term.
	 *   - string      $orderby     Field to sort by (slug|provider|source|status). Default 'slug'.
	 *   - string      $order       Sort direction (asc|desc). Default 'asc'.
	 *   - string      $source      Filter by source (plugin|theme|core|db).
	 *   - string      $status      Filter by status (publish|draft). Registry rows are always
	 *                              'publish'; only DB-created abilities can be drafts, and an
	 *                              unpublished one is never registered, so 'draft' yields none here.
	 *   - string[]    $slugs       Restrict to these ability names. Feature 102 passes the members
	 *                              of one toolset, resolved by AcrossAI_Ability_Group::member_names().
	 *   - bool|null   $has_override Filter to only overridden or non-overridden abilities.
	 *   - int         $page        1-based page number. Default 1.
	 *   - int         $per_page    Items per page (1–100). Default 20.
	 * @param  AcrossAI_Abilities_Query $db_query BerlinDB Query instance.
	 * @return array{abilities: array, total: int, pages: int}
	 */
	public static function query( array $params, AcrossAI_Abilities_Query $db_query ): array {
		$search       = isset( $params['search'] ) ? (string) $params['search'] : '';
		$orderby      = isset( $params['orderby'] ) ? (string) $params['orderby'] : 'slug';
		$order        = isset( $params['order'] ) && 'desc' === strtolower( $params['order'] ) ? 'desc' : 'asc';
		$source       = isset( $params['source'] ) ? (string) $params['source'] : '';
		$status       = isset( $params['status'] ) ? (string) $params['status'] : '';
		$slugs        = ( isset( $params['slugs'] ) && is_array( $params['slugs'] ) ) ? $params['slugs'] : null;
		$has_override = isset( $params['has_override'] ) ? $params['has_override'] : null;
		$page         = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		$per_page     = isset( $params['per_page'] ) ? min( 100, max( 1, (int) $params['per_page'] ) ) : 20;

		// Fetch all registered abilities from the WordPress registry.
		$all_abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();

		$results = array();

		foreach ( $all_abilities as $slug => $ability_data ) {
			// Normalize WP_Ability object to array (wp_get_abilities() returns objects in WP 6.9+).
			$ability_data = AcrossAI_Ability_Merger::normalize_registry( $ability_data );

			// Ensure slug is set — normalize_registry sets it from get_name(),
			// but fall back to the loop key for any bare array that omits 'slug'.
			if ( empty( $ability_data['slug'] ) ) {
				$ability_data['slug'] = $slug;
			}

			// Protected abilities are LISTED here, and marked — not skipped.
			//
			// "Protected" covers the dispatchers: every `toolset/*`, the
			// mcp-adapter routers, and any transport ability a sibling plugin
			// declares (acrossai-pro's Fleet tool arrives this way, through
			// `ToolAbilities::protect_from_toolsets()`). Protection exists to
			// keep them out of toolset MEMBERSHIP — `toolset/other` was
			// observed listing the server guide that describes how to call a
			// Toolset — and to refuse overrides on something that only routes.
			//
			// Neither of those is a reason to hide them from the operator's
			// own inventory. A dispatcher that cannot be seen cannot be
			// understood, and "791 abilities" that silently omits the 21 tools
			// an assistant actually calls is a worse answer than listing them.
			//
			// The other five consumers of the protected list are unchanged, so
			// the behaviours protection was built for all survive: grouping
			// (`AcrossAI_Ability_Group`), override writes, toolset counts and
			// Quick Connect all still exclude these slugs. Only the inventory
			// shows them, flagged so the UI can present them as read-only.
			$merged_protected = AcrossAI_Protected_Abilities::is_protected( $slug );

			// Restrict to an explicit slug set when one was supplied (Feature 102 toolset filter).
			// Placed after the protected-slug skip so protected abilities stay excluded even when a
			// caller names them, and before the source/search filters so it composes with both.
			// Strict in_array per SEC-04 / R-PAT-2.
			if ( null !== $slugs && ! in_array( $slug, $slugs, true ) ) {
				continue;
			}

			// Retrieve any stored override for this ability.
			$override = $db_query->get_override_by_slug( $slug );

			// Merge registry + override into the Effective Ability shape.
			$merged = AcrossAI_Ability_Merger::merge( $ability_data, $override );

			// Carried through to the REST shape so the screen can render the
			// row as something it must not try to edit.
			$merged['protected'] = $merged_protected;

			// Detect source if not already set.
			if ( empty( $merged['source'] ) ) {
				$merged['source'] = AcrossAI_Ability_Source_Detector::detect( $ability_data );
			}

			// Apply source filter.
			if ( '' !== $source && $merged['source'] !== $source ) {
				continue;
			}

			// Apply status filter. Everything reachable through wp_get_abilities() is published by
			// definition — a draft DB ability is never registered — so this only ever excludes.
			if ( '' !== $status && ( isset( $merged['status'] ) ? (string) $merged['status'] : 'publish' ) !== $status ) {
				continue;
			}

			// Apply has_override filter.
			if ( null !== $has_override && (bool) $merged['has_override'] !== (bool) $has_override ) {
				continue;
			}

			// Apply search filter (slug and provider).
			if ( '' !== $search ) {
				$search_lower = strtolower( $search );
				$slug_match   = false !== strpos( strtolower( $slug ), $search_lower );
				$prov_match   = false !== strpos( strtolower( (string) ( isset( $merged['provider'] ) ? $merged['provider'] : '' ) ), $search_lower );
				if ( ! $slug_match && ! $prov_match ) {
					continue;
				}
			}

			$results[] = $merged;
		}

		// Sort.
		$orderby_local = $orderby;
		$order_local   = $order;
		usort(
			$results,
			function ( $a, $b ) use ( $orderby_local, $order_local ) {
				$val_a = isset( $a[ $orderby_local ] ) ? (string) $a[ $orderby_local ] : '';
				$val_b = isset( $b[ $orderby_local ] ) ? (string) $b[ $orderby_local ] : '';
				$cmp   = strcmp( $val_a, $val_b );
				return 'desc' === $order_local ? -$cmp : $cmp;
			}
		);

		$total  = count( $results );
		$pages  = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;
		$offset = ( $page - 1 ) * $per_page;

		$paged = array_slice( $results, $offset, $per_page );

		return array(
			'abilities' => $paged,
			'total'     => $total,
			'pages'     => $pages,
		);
	}
}
