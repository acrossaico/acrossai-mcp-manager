#!/usr/bin/env node
/**
 * sync-ai-policy.mjs
 *
 * Reads ai-policy.yml and generates / merges all AI tool config files.
 * Run with: npm run sync:ai-policy
 *
 * Targets:
 *   .aiignore
 *   .claudeignore
 *   .claude/settings.json       (merges — only permissions.deny is replaced)
 *   .vscode/settings.json       (merges — only files.exclude / search.exclude replaced)
 *   .github/copilot-instructions.md  (merges — only COPILOT ACCESS POLICY block replaced)
 */

import { readFileSync, writeFileSync, existsSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname( fileURLToPath( import.meta.url ) );
const ROOT = resolve( __dirname, '..' );

// ─── YAML parser ────────────────────────────────────────────────────────────
// Minimal parser for our specific flat-list YAML format.
// Handles sections, list items, and inline comments.
function parsePolicy( yaml ) {
	const result = { hard_ignore: [], requires_permission: [] };
	let section = null;

	for ( const raw of yaml.split( '\n' ) ) {
		const line = raw.replace( /#.*$/, '' ).trimEnd(); // strip inline comments

		if ( /^hard_ignore\s*:/.test( line ) ) {
			section = 'hard_ignore';
		} else if ( /^requires_permission\s*:/.test( line ) ) {
			section = 'requires_permission';
		} else if ( section && /^\s+-\s+/.test( line ) ) {
			const value = line.replace( /^\s+-\s+/, '' ).replace( /^["']|["']$/g, '' ).trim();
			if ( value ) {
				result[ section ].push( value );
			}
		}
	}
	return result;
}

// ─── Helpers ────────────────────────────────────────────────────────────────
function read( rel ) {
	const p = resolve( ROOT, rel );
	return existsSync( p ) ? readFileSync( p, 'utf8' ) : null;
}

function write( rel, content ) {
	writeFileSync( resolve( ROOT, rel ), content, 'utf8' );
	console.log( `  ✔  ${ rel }` );
}

function readJson( rel ) {
	const raw = read( rel );
	return raw ? JSON.parse( raw ) : {};
}

function writeJson( rel, obj ) {
	write( rel, JSON.stringify( obj, null, '\t' ) + '\n' );
}

// ─── Generators ─────────────────────────────────────────────────────────────

function generateIgnoreFile( title, { hard_ignore, requires_permission } ) {
	const lines = [
		`# ${ title }`,
		`# AUTO-GENERATED — edit ai-policy.yml and run: npm run sync:ai-policy`,
		`#`,
		`# [HARD IGNORE]         Never read. Binary, generated, dev-only tooling.`,
		`# [REQUIRES PERMISSION] Ask before reading: "May I read <path>?"`,
		``,
		`# ============================================================`,
		`# [HARD IGNORE]`,
		`# ============================================================`,
		``,
		...hard_ignore,
		``,
		`# ============================================================`,
		`# [REQUIRES PERMISSION]`,
		`# Before reading, ask: "May I read <path> to help with this task?"`,
		`# ============================================================`,
		``,
		...requires_permission.map( ( p ) => `# PERMISSION_REQUIRED: ${ p }` ),
		``,
	];
	return lines.join( '\n' );
}

function syncAiignore( policy ) {
	write(
		'.aiignore',
		generateIgnoreFile( '.aiignore — JetBrains AI Assistant access policy', policy ),
	);
}

function syncClaudeignore( policy ) {
	write(
		'.claudeignore',
		generateIgnoreFile( '.claudeignore — Claude Code access policy', policy ),
	);
}

function syncClaudeSettings( { hard_ignore } ) {
	const settings = readJson( '.claude/settings.json' );

	// Map hard_ignore paths to Read(pattern) deny rules.
	settings.permissions = settings.permissions || {};
	settings.permissions.deny = hard_ignore.map( ( p ) => {
		// Paths ending with / or containing no wildcard get /** appended.
		if ( p.endsWith( '/' ) ) {
			return `Read(${ p }**)`;
		}
		if ( p.includes( '*' ) ) {
			return `Read(${ p })`;
		}
		return `Read(${ p })`;
	} );

	writeJson( '.claude/settings.json', settings );
}

function syncVscodeSettings( { hard_ignore } ) {
	const settings = readJson( '.vscode/settings.json' );

	const exclude = {};
	const searchExclude = {};

	for ( const p of hard_ignore ) {
		// Normalise: strip trailing slash for VS Code keys.
		const key = p.endsWith( '/' ) ? `**/${ p.slice( 0, -1 ) }` : p;
		exclude[ key ] = true;
		// search.exclude skips binary/very-large paths that aren't useful to grep.
		searchExclude[ key ] = true;
	}

	settings[ 'files.exclude' ] = exclude;
	settings[ 'search.exclude' ] = searchExclude;

	writeJson( '.vscode/settings.json', settings );
}

function syncCopilotInstructions( { hard_ignore, requires_permission } ) {
	const filePath = '.github/copilot-instructions.md';
	let content = read( filePath ) || '';

	const block = [
		`<!-- COPILOT ACCESS POLICY START -->`,
		`## File Access Policy`,
		``,
		`> AUTO-GENERATED — edit \`ai-policy.yml\` and run \`npm run sync:ai-policy\``,
		``,
		`### Hard Ignore — never read these paths`,
		``,
		...hard_ignore.map( ( p ) => `- \`${ p }\`` ),
		``,
		`### Requires Permission — ask before reading`,
		``,
		`Before reading any path below, ask:`,
		`> "May I read \`<path>\` to help with this task?"`,
		``,
		...requires_permission.map( ( p ) => `- \`${ p }\`` ),
		`<!-- COPILOT ACCESS POLICY END -->`,
	].join( '\n' );

	const marker = /<!-- COPILOT ACCESS POLICY START -->[\s\S]*?<!-- COPILOT ACCESS POLICY END -->/;

	if ( marker.test( content ) ) {
		content = content.replace( marker, block );
	} else {
		content = content.trimEnd() + '\n\n' + block + '\n';
	}

	write( filePath, content );
}

// ─── Main ────────────────────────────────────────────────────────────────────
const yamlPath = resolve( ROOT, 'ai-policy.yml' );
if ( ! existsSync( yamlPath ) ) {
	console.error( 'Error: ai-policy.yml not found at repo root.' );
	process.exit( 1 );
}

const policy = parsePolicy( readFileSync( yamlPath, 'utf8' ) );

console.log( '\nSyncing AI access policy from ai-policy.yml...\n' );
syncAiignore( policy );
syncClaudeignore( policy );
syncClaudeSettings( policy );
syncVscodeSettings( policy );
syncCopilotInstructions( policy );
console.log( '\nDone. All files are in sync with ai-policy.yml.\n' );
