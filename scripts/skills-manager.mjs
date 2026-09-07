#!/usr/bin/env node
/**
 * AcrossAI MCP Manager — Skillpack Manager
 *
 * Fetches upstream skills from GitHub and installs them into .agents/skills/,
 * the single canonical skillpack location for this project.
 *
 * Run `npm run skillpack:push` afterwards to distribute to tool-specific dirs.
 *
 * Sources
 *   • WPBoilerplate/agent-skills  (WPBoilerplate-specific skills)
 *   • WordPress/agent-skills      (WordPress core skills)
 */

import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import readline from 'node:readline';
import { spawnSync } from 'node:child_process';

const PLUGIN_ROOT = process.cwd();
const SKILLPACK_DIR = path.join( PLUGIN_ROOT, '.agents', 'skills' );

const SOURCES = [
	{ repo: 'WPBoilerplate/agent-skills', label: 'WPBoilerplate' },
	{ repo: 'WordPress/agent-skills', label: 'WordPress' },
];

// ── helpers ──────────────────────────────────────────────────────────────────

const HR = '━'.repeat( 67 );
function banner( text ) {
	console.log( `\n${ HR }\n${ text }\n${ HR }\n` );
}

function cloneRepo( repo, dest ) {
	console.log( `==> Cloning ${ repo }...` );
	if ( fs.existsSync( dest ) ) {
		fs.rmSync( dest, { recursive: true, force: true } );
	}

	const result = spawnSync(
		'git',
		[ 'clone', '--depth=1', `https://github.com/${ repo }.git`, dest ],
		{ stdio: 'inherit' },
	);

	if ( result.status !== 0 ) {
		console.error( `❌  Failed to clone ${ repo }` );
		process.exit( 1 );
	}
}

function listSkills( repoDir ) {
	const skillsDir = path.join( repoDir, 'skills' );
	if ( ! fs.existsSync( skillsDir ) ) {
		return [];
	}

	return fs.readdirSync( skillsDir, { withFileTypes: true } )
		.filter( ( d ) => d.isDirectory() && fs.existsSync( path.join( skillsDir, d.name, 'SKILL.md' ) ) )
		.map( ( d ) => d.name );
}

function readDesc( repoDir, skill ) {
	const md = path.join( repoDir, 'skills', skill, 'SKILL.md' );
	for ( const line of fs.readFileSync( md, 'utf8' ).split( '\n' ) ) {
		const t = line.trim();
		if ( t && ! t.startsWith( '#' ) && ! t.startsWith( '<!--' ) ) {
			return t.slice( 0, 80 );
		}
	}
	return '';
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

function ask( question ) {
	return new Promise( ( resolve ) => {
		const rl = readline.createInterface( { input: process.stdin, output: process.stdout } );
		rl.question( question, ( answer ) => {
			rl.close(); resolve( answer.trim() );
		} );
	} );
}

// ── main ─────────────────────────────────────────────────────────────────────

async function main() {
	banner( '🎓  WordPress Skillpack Manager' );

	const WORK_DIR = fs.mkdtempSync( path.join( os.tmpdir(), 'wpb-skills-' ) );

	try {
		// 1. Clone every source and collect available skills
		const allSkills = [];
		for ( const src of SOURCES ) {
			const repoDir = path.join( WORK_DIR, src.repo.replace( '/', '__' ) );
			cloneRepo( src.repo, repoDir );

			for ( const skill of listSkills( repoDir ) ) {
				allSkills.push( {
					name: skill,
					label: src.label,
					repoDir,
					desc: readDesc( repoDir, skill ),
				} );
			}
			console.log();
		}

		if ( allSkills.length === 0 ) {
			console.error( '❌  No skills found in any source.' );
			process.exit( 1 );
		}

		// 2. Display menu
		console.log( `Found ${ allSkills.length } skill(s):\n` );
		allSkills.forEach( ( { name, label, desc }, i ) => {
			const num = String( i + 1 ).padStart( 3 );
			console.log( `${ num }. [${ label }] ${ name }` );
			if ( desc ) {
				console.log( `       ${ desc }` );
			}
		} );
		console.log();

		const answer = await ask(
			"Enter numbers separated by spaces, 'all' to install everything, or press Enter to exit.\nYour selection: ",
		);

		if ( ! answer ) {
			console.log( '\nNo selection — exiting.' );
			process.exit( 0 );
		}

		// 3. Resolve selection
		let selected;
		if ( answer.toLowerCase() === 'all' ) {
			selected = [ ...allSkills ];
		} else {
			selected = [];
			for ( const token of answer.split( /\s+/ ) ) {
				const idx = parseInt( token, 10 ) - 1;
				if ( idx >= 0 && idx < allSkills.length ) {
					console.log( `✅  Selected: ${ allSkills[ idx ].name }` );
					selected.push( allSkills[ idx ] );
				} else {
					console.warn( `⚠️   Unknown index: ${ token }` );
				}
			}
		}

		if ( selected.length === 0 ) {
			console.log( '\nNo valid skills selected — exiting.' );
			process.exit( 0 );
		}

		banner( '📥  Installing to skillpack (.agents/skills/)...' );

		// 4. Copy each selected skill into .agents/skills/
		for ( const { name, label, repoDir } of selected ) {
			const src = path.join( repoDir, 'skills', name );
			const dest = path.join( SKILLPACK_DIR, name );

			console.log( `   [${ label }] ${ name } → .agents/skills/${ name }` );

			if ( fs.existsSync( dest ) ) {
				fs.rmSync( dest, { recursive: true, force: true } );
			}
			copyDir( src, dest );
		}

		console.log( `\n✅  ${ selected.length } skill(s) installed to .agents/skills/` );
		console.log( '   Run  npm run skillpack:push  to distribute to tool directories.\n' );
	} finally {
		fs.rmSync( WORK_DIR, { recursive: true, force: true } );
	}
}

main().catch( ( err ) => {
	console.error( 'Error:', err.message ); process.exit( 1 );
} );
