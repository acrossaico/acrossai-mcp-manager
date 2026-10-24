<?php
/**
 * Records MCP JSON-RPC requests into the activity log (Feature 096).
 *
 * The mcp-adapter already measures every dispatch and hands the numbers to an
 * observability handler; until this feature the plugin passed
 * NullMcpObservabilityHandler and threw them away. This is the replacement.
 *
 * The adapter instantiates the handler as `new $class()` with NO constructor
 * arguments, so everything not present in the tags has to come from request
 * scope. That constraint is why ConnectionContext and AuthContextHolder exist.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Observability
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Observability;

use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Query;
use AcrossAI_MCP_Manager\Includes\Database\ActivityLog\Recorder;
use AcrossAI_MCP_Manager\Includes\Utilities\ActivityLogConstants;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;
use WP\MCP\Infrastructure\Observability\McpObservabilityHelperTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `mcp.request`; ignores everything else.
 *
 * `mcp.server.created` and `mcp.component.registration` are deliberately
 * dropped. The former fires once per enabled server per HTTP request —
 * verified on live traffic as TWICE per request on this install — so recording
 * it would more than double every request's row count for no diagnostic gain.
 */
class ActivityObservabilityHandler implements McpObservabilityHandlerInterface {

	use McpObservabilityHelperTrait {
		is_sensitive_key as wp_is_sensitive_key;
	}

	/** Replacement for a redacted value. */
	private const REDACTED = '[REDACTED]';

	/**
	 * Record one adapter event.
	 *
	 * The whole body is wrapped. FR-013 is absolute: a logging failure must not
	 * alter the response an AI client receives. The adapter calls this on all
	 * four exit paths of every dispatch, so a throw here would surface as a
	 * broken tool call.
	 *
	 * @param string               $event       Event name.
	 * @param array<string, mixed> $tags        Event tags.
	 * @param float|null           $duration_ms Duration in milliseconds.
	 */
	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		try {
			if ( 'mcp.request' !== $event ) {
				return;
			}

			$tags = $this->sanitize( $tags );

			if ( $this->is_excluded_method( $this->tag( $tags, 'method' ) ) ) {
				return;
			}

			$request_id = Recorder::record_request( $this->build_row( $tags, $duration_ms ) );

			// Link the abilities this request ran. They were written while it
			// was still in flight, when this row did not yet exist, so the id
			// only becomes available now. `take_children()` consumes the list,
			// so a second request event in the same cycle cannot re-adopt them.
			//
			// Deliberately inside the same try: adoption is part of recording,
			// and FR-013 says a failure here must not reach the client either.
			if ( $request_id > 0 ) {
				$children = CaptureContext::instance()->take_children();

				if ( ! empty( $children ) ) {
					Query::instance()->adopt_children( $request_id, $children );
				}
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- FR-013: a logging failure must never reach the client.
			error_log( '[acrossai-mcp] ActivityObservabilityHandler failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Redact before storing.
	 *
	 * The adapter's own helper recognises 24 sensitive key patterns and
	 * truncates values at 1024 bytes. Reusing it rather than growing a second
	 * list is Principle VI, and it means the activity log and Feature 097's
	 * change log cannot drift apart on what counts as a secret.
	 *
	 * NOTE the limit, which the spec states plainly: this matches sensitive
	 * KEYS. A secret pasted into a free-text argument value matches nothing and
	 * is stored as sent, subject to truncation. FR-044's filter is the escape
	 * hatch for installs that cannot accept that.
	 *
	 * @param array<string, mixed> $tags Raw tags.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $tags ): array {
		$out = array();

		foreach ( $tags as $key => $value ) {
			$key = substr( (string) $key, 0, 64 );

			if ( self::is_sensitive_key( $key ) ) {
				$out[ $key ] = self::REDACTED;
				continue;
			}

			// Recurse into arrays rather than stringifying them. The adapter's
			// own sanitize_tags() JSON-encodes a nested array and then runs a
			// word-level regex over the string, which replaces the sensitive KEY
			// NAME and leaves the secret VALUE intact — the opposite of what is
			// wanted. It would also destroy `params` as an array, which this
			// handler needs for protocol_version and the byte count.
			$out[ $key ] = is_array( $value ) ? $this->redact_recursive( $value ) : $value;
		}

		return $out;
	}

	/**
	 * Replace the value of any sensitively-named key, at any depth.
	 *
	 * Depth-limited: a pathological payload must not be able to exhaust the
	 * stack from inside a logging path.
	 *
	 * @param array<array-key, mixed> $data  Payload to redact.
	 * @param int                     $depth Current recursion depth.
	 * @return array<array-key, mixed>
	 */
	private function redact_recursive( array $data, int $depth = 0 ): array {
		if ( $depth > 10 ) {
			return array( self::REDACTED );
		}

		$out = array();

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_sensitive_key( $key ) ) {
				$out[ $key ] = self::REDACTED;
				continue;
			}

			$out[ $key ] = is_array( $value ) ? $this->redact_recursive( $value, $depth + 1 ) : $value;
		}

		return $out;
	}

	/**
	 * Does this key name indicate a secret?
	 *
	 * Delegates to the adapter's helper so there is ONE pattern list across this
	 * log and Feature 097's change log (Principle VI). The trait is reached
	 * through this class because `NullMcpObservabilityHandler` — the obvious
	 * candidate — does not actually use it; assuming otherwise is what made an
	 * earlier version of this method silently skip redaction entirely.
	 *
	 * @param string $key Key name.
	 */
	private static function is_sensitive_key( string $key ): bool {
		return self::wp_is_sensitive_key( $key );
	}

	/**
	 * Should this method be skipped entirely?
	 *
	 * `ping` is most of the volume and none of the value. Filterable so an
	 * operator debugging a keepalive problem can turn it back on.
	 *
	 * @param string $method JSON-RPC method.
	 */
	private function is_excluded_method( string $method ): bool {
		/**
		 * Filters the JSON-RPC methods excluded from the activity log.
		 *
		 * `|mixed` because a filter return is third-party code and can be any
		 * type; narrowing it would make the is_array() guard below look
		 * redundant when it is the only thing preventing a fatal.
		 *
		 * @param array<int, string>|mixed $methods Excluded method names.
		 */
		$excluded = apply_filters( 'acrossai_mcp_activity_excluded_methods', array( 'ping' ) );

		return is_array( $excluded ) && in_array( $method, $excluded, true );
	}

	/**
	 * Assemble the row.
	 *
	 * Tag sourcing here is NOT guesswork — every line reflects what the adapter
	 * actually sends, verified against live traffic on 2026-10-16. See
	 * research.md §Phase 1, decisions D-10 to D-13. Four of these would have
	 * been wrong if taken from the original plan.
	 *
	 * @param array<string, mixed> $tags        Sanitized tags.
	 * @param float|null           $duration_ms Duration in milliseconds.
	 * @return array<string, mixed>
	 */
	private function build_row( array $tags, ?float $duration_ms ): array {
		$slug = $this->tag( $tags, 'server_id' );

		// D-10: `ability_name` is the canonical slug (`toolset/server-guide`);
		// `tool_name` is the same thing after McpNameSanitizer rewrites `/` to
		// `-`. Storing the sanitized form would match no ability slug anywhere
		// else in the system — not the registry, not F097's change rows.
		$ability_name = $this->tag( $tags, 'ability_name' );
		$tool_name    = $this->tag( $tags, 'tool_name' );
		$component    = '' !== $ability_name
			? $ability_name
			: ( '' !== $tool_name
				? $tool_name
				: ( '' !== $this->tag( $tags, 'prompt_name' )
					? $this->tag( $tags, 'prompt_name' )
					: $this->tag( $tags, 'resource_uri' ) ) );

		// D-9 / Open Decision 3: on `initialize` the `session_id` tag is absent
		// and the id arrives as `new_session_id`. Confirmed on live traffic —
		// without this coalesce the one event carrying clientInfo is the one
		// event that cannot be tied to the session it opened.
		$session_id = $this->tag( $tags, 'session_id' );

		if ( '' === $session_id ) {
			$session_id = $this->tag( $tags, 'new_session_id' );
		}

		$user_id = get_current_user_id();
		$client  = ConnectionContext::client_info( $session_id, $user_id );

		return array(
			// D-11: our column is `event_source`. The adapter sends its own
			// `source` tag (value `ability` on a toolset call) — never map it here.
			'event_source'     => ActivityLogConstants::SOURCE_MCP,
			'server_slug'      => $slug,
			'server_id'        => ConnectionContext::server_id_for_slug( $slug ),
			'connection_type'  => ConnectionContext::resolve_type(),
			'connector_slug'   => ConnectionContext::connector_slug(),
			'client_name'      => $client['name'],
			'client_version'   => $client['version'],
			'transport'        => $this->tag( $tags, 'transport' ),
			'method'           => $this->tag( $tags, 'method' ),
			'protocol_version' => $this->protocol_version( $tags ),
			'component_type'   => $this->tag( $tags, 'component_type' ),
			'component_name'   => $component,
			// Only when it genuinely differs — an empty column reads as "the
			// client used the canonical name", which is true and useful.
			'invoked_name'     => ( '' !== $tool_name && $tool_name !== $component ) ? $tool_name : '',
			'status'           => $this->tag( $tags, 'status' ),
			// D-12: there is no `error_code` tag. An input-validation failure
			// arrives as status=error with prose only, so this is often empty.
			'error_code'       => $this->tag( $tags, 'error_code' ),
			'error_message'    => mb_substr( $this->failure_reason( $tags ), 0, 500 ),
			'duration_ms'      => null === $duration_ms ? null : (int) round( $duration_ms ),
			// D-13: no `user_id`, `site_id` or `timestamp` tag exists.
			'user_id'          => $user_id,
			'ip_address'       => ConnectionContext::ip_address(),
			'user_agent'       => ConnectionContext::user_agent(),
			'session_id'       => $session_id,
			'request_id'       => $this->tag( $tags, 'request_id' ),
			'input_preview'    => $this->preview( $tags ),
			// Stays null on request rows: the seam never passes the result. The
			// child ability row carries it, which is why one table matters.
			'output_preview'   => null,
			'input_bytes'      => $this->bytes( $tags ),
			'output_bytes'     => ResponseSizeProbe::take(),
		);
	}

	/**
	 * Protocol version, from `initialize`'s params.
	 *
	 * The first thing wanted when a client breaks after an upgrade.
	 *
	 * @param array<string, mixed> $tags Sanitized tags.
	 */
	private function protocol_version( array $tags ): string {
		$params = isset( $tags['params'] ) && is_array( $tags['params'] ) ? $tags['params'] : array();

		return isset( $params['protocolVersion'] ) && is_scalar( $params['protocolVersion'] )
			? mb_substr( (string) $params['protocolVersion'], 0, 16 )
			: '';
	}

	/**
	 * The most specific failure description the tags offer.
	 *
	 * @param array<string, mixed> $tags Sanitized tags.
	 */
	private function failure_reason( array $tags ): string {
		foreach ( array( 'failure_reason', 'error_category', 'error_type' ) as $key ) {
			$value = $this->tag( $tags, $key );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Sanitized, truncated preview of the request params.
	 *
	 * @param array<string, mixed> $tags Sanitized tags.
	 * @return string|null
	 */
	private function preview( array $tags ) {
		if ( ! isset( $tags['params'] ) ) {
			return null;
		}

		$encoded = wp_json_encode( $tags['params'] );

		if ( ! is_string( $encoded ) ) {
			return null;
		}

		$preview = mb_substr( $encoded, 0, 2000 );

		/**
		 * Filters a recorded input preview — FR-044.
		 *
		 * Return '' or null to suppress it entirely. Exists so an install
		 * handling regulated data is not forced to choose between no
		 * diagnostics and recording argument content, given that redaction
		 * matches sensitive KEYS and cannot cover free-text values.
		 *
		 * `|mixed` on the return for the same reason as above — a site can
		 * return anything from this filter, including an object.
		 *
		 * @param string|null|mixed    $preview The truncated preview.
		 * @param array<string, mixed> $tags    The sanitized tags.
		 */
		$filtered = apply_filters( ActivityLogConstants::FILTER_INPUT_PREVIEW, $preview, $tags );

		if ( null === $filtered || '' === $filtered ) {
			return null;
		}

		return is_string( $filtered ) ? $filtered : $preview;
	}

	/**
	 * Byte length of the request params, BEFORE preview truncation.
	 *
	 * Bytes, not tokens: bytes are exact and cost a strlen(). The token figure
	 * is derived at read time behind a filter, because a guess stored in a
	 * column named `tokens_*` gets aggregated into a cost report later.
	 *
	 * @param array<string, mixed> $tags Sanitized tags.
	 * @return int|null
	 */
	private function bytes( array $tags ) {
		if ( ! isset( $tags['params'] ) ) {
			return null;
		}

		$encoded = wp_json_encode( $tags['params'] );

		return is_string( $encoded ) ? strlen( $encoded ) : null;
	}

	/**
	 * Read one tag as a trimmed string.
	 *
	 * Every tag is read defensively: we depend on names inside vendor
	 * RequestRouter, so a renamed tag must degrade one column to empty rather
	 * than fatal. The 0.6.1 → 0.7.0 meta-ability episode is the precedent.
	 *
	 * @param array<string, mixed> $tags Sanitized tags.
	 * @param string               $key  Tag name.
	 */
	private function tag( array $tags, string $key ): string {
		return isset( $tags[ $key ] ) && is_scalar( $tags[ $key ] ) ? trim( (string) $tags[ $key ] ) : '';
	}
}
