<?php
/**
 * Are the OAuth rewrite rules actually in the stored table? (Feature 101)
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot\Checks
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks;

use AcrossAI_MCP_Manager\Includes\OAuth\OAuthRouter;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Check;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;

defined( 'ABSPATH' ) || exit;

/**
 * The single most common cause of "it stopped working and re-saving
 * Permalinks fixed it".
 *
 * `.well-known/oauth-authorization-server`,
 * `.well-known/oauth-protected-resource`, `/authorize` and `/token` are all
 * rewrite-rule based. When those rules are missing from the stored
 * `rewrite_rules` option every one of them 404s, discovery fails, and nothing
 * in the admin says so — the plugin is configured correctly throughout.
 *
 * Three distinct states, three different sentences:
 *
 *   - plain permalinks     → flushing can never help; pick a structure
 *   - rules missing        → they went; the plugin repairs this on the next
 *                            admin request, and this check says so
 *   - all present          → ok
 */
final class RewriteRulesCheck implements Check {

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'rewrite_rules';
	}

	/**
	 * Human-readable check name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Pretty permalinks and OAuth routes', 'acrossai-mcp-manager' );
	}

	/**
	 * @return array<int, string>
	 */
	public function requires(): array {
		return array();
	}

	/**
	 * Run the check.
	 *
	 * @param Context $context Shared probe cache and prior results.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		if ( ! OAuthRouter::rewrites_are_possible() ) {
			return Result::fail(
				$this->id(),
				$this->label(),
				__(
					'This site uses plain permalinks, so WordPress keeps no rewrite table. The OAuth routes this plugin needs — /.well-known/oauth-authorization-server, /.well-known/oauth-protected-resource, /authorize and /token — cannot resolve at all, and no amount of flushing will create them.',
					'acrossai-mcp-manager'
				),
				sprintf(
					/* translators: %s: URL of the Permalinks settings screen */
					__( 'Choose any permalink structure other than Plain at Settings → Permalinks (%s) and save. Then run these checks again.', 'acrossai-mcp-manager' ),
					admin_url( 'options-permalink.php' )
				)
			);
		}

		$missing = OAuthRouter::missing_rules();

		if ( array() === $missing ) {
			return Result::ok(
				$this->id(),
				$this->label(),
				__( 'All five OAuth rewrite rules are present in the stored rewrite table.', 'acrossai-mcp-manager' )
			);
		}

		return Result::fail(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: 1: number of missing rules, 2: total, 3: comma-separated patterns */
				_n(
					'%1$d of %2$d OAuth rewrite rules is missing from the stored rewrite table, so its URL returns a WordPress 404: %3$s',
					'%1$d of %2$d OAuth rewrite rules are missing from the stored rewrite table, so their URLs return a WordPress 404: %3$s',
					count( $missing ),
					'acrossai-mcp-manager'
				),
				count( $missing ),
				5,
				implode( ', ', $missing )
			),
			sprintf(
				/* translators: %s: URL of the Permalinks settings screen */
				__( 'The plugin repairs this itself on the next admin page load — reload this page and run the checks again. If it comes back, something on this site is rewriting the rewrite table without this plugin loaded; re-saving Settings → Permalinks (%s) fixes it immediately while you find out what.', 'acrossai-mcp-manager' ),
				admin_url( 'options-permalink.php' )
			)
		);
	}
}
