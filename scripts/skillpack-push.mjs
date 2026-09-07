#!/usr/bin/env node
/**
 * AcrossAI MCP Manager — Skillpack Push
 *
 * Distributes skills from the canonical .agents/skills/ skillpack to
 * tool-specific directories so each AI tool can find them.
 *
 * Targets
 *   .claude/skills/   Claude Code
 *   .cursor/skills/   Cursor
 *   .codex/skills/    GitHub Copilot coding agent
 *   .github/skills/   GitHub Copilot / VS Code (gitignored — auto-generated)
 */

import fs from 'node:fs';
import path from 'node:path';

const PLUGIN_ROOT = process.cwd();
const SKILLPACK_DIR = path.join( PLUGIN_ROOT, '.agents', 'skills' );

const TARGETS = [
	'.claude/skills',
	'.cursor/skills',
	'.codex/skills',
	'.github/skills',
];

// ── helpers ──────────────────────────────────────────────────────────────────

const HR = '━'.repeat( 67 );
function banner( text ) {
	console.log( `\n${ HR }\n${ text }\n${ HR }\n` );
}

function copyDir( src, dest ) {
	fs.mkdirSync( dest, { recursive: true } );
	for ( const entry of fs.readdirSync( src, { withFileTypes: true } ) ) {
		const srcPath = path.join( src, entry.name );
		const destPath = path.join( dest, entry.name );
		if ( entry.isDirectory() ) {
			copyDir( srcPath, destPath );
		} else {
			fs.copyFileSync( srcPath, destPath );
		}
	}
}

function isSkillDir( entry ) {
	return (
		entry.isDirectory() &&
		! [ 'plans', 'tasks', 'memory' ].includes( entry.name ) &&
		fs.existsSync( path.join( SKILLPACK_DIR, entry.name, 'SKILL.md' ) )
	);
}

// ── main ─────────────────────────────────────────────────────────────────────

function main() {
	banner( '📤  Skillpack Push' );

	if ( ! fs.existsSync( SKILLPACK_DIR ) ) {
		console.error( `❌  Skillpack not found at .agents/skills/` );
		console.error( '   Run  npm run skillpack  first to install upstream skills.' );
		process.exit( 1 );
	}

	const skills = fs.readdirSync( SKILLPACK_DIR, { withFileTypes: true } )
		.filter( isSkillDir )
		.map( ( d ) => d.name );

	if ( skills.length === 0 ) {
		console.warn( '⚠️   No skills found in .agents/skills/ — nothing to push.' );
		process.exit( 0 );
	}

	console.log( `Pushing ${ skills.length } skill(s): ${ skills.join( ', ' ) }\n` );

	for ( const target of TARGETS ) {
		const targetDir = path.join( PLUGIN_ROOT, target );
		console.log( `→  ${ target }/` );

		for ( const skill of skills ) {
			const src = path.join( SKILLPACK_DIR, skill );
			const dest = path.join( targetDir, skill );

			if ( fs.existsSync( dest ) ) {
				fs.rmSync( dest, { recursive: true, force: true } );
			}
			copyDir( src, dest );
			console.log( `   ✓  ${ skill }` );
		}
	}

	console.log( '\n✅  Done.\n' );
}

main();
