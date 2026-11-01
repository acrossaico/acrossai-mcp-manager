<?php
/**
 * The paste-able remedy must name the right IP range.
 *
 * Anthropic publishes two CIDRs on the same page: a /21 of OUTBOUND addresses
 * (the ones Claude connects from, which is what a site needs to allow) and a
 * /23 of INBOUND addresses, for traffic going TO the API. Allowlisting the
 * /23 is a documented mistake that produces a support ticket which looks
 * right, changes nothing, and costs another round trip with the host.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Troubleshoot;

use AcrossAI_MCP_Manager\Includes\Troubleshoot\Remedies;
use WP_UnitTestCase;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Troubleshoot\Remedies
 */
final class RemediesTest extends WP_UnitTestCase {

	public function test_the_outbound_range_is_named(): void {
		$text = Remedies::paths_for_support();

		self::assertStringContainsString( '160.79.104.0/21', $text );
		self::assertStringContainsString( '2607:6bc0::/48', $text );
	}

	public function test_the_inbound_range_is_never_named(): void {
		self::assertStringNotContainsString(
			'/23',
			Remedies::paths_for_support(),
			'The /23 is the INBOUND range. Allowlisting it does nothing and wastes a ticket.'
		);
	}

	/**
	 * @dataProvider path_provider
	 *
	 * @param string $path A path an AI host touches.
	 */
	public function test_every_path_an_ai_host_touches_is_listed( string $path ): void {
		self::assertStringContainsString( $path, Remedies::paths_for_support() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function path_provider(): array {
		return array(
			'AS metadata'  => array( '/.well-known/oauth-authorization-server' ),
			'PR metadata'  => array( '/.well-known/oauth-protected-resource' ),
			'authorize'    => array( '/authorize' ),
			'token'        => array( '/token' ),
			'DCR + OAuth'  => array( '/wp-json/acrossai-mcp-manager/v1/oauth/' ),
			'MCP routes'   => array( '/wp-json/mcp/' ),
		);
	}

	/**
	 * The host reading this ticket needs to know WHY, or the request reads as
	 * "please turn off your firewall".
	 */
	public function test_it_explains_why_a_bot_rule_rejects_an_ai_client(): void {
		self::assertStringContainsString( 'python-httpx', Remedies::paths_for_support() );
	}

	public function test_it_names_this_site(): void {
		self::assertStringContainsString( untrailingslashit( home_url() ), Remedies::paths_for_support() );
	}

	/**
	 * The person reading the ticket is usually a host's support engineer, not
	 * the site owner. Without the reason, a bare list of paths and CIDRs
	 * reads as "please turn off your firewall".
	 */
	public function test_it_links_to_the_hosting_guidance(): void {
		self::assertStringContainsString( Remedies::DOC_URL, Remedies::paths_for_support() );
	}

	public function test_the_doc_url_points_at_a_file_that_exists(): void {
		self::assertStringEndsWith( 'docs/hosting-firewall-and-cache.md', Remedies::DOC_URL );
		self::assertFileExists( dirname( __DIR__, 3 ) . '/docs/hosting-firewall-and-cache.md' );
	}
}
