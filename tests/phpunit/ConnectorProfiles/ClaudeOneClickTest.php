<?php
/**
 * Claude one-click connect — deep link, connector name, reachability gate.
 *
 * The rendered panel is covered by the renderers suite; this suite covers the
 * two decisions behind it, because neither can be exercised from a local dev
 * site: the link is only offered when a third party could actually fetch the
 * URL, and the name has to match what the Clients tab already writes into a
 * config file.
 *
 * @package AcrossAI_MCP_Manager
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\ConnectorProfiles;

use AcrossAI_MCP_Manager\Includes\ConnectorProfiles\ClaudeConnectorProfile;
use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;
use AcrossAI_MCP_Manager\Includes\Utilities\SiteSlug;
use WP_UnitTestCase;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\ConnectorProfiles\ClaudeConnectorProfile
 * @covers \AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment
 */
final class ClaudeOneClickTest extends WP_UnitTestCase {

	/**
	 * Profile under test.
	 *
	 * @var ClaudeConnectorProfile
	 */
	private ClaudeConnectorProfile $profile;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();

		// Singleton with a private constructor — same accessor the registry uses.
		$this->profile = ClaudeConnectorProfile::instance();
	}

	/**
	 * URLs a hosted client could fetch, and URLs it provably could not.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function reachability_provider(): array {
		return array(
			'public https'          => array( 'https://example.com/wp-json/acrossai/mcp', true ),
			'public https subdir'   => array( 'https://shop.example.co.uk/wp/wp-json/acrossai/mcp', true ),
			'plain http is refused' => array( 'http://example.com/wp-json/acrossai/mcp', false ),
			'dot-local'             => array( 'https://wordpress-7-0.local/wp-json/acrossai/mcp', false ),
			'dot-test'              => array( 'https://site.test/wp-json/acrossai/mcp', false ),
			'dot-localhost'         => array( 'https://site.localhost/wp-json/acrossai/mcp', false ),
			'bare localhost'        => array( 'https://localhost/wp-json/acrossai/mcp', false ),
			'loopback v4'           => array( 'https://127.0.0.1/wp-json/acrossai/mcp', false ),
			'empty string'          => array( '', false ),
			'no scheme'             => array( 'example.com/wp-json/acrossai/mcp', false ),
		);
	}

	/**
	 * @dataProvider reachability_provider
	 *
	 * @param string $url      URL under test.
	 * @param bool   $expected Whether a third party could fetch it.
	 */
	public function test_reachability_gate( string $url, bool $expected ): void {
		$this->assertSame(
			$expected,
			LocalEnvironment::is_publicly_reachable( $url ),
			sprintf( 'Wrong reachability verdict for "%s".', $url )
		);
	}

	/**
	 * A local URL must never be offered as a one-click target: the button
	 * would open, prefill correctly, and then fail — which reads as a broken
	 * feature rather than an unreachable site.
	 */
	public function test_a_local_site_is_never_publicly_reachable(): void {
		$this->assertFalse(
			LocalEnvironment::is_publicly_reachable( 'https://wordpress-7-0.local/wp-json/acrossai/mcp' )
		);
	}

	/**
	 * The connector name is the site title slug, so the name Claude shows
	 * matches the key the Clients tab writes into a config file.
	 */
	public function test_connector_name_is_the_site_slug(): void {
		update_option( 'blogname', 'wordpress 7.0' );

		$this->assertSame( 'wordpress-7-0', $this->profile->get_connector_name() );
		$this->assertSame( SiteSlug::get(), $this->profile->get_connector_name() );
	}

	/**
	 * Spaces and punctuation collapse to hyphens — the whole point of
	 * routing the name through `sanitize_title()` rather than using the
	 * raw title.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function name_provider(): array {
		return array(
			'spaces'          => array( 'My Great Site', 'my-great-site' ),
			'dots'            => array( 'wordpress 7.0', 'wordpress-7-0' ),
			'punctuation'     => array( 'ACME! Widgets?', 'acme-widgets' ),
			'ampersand'       => array( 'Smith & Sons', 'smith-sons' ),
			'collapses runs'  => array( '  double   spaced  ', 'double-spaced' ),
			'accents folded'  => array( 'Café Bar', 'cafe-bar' ),
			'already a slug'  => array( 'already-a-slug', 'already-a-slug' ),
		);
	}

	/**
	 * @dataProvider name_provider
	 *
	 * @param string $title    Site title.
	 * @param string $expected Expected connector name.
	 */
	public function test_connector_name_slugifies_the_title( string $title, string $expected ): void {
		update_option( 'blogname', $title );

		$this->assertSame( $expected, $this->profile->get_connector_name() );
	}

	/**
	 * Non-ASCII that `remove_accents()` cannot fold is percent-encoded by
	 * `sanitize_title()` rather than dropped — a CJK or full-width title
	 * yields a name like `double%e3%80%80-spaced`.
	 *
	 * Recorded, not endorsed. It is ugly but harmless: Claude treats the
	 * connector name as a display string, so an encoded name still connects.
	 * If that ever stops being acceptable, the fix belongs in a shared
	 * helper — the Clients tab writes the same slug into config files and
	 * the two must not diverge.
	 */
	public function test_unfoldable_non_ascii_is_percent_encoded(): void {
		update_option( 'blogname', "double\u{3000} spaced" );

		$this->assertSame( 'double%e3%80%80-spaced', $this->profile->get_connector_name() );
	}

	/**
	 * An empty site title must still yield a usable name rather than a
	 * connector called "".
	 */
	public function test_empty_title_falls_back(): void {
		update_option( 'blogname', '   ' );

		$this->assertSame( SiteSlug::EMPTY_FALLBACK, $this->profile->get_connector_name() );
		$this->assertNotSame( '', $this->profile->get_connector_name() );
	}

	/**
	 * The deep link carries both parameters Claude reads, correctly encoded,
	 * and targets the add-custom-connector modal.
	 */
	public function test_deep_link_shape(): void {
		update_option( 'blogname', 'My Great Site' );

		$url = $this->profile->get_one_click_connect_url( 'https://example.com/wp-json/acrossai/mcp' );

		$this->assertStringStartsWith( 'https://claude.ai/customize/connectors?', $url );
		$this->assertStringContainsString( 'modal=add-custom-connector', $url );
		$this->assertStringContainsString( 'connectorName=my-great-site', $url );
		$this->assertStringContainsString(
			'connectorUrl=' . rawurlencode( 'https://example.com/wp-json/acrossai/mcp' ),
			$url
		);
	}

	/**
	 * The endpoint must survive encoding intact — an unencoded `://` or `?`
	 * would truncate the parameter and hand Claude a half URL.
	 */
	public function test_deep_link_encodes_the_endpoint(): void {
		$endpoint = 'https://example.com/wp-json/acrossai/mcp?server=5';

		$url = $this->profile->get_one_click_connect_url( $endpoint );

		$this->assertStringContainsString( rawurlencode( $endpoint ), $url );
		$this->assertStringNotContainsString( $endpoint, $url );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( $endpoint, $query['connectorUrl'] ?? null );
	}

	/**
	 * Round-trip: whatever the name is, it comes back out of the query
	 * string unchanged.
	 */
	public function test_deep_link_round_trips_the_name(): void {
		update_option( 'blogname', 'Smith & Sons' );

		$url = $this->profile->get_one_click_connect_url( 'https://example.com/mcp' );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'smith-sons', $query['connectorName'] ?? null );
		$this->assertSame( $this->profile->get_connector_name(), $query['connectorName'] ?? null );
	}
}
