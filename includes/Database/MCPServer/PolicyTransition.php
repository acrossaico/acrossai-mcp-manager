<?php
/**
 * F082 — server-level ability-policy transition service.
 *
 * Single source of truth for the policy-transition sequence that was
 * previously duplicated across `AbilitiesController::post_policy()` and
 * `QuickConnectController::apply_step_5()` (architecture-review R1):
 *
 *   1. Read the current policy off the server row.
 *   2. FR-015 no-op suppression — same policy → no DELETE, no action fire.
 *   3. Snapshot pre-change effective exposure for every registered ability.
 *   4. Persist the new policy (`MCPServer\Query::update_item()`).
 *   5. Clear every override row for the server (last-write-wins per spec
 *      Clarifications Q5) via `MCPServerAbility\Query::delete_items_for_server()`.
 *   6. Reset the resolver caches so post-change reads in this request are fresh.
 *   7. Compute the post-change effective state and fire
 *      `acrossai_mcp_server_policy_changed` with the FR-011 was/now diff map.
 *
 * This class owns the ONLY `do_action( 'acrossai_mcp_server_policy_changed' )`
 * call site in the plugin (tasks.md T059 grep audit).
 *
 * A11 pure-service exception (stateless, static-only — no singleton, no ctor),
 * same pattern as `MCPServerAbility\ExposureResolver`.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.1.0 (F082 — extracted 2026-09-06 per architecture-review R1)
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless service applying a server-level ability-policy transition.
 *
 * @since 0.1.0 (F082)
 */
final class PolicyTransition {

	/**
	 * Apply a policy transition to a server.
	 *
	 * Callers are expected to have already authorised the request and
	 * validated `$new_policy` against the `per-ability|expose|hide` enum
	 * (REST arg schema, or a compile-time literal at the call site).
	 *
	 * @since 0.1.0 (F082)
	 *
	 * @param int    $server_id  MCP server id (must reference an existing row —
	 *                           callers verify existence; a missing row behaves
	 *                           as `'per-ability'` and transitions normally).
	 * @param string $new_policy Target policy: 'per-ability' | 'expose' | 'hide'.
	 * @return array{changed: bool, old_policy: string, affected_slugs: array<string, array{was: bool, now: bool}>}
	 *               `changed` is false on an FR-015 no-op (nothing written, no
	 *               action fired); `affected_slugs` maps each flipped slug to
	 *               its was/now effective-exposure pair.
	 */
	public static function apply( int $server_id, string $new_policy ): array {
		// Read the current policy off the server row.
		$server_rows = Query::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		$server_row  = ! empty( $server_rows ) ? $server_rows[0] : null;
		$old_policy  = ( $server_row && ! empty( $server_row->abilities_default_policy ) )
			? (string) $server_row->abilities_default_policy
			: 'per-ability';

		// FR-015 no-op suppression — skip the DELETE, skip the action fire.
		if ( $old_policy === $new_policy ) {
			return array(
				'changed'        => false,
				'old_policy'     => $old_policy,
				'affected_slugs' => array(),
			);
		}

		// Snapshot pre-change effective state per registered ability.
		$snapshot = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( \wp_get_abilities() as $ability ) {
				$slug              = (string) $ability->get_name();
				$meta              = $ability->get_meta();
				$snapshot[ $slug ] = ExposureResolver::resolve_effective( $server_id, $slug, is_array( $meta ) ? $meta : array() );
			}
		}

		// Persist the policy change on the server row.
		Query::instance()->update_item( $server_id, array( 'abilities_default_policy' => $new_policy ) );

		// Clear every override row for this server (last-write-wins per spec
		// Clarifications Q5). Bulk delete + cache-group flush.
		MCPServerAbilityQuery::instance()->delete_items_for_server( $server_id );

		// Bust the resolver caches so post-change reads are fresh.
		ExposureResolver::reset_request_cache();

		// Compute post-change effective state + build the per-slug diff.
		$affected_slugs = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( \wp_get_abilities() as $ability ) {
				$slug = (string) $ability->get_name();
				$meta = $ability->get_meta();
				$now  = ExposureResolver::resolve_effective( $server_id, $slug, is_array( $meta ) ? $meta : array() );
				$was  = isset( $snapshot[ $slug ] ) ? (bool) $snapshot[ $slug ] : false;
				if ( $was !== $now ) {
					$affected_slugs[ $slug ] = array(
						'was' => (bool) $was,
						'now' => (bool) $now,
					);
				}
			}
		}

		/**
		 * Fires after a server-level default ability policy transition (F082).
		 *
		 * Suppressed on no-op transitions (FR-015). Payload's `$affected_slugs`
		 * is a map keyed by ability slug — `[ 'slug/name' => [ 'was' => bool,
		 * 'now' => bool ] ]` — carrying the per-slug transition (spec
		 * Clarifications Q2). Audit-log subscribers get the full diff without
		 * having to re-derive it.
		 *
		 * @since 0.1.0 (F082) @experimental May change without notice before 1.0.0
		 *
		 * @param int    $server_id      MCP server id.
		 * @param string $old_policy     Previous policy value ('per-ability' | 'expose' | 'hide').
		 * @param string $new_policy     New policy value.
		 * @param array  $affected_slugs Map of slug => [ 'was' => bool, 'now' => bool ].
		 * @param int    $user_id        User who initiated the transition.
		 */
		do_action(
			'acrossai_mcp_server_policy_changed',
			$server_id,
			$old_policy,
			$new_policy,
			$affected_slugs,
			get_current_user_id()
		);

		return array(
			'changed'        => true,
			'old_policy'     => $old_policy,
			'affected_slugs' => $affected_slugs,
		);
	}
}
