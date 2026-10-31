/**
 * Shared formatters for the activity log.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Human-readable byte size.
 *
 * @param {number|null} bytes Byte count.
 * @return {string} Formatted size, or an em dash when unmeasured.
 */
export function formatBytes( bytes ) {
	if ( bytes === null || bytes === undefined ) {
		return '—';
	}

	if ( bytes < 1024 ) {
		return sprintf( /* translators: %d: byte count. */ __( '%d B', 'acrossai-mcp-manager' ), bytes );
	}

	if ( bytes < 1024 * 1024 ) {
		return sprintf(
			/* translators: %s: kilobyte count. */ __( '%s KB', 'acrossai-mcp-manager' ),
			( bytes / 1024 ).toFixed( 1 ),
		);
	}

	return sprintf(
		/* translators: %s: megabyte count. */ __( '%s MB', 'acrossai-mcp-manager' ),
		( bytes / ( 1024 * 1024 ) ).toFixed( 1 ),
	);
}

/**
 * Context cost: a size with an explicitly APPROXIMATE token figure beside it.
 *
 * The approximation marker is not decoration. This log cannot report LLM token
 * usage — the model runs on the client's side and neither MCP nor the adapter
 * carries a usage block — so every token figure here is derived from bytes.
 * Presenting one as exact would invite it into a cost report.
 *
 * @param {number|null} bytes  Byte count.
 * @param {number|null} tokens Server-derived approximate token count.
 * @return {string} e.g. "12.4 KB ≈ 3.1k tokens", or an em dash.
 */
export function formatContextCost( bytes, tokens ) {
	if ( bytes === null || bytes === undefined ) {
		return '—';
	}

	const size = formatBytes( bytes );

	if ( tokens === null || tokens === undefined ) {
		return size;
	}

	const rounded =
		tokens >= 1000
			? sprintf( '%sk', ( tokens / 1000 ).toFixed( 1 ) )
			: String( tokens );

	return sprintf(
		/* translators: 1: byte size, 2: approximate token count. */
		__( '%1$s ≈ %2$s tokens', 'acrossai-mcp-manager' ),
		size,
		rounded,
	);
}

/**
 * Duration in milliseconds, or an em dash.
 *
 * @param {number|null} ms Milliseconds.
 * @return {string} Formatted duration.
 */
export function formatDuration( ms ) {
	if ( ms === null || ms === undefined ) {
		return '—';
	}

	if ( ms < 1000 ) {
		return sprintf( /* translators: %d: milliseconds. */ __( '%dms', 'acrossai-mcp-manager' ), ms );
	}

	return sprintf(
		/* translators: %s: seconds. */ __( '%ss', 'acrossai-mcp-manager' ),
		( ms / 1000 ).toFixed( 2 ),
	);
}

/**
 * Label for a connection type.
 *
 * @param {string} type Raw connection type.
 * @return {string} Human label.
 */
export function connectionLabel( type ) {
	const labels = {
		'ai-connector': __( 'AI connector', 'acrossai-mcp-manager' ),
		oauth: __( 'OAuth client', 'acrossai-mcp-manager' ),
		'app-password': __( 'Application password', 'acrossai-mcp-manager' ),
		browser: __( 'Browser', 'acrossai-mcp-manager' ),
		unknown: __( 'Unknown', 'acrossai-mcp-manager' ),
	};

	return labels[ type ] || type;
}

/**
 * A derived token count on its own, for the Tokens column.
 *
 * Carries the same "≈" marker as formatContextCost, and for the same reason:
 * this log cannot see real LLM usage, so every figure here is bytes divided by
 * a constant. A bare number in a sortable column is the easiest thing in the
 * screen to paste into a cost report, which is exactly what the marker is for.
 *
 * @param {number|null} tokens Server-derived approximate token count.
 * @return {string} e.g. "≈ 3.1k", or an em dash.
 */
export function formatApproxTokens( tokens ) {
	if ( tokens === null || tokens === undefined ) {
		return '—';
	}

	const rounded =
		tokens >= 1000
			? sprintf( '%sk', ( tokens / 1000 ).toFixed( 1 ) )
			: String( tokens );

	return sprintf(
		/* translators: %s: approximate token count, e.g. "3.1k". */
		__( '≈ %s', 'acrossai-mcp-manager' ),
		rounded,
	);
}
