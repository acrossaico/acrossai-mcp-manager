<?php
/**
 * Does a non-browser user agent get rejected? (Feature 101)
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot\Checks
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks;

use AcrossAI_MCP_Manager\Includes\Troubleshoot\Check;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Remedies;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch our own discovery document four times, varying only the User-Agent.
 *
 * This is the check that would have caught the incident behind issue #176
 * from inside the admin. A site's security plugin rejected the AI host on its
 * User-Agent before WordPress ran: the MCP route answered 401 with a correct
 * challenge header, both discovery documents returned 200, DCR validated
 * input correctly — and the connector still could not be added. Diagnosing it
 * meant reading the security plugin's block log.
 *
 * Claude.ai connects as `python-httpx`. Generic "block Python" and "block
 * bots" rules ship ENABLED BY DEFAULT in at least one popular firewall
 * plugin, and usually attach a multi-hour IP ban to the first hit.
 *
 * 403 is the plain rejection; 503 is the challenge or interstitial some
 * filters serve instead. Both count.
 *
 * This reports `warning`, never `fail`, and says why in its own message: the
 * probe originates from the server's own address, so it cannot see a block
 * keyed on the caller's IP — which is the other half of the same problem and
 * needs a vantage point outside this site.
 */
final class UserAgentProbeCheck implements Check {

	/**
	 * The shapes AI hosts and their SDKs actually connect with.
	 *
	 * @var array<int, string>
	 */
	private const AGENTS = array(
		'python-httpx/0.27.0',
		'python-requests/2.32.3',
		'node',
		'Go-http-client/2.0',
	);

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'user_agent';
	}

	/**
	 * Human-readable check name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Non-browser user agents', 'acrossai-mcp-manager' );
	}

	/**
	 * @return array<int, string>
	 */
	public function requires(): array {
		return array( 'rewrite_rules', 'discovery' );
	}

	/**
	 * Run the check.
	 *
	 * @param Context $context Shared probe cache and prior results.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$url     = DiscoveryCheck::url( 'oauth-protected-resource' );
		$blocked = array();

		foreach ( self::AGENTS as $agent ) {
			$probe = $context->fetch( $url, array( 'User-Agent' => $agent ) );

			if ( 403 === $probe['code'] || 503 === $probe['code'] ) {
				$blocked[] = sprintf( '%s (HTTP %d)', $agent, $probe['code'] );
			}
		}

		if ( array() !== $blocked ) {
			return Result::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: comma-separated user agents with their status codes */
					__( 'Something on this site rejects requests purely because of their user agent: %s. Claude connects as python-httpx, so a rule like this refuses it before WordPress runs — and the site looks healthy from every other angle.', 'acrossai-mcp-manager' ),
					implode( ', ', $blocked )
				),
				__( 'Look for a "block Python", "block bots" or "block empty/suspicious user agents" rule in your security plugin or WAF, and allow the OAuth and MCP paths below. Several firewall plugins enable such a rule by default and attach an IP ban to the first match.', 'acrossai-mcp-manager' ),
				Remedies::paths_for_support()
			);
		}

		return Result::warning(
			$this->id(),
			$this->label(),
			__( 'All four non-browser user agents were served normally. Read this as "no user-agent rule was found", not as "the site is reachable": this probe runs from the server to itself, so it carries this server\'s own IP address and cannot see a block keyed on the caller\'s address — a datacenter-IP rule, a country block or a CDN bot filter would reject a real AI client while passing this check.', 'acrossai-mcp-manager' ),
			__( 'If a client still cannot connect with everything on this page green, the block is almost certainly IP-based and visible only from outside. Ask your host or CDN for the block log covering the time of the attempt, and allowlist the addresses below.', 'acrossai-mcp-manager' ),
			Remedies::paths_for_support()
		);
	}
}
