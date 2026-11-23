/**
 * TableDenylistPanel — which tables no ability may touch.
 *
 * Shows the site's real table list rather than a free-text box alone. A denylist whose entries are
 * typed from memory is a denylist with a typo in it, and a typo here covers nothing while looking
 * like it covers something.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.0.45
 */

import { useState, useEffect, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const TableDenylistPanel = ( { data, tables, onSave } ) => {
	const [ denied, setDenied ] = useState( () => data.policy.denied_tables || [] );
	const [ saving, setSaving ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ filter, setFilter ] = useState( '' );

	useEffect( () => {
		setDenied( data.policy.denied_tables || [] );
	}, [ data ] );

	const prefix = tables?.prefix || '';

	// Offer the suffix an operator should store, not the physical name: `wp_my_logs` is stored as
	// `my_logs` so one entry keeps working across a prefix change and, on multisite, across sites.
	const suffixOf = ( name ) =>
		prefix && name.startsWith( prefix ) ? name.slice( prefix.length ) : name;

	const rows = useMemo( () => {
		const list = tables?.tables || [];
		if ( ! filter.trim() ) {
			return list;
		}
		const needle = filter.trim().toLowerCase();
		return list.filter( ( t ) => t.name.toLowerCase().includes( needle ) );
	}, [ tables, filter ] );

	const toggle = ( name ) => {
		const suffix = suffixOf( name );
		setDenied( ( prev ) =>
			prev.includes( suffix ) ? prev.filter( ( s ) => s !== suffix ) : [ ...prev, suffix ]
		);
	};

	const doSave = () => {
		setSaving( true );
		setStatus( '' );
		onSave( { denied_tables: denied } )
			.then( () => {
				setSaving( false );
				setStatus( __( 'Saved.', 'acrossai-abilities-manager' ) );
			} )
			.catch( ( err ) => {
				setSaving( false );
				setStatus( err.message || __( 'Save failed.', 'acrossai-abilities-manager' ) );
			} );
	};

	return (
		<div className="acrossai-db-panel">
			<h2>{ __( 'Table denylist', 'acrossai-abilities-manager' ) }</h2>

			<p className="description">
				{ sprintf(
					/* translators: %s: comma-separated table names */
					__( 'These are always denied and cannot be enabled: %s. They hold password hashes, session tokens and password-reset keys.', 'acrossai-abilities-manager' ),
					( data.always_denied || [] ).join( ', ' )
				) }
			</p>

			<p>
				<label className="screen-reader-text" htmlFor="acrossai-db-filter">
					{ __( 'Filter tables', 'acrossai-abilities-manager' ) }
				</label>
				<input
					id="acrossai-db-filter"
					type="search"
					className="regular-text"
					placeholder={ __( 'Filter tables…', 'acrossai-abilities-manager' ) }
					value={ filter }
					onChange={ ( e ) => setFilter( e.target.value ) }
				/>
			</p>

			{ ! tables && <p>{ __( 'Loading tables…', 'acrossai-abilities-manager' ) }</p> }

			{ tables && rows.length === 0 && (
				<p>{ __( 'No tables match that filter.', 'acrossai-abilities-manager' ) }</p>
			) }

			{ tables && rows.length > 0 && (
				<div className="acrossai-db-panel__tables">
					{ rows.map( ( t ) => {
						const suffix = suffixOf( t.name );
						const checked = t.locked || denied.includes( suffix );

						return (
							<label
								key={ t.name }
								className={ `acrossai-db-table${ t.locked ? ' is-locked' : '' }` }
							>
								<input
									type="checkbox"
									checked={ checked }
									disabled={ t.locked }
									onChange={ () => toggle( t.name ) }
								/>
								<code>{ t.name }</code>
								{ t.core && (
									<span className="acrossai-db-table__tag">
										{ __( 'core', 'acrossai-abilities-manager' ) }
									</span>
								) }
								{ t.locked && (
									<span className="acrossai-db-table__tag is-locked">
										{ __( 'always denied', 'acrossai-abilities-manager' ) }
									</span>
								) }
							</label>
						);
					} ) }
				</div>
			) }

			<p>
				<button type="button" className="button button-primary" disabled={ saving } onClick={ doSave }>
					{ saving ? __( 'Saving…', 'acrossai-abilities-manager' ) : __( 'Save denylist', 'acrossai-abilities-manager' ) }
				</button>
				{ status && <span className="acrossai-db-panel__status"> { status }</span> }
			</p>
		</div>
	);
};

export default TableDenylistPanel;
