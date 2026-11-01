<?php
/**
 * Paste-able remedy text shared by several checks (Feature 101).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot;

defined( 'ABSPATH' ) || exit;

/**
 * Text an operator can paste into a hosting ticket or a firewall allowlist.
 *
 * Kept in one place because three checks produce the same remedy from
 * different symptoms, and a support ticket that names the wrong IP range is
 * worse than no ticket at all.
 */
final class Remedies {

	/**
	 * Anthropic's OUTBOUND range — the addresses Claude connects FROM.
	 *
	 * The /23 on the same documentation page is the INBOUND range, for
	 * traffic going TO the API, and is the one people allowlist by mistake.
	 * Getting this wrong produces a ticket that looks right, changes nothing,
	 * and costs another round trip with the host.
	 *
	 * @see https://platform.claude.com/docs/en/api/ip-addresses
	 */
	public const ANTHROPIC_EGRESS_V4 = '160.79.104.0/21';
	public const ANTHROPIC_EGRESS_V6 = '2607:6bc0::/48';

	/**
	 * The hosting guidance this ticket text summarises.
	 *
	 * Included in the paste-able block so the person reading the ticket —
	 * usually a host's support engineer, not the site owner — can see why the
	 * exclusions are being asked for rather than treating the list as "please
	 * turn off your firewall".
	 */
	public const DOC_URL = 'https://github.com/acrossaico/acrossai-mcp-manager/blob/main/docs/hosting-firewall-and-cache.md';

	/**
	 * The paths an AI host touches, as a block for a hosting ticket.
	 */
	public static function paths_for_support(): string {
		$origin = untrailingslashit( home_url() );

		return sprintf(
			/* translators: 1: site origin, 2: IPv4 CIDR, 3: IPv6 CIDR, 4: documentation URL */
			__(
				"Please exclude these paths from caching, bot filtering, WAF rules and redirects on %1\$s:

  /.well-known/oauth-authorization-server
  /.well-known/oauth-protected-resource
  /authorize
  /token
  /wp-json/acrossai-mcp-manager/v1/oauth/*
  /wp-json/mcp/*

and allow requests from Anthropic's outbound addresses:

  %2\$s
  %3\$s

Context: AI clients connect with non-browser user agents (Claude uses python-httpx), so generic \"block Python\" or \"block bots\" rules reject them before WordPress runs. These are public metadata endpoints; they expose no private data.

Full guidance: %4\$s",
				'acrossai-mcp-manager'
			),
			$origin,
			self::ANTHROPIC_EGRESS_V4,
			self::ANTHROPIC_EGRESS_V6,
			self::DOC_URL
		);
	}
}
