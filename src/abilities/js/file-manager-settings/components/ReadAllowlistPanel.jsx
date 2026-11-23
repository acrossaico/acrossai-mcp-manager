/**
 * ReadAllowlistPanel — folder picker for the read allowlist with an
 * "unrestricted" toggle at the top.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.1.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import AllowlistTree from './AllowlistTree.jsx';

const AFFECTED_ABILITIES = [
	'file-manager/read-file',
	'file-manager/read-debug-log',
];

const UNAFFECTED_ABILITIES = [
	'file-manager/list-directory',
	'file-manager/file-info',
];

const ReadAllowlistPanel = ( { data, onSave } ) => {
	const [ paths, setPaths ]       = useState( data.allowed_paths || [] );
	const [ restrict, setRestrict ] = useState( ( data.allowed_paths || [] ).length > 0 );
	const [ saving, setSaving ]     = useState( false );
	const [ status, setStatus ]     = useState( '' );

	useEffect( () => {
		setPaths( data.allowed_paths || [] );
		setRestrict( ( data.allowed_paths || [] ).length > 0 );
	}, [ data ] );

	const doSave = () => {
		setSaving( true );
		setStatus( '' );
		const outgoing = restrict ? paths : [];
		onSave( outgoing )
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
		<section className="acrossai-fm-panel">
			<h2>{ __( 'Read access', 'acrossai-abilities-manager' ) }</h2>
			<p className="description">
				{ __(
					'By default the AI can read any file (with secrets scrubbed by the redactor below). Enable this toggle to restrict reads to specific folders instead.',
					'acrossai-abilities-manager'
				) }
			</p>

			<div className="acrossai-fm-affects">
				<strong>{ __( 'Affects these abilities:', 'acrossai-abilities-manager' ) }</strong>
				<ul className="acrossai-fm-affects-list">
					{ AFFECTED_ABILITIES.map( ( slug ) => (
						<li key={ slug }><code>{ slug }</code></li>
					) ) }
				</ul>
				<p className="description">
					{ __( 'Metadata-only abilities are NOT gated by the read allowlist:', 'acrossai-abilities-manager' ) }{ ' ' }
					{ UNAFFECTED_ABILITIES.map( ( slug, idx ) => (
						<span key={ slug }>
							{ idx > 0 && ', ' }
							<code>{ slug }</code>
						</span>
					) ) }.
				</p>
			</div>

			<p>
				<label>
					<input
						type="checkbox"
						checked={ restrict }
						onChange={ ( ev ) => setRestrict( ev.target.checked ) }
					/>{ ' ' }
					<strong>{ __( 'Restrict reads to specific folders', 'acrossai-abilities-manager' ) }</strong>
				</label>
			</p>

			<AllowlistTree
				value={ paths }
				available={ data.available || {} }
				onChange={ setPaths }
				disabled={ ! restrict }
			/>

			<p className="submit">
				<button type="button" className="button button-primary" disabled={ saving } onClick={ doSave }>
					{ saving ? __( 'Saving…', 'acrossai-abilities-manager' ) : __( 'Save read access', 'acrossai-abilities-manager' ) }
				</button>
				{ status && <span className="acrossai-fm-status"> { status }</span> }
			</p>
		</section>
	);
};

export default ReadAllowlistPanel;
