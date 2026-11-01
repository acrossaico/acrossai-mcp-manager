<?php
/**
 * Servers no AI client may connect to, by any route.
 *
 * Some servers exist for something other than an AI client talking to them.
 * The Fleet server is the first: it answers only to paired WordPress sites
 * presenting a signature, so every credential any connect flow could mint for
 * it would be refused on first use.
 *
 * ## One list, every surface
 *
 * There are at least five ways to point a client at a server — the Quick
 * Connect wizard, OAuth, the per-server Application Password tab, the WP-CLI
 * pairing route, and the public npm / mcp.json config renderers. Each knows
 * how to build a credential; none of them knows which servers are worth
 * building one for. So the answer lives here and all of them ask it.
 *
 * This started as a Quick-Connect-only exclusion. It was widened the moment
 * the same question came up for OAuth, because two lists that must agree are
 * one list that has not been written yet.
 *
 * ## This is ergonomics, not the security boundary
 *
 * The Fleet route already refuses anything without a valid signature, so an
 * AI client could not use it regardless. What these checks prevent is a flow
 * that cheerfully issues a credential and tells an operator they are
 * connected, when the first real call will fail. Removing a check here would
 * restore a confusing dead end, not open a hole.
 *
 * ## Excluded by slug, deliberately
 *
 * Slugs, not server types. A slug names one specific row that is known not to
 * belong; a type would exclude every server of that kind, including one an
 * operator created on purpose and might reasonably want listed. If that turns
 * out to be the behaviour wanted, add a parallel type list rather than
 * widening this one — the two questions have different answers.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.4.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

defined( 'ABSPATH' ) || exit;

final class NonConnectableServers {

	/**
	 * Filter name — companion plugins add their own non-connectable servers.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	public const FILTER = 'acrossai_mcp_non_connectable_servers';

	/**
	 * Slugs no connect flow may offer or issue credentials for.
	 *
	 * Add a slug here when its server exists for something other than an AI
	 * client connecting to it, with a line saying what that something is.
	 *
	 * @since 0.4.6
	 * @return array<int, string>
	 */
	public static function slugs(): array {
		$slugs = array(
			// Reached by paired WordPress sites, never by an AI client. Its
			// route refuses anything without a signature from a paired host,
			// so every credential the wizard could produce for it would fail.
			DefaultServerSeeder::FLEET_SLUG,
		);

		/**
		 * Filter the servers no AI client may connect to.
		 *
		 * @since 0.4.6
		 *
		 * @param array<int, string> $slugs Excluded server slugs.
		 */
		// Cast rather than type-check: a callback may return anything, and
		// `(array)` is the idiom the rest of the plugin uses for exactly this
		// (see `ServerTypes::get_all_registered_transports`-style guards).
		$filtered = (array) apply_filters( self::FILTER, $slugs );

		return array_values( array_unique( array_map( 'strval', $filtered ) ) );
	}

	/**
	 * Whether a slug names a server no AI client may connect to.
	 *
	 * @since 0.4.6
	 *
	 * @param string $slug Server slug.
	 */
	public static function is_excluded( string $slug ): bool {
		return '' !== $slug && in_array( $slug, self::slugs(), true );
	}

	/**
	 * The same question, asked with a row id.
	 *
	 * Every caller but the wizard has an id rather than a slug, and resolving
	 * it in each of them would be the duplication this class exists to avoid.
	 * Matching stays on slug because ids differ between installs.
	 *
	 * An id that resolves to nothing is NOT treated as excluded: a missing
	 * server is a different failure, and the caller's own not-found handling
	 * gives a better message than this one could.
	 *
	 * @since 0.4.6
	 *
	 * @param int $server_id Server row id.
	 */
	public static function is_excluded_id( int $server_id ): bool {
		if ( $server_id <= 0 ) {
			return false;
		}

		$rows = Query::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return false;
		}

		$row = (array) $rows[0];

		return self::is_excluded( isset( $row['server_slug'] ) ? (string) $row['server_slug'] : '' );
	}

	/**
	 * The refusal every caller shows, so the wording cannot drift between
	 * five surfaces that all mean the same thing.
	 *
	 * @since 0.4.6
	 */
	public static function refusal_message(): string {
		return __( 'That server is not one an AI client connects to, so it cannot be set up this way.', 'acrossai-mcp-manager' );
	}
}
