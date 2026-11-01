<?php
/**
 * Does the REST API answer an unauthenticated request? (Feature 101)
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
 * Several security plugins ship a "restrict REST API to logged-in users"
 * option, and some enable it by default. It is a reasonable setting for a site
 * with no API consumers — and it breaks the first step of every AI connection,
 * because dynamic client registration happens before any credential exists and
 * is therefore necessarily anonymous.
 *
 * The operator sees "couldn't register" in the client and a perfectly healthy
 * site in the admin.
 */
final class AnonymousRestCheck implements Check {

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'rest';
	}

	/**
	 * Human-readable check name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Anonymous REST API access', 'acrossai-mcp-manager' );
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
		$url   = rest_url();
		$probe = $context->fetch( $url );

		if ( '' !== $probe['error'] ) {
			return Result::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: HTTP error message */
					__( 'This site could not fetch its own REST index: %s', 'acrossai-mcp-manager' ),
					$probe['error']
				),
				__( 'Ask the host whether the server can make HTTP requests to its own public URL. Until it can, none of the probes on this page mean anything.', 'acrossai-mcp-manager' )
			);
		}

		if ( 401 === $probe['code'] || 403 === $probe['code'] ) {
			return Result::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The REST API answers anonymous requests with HTTP %d. A security plugin is restricting REST access to logged-in users, which blocks the registration step every AI client performs before it has any credential.', 'acrossai-mcp-manager' ),
					$probe['code']
				),
				__( 'In your security plugin, allow unauthenticated access to the REST API — at minimum the acrossai-mcp-manager/v1 and mcp namespaces. Then run these checks again.', 'acrossai-mcp-manager' ),
				Remedies::paths_for_support()
			);
		}

		if ( 200 !== $probe['code'] ) {
			return Result::warning(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The REST index answered HTTP %d instead of 200. That is not a flat refusal, but it is not healthy either.', 'acrossai-mcp-manager' ),
					$probe['code']
				),
				__( 'Open the REST index in a private browser window and read what it says.', 'acrossai-mcp-manager' )
			);
		}

		return Result::ok(
			$this->id(),
			$this->label(),
			__( 'The REST API answers anonymous requests, so client registration can reach it.', 'acrossai-mcp-manager' )
		);
	}
}
