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
	 * Returns the Null handler while request logging is off, so the adapter
	 * discards its measurements exactly as it did before this feature and the
	 * recording path is never entered — not entered and returning early, but
	 * never reached at all (FR-029).
	 *
	 * @return class-string<\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface>
	 */
	public static function class_string(): string {
		$enabled = get_option( ActivityLogConstants::OPTION_REQUEST_LOGGING, ActivityLogConstants::DEFAULT_REQUEST_LOGGING );

		if ( ! $enabled ) {
			return \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class;
		}

		return ActivityObservabilityHandler::class;
	}
}
