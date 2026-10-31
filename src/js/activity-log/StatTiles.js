/**
 * Four stat tiles for the activity log period.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';
import { formatContextCost, formatDuration } from './format';

/**
 * Period summary tiles.
 *
 * The fourth tile is Active Clients, NOT tokens. The WordPress AI plugin's
 * equivalent screen shows a token count because it is the thing calling the
 * model; we are the tool server and never see a usage block. Distinct client
 * applications is the number an operator actually wants here.
 *
 * @param {Object} props         Component props.
 * @param {Object} props.summary Summary payload from /activity/summary.
 * @return {Element} The tiles.
 */
export default function StatTiles( { summary } ) {
	const tiles = [
		{
			label: __( 'Requests', 'acrossai-mcp-manager' ),
			value: summary?.requests ?? 0,
		},
		{
			label: __( 'Avg time', 'acrossai-mcp-manager' ),
			value: formatDuration( summary?.avg_duration_ms ?? null ),
		},
		{
			label: __( 'Success rate', 'acrossai-mcp-manager' ),
			value: sprintf(
				/* translators: %s: percentage. */ __( '%s%%', 'acrossai-mcp-manager' ),
				summary?.success_rate ?? 0,
			),
		},
		{
			label: __( 'Active clients', 'acrossai-mcp-manager' ),
			value: summary?.active_clients ?? 0,
		},
	];

	return (
		<>
			{ /* A bordered grid, not four Cards. The Card version floated them
			   as separate widgets above an unrelated table; these are one
			   reading of one period and belong inside the same frame. */ }
			<div className="acrossai-mcp-activity__stats">
				{ tiles.map( ( tile ) => (
					<div className="acrossai-mcp-activity__tile" key={ tile.label }>
						<span className="acrossai-mcp-activity__tile-label">
							{ tile.label }
						</span>
						<span className="acrossai-mcp-activity__tile-value">
							{ tile.value }
						</span>
					</div>
				) ) }
			</div>
			<p className="acrossai-mcp-activity__context">
				{ sprintf(
					/* translators: %s: formatted context size with approximate token count. */
					__( 'Context returned this period: %s', 'acrossai-mcp-manager' ),
					formatContextCost(
						summary?.context_bytes ?? null,
						summary?.context_tokens_approx ?? null,
					),
				) }
			</p>
		</>
	);
}
