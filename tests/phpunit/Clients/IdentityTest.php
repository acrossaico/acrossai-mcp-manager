<?php
/**
 * What makes two requests the same client.
 *
 * Every case below is a real failure, not an invented one. Before a line of
 * this feature was written the proposed key was run against this site's own
 * activity log, and it fragmented three ways — so those three are the spine
 * of this file:
 *
 *  - five different callers collapsing into one row because `client_name`
 *    was empty on all of them;
 *  - one caller splitting into two rows because its user agent carried a
 *    version and the version moved;
 *  - one fleet peer appearing under two names because `fleet_peer` is a
 *    label the other site chooses, not an identity.
 *
 * The stakes are what make this worth a file of its own: the Clients table is
 * durable. A key that merges two clients, or splits one, does not correct
 * itself on the next request — it is a wrong row that stays wrong.
 *
 * Pure suite: `Identity` reads only `ConnectionContext`'s constants and does
 * no I/O, so it needs no WordPress.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Clients;

use AcrossAI_MCP_Manager\Includes\Database\Clients\Identity;
use AcrossAI_MCP_Manager\Includes\Observability\ConnectionContext;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase {

	// ─────────────────────────────────────────────────────────────────
	// The three fragmentations measured against live data
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Five callers with no client name stay five clients.
	 *
	 * On the site this was measured against, 94 requests carried an empty
	 * `client_name`. They were not one anonymous client: the agent separated
	 * them cleanly. Keying on the name alone merged all five, and because the
	 * table is durable that merge would have been permanent.
	 */
	public function test_nameless_callers_are_not_merged(): void {
		$agents = array(
			'curl/8.7.1',
			'claude-code/2.1.236 mcp-wordpress-remote/0.5.4',
			'Python-urllib/3.14',
			'WP CLI 2.12.0',
			'AcrossAI Fleet/0.11.0 (https://wordpress-7-0.local)',
		);

		$identities = array();

		foreach ( $agents as $agent ) {
			$identities[] = Identity::resolve(
				array(
					'connection_type' => ConnectionContext::TYPE_APP_PASSWORD,
					'client_name'     => '',
					'user_agent'      => $agent,
					'user_id'         => 1,
				)
			);
		}

		$this->assertCount(
			5,
			array_unique( $identities ),
			'Callers distinguishable by agent were merged into fewer rows: ' . implode( ', ', $identities )
		);
	}

	/**
	 * A client that updates is still the same client.
	 *
	 * This one actually happened: `mcp-wordpress-remote` went 0.5.4 → 0.5.5
	 * and the same Claude Code install became two rows.
	 */
	public function test_a_version_bump_does_not_split_a_client(): void {
		$before = Identity::ua_token( 'claude-code/2.1.236 mcp-wordpress-remote/0.5.4' );
		$after  = Identity::ua_token( 'claude-code/2.1.237 mcp-wordpress-remote/0.5.5' );

		$this->assertSame( 'claude-code', $before );
		$this->assertSame( $before, $after, 'A version bump created a second client.' );
	}

	/**
	 * Fleet keys on the host key, because the peer label moves.
	 *
	 * Observed thirty-three seconds apart, same user, same method: the peer
	 * reported its site name, then its hostname. One peer, two labels — so
	 * the label cannot be the identity.
	 */
	public function test_fleet_keys_on_the_host_key_not_the_peer_label(): void {
		$signals = array(
			'connection_type' => ConnectionContext::TYPE_FLEET,
			'fleet_host_key'  => 'hub-abc123',
			'client_name'     => '',
			'user_id'         => 1,
		);

		$as_site_name = Identity::resolve( $signals + array( 'user_agent' => 'AcrossAI Fleet (https://hub.example)' ) );
		$as_hostname  = Identity::resolve( $signals + array( 'user_agent' => 'AcrossAI Fleet/0.11.0 (https://hub.example)' ) );

		$this->assertSame( 'hub-abc123', $as_site_name );
		$this->assertSame( $as_site_name, $as_hostname );
	}

	// ─────────────────────────────────────────────────────────────────
	// Precedence
	// ─────────────────────────────────────────────────────────────────

	/**
	 * An OAuth client is its client id, whatever machine it calls from.
	 *
	 * An OAuth caller also has a user agent. Keying on the agent would split
	 * one registered client across every machine that used it.
	 *
	 * @param string $type A connector-bearing connection type.
	 *
	 * @dataProvider oauth_types
	 */
	public function test_oauth_keys_on_the_client_id( string $type ): void {
		$base = array(
			'connection_type' => $type,
			'client_id'       => 'server-5-claude-6c67b242',
			'client_name'     => 'Claude',
			'user_id'         => 1,
		);

		$from_one = Identity::resolve( $base + array( 'user_agent' => 'claude-code/2.1.236' ) );
		$from_two = Identity::resolve( $base + array( 'user_agent' => 'curl/8.7.1' ) );

		$this->assertSame( 'server-5-claude-6c67b242', $from_one );
		$this->assertSame( $from_one, $from_two );
	}

	/** @return array<string, array{0: string}> */
	public function oauth_types(): array {
		return array(
			'oauth'        => array( ConnectionContext::TYPE_OAUTH ),
			'ai-connector' => array( ConnectionContext::TYPE_AI_CONNECTOR ),
		);
	}

	/**
	 * A missing client id falls through rather than producing an empty key.
	 *
	 * An OAuth row whose client id did not resolve must not become the
	 * catch-all row that absorbs every other unidentifiable caller.
	 */
	public function test_oauth_without_a_client_id_falls_through(): void {
		$identity = Identity::resolve(
			array(
				'connection_type' => ConnectionContext::TYPE_OAUTH,
				'client_id'       => '',
				'client_name'     => 'Claude',
			)
		);

		$this->assertSame( 'Claude', $identity );
	}

	/** A named client beats its agent — the name is what a person recognises. */
	public function test_a_client_name_beats_the_agent(): void {
		$this->assertSame(
			'claude-code',
			Identity::resolve(
				array(
					'connection_type' => ConnectionContext::TYPE_APP_PASSWORD,
					'client_name'     => 'claude-code',
					'user_agent'      => 'curl/8.7.1',
				)
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// The agent parser
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Real agents from the log, and what each should be called.
	 *
	 * @param string $agent    Raw user agent.
	 * @param string $expected Product token.
	 *
	 * @dataProvider real_agents
	 */
	public function test_ua_token( string $agent, string $expected ): void {
		$this->assertSame( $expected, Identity::ua_token( $agent ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public function real_agents(): array {
		return array(
			'bridge is skipped'    => array( 'claude-code/2.1.236 mcp-wordpress-remote/0.5.4', 'claude-code' ),
			'bare product'         => array( 'curl/8.7.1', 'curl' ),
			'python'               => array( 'Python-urllib/3.14', 'python-urllib' ),
			'space-separated name' => array( 'WP CLI 2.12.0', 'wp-cli' ),
			'comment is ignored'   => array( 'AcrossAI Fleet/0.11.0 (https://wordpress-7-0.local)', 'acrossai-fleet' ),
			'no version at all'    => array( 'SomeClient', 'someclient' ),
			'empty'                => array( '', '' ),
			'whitespace only'      => array( "  \t ", '' ),
			'comment only'         => array( '(https://example.com)', '' ),
			'bridge alone'         => array( 'mcp-wordpress-remote/0.5.4', '' ),
		);
	}

	/**
	 * A space-separated product name is not truncated at its first word.
	 *
	 * Caught while building this, and it is the same merge failure as the
	 * empty-name case wearing different clothes. WP-CLI reports
	 * `WP CLI 2.12.0` — spaces, not `product/version` — so taking only the
	 * first word made it `wp`, and every other agent beginning "WP " landed
	 * in the same durable row with it.
	 */
	public function test_a_wp_prefixed_agent_does_not_collide_with_wp_cli(): void {
		$tokens = array(
			Identity::ua_token( 'WP CLI 2.12.0' ),
			Identity::ua_token( 'WP Mail SMTP/1.0' ),
			Identity::ua_token( 'WP Rocket/3.1' ),
		);

		$this->assertSame( 'wp-cli', $tokens[0] );
		$this->assertCount( 3, array_unique( $tokens ), 'Three clients collapsed into: ' . implode( ', ', $tokens ) );
	}

	/**
	 * The bridge never becomes an identity, even first in the string.
	 *
	 * It appears in the agent of everything it carries, so treating it as the
	 * product would collapse every bridged client into one row — the same
	 * merge failure as the empty-name case, with a different cause.
	 */
	public function test_the_bridge_is_never_the_identity(): void {
		$this->assertSame( 'cursor', Identity::ua_token( 'mcp-wordpress-remote/0.5.4 cursor/1.2.0' ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// The fallbacks
	// ─────────────────────────────────────────────────────────────────

	/** With nothing else, a logged-in caller is at least distinguishable by user. */
	public function test_it_falls_back_to_the_user(): void {
		$this->assertSame(
			'user-7',
			Identity::resolve( array( 'connection_type' => ConnectionContext::TYPE_BROWSER, 'user_id' => 7 ) )
		);
	}

	/**
	 * The last resort is a named value, never an empty string.
	 *
	 * An empty identity would hash to one row quietly absorbing every
	 * unidentifiable caller on the site.
	 */
	public function test_the_last_resort_is_named(): void {
		$this->assertSame( Identity::UNKNOWN, Identity::resolve( array() ) );
		$this->assertNotSame( '', Identity::resolve( array() ) );
	}

	/** Non-scalar signals do not fatal or leak "Array" into an identity. */
	public function test_it_survives_junk_signals(): void {
		$identity = Identity::resolve(
			array(
				'connection_type' => ConnectionContext::TYPE_APP_PASSWORD,
				'client_name'     => array( 'nope' ),
				'user_agent'      => null,
				'user_id'         => '3',
			)
		);

		$this->assertSame( 'user-3', $identity );
	}

	/** An over-long identity is clamped to the column it has to fit. */
	public function test_a_long_identity_is_clamped(): void {
		$token = Identity::ua_token( str_repeat( 'a', 400 ) . '/1.0' );

		$this->assertSame( Identity::MAX_LENGTH, strlen( $token ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// Scoping
	// ─────────────────────────────────────────────────────────────────

	/**
	 * The same name on two servers is two clients.
	 *
	 * They are separate grants; merging them would make one Revoke look like
	 * it covered both.
	 */
	public function test_the_hash_is_scoped_by_server_and_type(): void {
		$a = Identity::hash( 5, ConnectionContext::TYPE_APP_PASSWORD, 'claude-code' );
		$b = Identity::hash( 7, ConnectionContext::TYPE_APP_PASSWORD, 'claude-code' );
		$c = Identity::hash( 5, ConnectionContext::TYPE_OAUTH, 'claude-code' );

		$this->assertNotSame( $a, $b, 'The same client on two servers collapsed to one row.' );
		$this->assertNotSame( $a, $c, 'The same name over two routes collapsed to one row.' );
		$this->assertSame( $a, Identity::hash( 5, ConnectionContext::TYPE_APP_PASSWORD, 'claude-code' ) );
		$this->assertSame( 40, strlen( $a ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// Route labels
	// ─────────────────────────────────────────────────────────────────

	/**
	 * The route names match the Connect tab's own four, so the Clients screen
	 * speaks the language the operator chose from when setting the client up.
	 *
	 * @param array<string, mixed> $signals  Request facts.
	 * @param string               $expected Route label.
	 *
	 * @dataProvider routes
	 */
	public function test_route( array $signals, string $expected ): void {
		$this->assertSame( $expected, Identity::route( $signals ) );
	}

	/** @return array<string, array{0: array<string, mixed>, 1: string}> */
	public function routes(): array {
		return array(
			'connector'   => array( array( 'connection_type' => ConnectionContext::TYPE_AI_CONNECTOR ), 'connector' ),
			'oauth'       => array( array( 'connection_type' => ConnectionContext::TYPE_OAUTH ), 'connector' ),
			'fleet'       => array( array( 'connection_type' => ConnectionContext::TYPE_FLEET ), 'fleet' ),
			'npm bridge'  => array(
				array(
					'connection_type' => ConnectionContext::TYPE_APP_PASSWORD,
					'user_agent'      => 'claude-code/2.1.236 mcp-wordpress-remote/0.5.4',
				),
				'npm',
			),
			'wp-cli'      => array(
				array( 'connection_type' => ConnectionContext::TYPE_UNKNOWN, 'user_agent' => 'WP CLI 2.12.0' ),
				'wp-cli',
			),
			'config file' => array(
				array( 'connection_type' => ConnectionContext::TYPE_APP_PASSWORD, 'user_agent' => 'curl/8.7.1' ),
				'config-file',
			),
			'browser'     => array( array( 'connection_type' => ConnectionContext::TYPE_BROWSER ), 'browser' ),
			'direct'      => array( array( 'connection_type' => ConnectionContext::TYPE_UNKNOWN ), 'direct' ),
		);
	}

	/**
	 * Fleet wins over the bridge token.
	 *
	 * A fleet peer's agent names the plugin, not a bridge, but the ordering
	 * is worth pinning: connection type is a fact, the agent is a claim.
	 */
	public function test_route_prefers_the_connection_type_over_the_agent(): void {
		$this->assertSame(
			'fleet',
			Identity::route(
				array(
					'connection_type' => ConnectionContext::TYPE_FLEET,
					'user_agent'      => 'claude-code/1.0 mcp-wordpress-remote/0.5.5',
				)
			)
		);
	}
}
