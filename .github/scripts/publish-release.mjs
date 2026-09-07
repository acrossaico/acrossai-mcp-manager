#!/usr/bin/env node
/**
 * Publish a GitHub release entry to a WordPress CPT.
 *
 * Reads env vars only — no repo-specific values live in this file so the same
 * script can be dropped into multiple plugin repos unchanged.
 *
 * Required env:
 *   WP_URL, WP_USER, WP_APP_PASSWORD  (secrets)
 *   RELEASE_TAG                        (e.g. "v0.3.0")
 *   RELEASE_PUBLISHED_AT               (ISO-8601 timestamp)
 *   CPT_REST_BASE                      (e.g. "changelogs")
 *   TAXONOMY_REST_BASE                 (e.g. "products")
 *   TAXONOMY_TERM_SLUG                 (e.g. "acrossai-mcp-manager")
 *   PRODUCT_NAME                       (display name — used in post title)
 *   PRODUCT_TERM                       (slug prefix for the post slug)
 *
 * Optional env (defaults noted):
 *   README_PATH        default "readme.txt"
 *   POST_STATUS        default "publish"
 *   IGNORE_GLOBS       default ".github/**,tests/**,.gitignore,composer.lock,package-lock.json,yarn.lock,build/**,vendor/**"
 *   FILE_LIST_CAP      default "150"
 *   TAXONOMY_FIELD     default = TAXONOMY_REST_BASE  (post JSON field for the term IDs)
 *   DRY_RUN            "true" to print payload and exit
 *   PARSE_ONLY         "true" to print parsed changelog + diff and exit (used by CI preflight)
 */

import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

// ---------- env ----------

function env( name, fallback ) {
	const v = process.env[ name ];
	if ( v === undefined || v === '' ) {
		if ( fallback !== undefined ) {
			return fallback;
		}
		die( `Missing required env var: ${ name }` );
	}
	return v;
}

function die( message ) {
	console.error( `::error::${ message }` );
	process.exit( 1 );
}

const PARSE_ONLY = process.env.PARSE_ONLY === 'true';
const DRY_RUN = process.env.DRY_RUN === 'true';

const README_PATH = env( 'README_PATH', 'readme.txt' );
const IGNORE_GLOBS = env(
	'IGNORE_GLOBS',
	'.github/**,tests/**,.gitignore,composer.lock,package-lock.json,yarn.lock,build/**,vendor/**',
)
	.split( ',' )
	.map( ( g ) => g.trim() )
	.filter( Boolean );
const FILE_LIST_CAP = parseInt( env( 'FILE_LIST_CAP', '150' ), 10 );

// ---------- changelog parser ----------

/**
 * Parse the topmost changelog entry from a wp.org-format readme.
 *
 * Recognises headers of the form:
 *   = 1.2.3 =
 *   = 1.2.3 - 2026-08-04 =
 *
 * Bullets start with "* " (with optional leading whitespace, which lets
 * nested "    * " sub-bullets appear as their own peer entries). A line
 * with no "*" marker is treated as a wrapped continuation of the
 * preceding bullet.
 * @param readmeText
 */
export function parseTopmostChangelogEntry( readmeText ) {
	const lines = readmeText.split( /\r?\n/ );

	// Find "== Changelog ==" (case-insensitive), extract until the next "== X =="
	let start = -1;
	for ( let i = 0; i < lines.length; i++ ) {
		if ( /^==\s*Changelog\s*==\s*$/i.test( lines[ i ] ) ) {
			start = i + 1;
			break;
		}
	}
	if ( start === -1 ) {
		die( 'Could not find "== Changelog ==" section in readme.' );
	}

	let end = lines.length;
	for ( let i = start; i < lines.length; i++ ) {
		if ( /^==\s*[^=]+\s*==\s*$/.test( lines[ i ] ) ) {
			end = i;
			break;
		}
	}

	const region = lines.slice( start, end );

	// Find topmost version header
	const headerRe = /^=\s*(?<version>[\w.\-+]+)\s*(?:-\s*(?<date>.+?))?\s*=\s*$/;
	let headerIdx = -1;
	let version = null;
	let date = null;
	for ( let i = 0; i < region.length; i++ ) {
		const m = region[ i ].match( headerRe );
		if ( m ) {
			headerIdx = i;
			version = m.groups.version.trim();
			date = m.groups.date ? m.groups.date.trim() : null;
			break;
		}
	}
	if ( headerIdx === -1 ) {
		die( 'Could not find any "= X.Y.Z =" version header in changelog.' );
	}

	// Collect bullets until the next version header
	const bullets = [];
	for ( let i = headerIdx + 1; i < region.length; i++ ) {
		const line = region[ i ];
		if ( headerRe.test( line ) ) {
			break;
		}
		if ( line.trim() === '' ) {
			continue;
		}

		const bulletMatch = line.match( /^\s*\*\s+(.*)$/ );
		if ( bulletMatch ) {
			bullets.push( bulletMatch[ 1 ].trimEnd() );
		} else if ( bullets.length > 0 ) {
			// wrapped continuation of the previous bullet
			bullets[ bullets.length - 1 ] += ' ' + line.trim();
		}
		// pre-bullet stray text is ignored
	}

	return { version, date, bullets };
}

// ---------- git diff ----------

function git( args ) {
	return execFileSync( 'git', args, { encoding: 'utf8' } ).trim();
}

function gitOrEmpty( args ) {
	try {
		return execFileSync( 'git', args, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } ).trim();
	} catch {
		return '';
	}
}

/**
 * Convert a shell-glob pattern to a RegExp anchored to the whole path.
 *  "**"   → ".*"          (any run of chars, including "/")
 *  "*"    → "[^/]*"       (any run of chars except "/")
 *  Every other regex metachar is escaped.
 * @param glob
 */
function globToRegExp( glob ) {
	const DOUBLE = '\u0000';
	let out = glob.replace( /\*\*/g, DOUBLE );
	out = out.replace( /[.+^${}()|[\]\\?]/g, '\\$&' );
	out = out.replace( /\*/g, '[^/]*' );
	out = out.split( DOUBLE ).join( '.*' );
	return new RegExp( '^' + out + '$' );
}

function matchesAnyGlob( path, globs ) {
	return globs.some( ( g ) => globToRegExp( g ).test( path ) );
}

/**
 * @param  currentTag
 * @param  ignoreGlobs
 * @param  cap
 * @return {{ prevTag: string|null, files: Array<{status:string,path:string,oldPath?:string}>, truncated:number }}
 */
export function collectChangedFiles( currentTag, ignoreGlobs, cap ) {
	// Previous tag reachable from the parent of the current tag's commit.
	// Returns "" when there is no previous tag (first release).
	const prevTag = gitOrEmpty( [ 'describe', '--tags', '--abbrev=0', `${ currentTag }^` ] );

	if ( ! prevTag ) {
		return { prevTag: null, files: [], truncated: 0 };
	}

	const raw = git( [ 'diff', '--name-status', '-M', `${ prevTag }..${ currentTag }` ] );
	if ( ! raw ) {
		return { prevTag, files: [], truncated: 0 };
	}

	const all = [];
	for ( const line of raw.split( '\n' ) ) {
		if ( ! line.trim() ) {
			continue;
		}
		const parts = line.split( '\t' );
		const rawStatus = parts[ 0 ];
		// Renames come as "R100\tOLD\tNEW"
		const status = rawStatus[ 0 ];
		if ( status === 'R' || status === 'C' ) {
			const oldPath = parts[ 1 ];
			const path = parts[ 2 ];
			if ( matchesAnyGlob( path, ignoreGlobs ) && matchesAnyGlob( oldPath, ignoreGlobs ) ) {
				continue;
			}
			all.push( { status: 'R', path, oldPath } );
		} else {
			const path = parts[ 1 ];
			if ( matchesAnyGlob( path, ignoreGlobs ) ) {
				continue;
			}
			all.push( { status, path } );
		}
	}

	const files = all.slice( 0, cap );
	const truncated = Math.max( 0, all.length - files.length );
	return { prevTag, files, truncated };
}

// ---------- markup ----------

const STATUS_LABEL = { A: 'ADDED', M: 'MODIFIED', D: 'DELETED', R: 'RENAMED', C: 'COPIED', T: 'TYPE-CHANGED' };
const STATUS_ORDER = [ 'A', 'M', 'R', 'C', 'T', 'D' ];

function escapeHtml( s ) {
	return String( s )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );
}

/**
 * Convert a subset of markdown (bold, code, links) to HTML.
 * Everything else is HTML-escaped as plain text — no raw HTML passthrough.
 * @param text
 */
export function inlineMarkdownToHtml( text ) {
	// Escape first so any HTML in the source becomes inert.
	let out = escapeHtml( text );

	// Links: [text](url) — url was already escaped, so &, " etc. are safe.
	// SEC-009 (2026-09-06 staged review): only http(s), same-page (#) and
	// site-relative (/) URLs become anchors; anything else (javascript:,
	// data:, …) stays escaped plain text.
	out = out.replace( /\[([^\]]+)\]\(([^)\s]+)\)/g, ( m, label, url ) => {
		if ( ! /^(https?:\/\/|#|\/)/i.test( url ) ) {
			return m;
		}
		return `<a href="${ url }" rel="noopener">${ label }</a>`;
	} );

	// Bold: **text**
	out = out.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );

	// Inline code: `text`
	out = out.replace( /`([^`]+)`/g, '<code>$1</code>' );

	return out;
}

function block( name, inner ) {
	return `<!-- wp:${ name } -->\n${ inner }\n<!-- /wp:${ name } -->`;
}

function heading( text ) {
	return block( 'heading', `<h2 class="wp-block-heading">${ escapeHtml( text ) }</h2>` );
}

function listItem( innerHtml ) {
	return `<!-- wp:list-item -->\n<li>${ innerHtml }</li>\n<!-- /wp:list-item -->`;
}

function list( itemsHtml ) {
	return block( 'list', `<ul class="wp-block-list">\n${ itemsHtml.join( '\n' ) }\n</ul>` );
}

export function composePostBody( { bullets, files, prevTag, currentTag, truncated } ) {
	const parts = [];

	parts.push( heading( "What's changed" ) );
	parts.push( list( bullets.map( ( b ) => listItem( inlineMarkdownToHtml( b ) ) ) ) );

	parts.push( heading( 'Files changed' ) );
	if ( prevTag === null ) {
		parts.push(
			block(
				'paragraph',
				`<p>${ escapeHtml( `First release (${ currentTag }) — no previous tag to diff against.` ) }</p>`,
			),
		);
	} else if ( files.length === 0 ) {
		parts.push(
			block(
				'paragraph',
				`<p>${ escapeHtml( `No files changed between ${ prevTag } and ${ currentTag } (after ignore filters).` ) }</p>`,
			),
		);
	} else {
		const sorted = [ ...files ].sort( ( a, b ) => {
			const oa = STATUS_ORDER.indexOf( a.status );
			const ob = STATUS_ORDER.indexOf( b.status );
			if ( oa !== ob ) {
				return oa - ob;
			}
			return a.path.localeCompare( b.path );
		} );
		const items = sorted.map( ( f ) => {
			const label = STATUS_LABEL[ f.status ] || f.status;
			const path =
				f.status === 'R' && f.oldPath
					? `${ escapeHtml( f.oldPath ) } → ${ escapeHtml( f.path ) }`
					: escapeHtml( f.path );
			return listItem( `<code>${ label }</code> — ${ path }` );
		} );
		if ( truncated > 0 ) {
			items.push( listItem( escapeHtml( `… and ${ truncated } more file(s) not shown.` ) ) );
		}
		parts.push( list( items ) );
	}

	return parts.join( '\n\n' );
}

// ---------- wp rest ----------

function authHeader() {
	const user = env( 'WP_USER' );
	const pass = env( 'WP_APP_PASSWORD' );
	const basic = Buffer.from( `${ user }:${ pass }` ).toString( 'base64' );
	return `Basic ${ basic }`;
}

function apiBase() {
	const url = env( 'WP_URL' ).replace( /\/+$/, '' );
	// SEC-010 (2026-09-06 staged review): Basic credentials ride every request —
	// refuse anything but https so a misconfigured secret can't send them cleartext.
	if ( ! /^https:\/\//i.test( url ) ) {
		die( `WP_URL must start with https:// (got scheme of "${ url.split( '://' )[ 0 ] }://…").` );
	}
	return `${ url }/wp-json/wp/v2`;
}

async function wpFetch( path, init = {} ) {
	const url = path.startsWith( 'http' ) ? path : `${ apiBase() }${ path }`;
	const res = await fetch( url, {
		...init,
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
			Authorization: authHeader(),
			...( init.headers || {} ),
		},
	} );
	const text = await res.text();
	if ( ! res.ok ) {
		// SEC-012 (2026-09-06 staged review): cap the logged body — full WP error
		// pages can leak server paths / debug output into public Actions logs.
		const snippet = text.length > 500 ? `${ text.slice( 0, 500 ) }… [truncated ${ text.length - 500 } chars]` : text;
		die( `WP REST ${ init.method || 'GET' } ${ url } → ${ res.status }\n${ snippet }` );
	}
	return text ? JSON.parse( text ) : null;
}

async function resolveTermId( taxRestBase, termSlug ) {
	const rows = await wpFetch( `/${ taxRestBase }?slug=${ encodeURIComponent( termSlug ) }&_fields=id,slug` );
	if ( ! Array.isArray( rows ) || rows.length === 0 ) {
		die( `Taxonomy term not found: rest_base="${ taxRestBase }" slug="${ termSlug }". The spec assumes it already exists — create it in the site admin.` );
	}
	return rows[ 0 ].id;
}

async function findExistingPostBySlug( cptRestBase, slug ) {
	const rows = await wpFetch(
		`/${ cptRestBase }?slug=${ encodeURIComponent( slug ) }&status=any&context=edit&_fields=id,slug,status`,
	);
	return Array.isArray( rows ) && rows.length > 0 ? rows[ 0 ] : null;
}

// ---------- main ----------

async function main() {
	// Read + parse changelog
	const readmePath = resolve( process.cwd(), README_PATH );
	const readme = readFileSync( readmePath, 'utf8' );
	const entry = parseTopmostChangelogEntry( readme );

	// Diff
	const currentTag = env( 'RELEASE_TAG' );
	const { prevTag, files, truncated } = collectChangedFiles( currentTag, IGNORE_GLOBS, FILE_LIST_CAP );

	// PARSE_ONLY: dump both halves and exit — the workflow's preflight step uses this.
	if ( PARSE_ONLY ) {
		console.log( '--- parsed changelog entry ---' );
		console.log( JSON.stringify( entry, null, 2 ) );
		console.log( '--- diff ---' );
		console.log( JSON.stringify( { prevTag, currentTag, fileCount: files.length, truncated, files }, null, 2 ) );
		return;
	}

	// Version check: readme's topmost version must match the release tag (minus leading "v").
	const tagVersion = currentTag.replace( /^v/, '' );
	if ( entry.version !== tagVersion ) {
		die(
			`Version mismatch: readme's topmost changelog entry is "${ entry.version }" but the release tag is "${ currentTag }" (normalised "${ tagVersion }"). Update the readme's Changelog section before tagging.`,
		);
	}

	// Compose body
	const content = composePostBody( {
		bullets: entry.bullets,
		files,
		prevTag,
		currentTag,
		truncated,
	} );

	const productName = env( 'PRODUCT_NAME' );
	const productTerm = env( 'PRODUCT_TERM' );
	const cptRestBase = env( 'CPT_REST_BASE' );
	const taxRestBase = env( 'TAXONOMY_REST_BASE' );
	const taxField = env( 'TAXONOMY_FIELD', taxRestBase );
	const termSlug = env( 'TAXONOMY_TERM_SLUG' );
	const postStatus = env( 'POST_STATUS', 'publish' );
	const publishedAt = env( 'RELEASE_PUBLISHED_AT' );

	const slug = `${ productTerm }-${ tagVersion.replace( /\./g, '-' ) }`;
	const title = `${ productName } ${ tagVersion }`;

	const payload = {
		title,
		slug,
		content,
		status: postStatus,
		date_gmt: new Date( publishedAt ).toISOString().replace( /\.\d+Z$/, '' ),
	};

	if ( DRY_RUN ) {
		// Dry-run stays fully offline: don't touch the site, don't resolve the term.
		console.log( '--- DRY RUN: payload that would be sent (taxonomy field resolved live in a real run) ---' );
		console.log( JSON.stringify( { ...payload, [ taxField ]: '<resolved-term-id>' }, null, 2 ) );
		console.log( '--- diff summary ---' );
		console.log(
			JSON.stringify( { prevTag, currentTag, fileCount: files.length, truncated }, null, 2 ),
		);
		return;
	}

	// Live path: resolve term, look up existing post, POST create or update.
	const termId = await resolveTermId( taxRestBase, termSlug );
	payload[ taxField ] = [ termId ];

	const existing = await findExistingPostBySlug( cptRestBase, slug );
	if ( existing ) {
		console.log( `Updating existing post id=${ existing.id } slug="${ slug }" status="${ existing.status }"` );
		const updated = await wpFetch( `/${ cptRestBase }/${ existing.id }`, {
			method: 'POST',
			body: JSON.stringify( payload ),
		} );
		console.log( `Updated: ${ updated.link || updated.id }` );
	} else {
		console.log( `Creating new post slug="${ slug }"` );
		const created = await wpFetch( `/${ cptRestBase }`, {
			method: 'POST',
			body: JSON.stringify( payload ),
		} );
		console.log( `Created: ${ created.link || created.id }` );
	}
}

main().catch( ( err ) => die( err && err.stack ? err.stack : String( err ) ) );
