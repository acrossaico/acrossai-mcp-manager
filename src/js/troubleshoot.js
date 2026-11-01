/**
 * Connection troubleshooter — run the checks and render the results.
 *
 * Plain DOM, no React. The page is a button and a list of rows whose content
 * is produced entirely on the server, so a component tree would add a
 * dependency graph and a build-size cost to re-implement `appendChild`.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';

import '../scss/troubleshoot.scss';

const config = window.acrossaiMcpTroubleshoot || {};

/**
 * Human-readable heading for each status.
 *
 * @return {Object} Status key to label.
 */
function statusLabels() {
	return {
		ok: __( 'Pass', 'acrossai-mcp-manager' ),
		warning: __( 'Check this', 'acrossai-mcp-manager' ),
		fail: __( 'Problem', 'acrossai-mcp-manager' ),
		info: __( 'Information', 'acrossai-mcp-manager' ),
		skipped: __( 'Not run', 'acrossai-mcp-manager' ),
	};
}

/**
 * Build one result row.
 *
 * Every value goes in through textContent, never innerHTML. These strings
 * carry response headers and Location values echoed back from whatever
 * answered the probe — which on a compromised or hostile edge is attacker
 * input, arriving on an administrator's screen.
 *
 * @param {Object} check One result from the REST response.
 * @return {HTMLElement} The row.
 */
function renderRow( check ) {
	const row = document.createElement( 'div' );
	row.className = `acrossai-mcp-troubleshoot__row acrossai-mcp-troubleshoot__row--${ check.status }`;

	const head = document.createElement( 'div' );
	head.className = 'acrossai-mcp-troubleshoot__head';

	const badge = document.createElement( 'span' );
	badge.className = `acrossai-mcp-troubleshoot__badge acrossai-mcp-troubleshoot__badge--${ check.status }`;
	badge.textContent = statusLabels()[ check.status ] || check.status;
	head.appendChild( badge );

	const label = document.createElement( 'span' );
	label.className = 'acrossai-mcp-troubleshoot__label';
	label.textContent = check.label;
	head.appendChild( label );

	row.appendChild( head );

	const message = document.createElement( 'p' );
	message.className = 'acrossai-mcp-troubleshoot__message';
	message.textContent = check.message;
	row.appendChild( message );

	if ( check.remedy ) {
		const remedy = document.createElement( 'p' );
		remedy.className = 'acrossai-mcp-troubleshoot__remedy';
		remedy.textContent = check.remedy;
		row.appendChild( remedy );
	}

	if ( check.copy ) {
		row.appendChild( renderCopyBlock( check.copy, __( 'Copy for your host', 'acrossai-mcp-manager' ) ) );
	}

	return row;
}

/**
 * A read-only textarea plus a copy button.
 *
 * A textarea rather than a <pre>: the operator is going to paste this into a
 * support form, and selecting many lines of a <pre> by hand on a trackpad is
 * a worse experience than the problem being diagnosed.
 *
 * @param {string} text  The paste-able content.
 * @param {string} title Heading above it.
 * @return {HTMLElement} The block.
 */
function renderCopyBlock( text, title ) {
	const wrap = document.createElement( 'div' );
	wrap.className = 'acrossai-mcp-troubleshoot__copy';

	const bar = document.createElement( 'div' );
	bar.className = 'acrossai-mcp-troubleshoot__copy-bar';

	const heading = document.createElement( 'span' );
	heading.textContent = title;
	bar.appendChild( heading );

	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className = 'button button-small';
	button.textContent = __( 'Copy', 'acrossai-mcp-manager' );
	bar.appendChild( button );

	const area = document.createElement( 'textarea' );
	area.className = 'acrossai-mcp-troubleshoot__copy-text';
	area.readOnly = true;
	area.rows = Math.min( 16, text.split( '\n' ).length + 1 );
	area.value = text;

	button.addEventListener( 'click', () => {
		area.select();
		// The async Clipboard API needs a secure context, and plenty of these
		// sites are plain http. Selecting first means the deprecated path and
		// the manual Cmd-C fallback both work from the same state.
		try {
			document.execCommand( 'copy' );
			button.textContent = __( 'Copied', 'acrossai-mcp-manager' );
			setTimeout( () => {
				button.textContent = __( 'Copy', 'acrossai-mcp-manager' );
			}, 2000 );
		} catch {
			button.textContent = __( 'Press Ctrl/Cmd + C', 'acrossai-mcp-manager' );
		}
	} );

	wrap.appendChild( bar );
	wrap.appendChild( area );

	return wrap;
}

/**
 * One line summarising the run, so the answer is readable without counting rows.
 *
 * @param {Array} checks All results.
 * @return {HTMLElement} The summary.
 */
function renderSummary( checks ) {
	const fails = checks.filter( ( c ) => c.status === 'fail' ).length;
	const warns = checks.filter( ( c ) => c.status === 'warning' ).length;

	const el = document.createElement( 'div' );
	el.className = `acrossai-mcp-troubleshoot__summary acrossai-mcp-troubleshoot__summary--${ fails ? 'fail' : 'ok' }`;

	if ( fails ) {
		el.textContent = sprintf(
			/* translators: %d: number of failing checks */
			__( '%d problem found. Each one below says what to do about it.', 'acrossai-mcp-manager' ),
			fails,
		);
		return el;
	}

	if ( warns ) {
		el.textContent = __(
			'No problem found on this server. Note that these checks run from the site to itself, so they cannot see a block that depends on who is calling — read the warnings below before concluding the site is reachable.',
			'acrossai-mcp-manager',
		);
		return el;
	}

	el.textContent = __( 'Everything this site can check about itself is healthy.', 'acrossai-mcp-manager' );
	return el;
}

/**
 * Replace the mount point's contents.
 *
 * @param {HTMLElement} root  Mount point.
 * @param {Array}       nodes Children to show.
 */
function replace( root, nodes ) {
	root.textContent = '';
	nodes.forEach( ( node ) => root.appendChild( node ) );
}

/**
 * Run the checks and render.
 *
 * @param {HTMLElement} root   Mount point.
 * @param {HTMLElement} button The run button.
 */
async function run( root, button ) {
	button.disabled = true;
	const original = button.textContent;
	button.textContent = __( 'Running…', 'acrossai-mcp-manager' );

	const pending = document.createElement( 'p' );
	pending.className = 'acrossai-mcp-troubleshoot__pending';
	pending.textContent = __(
		'Probing this site over HTTP. This takes a few seconds — each check waits for a real response.',
		'acrossai-mcp-manager',
	);
	replace( root, [ pending ] );

	try {
		const response = await fetch( config.root, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
		} );

		if ( ! response.ok ) {
			throw new Error( `HTTP ${ response.status }` );
		}

		const data = await response.json();
		const checks = Array.isArray( data.checks ) ? data.checks : [];

		const nodes = [ renderSummary( checks ), ...checks.map( renderRow ) ];

		if ( data.report ) {
			nodes.push( renderCopyBlock( data.report, __( 'Full report', 'acrossai-mcp-manager' ) ) );
		}

		replace( root, nodes );
	} catch ( error ) {
		const failed = document.createElement( 'div' );
		failed.className = 'acrossai-mcp-troubleshoot__row acrossai-mcp-troubleshoot__row--fail';
		failed.textContent = sprintf(
			/* translators: %s: error message */
			__( 'The checks could not run: %s', 'acrossai-mcp-manager' ),
			error.message,
		);
		replace( root, [ failed ] );
	} finally {
		button.disabled = false;
		button.textContent = original;
	}
}

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( config.rootId );
	const button = document.getElementById( 'acrossai-mcp-troubleshoot-run' );

	if ( ! root || ! button ) {
		return;
	}

	button.addEventListener( 'click', () => run( root, button ) );
} );
