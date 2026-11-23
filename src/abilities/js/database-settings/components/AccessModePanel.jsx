/**
 * AccessModePanel — the one switch that governs every database ability.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.0.45
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

// Copy per mode. Kept here rather than server-side because it is UI prose, but the mode LIST comes
// from the server so the two cannot disagree about what is valid.
const DESCRIPTIONS = {
	unrestricted: __(
		'Reads and writes both allowed. This is how the database abilities have always behaved.',
		'acrossai-abilities-manager'
	),
	'read-only': __(
		'Reads allowed, writes refused. Reads also run inside a read-only transaction, so the database server itself rejects a write however the query is written — not just a check we perform.',
		'acrossai-abilities-manager'
	),
	off: __(
		'No database ability runs. They stay listed so an assistant can see they exist and be told why they are unavailable, rather than appearing broken.',
		'acrossai-abilities-manager'
	),
};

const LABELS = {
	unrestricted: __( 'Unrestricted', 'acrossai-abilities-manager' ),
	'read-only': __( 'Read-only', 'acrossai-abilities-manager' ),
	off: __( 'Off', 'acrossai-abilities-manager' ),
};

const AccessModePanel = ( { data, onSave } ) => {
	const [ mode, setMode ]     = useState( data.policy.mode );
	const [ saving, setSaving ] = useState( false );
	const [ status, setStatus ] = useState( '' );

	useEffect( () => {
		setMode( data.policy.mode );
	}, [ data ] );

	const doSave = () => {
		setSaving( true );
		setStatus( '' );
		onSave( { mode } )
			.then( () => {
				setSaving( false );
				setStatus( __( 'Saved.', 'acrossai-abilities-manager' ) );
			} )
			.catch( ( err ) => {
				setSaving( false );
				setStatus( err.message || __( 'Save failed.', 'acrossai-abilities-manager' ) );
			} );
	};

	const dirty = mode !== data.policy.mode;

	return (
		<div className="acrossai-db-panel">
			<h2>{ __( 'Access mode', 'acrossai-abilities-manager' ) }</h2>

			{ ( data.modes || [] ).map( ( value ) => (
				<p key={ value } className="acrossai-db-panel__mode">
					<label>
						<input
							type="radio"
							name="acrossai-db-mode"
							value={ value }
							checked={ mode === value }
							onChange={ () => setMode( value ) }
						/>
						<strong>{ LABELS[ value ] || value }</strong>
					</label>
					<span className="description acrossai-db-panel__mode-desc">
						{ DESCRIPTIONS[ value ] || '' }
					</span>
				</p>
			) ) }

			<p>
				<button
					type="button"
					className="button button-primary"
					disabled={ saving || ! dirty }
					onClick={ doSave }
				>
					{ saving ? __( 'Saving…', 'acrossai-abilities-manager' ) : __( 'Save access mode', 'acrossai-abilities-manager' ) }
				</button>
				{ status && <span className="acrossai-db-panel__status"> { status }</span> }
			</p>
		</div>
	);
};

export default AccessModePanel;
