<?php
/**
 * Server slugs the Quick Connect wizard must not offer.
 *
 * Quick Connect answers one question — "which server should an AI client
 * connect to?" — so a server no AI client can connect to does not belong in
 * its list. Offering one is worse than untidy: the wizard would walk an
 * operator through six steps and hand them connection details for an endpoint
 * that refuses every client it was just configured for.
 *
 * ## Why a list rather than a check in the wizard
 *
 * The condition is a property of the server, not of the wizard, and more than
 * one surface will eventually need to ask it. Keeping the answer here means
 * the next caller reads the same list instead of re-deriving the rule, which
 * is how the two drift apart.
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

final class QuickConnectExclusions {

	/**
	 * Filter name — companion plugins add their own non-connectable servers.
	 *
	 * @since 0.4.6
	 * @var   string
	 */
	public const FILTER = 'acrossai_mcp_quick_connect_excluded_servers';

	/**
	 * Slugs hidden from the wizard.
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
		 * Filter the server slugs Quick Connect will not offer.
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
	 * Whether a slug is hidden from the wizard.
	 *
	 * @since 0.4.6
	 *
	 * @param string $slug Server slug.
	 */
	public static function is_excluded( string $slug ): bool {
		return '' !== $slug && in_array( $slug, self::slugs(), true );
	}
}
