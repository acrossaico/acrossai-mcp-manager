<?php
/**
 * Behavioural tests for server-scoped DCR client dedup.
 *
 * Defect 3 of B18: `find_by_fingerprint()` matched on canonical client
 * metadata alone. Every MCP host registers byte-identical metadata for every
 * connector it holds — Claude.ai always sends the same single
 * `redirect_uris` — so the SECOND connector's registration returned the FIRST
 * connector's client row, permanently bound to the first server. From that
 * point the second server was unreachable no matter what else was fixed.
 *
 * The query is exercised through a double that captures the args BerlinDB
 * would receive, so the scoping is asserted without a database.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Unit\OAuthDiscovery;

use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Query as ClientsQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Row as ClientRow;
use PHPUnit\Framework\TestCase;

/**
 * Captures query args and returns a canned row set.
 */
final class CapturingClientsQuery extends ClientsQuery {

	/** @var array<int, array<string, mixed>> */
	public array $captured = array();

	/** @var array<int, ClientRow> */
	public array $rows = array();

	/** @var bool */
	public bool $has_server_id_column = true;

	public function __construct() {} // phpcs:ignore -- deliberately skips BerlinDB boot.

	public function query( $args = array() ) {
		$this->captured[] = $args;

		$rows = $this->rows;
		if ( isset( $args['server_id'] ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( $r ) => (int) $r->server_id === (int) $args['server_id']
				)
			);
		}
		if ( isset( $args['metadata_fingerprint'] ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( $r ) => $r->metadata_fingerprint === $args['metadata_fingerprint']
				)
			);
		}

		return isset( $args['number'] ) ? array_slice( $rows, 0, (int) $args['number'] ) : $rows;
	}

	public function server_id_column_exists(): bool {
		return $this->has_server_id_column;
	}
}

final class DcrServerScopeTest extends TestCase {

	private CapturingClientsQuery $query;

	/** Canonical metadata every connector from one MCP host shares. */
	private const SHARED_FINGERPRINT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

	protected function setUp(): void {
		parent::setUp();
		$this->query = new CapturingClientsQuery();

		// One MCP host, already connected to server 1.
		$this->query->rows = array(
			new ClientRow(
				array(
					'id'                   => 1,
					'client_id'            => 'aaaabbbbccccddddeeeeffff00001111',
					'server_id'            => 1,
					'metadata_fingerprint' => self::SHARED_FINGERPRINT,
				)
			),
		);
	}

	public function test_existing_client_is_reused_for_the_same_server(): void {
		$found = $this->query->find_by_fingerprint( self::SHARED_FINGERPRINT, 1 );

		self::assertNotNull( $found, 'DCR must stay idempotent per server (FR-022).' );
		self::assertSame( 'aaaabbbbccccddddeeeeffff00001111', $found->client_id );
	}

	/**
	 * The bug, stated as a test: identical metadata, different server, must
	 * NOT return the first server's row.
	 */
	public function test_identical_metadata_on_a_different_server_is_not_reused(): void {
		self::assertNull(
			$this->query->find_by_fingerprint( self::SHARED_FINGERPRINT, 2 ),
			'Reusing the row here binds connector #2 to server #1 forever.'
		);
	}

	public function test_a_third_server_also_gets_its_own_row(): void {
		$this->query->rows[] = new ClientRow(
			array(
				'id'                   => 2,
				'client_id'            => '22223333444455556666777788889999',
				'server_id'            => 2,
				'metadata_fingerprint' => self::SHARED_FINGERPRINT,
			)
		);

		self::assertSame( 2, $this->query->find_by_fingerprint( self::SHARED_FINGERPRINT, 2 )->id );
		self::assertNull( $this->query->find_by_fingerprint( self::SHARED_FINGERPRINT, 3 ) );
	}

	public function test_server_id_is_passed_to_the_query(): void {
		$this->query->find_by_fingerprint( self::SHARED_FINGERPRINT, 2 );

		self::assertSame( 2, $this->query->captured[0]['server_id'] ?? null );
		self::assertSame( self::SHARED_FINGERPRINT, $this->query->captured[0]['metadata_fingerprint'] );
		self::assertSame( 1, $this->query->captured[0]['number'] );
	}

	public function test_zero_server_id_searches_across_servers(): void {
		$this->query->find_by_fingerprint( self::SHARED_FINGERPRINT, 0 );

		self::assertArrayNotHasKey(
			'server_id',
			$this->query->captured[0],
			'Callers that pass no server must keep the pre-existing global behaviour.'
		);
	}

	/**
	 * Pre-F032 installs have no `server_id` column; filtering on it would make
	 * every DCR request a fresh registration.
	 */
	public function test_scoping_degrades_safely_when_the_column_is_absent(): void {
		$this->query->has_server_id_column = false;

		$this->query->find_by_fingerprint( self::SHARED_FINGERPRINT, 2 );

		self::assertArrayNotHasKey( 'server_id', $this->query->captured[0] );
	}

	public function test_empty_fingerprint_never_matches(): void {
		self::assertNull( $this->query->find_by_fingerprint( '', 1 ) );
		self::assertSame( array(), $this->query->captured );
	}
}
