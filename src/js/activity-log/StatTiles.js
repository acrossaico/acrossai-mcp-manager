/**
 * Four stat tiles for the activity log period.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';
import { Flex, FlexItem, Card, CardBody } from '@wordpress/components';
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
			<Flex gap={ 3 } align="stretch" wrap>
				{ tiles.map( ( tile ) => (
					<FlexItem key={ tile.label } style={ { flex: '1 1 180px' } }>
						<Card size="small">
							<CardBody>
								<p
									className="acrossai-mcp-activity__tile-label"
									style={ { margin: 0, color: '#646970', fontSize: '12px' } }
								>
									{ tile.label }
								</p>
								<p
									className="acrossai-mcp-activity__tile-value"
									style={ { margin: '4px 0 0', fontSize: '20px', fontWeight: 600 } }
								>
									{ tile.value }
								</p>
							</CardBody>
						</Card>
					</FlexItem>
				) ) }
			</Flex>
			<p className="description" style={ { marginTop: '8px' } }>
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
