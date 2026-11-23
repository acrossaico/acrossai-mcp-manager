/**
 * WriteAllowlistPanel — folder picker for the write allowlist.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.1.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import AllowlistTree from './AllowlistTree.jsx';

const AFFECTED_ABILITIES = [
	'file-manager/create-file',
	'file-manager/edit-file',
	'file-manager/delete-file',
	'file-manager/copy-file',
	'file-manager/move-file',
	'file-manager/append-file',
	'file-manager/create-directory',
	'file-manager/delete-directory',
];

const WriteAllowlistPanel = ( { data, onSave } ) => {
	const [ paths, setPaths ]   = useState( data.allowed_paths || [] );
	const [ saving, setSaving ] = useState( false );
	const [ status, setStatus ] = useState( '' );

	useEffect( () => {
		setPaths( data.allowed_paths || [] );
	}, [ data ] );

	const doSave = () => {
		setSaving( true );
		setStatus( '' );
		onSave( paths )
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
			<h2>{ __( 'Write access', 'acrossai-abilities-manager' ) }</h2>
			<p className="description">
				{ __(
					'Pick which folders the AI is allowed to modify (create, edit, delete, copy, move, append). Everything else returns blocked. Default: wp-content and everything below it.',
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
					{ __( 'Every other file-manager/* ability (metadata reads, zip backups, wp-config wrappers) is not affected by the write allowlist.', 'acrossai-abilities-manager' ) }
				</p>
			</div>

			<AllowlistTree value={ paths } available={ data.available || {} } onChange={ setPaths } />

			<p className="submit">
				<button type="button" className="button button-primary" disabled={ saving } onClick={ doSave }>
					{ saving ? __( 'Saving…', 'acrossai-abilities-manager' ) : __( 'Save write access', 'acrossai-abilities-manager' ) }
				</button>
				{ status && <span className="acrossai-fm-status"> { status }</span> }
			</p>
		</section>
	);
};

export default WriteAllowlistPanel;
