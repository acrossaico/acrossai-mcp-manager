<?php
/**
 * ServerEnablement — the only sanctioned writer of `is_enabled`.
 *
 * Feature 090 (ARCH-1). A server whose type has an unmet requirement must never
 * be switched ON. The RULE for that lives in `ServerTypes::enablement_error()`;
 * this class is where the rule is ENFORCED.
 *
 * **Why a facade rather than a check at each call site.** The plan originally
 * gated the three known writers in place. Architecture review rated that High:
 * the list of writers had been built by grep, and the security review then found
 * a path the grep had missed. An invariant enforced at N call sites is a
 * convention, not a boundary — it holds only while every present and future
 * contributor remembers it.
 *
 * So: one entry point, plus a grep gate in `bin/verify-f021-gates.sh` that fails
 * CI on any `'is_enabled' =>` write outside this class and `DefaultServerSeeder`.
 * The gate is the half that makes the boundary self-maintaining.
 *
 * **Rejected**: enforcing inside `MCPServer\Query::update_item()`. That is a
 * BerlinDB base-class method the seeder and the migrations also use, so a
 * type-policy guard there would block legitimate plugin-owned writes and couple
 * schema plumbing to feature policy.
 *
 * **Direction matters.** Only off -> on is gated. Switching a server OFF is
 * always permitted — otherwise a server stranded by a deactivated sibling could
 * never be switched off again. And a running server is NEVER auto-disabled when
 * its requirement later becomes unmet: that would 404 the route mid-session
 * instead of explaining itself (see `Abilities\SetupRequired`).
 *
 * Relation to A21: the MCP Adapter framework has no `is_enabled` concept at all.
 * That column, its default of `0`, and the request-time gate are a safety layer
 * owned by THIS plugin. F090 adds a second condition to that same layer rather
 * than inventing a parallel one.
 *
 * Stateless pure service per the A11 exemption.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Single enforcement point for changing a server's enabled state.
 *
 * @since 0.1.0
 */
final class ServerEnablement {

	/**
	 * Enable or disable one server, enforcing the type requirement.
	 *
	 * @since 0.1.0
	 * @param int  $server_id Server row id.
	 * @param bool $enabled   Desired state.
	 * @return true|WP_Error True on success (including a no-op), WP_Error when refused.
	 */
	public static function set( int $server_id, bool $enabled ) {
		$rows = Query::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);

		if ( empty( $rows ) ) {
			return new WP_Error(
				'acrossai_mcp_server_not_found',
				esc_html__( 'That MCP server no longer exists.', 'acrossai-mcp-manager' ),
				array( 'status' => 404 )
			);
		}

		$row     = $rows[0];
		$current = ! empty( $row->is_enabled );

		// No-op. Return success rather than re-running the gate, so a redundant
		// call on a stranded server cannot fail.
		if ( $current === $enabled ) {
			return true;
		}

		// Disabling is ALWAYS permitted — never gate on -> off.
		if ( $enabled ) {
			$error = ServerTypes::enablement_error( (string) $row->server_type );

			if ( null !== $error ) {
				/**
				 * Fires when an enable attempt is refused because the server's
				 * type requirement is unmet.
				 *
				 * Fire-and-forget observability per D19 — lets an operator audit
				 * refusals (notably which rows a bulk enable skipped) without
				 * this plugin taking a logging dependency.
				 *
				 * @since 0.1.0 (Feature 090)
				 *
				 * @param int    $server_id   Server row id.
				 * @param string $server_type The row's stored type slug.
				 * @param string $reason      WP_Error code.
				 */
				do_action(
					'acrossai_mcp_server_enable_refused',
					$server_id,
					(string) $row->server_type,
					$error->get_error_code()
				);

				return $error;
			}
		}

		$updated = Query::instance()->update_item(
			$server_id,
			array( 'is_enabled' => $enabled ? 1 : 0 )
		);

		if ( false === $updated ) {
			return new WP_Error(
				'acrossai_mcp_server_enable_failed',
				esc_html__( 'Failed to update the server. Try again.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Enable or disable many servers, reporting per-row outcomes.
	 *
	 * PARTIAL SUCCESS is the contract (FR-016a, clarification Q2): eligible rows
	 * are switched, ineligible rows are left untouched, and every skipped row is
	 * named with its reason. Wholesale failure would punish the operator for one
	 * bad row in a large selection; silent skipping would break the rule that
	 * every refusal says what is missing.
	 *
	 * @since 0.1.0
	 * @param int[] $server_ids Server row ids.
	 * @param bool  $enabled    Desired state.
	 * @return array{changed: int[], skipped: array<int, string>} Ids changed, and id => message for each skipped.
	 */
	public static function set_many( array $server_ids, bool $enabled ): array {
		$changed = array();
		$skipped = array();

		foreach ( $server_ids as $server_id ) {
			$server_id = (int) $server_id;
			$result    = self::set( $server_id, $enabled );

			if ( is_wp_error( $result ) ) {
				$skipped[ $server_id ] = $result->get_error_message();
				continue;
			}

			$changed[] = $server_id;
		}

		return array(
			'changed' => $changed,
			'skipped' => $skipped,
		);
	}
}
