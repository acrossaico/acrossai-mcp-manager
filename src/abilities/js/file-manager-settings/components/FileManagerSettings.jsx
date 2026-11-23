/**
 * FileManagerSettings — top-level orchestrator.
 *
 * Loads the five settings endpoints in parallel and renders five panels.
 * Each panel manages its own local state and posts back to its endpoint
 * on Save.
 *
 *   1. Write allowlist   → /file-manager-settings/write-allowlist
 *   2. Read allowlist    → /file-manager-settings/read-allowlist
 *   3. Redaction         → /file-manager-settings/redaction
 *   4. Content filters   → /file-manager-settings/content-filters  (scaffold — feature 093)
 *   5. Backup + audit    → /file-manager-settings/backup-audit     (scaffold — feature 094)
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.1.0
 */

import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import WriteAllowlistPanel from './WriteAllowlistPanel.jsx';
import ReadAllowlistPanel from './ReadAllowlistPanel.jsx';
import RedactionPanel from './RedactionPanel.jsx';
import ContentFiltersPanel from './ContentFiltersPanel.jsx';
import BackupAuditPanel from './BackupAuditPanel.jsx';

const FileManagerSettings = ( { restNamespace, restBase } ) => {
	const base = `/${ restNamespace }/${ restBase }`;

	const [ writeData, setWriteData ]         = useState( null );
	const [ readData, setReadData ]           = useState( null );
	const [ redaction, setRedaction ]         = useState( null );
	const [ contentFilters, setContentFilters ] = useState( null );
	const [ backupAudit, setBackupAudit ]     = useState( null );
	const [ loading, setLoading ]             = useState( true );
	const [ error, setError ]                 = useState( null );

	useEffect( () => {
		let cancelled = false;
		Promise.all( [
			apiFetch( { path: `${ base }/write-allowlist` } ),
			apiFetch( { path: `${ base }/read-allowlist` } ),
			apiFetch( { path: `${ base }/redaction` } ),
			apiFetch( { path: `${ base }/content-filters` } ),
			apiFetch( { path: `${ base }/backup-audit` } ),
		] )
			.then( ( [ w, r, red, cf, ba ] ) => {
				if ( cancelled ) {
					return;
				}
				setWriteData( w );
				setReadData( r );
				setRedaction( red );
				setContentFilters( cf );
				setBackupAudit( ba );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setError( err.message || __( 'Failed to load settings.', 'acrossai-abilities-manager' ) );
				setLoading( false );
			} );
		return () => {
			cancelled = true;
		};
	}, [ base ] );

	if ( loading ) {
		return <p>{ __( 'Loading settings…', 'acrossai-abilities-manager' ) }</p>;
	}
	if ( error ) {
		return (
			<div className="notice notice-error inline">
				<p>{ error }</p>
			</div>
		);
	}

	const saveWrite = ( allowed_paths ) =>
		apiFetch( {
			path: `${ base }/write-allowlist`,
			method: 'POST',
			data: { allowed_paths },
		} ).then( setWriteData );

	const saveRead = ( allowed_paths ) =>
		apiFetch( {
			path: `${ base }/read-allowlist`,
			method: 'POST',
			data: { allowed_paths },
		} ).then( setReadData );

	const saveRedaction = ( config ) =>
		apiFetch( {
			path: `${ base }/redaction`,
			method: 'POST',
			data: config,
		} ).then( setRedaction );

	const saveContentFilters = ( payload ) =>
		apiFetch( {
			path: `${ base }/content-filters`,
			method: 'POST',
			data: payload,
		} ).then( setContentFilters );

	const saveBackupAudit = ( payload ) =>
		apiFetch( {
			path: `${ base }/backup-audit`,
			method: 'POST',
			data: payload,
		} ).then( setBackupAudit );

	return (
		<div className="acrossai-file-manager-settings">
			<div className="acrossai-fm-intro">
				<p>{ __(
					'Control which folders the AI can write to, which folders it can read, which secrets get scrubbed from every read response, and — coming soon — extra content filters and a pre-write backup + audit log.',
					'acrossai-abilities-manager'
				) }</p>
			</div>

			<WriteAllowlistPanel data={ writeData } onSave={ saveWrite } />
			<ReadAllowlistPanel data={ readData } onSave={ saveRead } />
			<RedactionPanel data={ redaction } onSave={ saveRedaction } />
			<ContentFiltersPanel data={ contentFilters } onSave={ saveContentFilters } />
			<BackupAuditPanel data={ backupAudit } onSave={ saveBackupAudit } statsPath={ `${ base }/backup-audit-stats` } />
		</div>
	);
};

export default FileManagerSettings;
