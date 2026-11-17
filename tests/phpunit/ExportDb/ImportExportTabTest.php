<?php
/**
 * The export screen's input handling (Feature 105).
 *
 * The form posts a server list and a per-server block list, both of which are
 * attacker-shaped by definition — they arrive from a browser. These tests
 * exercise `selection_from_request()` through reflection rather than the
 * admin-post handler itself, because the handler ends in `exit` after
 * streaming headers and cannot be called from a test.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ExportDb;

use AcrossAI_MCP_Manager\Admin\Partials\ImportExportTab;
use AcrossAI_MCP_Manager\Includes\Utilities\ExportConstants;
use ReflectionMethod;

/**
 * @covers \AcrossAI_MCP_Manager\Admin\Partials\ImportExportTab
 */
class ImportExportTabTest extends \WP_UnitTestCase {

	/**
	 * Parse a simulated form submission.
	 *
	 * @param array<string, mixed> $post Submitted fields.
	 * @return array<int, array<int, string>>
	 */
	private function parse( array $post ): array {
		$_POST = $post;

		$method = new ReflectionMethod( ImportExportTab::class, 'selection_from_request' );
		$method->setAccessible( true );

		$selection = $method->invoke( ImportExportTab::instance() );

		$_POST = array();

		return $selection;
	}

	/** Each server keeps its own block list. */
	public function test_each_server_carries_its_own_blocks(): void {
		$selection = $this->parse(
			array(
				'servers' => array( '5', '7' ),
				'blocks'  => array(
					5 => array( 'status', 'tools' ),
					7 => array( 'abilities' ),
				),
			)
		);

		$this->assertSame(
			array(
				5 => array( 'status', 'tools' ),
				7 => array( 'abilities' ),
			),
			$selection
		);
	}

	/**
	 * A negative id must not resolve to a different server.
	 *
	 * Pinned because it did: `absint( '-3' )` is 3, so posting `-3` exported
	 * server 3 — a real row the operator never ticked. Found by feeding the
	 * parser hostile input rather than by reading it.
	 */
	public function test_a_negative_id_does_not_become_another_server(): void {
		$selection = $this->parse( array( 'servers' => array( '-3' ) ) );

		$this->assertSame( array(), $selection, 'A negative id must be dropped, not sign-flipped onto a real server.' );
	}

	/** Ids that are not numbers are dropped. */
	public function test_non_numeric_ids_are_dropped(): void {
		$selection = $this->parse( array( 'servers' => array( 'abc', '0', '', array( 'nested' ) ) ) );

		$this->assertSame( array(), $selection );
	}

	/** An invented block name cannot reach the exporter's switch. */
	public function test_unknown_block_names_are_filtered(): void {
		$selection = $this->parse(
			array(
				'servers' => array( '5' ),
				'blocks'  => array( 5 => array( 'status', 'DROP TABLE', '../../etc/passwd', 'connectors' ) ),
			)
		);

		$this->assertSame( array( 5 => array( 'status', 'connectors' ) ), $selection );
	}

	/** Blocks posted for a server that was not ticked are ignored. */
	public function test_blocks_for_an_unticked_server_are_ignored(): void {
		$selection = $this->parse(
			array(
				'servers' => array( '5' ),
				'blocks'  => array(
					5 => array( 'status' ),
					7 => array( 'tools' ),
				),
			)
		);

		$this->assertArrayNotHasKey( 7, $selection );
	}

	/**
	 * A server ticked with no blocks still exports.
	 *
	 * Its identity travels and none of its settings do, which is a thing an
	 * operator can legitimately ask for — and an empty list is how the record
	 * says so, distinct from the key being absent.
	 */
	public function test_a_server_with_no_blocks_is_kept(): void {
		$selection = $this->parse( array( 'servers' => array( '5' ) ) );

		$this->assertSame( array( 5 => array() ), $selection );
	}

	/** Blocks come back in the declared order, whatever order they were posted. */
	public function test_blocks_are_returned_in_declared_order(): void {
		$selection = $this->parse(
			array(
				'servers' => array( '5' ),
				'blocks'  => array( 5 => array( 'connectors', 'status', 'tools' ) ),
			)
		);

		$this->assertSame( array( 'status', 'tools', 'connectors' ), $selection[5] );
		$this->assertSame(
			array_values( array_intersect( ExportConstants::BLOCKS, $selection[5] ) ),
			$selection[5],
			'Order follows ExportConstants::BLOCKS so two exports of one server diff cleanly.'
		);
	}

	/** Nothing ticked yields nothing, and the handler redirects rather than streaming. */
	public function test_an_empty_submission_selects_nothing(): void {
		$this->assertSame( array(), $this->parse( array() ) );
	}
}
