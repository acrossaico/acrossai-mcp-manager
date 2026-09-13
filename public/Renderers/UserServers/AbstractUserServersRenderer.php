<?php
/**
 * Data-only abstract base for the F038 user-accessible MCP servers primitive.
 *
 * Enumerates every MCP server the current logged-in user can access (via the
 * F015 access-control wrapper) whose F037 Embeds tab has the master toggle ON
 * and which has at least one enabled DTO across any registered transport —
 * returns the assembled list as a structured array. Companion plugins
 * (BuddyBoss add-on, WooCommerce My Account extension, WPUM, MemberPress)
 * subclass this base to build their own contexts without re-implementing the
 * F015 + F037 gate cascade.
 *
 * ## Composition contract
 *
 * F038 delegates every gate to a shipped upstream helper:
 *
 *  - `MCPServerQuery::instance()->query()` (F011) — server enumeration.
 *  - `AcrossAI_MCP_Access_Control::instance()->user_has_server_access()`
 *    (F015 / F032) — per-server access gate. Fail-open when the
 *    wpb-access-control package is absent (documented D19 pattern).
 *  - `AbstractEmbedTransport::get_all_registered_transports()` (F037) —
 *    canonical transport enumeration. Companion plugins register new
 *    transports via `acrossai_mcp_embed_transports` filter and F038
 *    surfaces them automatically.
 *  - `AbstractEmbedTransport::is_enabled_for_server()` (F037) — two-check
 *    gate (master `_embeds_enabled` toggle + per-DTO membership in
 *    `_embeds_clients` JSON) with R2 per-request memoization.
 *
 * F038 MUST NOT re-fire any upstream filter (grep-gates on
 * `acrossai_mcp_embed_transports` + `acrossai_mcp_client_classes` inside
 * this directory return zero). F038 MUST NOT read `_embeds_enabled` or
 * `_embeds_clients` meta keys directly (preserves R2 memoization and the
 * fail-open contract).
 *
 * ## Caller-authority responsibility (SEC-001)
 *
 * When a consumer calls `get_accessible_servers( $target_user_id )` where
 * `$target_user_id !== get_current_user_id()`, the consumer MUST
 * independently verify that the **current viewer** is authorized to see
 * the target user's information. F038 does NOT gate the caller — it
 * evaluates the F015 access-control gate FOR the target user, not
 * against the calling user's authority.
 *
 * The "allowed set per user" is meta-information about access-control
 * policy. Leaking it to an unauthorized viewer would let an attacker map
 * out AC rules without needing admin capabilities.
 *
 * Typical caller-side guards:
 *
 *  - Admin views rendering another user's list for support / audit:
 *    `current_user_can( 'edit_user', $target_user_id )`.
 *  - BuddyPress profile tabs: `bp_is_my_profile()` (profile owner sees
 *    it) or a group-membership check for moderator-visible summaries.
 *  - WooCommerce "My Account" endpoint: already gated to the account
 *    owner by WC core.
 *
 * A consumer that lets any logged-in visitor pass an arbitrary
 * `$target_user_id` has built a cross-user access-control-policy
 * enumeration surface. That is a **consumer defect**, not an F038
 * defect — but F038 documents it here so implementers are forewarned.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public\Renderers\UserServers
 * @since      0.1.11
 * @experimental May change without notice before 1.0.0. See
 *               DEC-CLIENT-RENDERER-PUBLIC-API. Subclass extension is
 *               the intentional design (base IS the extension surface);
 *               D36's `final class` rule targets consumed renderers, not
 *               extension-surface bases (precedent: AbstractClientRenderer).
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Public\Renderers\UserServers;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Embeds\AbstractEmbedTransport;

defined( 'ABSPATH' ) || exit;

abstract class AbstractUserServersRenderer {

	/**
	 * Enumerate every MCP server the given user can access whose F037
	 * Embeds master toggle is ON and which has at least one enabled DTO
	 * across any registered transport.
	 *
	 * @since 0.1.11
	 *
	 * @param int|null $user_id Target user's WP user id. NULL / 0 / negative
	 *                          → returns []. Defaults to
	 *                          `get_current_user_id()`.
	 * @return array<int, array<string, mixed>> Ordered list of accessible-server
	 *         projections, alphabetically sorted by `server_name`
	 *         (case-insensitive). Each entry has keys `server_id` (int),
	 *         `server_slug` (string), `server_name` (string),
	 *         `description` (string), and `transports` (array<int,
	 *         array>). Each transport has keys `key` (string), `label`
	 *         (string), `priority` (int), and `dtos` (array<int, array>).
	 *         Each DTO has keys `slug` (string), `name` (string), `icon`
	 *         (string), `description` (string), `meta` (array).
	 */
	public function get_accessible_servers( ?int $user_id = null ): array {
		// Step 1 — resolve user id + anonymous short-circuit.
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( $user_id <= 0 ) {
			return array();
		}

		// Step 2 — enumerate enabled servers via canonical Query (F011).
		$rows = MCPServerQuery::instance()->query(
			array(
				'is_enabled' => 1,
				// BerlinDB's "no limit" is 0, not -1: parse_limits() runs
				// absint( $number ), so -1 became LIMIT 1 and this block only ever
				// rendered a single server — whichever the default ordering put
				// first — no matter how many the visitor could access.
				'number'     => 0,
			)
		);
		if ( empty( $rows ) ) {
			return array();
		}

		// Step 3 — enumerate transports once per request (F037 canonical
		// enumeration — sorted priority ASC). Cached locally for the
		// duration of this call to avoid re-invoking the filter loop per
		// server row.
		$transports = AbstractEmbedTransport::get_all_registered_transports();

		// Step 4 — for each server row, apply the gate cascade.
		$access_control = AcrossAI_MCP_Access_Control::instance();
		$data           = array();

		foreach ( $rows as $row ) {
			$server_id = (int) $row->id;
			if ( $server_id <= 0 ) {
				continue; // Defensive — F011 Query should never return id <= 0.
			}

			// Gate: F015 access control (fail-open when package absent is
			// inside the wrapper). Admin bypass handled by v2 vendor
			// manager per DEC-ACCESS-CONTROL-V2-ADOPTION.
			if ( ! $access_control->user_has_server_access( $user_id, $server_id ) ) {
				continue;
			}

			// Per-transport per-DTO iteration. `is_enabled_for_server`
			// subsumes the master `_embeds_enabled` check (its Gate 1)
			// and is R2-memoized per-request — no need to short-circuit
			// the master check separately at the server level.
			$server_transports = array();
			foreach ( $transports as $transport ) {
				$transport_key = $transport->get_transport_key();
				$dto_list      = $transport->get_dtos();

				$enabled_dtos = array();
				foreach ( $dto_list as $dto ) {
					if ( ! is_array( $dto ) ) {
						continue;
					}
					$dto_slug = $dto['slug'] ?? null;
					if ( ! is_string( $dto_slug ) || '' === $dto_slug ) {
						continue;
					}

					if ( ! AbstractEmbedTransport::is_enabled_for_server( $server_id, $transport_key, $dto_slug ) ) {
						continue;
					}

					$enabled_dtos[] = array(
						'slug'        => $dto_slug,
						'name'        => isset( $dto['name'] ) && is_string( $dto['name'] ) ? $dto['name'] : '',
						'icon'        => isset( $dto['icon'] ) && is_string( $dto['icon'] ) ? $dto['icon'] : '',
						'description' => isset( $dto['description'] ) && is_string( $dto['description'] ) ? $dto['description'] : '',
						'meta'        => isset( $dto['meta'] ) && is_array( $dto['meta'] ) ? $dto['meta'] : array(),
					);
				}

				if ( ! empty( $enabled_dtos ) ) {
					$server_transports[] = array(
						'key'      => $transport_key,
						'label'    => $transport->get_checkbox_label(),
						'priority' => (int) $transport->get_priority(),
						'dtos'     => $enabled_dtos,
					);
				}
			}

			if ( empty( $server_transports ) ) {
				continue; // No enabled DTOs across any transport → drop the server.
			}

			$data[] = array(
				'server_id'   => $server_id,
				'server_slug' => (string) $row->server_slug,
				'server_name' => (string) $row->server_name,
				'description' => isset( $row->description ) ? (string) $row->description : '',
				'transports'  => $server_transports,
			);
		}

		// Step 5 — sort alphabetically by server_name (case-insensitive
		// natural comparison).
		usort(
			$data,
			static function ( array $a, array $b ): int {
				return strnatcasecmp( (string) $a['server_name'], (string) $b['server_name'] );
			}
		);

		/**
		 * Filter the assembled list of accessible servers before the
		 * primitive returns.
		 *
		 * Consumers may add / remove / mutate entries in `$data`. The
		 * concrete `UserServersBlock` renderer defensively coerces a
		 * non-array return to `[]` at its own HTML boundary — the
		 * abstract base returns whatever the filter yields.
		 *
		 * ### Non-goals (SEC-004)
		 *
		 * NOT a gate-bypass surface. The filter fires AFTER the F015 +
		 * F037 gate cascade — a listener that APPENDS server entries
		 * effectively grants unmediated access, because F038 does NOT
		 * re-verify appended entries. Consumers that add entries MUST
		 * replay the gate cascade themselves: for each appended entry
		 * call `AcrossAI_MCP_Access_Control::instance()->user_has_server_access`
		 * and `AbstractEmbedTransport::is_enabled_for_server` before
		 * adding it. The filter's intended use is **removing /
		 * reshaping** entries the cascade already allowed, not adding
		 * new ones.
		 *
		 * @since 0.1.11
		 *
		 * @param array<int, array<string, mixed>> $data    Assembled
		 *        server list per the return-shape documented above.
		 * @param int                              $user_id Target user id
		 *        the enumeration evaluated for.
		 */
		$data = apply_filters( 'acrossai_mcp_user_accessible_servers', $data, $user_id );

		return is_array( $data ) ? $data : array();
	}
}
