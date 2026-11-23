/**
 * DatabaseSettings — loads the policy and renders the control panels.
 *
 * One fetch for the policy and one for the table list, then the panels are pure. Saving sends only
 * the keys that changed: the REST controller merges onto the stored policy, so a panel that does not
 * know about a control cannot reset it.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.0.45
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import AccessModePanel from './AccessModePanel.jsx';
import TableDenylistPanel from './TableDenylistPanel.jsx';
import QueryLimitsPanel from './QueryLimitsPanel.jsx';

const DatabaseSettings = ( { restNamespace, restBase } ) => {
	const [ data, setData ]       = useState( null );
	const [ tables, setTables ]   = useState( null );
	const [ error, setError ]     = useState( '' );

	const policyPath = `/${ restNamespace }/${ restBase }/policy`;
	const tablesPath = `/${ restNamespace }/${ restBase }/tables`;

	useEffect( () => {
		apiFetch( { path: policyPath } )
			.then( setData )
			.catch( ( err ) => setError( err.message || __( 'Could not load the database policy.', 'acrossai-abilities-manager' ) ) );

		// The table list is a convenience for the denylist, not a prerequisite — a failure here
		// leaves the rest of the tab usable rather than blanking it.
		apiFetch( { path: tablesPath } )
			.then( setTables )
			.catch( () => setTables( { tables: [], total: 0, prefix: '' } ) );
	}, [ policyPath, tablesPath ] );

	const save = useCallback(
		( patch ) =>
			apiFetch( { path: policyPath, method: 'POST', data: patch } ).then( ( next ) => {
				// Render what came back, not what we sent: the server sanitises, and an operator
				// should see the policy that is actually enforced.
				setData( next );
				return next;
			} ),
		[ policyPath ]
	);

	if ( error ) {
		return (
			<div className="notice notice-error inline">
				<p>{ error }</p>
			</div>
		);
	}

	if ( ! data ) {
		return <p>{ __( 'Loading database settings…', 'acrossai-abilities-manager' ) }</p>;
	}

	return (
		<div className="acrossai-db-settings">
			<p className="acrossai-db-settings__intro">
				{ __(
					'These controls apply to every database ability an AI client can reach. They protect the site from mistakes made by your own agent — they are not a boundary against an administrator, who can change them here.',
					'acrossai-abilities-manager'
				) }
			</p>

			<AccessModePanel data={ data } onSave={ save } />
			<TableDenylistPanel data={ data } tables={ tables } onSave={ save } />
			<QueryLimitsPanel data={ data } onSave={ save } />
		</div>
	);
};

export default DatabaseSettings;
