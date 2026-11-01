<?php
/**
 * Which tab a server edit screen lands on when the request does not say.
 *
 * `Settings::render_edit()` resolved an unrecognised tab by hardcoding
 * `'overview'`. That was true for every server until one existed without an
 * Overview tab: `FleetTab` REPLACES Overview rather than sitting beside it, so
 * a fleet server would render a tab its own nav does not list and that
 * `visible_tabs()` had deliberately excluded.
 *
 * Paired with `RegistryTest`, which pins the slugs themselves. This pins the
 * fallback, which is the part a reader would assume rather than check.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use WP_UnitTestCase;

final class FleetTabFallbackTest extends WP_UnitTestCase {

	/**
	 * A server row.
	 *
	 * @param string $type Server type slug.
	 * @return array<string, mixed>
	 */
	private function server( string $type ): array {
		return array(
			'id'                     => 1,
			'server_name'            => 'Fixture',
			'server_slug'            => 'fixture',
			'server_type'            => $type,
			'registered_from'        => 'database',
			'server_route_namespace' => 'acrossai',
			'server_route'           => 'fixture',
			'server_version'         => 'v1.0.0',
			'is_enabled'             => 1,
			'description'            => '',
		);
	}

	/**
	 * Visible tab slugs, in nav order.
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return array<int, string>
	 */
	private function slugs( array $server ): array {
		return array_map(
			static fn( $tab ) => $tab->slug(),
			Registry::instance()->visible_tabs( $server )
		);
	}

	/**
	 * The hardcoded fallback must be gone.
	 *
	 * Asserted on the source: `render_edit()` reads the request, redirects and
	 * echoes a whole screen, none of which a unit test can stand up — but the
	 * one line that mattered is checkable, and leaving it unchecked is how it
	 * got written in the first place.
	 */
	public function test_the_fallback_follows_visibility_rather_than_naming_overview(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/admin/Partials/Settings.php' );

		$this->assertStringNotContainsString(
			"\$tab = 'overview';",
			$source,
			'A hardcoded fallback sends a fleet server to a tab it does not have.'
		);

		$this->assertStringContainsString(
			'$tab = (string) array_key_first( $tabs );',
			$source,
			'The fallback must be the first VISIBLE tab; $tabs is already in priority order.'
		);
	}

	/**
	 * For every server type, the first visible tab is one the server has.
	 *
	 * This is what the fallback resolves to, so it must never be empty and
	 * must always be a tab that is genuinely on the nav.
	 */
	public function test_every_server_type_has_a_usable_first_tab(): void {
		foreach ( array( ServerTypes::FLEET, 'acrossai', 'mcp-adapter', '' ) as $type ) {
			$slugs = $this->slugs( $this->server( $type ) );

			$this->assertNotEmpty( $slugs, sprintf( 'Server type "%s" has no visible tabs at all.', $type ) );
			$this->assertNotSame( '', $slugs[0], sprintf( 'Server type "%s" has an empty first slug.', $type ) );
		}
	}

	/** A fleet server lands on Fleet, and has no Overview to land on. */
	public function test_a_fleet_server_lands_on_the_fleet_tab(): void {
		$slugs = $this->slugs( $this->server( ServerTypes::FLEET ) );

		$this->assertSame( 'fleet', $slugs[0] );
		$this->assertNotContains( 'overview', $slugs, 'FleetTab replaces Overview; it does not sit beside it.' );
	}

	/** Every other server lands on Overview, exactly as before. */
	public function test_an_ordinary_server_still_lands_on_overview(): void {
		foreach ( array( 'acrossai', 'mcp-adapter', '' ) as $type ) {
			$slugs = $this->slugs( $this->server( $type ) );

			$this->assertSame( 'overview', $slugs[0], sprintf( 'Server type "%s" must be unchanged.', $type ) );
			$this->assertNotContains( 'fleet', $slugs );
		}
	}

	/**
	 * No server may offer two tabs under one slug.
	 *
	 * The global uniqueness this replaced was a weaker claim: two built-ins
	 * sharing a slug meant one was DISCARDED before visibility was consulted,
	 * which is how Overview disappeared from every ordinary server. This is
	 * the property that actually matters, checked per server type.
	 */
	public function test_no_server_type_offers_a_duplicate_slug(): void {
		foreach ( array( ServerTypes::FLEET, 'acrossai', 'mcp-adapter', '' ) as $type ) {
			$slugs = $this->slugs( $this->server( $type ) );

			$this->assertSame(
				$slugs,
				array_values( array_unique( $slugs ) ),
				sprintf( 'Server type "%s" offers a duplicate tab slug.', $type )
			);
		}
	}

	/** A fleet server must not offer the connect-oriented tab. */
	public function test_a_fleet_server_offers_no_connect_tab(): void {
		$this->assertNotContains(
			'connect',
			$this->slugs( $this->server( ServerTypes::FLEET ) ),
			'No AI client connects to the fleet route; the tab would configure nothing.'
		);
	}
}
