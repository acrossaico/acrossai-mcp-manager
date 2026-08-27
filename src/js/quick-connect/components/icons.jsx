/**
 * F069 — MCP Quick Connect via AcrossAI wizard inline SVG icons.
 *
 * All icons are pure React components rendering inline SVG. No external icon
 * library (per plan.md Technical Context — @wordpress/* packages only).
 * Every icon inherits currentColor so parent CSS drives the fill/stroke.
 *
 * Path/viewBox choices match Feather/Lucide 24×24 style for visual
 * consistency with WP core admin (which uses Dashicons but the wizard's
 * design language is closer to Feather).
 *
 * @package AcrossAI_MCP_Manager
 */

const iconProps = ( size = 20 ) => ( {
	width: size,
	height: size,
	viewBox: '0 0 24 24',
	fill: 'none',
	stroke: 'currentColor',
	strokeWidth: 2,
	strokeLinecap: 'round',
	strokeLinejoin: 'round',
	'aria-hidden': true,
	focusable: false,
} );

export const LinkIcon = ( { size } ) => (
	<svg { ...iconProps( size ) }>
		<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
		<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
	</svg>
);

export const PuzzleIcon = ( { size } ) => (
	<svg { ...iconProps( size ) }>
		<path d="M19.439 7.85c-.049.322.059.648.289.878l1.568 1.568c.47.47.706 1.087.706 1.704s-.235 1.233-.706 1.704l-1.611 1.611a.98.98 0 0 1-.837.276c-.47-.07-.802-.48-.968-.925a2.501 2.501 0 1 0-3.214 3.214c.446.166.855.497.925.968a.979.979 0 0 1-.276.837l-1.61 1.61a2.404 2.404 0 0 1-1.705.707 2.402 2.402 0 0 1-1.704-.706l-1.568-1.568a1.026 1.026 0 0 0-.877-.29c-.493.074-.84.504-1.02.968a2.5 2.5 0 1 1-3.237-3.237c.464-.18.894-.527.967-1.02a1.026 1.026 0 0 0-.289-.877l-1.568-1.568A2.402 2.402 0 0 1 1.998 12c0-.617.236-1.234.706-1.704L4.23 8.77c.24-.24.581-.353.917-.303.515.077.877.528 1.073 1.01a2.5 2.5 0 1 0 3.259-3.259c-.482-.196-.933-.558-1.01-1.073-.05-.336.062-.676.303-.917l1.525-1.525A2.402 2.402 0 0 1 12 1.998c.617 0 1.234.236 1.704.706l1.568 1.568c.23.23.556.338.877.29.493-.074.84-.504 1.02-.968a2.5 2.5 0 1 1 3.237 3.237c-.464.18-.894.527-.967 1.02Z" />
	</svg>
);

export const TerminalIcon = ( { size } ) => (
	<svg { ...iconProps( size ) }>
		<polyline points="4 17 10 11 4 5" />
		<line x1="12" y1="19" x2="20" y2="19" />
	</svg>
);

export const CheckIcon = ( { size } ) => (
	<svg { ...iconProps( size ) }>
		<polyline points="20 6 9 17 4 12" />
	</svg>
);

export const ChevronDown = ( { size } ) => (
	<svg { ...iconProps( size ) }>
		<polyline points="6 9 12 15 18 9" />
	</svg>
);

export const ChevronRight = ( { size } ) => (
	<svg { ...iconProps( size ) }>
		<polyline points="9 18 15 12 9 6" />
	</svg>
);

export const ExternalLinkArrow = ( { size } ) => (
	<svg { ...iconProps( size ) }>
		<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
		<polyline points="15 3 21 3 21 9" />
		<line x1="10" y1="14" x2="21" y2="3" />
	</svg>
);
