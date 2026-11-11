<?php
/**
 * Chooses which observability handler the adapter receives (Feature 096).
 *
 * `MCP\Controller` assembles and registers servers; whether activity logging is
 * enabled is not its business. Without this resolver the registration path
 * would read a setting inline, and the next toggle would read another — until
 * `create_server()`'s argument list is a settings reader. One call, and the
 * boundary stays clean.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Observability
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Observability;

use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless resolver. Never Loader-wired.
 */
final class HandlerResolver {

	/**
	 * The handler class-string to hand `McpAdapter::create_server()`.
	 *
	 * While request logging is off the adapter gets the CONNECTION handler, not
	 * the Null one. That is a deliberate change from 096 (F104). The log keeps
	 * its original guarantee either way — `ActivityObservabilityHandler` is the
	 * only class that writes a log row, and it is not returned here when the
	 * toggle is off, so its recording path is still never reached at all
	 * (FR-029), not entered and returning early.
	 *
	 * What changed is that the clients registry no longer rides on that toggle.
	 * Returning Null here would mean turning off a diagnostic log also stopped
	 * recording who connects to the site — two unrelated things behind one
	 * switch, with no way for an operator to tell that the second had happened.
	 *
	 * @return class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface>
	 */
	public static function class_string(): string {
		$enabled = get_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, ActivityLogConstants::DEFAULT_REQUEST_LOGGING );

		if ( ! $enabled ) {
			return ConnectionObservabilityHandler::class;
		}

		return ActivityObservabilityHandler::class;
	}
}
