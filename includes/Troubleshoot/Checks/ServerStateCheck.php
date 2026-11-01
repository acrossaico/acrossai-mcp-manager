<?php
/**
 * Is there an enabled MCP server to connect to at all? (Feature 101)
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot\Checks
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Check;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

defined( 'ABSPATH' ) || exit;

/**
 * The first question, asked first.
 *
 * Every other check on this page probes a route that only exists because a
 * server is enabled. Running them against a site with no enabled server
 * produces a page of confident red rows whose single cause is a switch that
 * is off, so this runs ahead of all of them and blocks the rest when it fails.
 */
final class ServerStateCheck implements Check {

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'server_state';
	}

	/**
	 * Human-readable check name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'MCP server enabled', 'acrossai-mcp-manager' );
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
		$all     = MCPServerQuery::instance()->query( array( 'number' => 100 ) );
		$enabled = MCPServerQuery::instance()->query(
			array(
				'number'     => 100,
				'is_enabled' => 1,
			)
		);

		$total       = is_array( $all ) ? count( $all ) : 0;
		$enabled_num = is_array( $enabled ) ? count( $enabled ) : 0;

		if ( 0 === $total ) {
			return Result::fail(
				$this->id(),
				$this->label(),
				__( 'This site has no MCP servers, so there is nothing for an AI client to connect to.', 'acrossai-mcp-manager' ),
				sprintf(
					/* translators: %s: URL of the MCP servers screen */
					__( 'Create one on the MCP screen (%s).', 'acrossai-mcp-manager' ),
					admin_url( 'admin.php?page=' . AdminPageSlugs::PARENT )
				)
			);
		}

		if ( 0 === $enabled_num ) {
			return Result::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %d: number of servers defined on this site */
					_n(
						'This site has %d MCP server and it is switched off, so its routes do not exist and every client sees a missing server.',
						'This site has %d MCP servers and all of them are switched off, so their routes do not exist and every client sees a missing server.',
						$total,
						'acrossai-mcp-manager'
					),
					$total
				),
				sprintf(
					/* translators: %s: URL of the MCP servers screen */
					__( 'Enable the server you are connecting to on the MCP screen (%s), then run these checks again.', 'acrossai-mcp-manager' ),
					admin_url( 'admin.php?page=' . AdminPageSlugs::PARENT )
				)
			);
		}

		return Result::ok(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: 1: number of enabled servers, 2: total number of servers */
				_n(
					'%1$d of %2$d MCP server is enabled and serving.',
					'%1$d of %2$d MCP servers are enabled and serving.',
					$enabled_num,
					'acrossai-mcp-manager'
				),
				$enabled_num,
				$total
			)
		);
	}
}
