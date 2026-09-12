<?php
/**
 * Plugin-managed (protected) MCP server predicates — Feature 088.
 *
 * Stateless pure service helper (A11/A15) — no singleton, no ctor.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies the server rows that DefaultServerSeeder owns.
 *
 * Those rows are re-asserted on every admin request, so letting an operator
 * rename or delete one produces a row that silently reverts (or reappears)
 * on the next page load. Instead the admin UI hides the Update Server and
 * Danger Zone tabs, hides the list-table Delete affordances, and the
 * server-side action handlers refuse update/delete for these ids.
 *
 * Note this is deliberately NOT the same test as `registered_from`: the
 * AcrossAI row must be `registered_from = 'database'` for
 * MCP\Controller::register_database_servers() to register its endpoint at
 * all, so protection needs its own predicate.
 */
final class ProtectedServers {

	/**
	 * Slugs of every plugin-managed server row.
	 *
	 * @return array<int, string>
	 */
	public static function slugs(): array {
		return array(
			DefaultServerSeeder::ACROSSAI_SLUG,
			DefaultServerSeeder::SLUG,
		);
	}

	/**
	 * Whether a slug belongs to a plugin-managed row.
	 *
	 * @param string $slug Server slug.
	 * @return bool
	 */
	public static function is_protected( string $slug ): bool {
		return '' !== $slug && in_array( $slug, self::slugs(), true );
	}

	/**
	 * Whether a server array belongs to a plugin-managed row.
	 *
	 * Accepts both key shapes in circulation: `server_slug` (Row::to_array(),
	 * used by the server-edit screen and the tab Registry) and `slug`
	 * (MCPServerListTable::prepare_items()'s remapped item).
	 *
	 * @param array<string, mixed> $server Server row array.
	 * @return bool
	 */
	public static function is_protected_server( array $server ): bool {
		$slug = (string) ( $server['server_slug'] ?? $server['slug'] ?? '' );

		return self::is_protected( $slug );
	}

	/**
	 * Whether a server primary key belongs to a plugin-managed row.
	 *
	 * For the action handlers, which only receive an id. A missing row is not
	 * protected — the caller's own not-found handling takes over.
	 *
	 * @param int $server_id Server primary key.
	 * @return bool
	 */
	public static function is_protected_id( int $server_id ): bool {
		if ( $server_id <= 0 ) {
			return false;
		}

		$rows = Query::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);

		if ( empty( $rows ) ) {
			return false;
		}

		return self::is_protected( (string) $rows[0]->server_slug );
	}

	/**
	 * Whether a slug is the one server surfaced as "Recommended".
	 *
	 * Drives both the list-table ordering (recommended row pinned first) and
	 * the Recommended badge in the list + Overview tab.
	 *
	 * @param string $slug Server slug.
	 * @return bool
	 */
	public static function is_recommended( string $slug ): bool {
		return DefaultServerSeeder::ACROSSAI_SLUG === $slug;
	}

	/**
	 * Pre-escaped "Recommended" pill markup.
	 *
	 * @return string
	 */
	public static function recommended_badge(): string {
		return sprintf(
			'<span class="acrossai-recommended-badge">%s</span>',
			esc_html__( 'Recommended', 'acrossai-mcp-manager' )
		);
	}
}
