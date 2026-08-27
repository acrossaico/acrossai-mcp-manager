/**
 * F069 — Wizard code block with copy-to-clipboard.
 *
 * Variants:
 *   - `variant="inline"` — single-line command box + Copy button to the right
 *   - `variant="pane"`   — dark multi-line pane with title strip + Copy button
 *
 * SECURITY (TASK-SEC-004 / SEC-004): the code body renders as text-node
 * children of <code>/<pre>. NEVER via dangerouslySetInnerHTML. Any
 * interpolation of server-derived values (server_slug, site_url) must be
 * plain JS string concat — the caller passes the composed string as
 * `children` and React auto-escapes.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useEffect } from '@wordpress/element';
import { useCopyToClipboard } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

const CodeBlock = ( {
	variant = 'inline',
	children,
	title,
} ) => {
	const [ copied, setCopied ] = useState( false );

	useEffect( () => {
		if ( ! copied ) {
			return undefined;
		}
		const timer = setTimeout( () => setCopied( false ), 2000 );
		return () => clearTimeout( timer );
	}, [ copied ] );

	const copyRef = useCopyToClipboard(
		typeof children === 'string' ? children : '',
		() => setCopied( true )
	);

	if ( variant === 'pane' ) {
		return (
			<div className="qs-code qs-code--pane">
				<div className="qs-code__title-bar">
					<span>{ title || __( 'code', 'acrossai-mcp-manager' ) }</span>
					<button
						ref={ copyRef }
						type="button"
						className="qs-code__copy-btn"
						aria-label={ __( 'Copy code to clipboard', 'acrossai-mcp-manager' ) }
					>
						{ copied
							? __( 'Copied!', 'acrossai-mcp-manager' )
							: __( 'Copy config', 'acrossai-mcp-manager' )
						}
					</button>
				</div>
				<pre>{ children }</pre>
			</div>
		);
	}

	// variant = 'inline'
	return (
		<div className="qs-code qs-code--inline">
			<code>{ children }</code>
			<button
				ref={ copyRef }
				type="button"
				className="qs-code__copy-btn"
				aria-label={ __( 'Copy command to clipboard', 'acrossai-mcp-manager' ) }
			>
				{ copied
					? __( 'Copied!', 'acrossai-mcp-manager' )
					: __( 'Copy', 'acrossai-mcp-manager' )
				}
			</button>
		</div>
	);
};

export default CodeBlock;
