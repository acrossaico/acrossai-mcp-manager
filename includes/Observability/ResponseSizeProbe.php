<?php
/**
 * Measures how many bytes the server handed back (Feature 096).
 *
 * The adapter's observability seam passes tags and a duration and NEVER the
 * result payload, so the response's size is unreachable from the handler alone.
 * Five published vendor filters do see it. This probe hooks them purely to take
 * a `strlen()` — no payload is retained, so there is nothing to redact and no
 * truncation question to answer.
 *
 * Why size at all: every byte returned is injected into the client's context
 * window. A fat `tools/list` or a call returning 200 posts silently eats the
 * user's context, and nothing in WordPress says which tool is responsible.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Observability
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Observability;

defined( 'ABSPATH' ) || exit;

/**
 * Request-scoped size store.
 *
 * Every callback is a READ-ONLY PASS-THROUGH: it takes a length and returns
 * its input unchanged. A filter that mutated a tool result in order to log it
 * would be a bug waiting for a support ticket, which is why each one is
 * asserted byte-identical by test.
 *
 * `take()` returning null means "not measured", not "zero bytes" — an unhooked
 * method or an unmeasurable payload leaves the column empty rather than
 * claiming the server sent nothing.
 */
final class ResponseSizeProbe {

	/**
	 * Bytes recorded for the response in flight, or null when unmeasured.
	 *
	 * @var int|null
	 */
	private static $bytes = null;

	/**
	 * Record a measured size.
	 *
	 * Additive across a request: a single JSON-RPC batch can carry more than
	 * one result, and the client's context pays for all of them.
	 *
	 * @param int $bytes Byte length of one encoded result.
	 */
	public static function add( int $bytes ): void {
		if ( $bytes < 0 ) {
			return;
		}

		self::$bytes = (int) self::$bytes + $bytes;
	}

	/**
	 * Read and clear the recorded size.
	 *
	 * Clearing on read is deliberate: the handler fires once per dispatch, and
	 * leaving the value behind would attribute one response's size to the next
	 * request on a long-lived process — the same class of leak A17's shutdown
	 * stage exists to prevent.
	 *
	 * @return int|null Bytes, or null when nothing was measured.
	 */
	public static function take(): ?int {
		$bytes       = self::$bytes;
		self::$bytes = null;

		return $bytes;
	}

	/**
	 * Measure a tool-call result.
	 *
	 * `mcp_adapter_tool_call_result` — ToolsHandler.php:206.
	 *
	 * @param mixed $result    The tool result.
	 * @param mixed $args      Call arguments (unused).
	 * @param mixed $tool_name Tool name (unused).
	 * @return mixed The result, unchanged.
	 */
	public function measure_tool_call( $result = null, $args = null, $tool_name = null ) {
		unset( $args, $tool_name );

		return self::measure( $result );
	}

	/**
	 * Measure the tool catalogue.
	 *
	 * `mcp_adapter_tools_list` — ToolsHandler.php:84. **The load-bearing one.**
	 * `tools/list` has no ability children, so its size cannot be recovered by
	 * rolling up `parent_id`; and the catalogue injected at connect, on every
	 * new conversation, is usually the single largest context cost a server
	 * imposes. Without this hook the biggest consumer is invisible.
	 *
	 * @param mixed $tools The tool list.
	 * @return mixed The list, unchanged.
	 */
	public function measure_tools_list( $tools = null ) {
		return self::measure( $tools );
	}

	/**
	 * Measure the initialize response.
	 *
	 * `mcp_adapter_initialize_response` — InitializeHandler.php:85.
	 *
	 * @param mixed $result The initialize result.
	 * @return mixed The result, unchanged.
	 */
	public function measure_initialize( $result = null ) {
		return self::measure( $result );
	}

	/**
	 * Measure a prompt-get result.
	 *
	 * @param mixed $result      The prompt result.
	 * @param mixed $arguments   Arguments (unused).
	 * @param mixed $prompt_name Prompt name (unused).
	 * @return mixed The result, unchanged.
	 */
	public function measure_prompt_get( $result = null, $arguments = null, $prompt_name = null ) {
		unset( $arguments, $prompt_name );

		return self::measure( $result );
	}

	/**
	 * Measure the prompt catalogue.
	 *
	 * @param mixed $prompts The prompt list.
	 * @return mixed The list, unchanged.
	 */
	public function measure_prompts_list( $prompts = null ) {
		return self::measure( $prompts );
	}

	/**
	 * Measure a resource-read result.
	 *
	 * @param mixed $contents       The resource contents.
	 * @param mixed $request_params Request params (unused).
	 * @param mixed $uri            Resource URI (unused).
	 * @return mixed The contents, unchanged.
	 */
	public function measure_resource_read( $contents = null, $request_params = null, $uri = null ) {
		unset( $request_params, $uri );

		return self::measure( $contents );
	}

	/**
	 * Measure the resource catalogue.
	 *
	 * @param mixed $resources The resource list.
	 * @return mixed The list, unchanged.
	 */
	public function measure_resources_list( $resources = null ) {
		return self::measure( $resources );
	}

	/**
	 * Take a length and hand the value straight back.
	 *
	 * The whole body is wrapped. These filters sit on the path that produces
	 * every MCP response, so a failure here must return the payload untouched
	 * rather than break the call — the same absolute rule as the recorders.
	 *
	 * @param mixed $value The payload to measure.
	 * @return mixed The payload, unchanged.
	 */
	private static function measure( $value ) {
		try {
			if ( null !== $value ) {
				$encoded = wp_json_encode( $value );

				if ( is_string( $encoded ) ) {
					self::add( strlen( $encoded ) );
				}
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Measuring must never alter a response.
			error_log( '[acrossai-mcp] ResponseSizeProbe failed: ' . $e->getMessage() );
		}

		return $value;
	}

	/**
	 * Singleton instance, for hook registration.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/** Singleton accessor. */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/** Drop any recorded size without reading it. */
	public static function reset(): void {
		self::$bytes = null;
	}
}
