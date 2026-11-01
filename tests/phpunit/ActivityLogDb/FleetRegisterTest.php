<?php
/**
 * The child's half of pairing.
 *
 * `/fleet/register` is the one Fleet route with `permission_callback =>
 * '__return_true'`, and it has to be: the hub holds nothing this site would
 * recognise until the call succeeds. The security ID and the rate limiter ARE
 * the authentication, which makes each of them worth a test rather than a
 * comment.
 *
 * Covered by the live two-site run and nothing else until now.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\ActivityLogDb;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Fleet\Hosts;
use AcrossAI_MCP_Manager\Includes\Fleet\Register;
use AcrossAI_MCP_Manager\Includes\Fleet\SecurityId;
use WP_REST_Request;
use WP_UnitTestCase;

final class FleetRegisterTest extends WP_UnitTestCase {

	/** @var int */
	private $server_id = 0;

	/** A throwaway public key, generated once for the whole class. */
	private static string $pubkey = '';

	public static function set_up_before_class() {
		parent::set_up_before_class();

		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		self::$pubkey = (string) ( openssl_pkey_get_details( $key )['key'] ?? '' );
	}

	public function set_up() {
		parent::set_up();

		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => DefaultServerSeeder::FLEET_SLUG,
				'number'      => 1,
			)
		);

		if ( empty( $rows ) ) {
			$this->markTestSkipped( 'The fleet server is seeded by the activator; nothing to test without it.' );
		}

		$this->server_id = (int) $rows[0]->id;

		// Fleet access on, host list empty, rate limiter clear.
		MCPServerQuery::instance()->update_item( $this->server_id, array( 'is_enabled' => 1 ) );

		foreach ( Hosts::all( $this->server_id ) as $host ) {
			Hosts::remove( $this->server_id, (string) $host['key'] );
		}

		$this->clear_rate_limit();

		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		foreach ( Hosts::all( $this->server_id ) as $host ) {
			Hosts::remove( $this->server_id, (string) $host['key'] );
		}

		$this->clear_rate_limit();
		parent::tear_down();
	}

	private function clear_rate_limit(): void {
		global $wpdb;

		// The limiter keys on REMOTE_ADDR; the key is an implementation
		// detail, so clear by prefix rather than guessing the hash.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%acrossai_mcp_fleet_reg%'" );
		wp_cache_flush();
	}

	/**
	 * POST to the registration route.
	 *
	 * @param array<string, mixed> $body Request parameters.
	 * @return \WP_REST_Response
	 */
	private function post( array $body ) {
		$request = new WP_REST_Request( 'POST', '/acrossai-mcp-manager/v1/fleet/register' );

		foreach ( $body as $k => $v ) {
			$request->set_param( $k, $v );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A well-formed registration for the current security ID.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function valid( array $overrides = array() ): array {
		return array_merge(
			array(
				'security_id' => SecurityId::get( $this->server_id ),
				'pubkey'      => self::$pubkey,
				'hub_url'     => 'https://agency.example',
				'hub_name'    => 'Agency',
			),
			$overrides
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// The happy path, and what it must and must not store
	// ─────────────────────────────────────────────────────────────────

	public function test_a_valid_registration_is_accepted_and_tells_the_hub_where_to_call(): void {
		$response = $this->post( $this->valid() );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertNotEmpty( $data['host_key'] ?? '', 'The hub needs the key this site will expect back on every call.' );
		$this->assertNotEmpty( $data['endpoint'] ?? '', 'Without an endpoint the hub is paired and cannot call.' );
		$this->assertStringContainsString( '/fleet', (string) $data['endpoint'] );
		$this->assertCount( 1, Hosts::all( $this->server_id ) );
	}

	/**
	 * THE SECURITY PROPERTY. The child stores a public key and nothing else.
	 *
	 * This is the whole reason pairing is signature-based: a database leak on
	 * a managed site must expose no credential that could be used against it
	 * or against any other site in the fleet.
	 */
	public function test_only_the_public_half_is_ever_stored(): void {
		$this->post( $this->valid() );

		$host = Hosts::all( $this->server_id )[0];

		$this->assertStringStartsWith( '-----BEGIN PUBLIC KEY', (string) $host['pubkey'] );
		$this->assertStringNotContainsString( 'PRIVATE KEY', (string) $host['pubkey'] );

		$serialised = (string) wp_json_encode( $host );

		foreach ( array( 'privkey', 'private', 'secret' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, strtolower( $serialised ) );
		}
	}

	/** The hub's hostname is recorded, for the log and the host list. */
	public function test_the_hubs_url_is_recorded(): void {
		$this->post( $this->valid() );

		$this->assertSame( 'https://agency.example', Hosts::all( $this->server_id )[0]['url'] );
	}

	// ─────────────────────────────────────────────────────────────────
	// The security ID is the authentication
	// ─────────────────────────────────────────────────────────────────

	public function test_a_wrong_security_id_is_refused_and_stores_nothing(): void {
		$response = $this->post( $this->valid( array( 'security_id' => 'wrongwrongwr' ) ) );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertCount( 0, Hosts::all( $this->server_id ), 'A refused registration must leave no host behind.' );
	}

	/**
	 * The ID is single-use.
	 *
	 * This is what makes a long-lived ID safe to offer: an unused one is a
	 * single pending invitation, not a standing back door. Replaying it must
	 * fail even though it was valid moments earlier.
	 */
	public function test_the_security_id_cannot_be_used_twice(): void {
		$body = $this->valid();

		$this->assertSame( 200, $this->post( $body )->get_status() );

		$replay = $this->post( $body );

		$this->assertNotSame( 200, $replay->get_status(), 'A spent security ID must not pair a second hub.' );
		$this->assertCount( 1, Hosts::all( $this->server_id ) );
	}

	/** Spending it rotates it, so the screen never shows a dead code. */
	public function test_a_successful_pairing_rotates_the_id(): void {
		$before = SecurityId::get( $this->server_id );

		$this->post( $this->valid( array( 'security_id' => $before ) ) );

		$this->assertNotSame( $before, SecurityId::get( $this->server_id ) );
	}

	/**
	 * A wrong ID and an unknown one must read the same.
	 *
	 * Distinguishing them would turn the route into an oracle for probing
	 * which IDs exist.
	 */
	public function test_a_wrong_id_and_a_malformed_one_are_indistinguishable(): void {
		$wrong     = $this->post( $this->valid( array( 'security_id' => 'abcdefghjkmn' ) ) );
		$malformed = $this->post( $this->valid( array( 'security_id' => '!!!' ) ) );

		$this->assertSame( $wrong->get_status(), $malformed->get_status() );
		$this->assertSame(
			$wrong->get_data()['message'] ?? 'a',
			$malformed->get_data()['message'] ?? 'b',
			'The refusal must not reveal whether an ID was merely wrong or not an ID at all.'
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// The owner's switch
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Fleet access off means off, even with a correct ID.
	 *
	 * The client's own switch has to win: it is the thing that makes
	 * "revoking is local" true rather than a claim on a screen.
	 */
	public function test_a_correct_id_is_still_refused_while_fleet_access_is_off(): void {
		$body = $this->valid();

		MCPServerQuery::instance()->update_item( $this->server_id, array( 'is_enabled' => 0 ) );
		wp_cache_flush();

		$response = $this->post( $body );

		MCPServerQuery::instance()->update_item( $this->server_id, array( 'is_enabled' => 1 ) );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertCount( 0, Hosts::all( $this->server_id ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// Input validation
	// ─────────────────────────────────────────────────────────────────

	/** A key that is not a public key is refused rather than stored. */
	public function test_a_junk_public_key_is_refused(): void {
		$response = $this->post( $this->valid( array( 'pubkey' => 'not a key at all' ) ) );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertCount( 0, Hosts::all( $this->server_id ) );
	}

	/** A private key offered as the public one must not be accepted. */
	public function test_a_private_key_is_not_accepted_as_a_public_one(): void {
		$key     = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		$private = '';
		openssl_pkey_export( $key, $private );

		$response = $this->post( $this->valid( array( 'pubkey' => $private ) ) );

		$this->assertNotSame( 200, $response->get_status(), 'Accepting a private key here would store a credential this site has no business holding.' );
		$this->assertCount( 0, Hosts::all( $this->server_id ) );
	}

	/**
	 * A hub URL must already be an absolute URL.
	 *
	 * `esc_url_raw( 'not-a-url' )` returns `http://not-a-url` — it repairs
	 * schemeless input rather than refusing it — so a guard applied to its
	 * OUTPUT can never fail, and this registered happily until the check moved
	 * ahead of the normalisation. The value ends up as `fleet_peer`, the
	 * column that attributes every later request in the activity log, so a
	 * non-hostname there is a nonsense identity in an audit trail.
	 *
	 * This plugin pins PHPUnit 9.6, so the provider is wired by annotation.
	 * (acrossai-pro pins 13, where only the attribute works — the two are not
	 * interchangeable between the repos.)
	 *
	 * @dataProvider unusable_urls
	 *
	 * @param string $url Submitted address.
	 */
	public function test_an_unusable_hub_url_is_refused( string $url ): void {
		$response = $this->post( $this->valid( array( 'hub_url' => $url ) ) );

		$this->assertNotSame( 200, $response->get_status(), sprintf( '%s must not be accepted as a hub address.', var_export( $url, true ) ) );
		$this->assertCount( 0, Hosts::all( $this->server_id ) );
	}

	/** @return array<string, array{0: string}> */
	public static function unusable_urls(): array {
		return array(
			'no scheme at all'   => array( 'not-a-url' ),
			'a bare hostname'    => array( 'agency.example' ),
			'empty'              => array( '' ),
			'a javascript: href' => array( 'javascript:alert(1)' ),
			'a file: path'       => array( 'file:///etc/passwd' ),
		);
	}

	/** A proper URL still works, or the rule above is too strict. */
	public function test_an_absolute_https_url_is_still_accepted(): void {
		$this->assertSame( 200, $this->post( $this->valid( array( 'hub_url' => 'https://agency.example' ) ) )->get_status() );
	}

	/**
	 * `host_key` is the identity; `hub_url` is the label.
	 *
	 * Worth pinning because the two are easy to conflate. The hub asserts its
	 * own URL and name at registration, so neither is trustworthy on its own —
	 * what authenticates a later request is the key this site mints here and
	 * the signature made with the matching private half.
	 */
	public function test_the_host_key_is_minted_here_not_supplied_by_the_hub(): void {
		$response = $this->post( $this->valid( array( 'hub_name' => 'Pretending To Be Someone' ) ) );

		$host_key = (string) ( $response->get_data()['host_key'] ?? '' );

		$this->assertNotEmpty( $host_key );
		$this->assertStringNotContainsString( 'Pretending', $host_key, 'The key must not be derived from anything the caller chose to call itself.' );
		$this->assertSame( $host_key, Hosts::all( $this->server_id )[0]['key'] );
	}

	// ─────────────────────────────────────────────────────────────────
	// The rate limiter
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Guessing is bounded.
	 *
	 * 12 characters from a 27-letter alphabet is not brute-forceable, but the
	 * limiter is what stops the route being used to grind at it — and it must
	 * count attempts BEFORE checking the ID, or a wrong guess would be free.
	 */
	public function test_repeated_wrong_guesses_are_eventually_rate_limited(): void {
		$seen_limit = false;

		for ( $i = 0; $i < 15; $i++ ) {
			$response = $this->post( $this->valid( array( 'security_id' => 'abcdefghjkmn' ) ) );

			if ( 'acrossai_mcp_fleet_rate_limited' === ( $response->get_data()['code'] ?? '' ) ) {
				$seen_limit = true;
				break;
			}
		}

		$this->assertTrue( $seen_limit, 'Wrong guesses must stop being free before 15 attempts.' );
		$this->assertCount( 0, Hosts::all( $this->server_id ) );
	}

	/** Being rate-limited must not pair anything, even with a correct ID. */
	public function test_a_rate_limited_caller_cannot_pair_with_a_correct_id(): void {
		for ( $i = 0; $i < 15; $i++ ) {
			$this->post( $this->valid( array( 'security_id' => 'abcdefghjkmn' ) ) );
		}

		$response = $this->post( $this->valid() );

		$this->assertNotSame( 200, $response->get_status() );
		$this->assertCount( 0, Hosts::all( $this->server_id ) );
	}
}
