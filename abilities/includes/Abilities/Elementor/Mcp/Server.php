<?php
/**
 * Publishing this plugin's Toolsets onto Elementor's MCP server.
 *
 * Six declarations over {@see Host_Mcp_Server}, which holds the behaviour. If this class ever
 * needs more than declarations, the shared class is missing something; add it there.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Elementor/Mcp
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor\Mcp;

use AcrossAI_Abilities_Manager\Includes\Abilities\Mcp\Host_Mcp_Server;
use AcrossAI_Abilities_Manager\Includes\Abilities\Mcp\Site_Guide;
use AcrossAI_Abilities_Manager\Includes\Abilities\Mcp\Site_Toolset;

defined( 'ABSPATH' ) || exit;

/**
 * Contributes two Toolsets and a connect message to `elementor-mcp-server`.
 */
final class Server extends Host_Mcp_Server {

	/**
	 * @since  0.0.42
	 * @return string
	 */
	protected function integration_slug(): string {
		return 'elementor';
	}

	/**
	 * Whether Elementor's MCP package is here to contribute to.
	 *
	 * The composer package's registry rather than `\Elementor\Plugin`: the plugin can be active
	 * while being too old to have `modules/mcp/` at all, and there is no server to join on such a
	 * site. Elementor's own `Server_Bootstrap` guards the same way.
	 *
	 * Deliberately NOT also checking whether the operator enabled Elementor's MCP module. That
	 * switch decides whether Elementor creates the server; if it did not, the filter never fires
	 * and this contributes nothing anyway. Reading it here would add a second copy of Elementor's
	 * own condition that could disagree with it.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function host_available(): bool {
		return class_exists( '\Elementor\MCP\Composer\Mcp\Registry' );
	}

	/**
	 * @since  0.0.42
	 * @return string
	 */
	protected function tools_filter(): string {
		return 'elementor/mcp/server/tools';
	}

	/**
	 * @since  0.0.42
	 * @return string
	 */
	protected function server_id(): string {
		return 'elementor-mcp-server';
	}

	/**
	 * The gateway, then the guide that explains it.
	 *
	 * Both are the shared, host-neutral pair under `Abilities\Mcp` — not Elementor's own. One
	 * ability slug can be contributed to any number of servers, so a second host declares the same
	 * two here rather than duplicating them. See {@see Site_Toolset} for why there is one pair.
	 *
	 * @since  0.0.42
	 * @return string[]
	 */
	protected function toolsets(): array {
		return array(
			Site_Toolset::SLUG,
			Site_Guide::SLUG,
		);
	}

	/**
	 * What a client connecting to Elementor's server is told.
	 *
	 * Appended to Elementor's own sentence, which stays first. Names both tools explicitly: the
	 * alternative is a client that reads "Read and modify Elementor Editor abilities", concludes
	 * this site does pages and nothing else, and never calls either.
	 *
	 * **This is the only place Elementor is named.** The two toolsets are shared across hosts, so
	 * their descriptions became host-neutral in 0.0.42 — which means the useful routing hint
	 * ("use the elementor-* tools for editor work") lives here or nowhere. That is the right home
	 * for it: `initialize` is per server, so each host can say how the pair relates to its own
	 * tools without being wrong on anybody else's.
	 *
	 * @since  0.0.42
	 * @return string
	 */
	protected function instructions(): string {
		return sprintf(
			/* translators: 1: the site guide's ability name, 2: the site toolset's ability name. */
			__( 'This site also exposes the rest of WordPress, beyond the Elementor editor, through two tools added by AcrossAI Abilities Manager. Call %1$s first for what is here and how to reach it. Then %2$s: it takes action=discover with no arguments to return a short menu of areas — content, media, database, files, cron, users, updates and every installed plugin\'s capability — then discover again with area=<area> to list one area, action=info to read an ability\'s parameters, and action=execute to run it. Use the elementor-* tools for editor work and these two for everything else.', 'acrossai-abilities-manager' ),
			Site_Guide::SLUG,
			Site_Toolset::SLUG
		);
	}
}
