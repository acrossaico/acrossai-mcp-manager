/**
 * MCP Activity Log — DataViews app.
 *
 * One component, two mount points: the global Activity page and the per-server
 * Logs tab. The per-server mount passes a serverId and hides the Server column.
 *
 * @package
 */

import { useEffect, useMemo, useState, createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	Flex,
	FlexItem,
	Modal,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import StatTiles from './StatTiles';
import DetailPanel from './DetailPanel';
import { getFields, getDefaultView } from './fields';

const NS = '/acrossai-mcp-manager/v1';

/**
 * The activity screen.
 *
 * @param {Object}  props            Component props.
 * @param {number}  props.serverId   Narrow to one server; 0 for all.
 * @param {boolean} props.showServer Whether to show the Server column.
 * @param {Object}  props.config     Localized bootstrap payload.
 * @return {Element} The screen.
 */
function ActivityLog( { serverId, showServer, config } ) {
	const [ view, setView ] = useState( () => getDefaultView( showServer ) );
	const [ events, setEvents ] = useState( [] );
	const [ summary, setSummary ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ days, setDays ] = useState( '1' );
	const [ eventType, setEventType ] = useState( 'request' );
	const [ purging, setPurging ] = useState( false );
	const [ confirmPurge, setConfirmPurge ] = useState( false );
	const [ openRow, setOpenRow ] = useState( null );

	const fields = useMemo( () => getFields( showServer ), [ showServer ] );

	const load = async () => {
		setLoading( true );
		setError( null );

		try {
			const query = new URLSearchParams( {
				event_type: eventType,
				per_page: String( view.perPage ?? 25 ),
			} );

			if ( serverId > 0 ) {
				query.set( 'server_id', String( serverId ) );
			}

			if ( view.search ) {
				query.set( 'search', view.search );
			}

			const [ list, stats ] = await Promise.all( [
				apiFetch( { path: `${ NS }/activity?${ query.toString() }` } ),
				apiFetch(
					{ path: `${ NS }/activity/summary?days=${ encodeURIComponent( days ) }&server_id=${ serverId }` },
				),
			] );

			setEvents( list?.events ?? [] );
			setSummary( stats ?? null );
		} catch ( e ) {
			setError( e?.message ?? __( 'Could not load the activity log.', 'acrossai-mcp-manager' ) );
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		load();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ eventType, days, serverId, view.search, view.perPage ] );

	const purge = async () => {
		setPurging( true );

		try {
			await apiFetch( { path: `${ NS }/activity`, method: 'DELETE' } );
			setConfirmPurge( false );
			await load();
		} catch ( e ) {
			setError( e?.message ?? __( 'The purge failed.', 'acrossai-mcp-manager' ) );
		} finally {
			setPurging( false );
		}
	};

	// Two distinct empty states. "Recording is off" and "nothing happened" look
	// identical on screen but mean opposite things, and conflating them is the
	// same class of mistake as showing 0 where nothing was captured.
	const loggingOff = eventType === 'request' ? ! config.requestLogging : ! config.abilityLogging;

	return (
		<div className="acrossai-mcp-activity">
			{ error && (
				<Notice status="error" isDismissible={ false } onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ /* T064: on WordPress 6.9/7.0 core's hook pair records successes
			     only — a WP_Error early-returns before the end hook fires, and
			     a permission denial returns before the start hook fires at all.
			     MCP-originated calls are still fully covered by the plugin's own
			     bracket. Saying so is better than a log that silently implies
			     complete coverage. */ }
			{ /* A failing recorder looks exactly like a quiet one on screen.
			     Saying which is the difference between an operator
			     investigating and an operator assuming all is well. */ }
			{ config.writeFailures > 0 && (
				<Notice status="error" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: consecutive failed writes. */
						__(
							'Recording is failing: %d writes have not been saved since the last success. Check the PHP error log — the log may be incomplete.',
							'acrossai-mcp-manager',
						),
						config.writeFailures,
					) }
				</Notice>
			) }

			{ eventType === 'ability' && ! config.richAbilityHooks && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'This WordPress version reports only successful ability runs to plugins. Calls that came through an MCP client are still recorded in full, including failures; failures from the REST API, WP-CLI or the admin screens are not.',
						'acrossai-mcp-manager',
					) }
				</Notice>
			) }

			{ loggingOff && (
				<Notice status="warning" isDismissible={ false }>
					{ eventType === 'request'
						? __(
							'Request logging is switched off, so nothing new is being recorded.',
							'acrossai-mcp-manager',
						)
						: __(
							'Ability logging is switched off, so nothing new is being recorded.',
							'acrossai-mcp-manager',
						) }{ ' ' }
					<a href={ config.settingsUrl }>{ __( 'Settings', 'acrossai-mcp-manager' ) }</a>
				</Notice>
			) }

			<Flex justify="space-between" align="flex-end" style={ { margin: '12px 0' } } wrap>
				<FlexItem>
					<Flex gap={ 2 }>
						<FlexItem>
							<Button
								variant={ eventType === 'request' ? 'primary' : 'secondary' }
								size="compact"
								onClick={ () => setEventType( 'request' ) }
							>
								{ __( 'Requests', 'acrossai-mcp-manager' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							{ /* A filter, not a tab: it is one table, and the
							distinction is why the parent/child tree works. */ }
							<Button
								variant={ eventType === 'ability' ? 'primary' : 'secondary' }
								size="compact"
								onClick={ () => setEventType( 'ability' ) }
							>
								{ __( 'Abilities', 'acrossai-mcp-manager' ) }
							</Button>
						</FlexItem>
					</Flex>
				</FlexItem>
				<FlexItem>
					<SelectControl
						label={ __( 'Period', 'acrossai-mcp-manager' ) }
						value={ days }
						options={ [
							{ value: '1', label: __( 'Last 24 hours', 'acrossai-mcp-manager' ) },
							{ value: '7', label: __( 'Last 7 days', 'acrossai-mcp-manager' ) },
							{ value: '30', label: __( 'Last 30 days', 'acrossai-mcp-manager' ) },
						] }
						onChange={ setDays }
						__nextHasNoMarginBottom
					/>
				</FlexItem>
			</Flex>

			<StatTiles summary={ summary } />

			<div style={ { marginTop: '16px' } }>
				{ loading ? (
					<Spinner />
				) : (
					<DataViews
						data={ events }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						paginationInfo={ { totalItems: events.length, totalPages: 1 } }
						getItemId={ ( item ) => String( item.id ) }
						defaultLayouts={ { table: {} } }
						actions={ [
							{
								id: 'detail',
								label: __( 'Details', 'acrossai-mcp-manager' ),
								callback: ( items ) => setOpenRow( items[ 0 ] ),
							},
						] }
					/>
				) }
			</div>

			{ ! loading && events.length === 0 && (
				<p className="description">
					{ loggingOff
						? __(
							'Nothing recorded, because recording is switched off — not because nothing happened.',
							'acrossai-mcp-manager',
						)
						: __( 'Nothing happened in this period.', 'acrossai-mcp-manager' ) }
				</p>
			) }

			<hr style={ { margin: '24px 0 12px' } } />

			<h2 style={ { fontSize: '14px' } }>{ __( 'Manage logs', 'acrossai-mcp-manager' ) }</h2>
			<p className="description">
				{ __(
					'Purging empties this log. The purge itself is recorded, so the history that remains always begins by saying what was removed.',
					'acrossai-mcp-manager',
				) }
			</p>
			<Button variant="secondary" isDestructive onClick={ () => setConfirmPurge( true ) }>
				{ __( 'Purge all activity', 'acrossai-mcp-manager' ) }
			</Button>

			{ confirmPurge && (
				<Modal
					title={ __( 'Purge all activity?', 'acrossai-mcp-manager' ) }
					onRequestClose={ () => setConfirmPurge( false ) }
				>
					<p>
						{ __(
							'Every recorded event is deleted and cannot be recovered. A record of this purge — who, when, and how many — is written afterwards and survives.',
							'acrossai-mcp-manager',
						) }
					</p>
					<Flex justify="flex-end" gap={ 2 }>
						<FlexItem>
							<Button variant="tertiary" onClick={ () => setConfirmPurge( false ) }>
								{ __( 'Cancel', 'acrossai-mcp-manager' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="primary" isDestructive isBusy={ purging } onClick={ purge }>
								{ __( 'Purge', 'acrossai-mcp-manager' ) }
							</Button>
						</FlexItem>
					</Flex>
				</Modal>
			) }

			{ openRow && (
				<Modal
					title={ openRow.component_name || __( 'Event', 'acrossai-mcp-manager' ) }
					onRequestClose={ () => setOpenRow( null ) }
					size="large"
				>
					<DetailPanel event={ openRow } />
				</Modal>
			) }
		</div>
	);
}

/** Mount into whichever root the page declared. */
function mount() {
	const config = window.acrossaiMcpActivity;

	if ( ! config ) {
		return;
	}

	const node = document.getElementById( config.rootId );

	if ( ! node ) {
		return;
	}

	if ( config.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	}

	// B25: admin bundles inherit wpApiSettings.root from core, which is not
	// necessarily this site's REST root. Set it explicitly.
	if ( config.root ) {
		apiFetch.use( apiFetch.createRootURLMiddleware( config.root.replace( /\/?$/, '/' ) ) );
	}

	createRoot( node ).render(
		<ActivityLog
			serverId={ parseInt( config.serverId, 10 ) || 0 }
			showServer={ !! config.showServer }
			config={ config }
		/>,
	);
}

if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
}

export default ActivityLog;
