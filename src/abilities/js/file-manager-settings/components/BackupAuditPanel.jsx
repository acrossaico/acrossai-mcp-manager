/**
 * BackupAuditPanel — enable/disable pre-write backups + audit log, plus
 * retention windows for each.
 *
 * Feature 094 (2026-08-26) went live: enforcement now writes backups and
 * emits log entries when the toggles are on. Panel drops its scaffold
 * banner and shows an info line sourced from the /backup-audit-stats
 * endpoint with the current log path + line count + backup dir size.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.1.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const AFFECTED_ABILITIES = [
	'file-manager/create-file',
	'file-manager/edit-file',
	'file-manager/delete-file',
	'file-manager/append-file',
	'file-manager/copy-file',
	'file-manager/move-file',
	'file-manager/create-directory',
	'file-manager/delete-directory',
	'file-manager/edit-wp-config',
	'file-manager/clear-debug-log',
];

const formatBytes = ( bytes ) => {
	if ( ! bytes ) {
		return '0 B';
	}
	if ( bytes < 1024 ) {
		return bytes + ' B';
	}
	if ( bytes < 1024 * 1024 ) {
		return ( bytes / 1024 ).toFixed( 1 ) + ' KiB';
	}
	if ( bytes < 1024 * 1024 * 1024 ) {
		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MiB';
	}
	return ( bytes / ( 1024 * 1024 * 1024 ) ).toFixed( 2 ) + ' GiB';
};

const BackupAuditPanel = ( { data, onSave, statsPath } ) => {
	const cfg = data.config || {};

	const [ auditEnabled, setAuditEnabled ]     = useState( !! cfg.audit_log_enabled );
	const [ auditRetention, setAuditRetention ] = useState( cfg.audit_log_retention_days || 7 );
	const [ backupEnabled, setBackupEnabled ]   = useState( !! cfg.backup_enabled );
	const [ backupRetention, setBackupRetention ] = useState( cfg.backup_retention_days || 7 );
	const [ saving, setSaving ]                 = useState( false );
	const [ status, setStatus ]                 = useState( '' );
	const [ stats, setStats ]                   = useState( null );

	useEffect( () => {
		const c = data.config || {};
		setAuditEnabled( !! c.audit_log_enabled );
		setAuditRetention( c.audit_log_retention_days || 7 );
		setBackupEnabled( !! c.backup_enabled );
		setBackupRetention( c.backup_retention_days || 7 );
	}, [ data ] );

	useEffect( () => {
		if ( ! statsPath ) {
			return;
		}
		let cancelled = false;
		apiFetch( { path: statsPath } )
			.then( ( result ) => {
				if ( ! cancelled ) {
					setStats( result );
				}
			} )
			.catch( () => {
				/* stats are informational; failures are silent */
			} );
		return () => {
			cancelled = true;
		};
	}, [ statsPath, auditEnabled, backupEnabled ] );

	const doSave = () => {
		setSaving( true );
		setStatus( '' );
		onSave( {
			audit_log_enabled: auditEnabled,
			audit_log_retention_days: parseInt( auditRetention, 10 ) || 7,
			backup_enabled: backupEnabled,
			backup_retention_days: parseInt( backupRetention, 10 ) || 7,
		} )
			.then( () => {
				setSaving( false );
				setStatus( __( 'Saved.', 'acrossai-abilities-manager' ) );
			} )
			.catch( ( err ) => {
				setSaving( false );
				setStatus( err.message || __( 'Save failed.', 'acrossai-abilities-manager' ) );
			} );
	};

	const retMin = data.limits?.retention_days_min || 1;
	const retMax = data.limits?.retention_days_max || 90;

	return (
		<section className="acrossai-fm-panel">
			<h2>{ __( 'Backup & audit log', 'acrossai-abilities-manager' ) }</h2>

			<div className="notice notice-info inline" style={ { padding: '8px 12px', marginTop: 0 } }>
				<p style={ { margin: 0 } }>
					{ __( 'Backup + audit are now live. Toggles save into the database and take effect on the very next ability call.', 'acrossai-abilities-manager' ) }
					{ stats && (
						<>
							<br />
							<strong>{ __( 'Current storage:', 'acrossai-abilities-manager' ) }</strong>{ ' ' }
							{ sprintf(
								/* translators: 1: number of log entries, 2: backup dir human size, 3: number of day dirs */
								__( '%1$d log entries; %2$s across %3$d backup day(s).', 'acrossai-abilities-manager' ),
								stats.log_total_lines || 0,
								formatBytes( stats.backup_total_size_bytes || 0 ),
								stats.backup_days_present || 0
							) }
						</>
					) }
				</p>
			</div>

			<div className="acrossai-fm-affects">
				<strong>{ __( 'Affects these abilities:', 'acrossai-abilities-manager' ) }</strong>
				<ul className="acrossai-fm-affects-list">
					{ AFFECTED_ABILITIES.map( ( slug ) => (
						<li key={ slug }><code>{ slug }</code></li>
					) ) }
				</ul>
			</div>

			<h3>{ __( 'Pre-write backups', 'acrossai-abilities-manager' ) }</h3>
			<p className="description">
				{ __( 'When enabled, every mutation (edit, overwrite, delete, append, move, copy) writes a copy of the pre-image into wp-content/acrossai-file-manager-backups/<YYYY-MM-DD>/ before the change lands. Replaces the current inline `.bak.<timestamp>` behaviour of delete-file.', 'acrossai-abilities-manager' ) }
			</p>
			<label>
				<input type="checkbox" checked={ backupEnabled } onChange={ ( ev ) => setBackupEnabled( ev.target.checked ) } />{ ' ' }
				<strong>{ __( 'Write pre-image backups on every mutation', 'acrossai-abilities-manager' ) }</strong>
			</label>
			<p>
				<label>
					{ __( 'Delete backup folders older than', 'acrossai-abilities-manager' ) }{ ' ' }
					<input
						type="number"
						min={ retMin }
						max={ retMax }
						value={ backupRetention }
						onChange={ ( ev ) => setBackupRetention( ev.target.value ) }
						className="small-text"
						disabled={ ! backupEnabled }
					/>{ ' ' }
					{ __( 'days.', 'acrossai-abilities-manager' ) }
				</label>
			</p>

			<h3>{ __( 'Audit log', 'acrossai-abilities-manager' ) }</h3>
			<p className="description">
				{ __( 'When enabled, every mutation appends one entry to wp-content/acrossai-file-manager.log — timestamp, ability slug, user, IP, size delta, and (if backups are on) the backup path. Callers can also pass an optional context string on each write to explain the change.', 'acrossai-abilities-manager' ) }
			</p>
			<label>
				<input type="checkbox" checked={ auditEnabled } onChange={ ( ev ) => setAuditEnabled( ev.target.checked ) } />{ ' ' }
				<strong>{ __( 'Log every file-manager mutation', 'acrossai-abilities-manager' ) }</strong>
			</label>
			<p>
				<label>
					{ __( 'Trim entries older than', 'acrossai-abilities-manager' ) }{ ' ' }
					<input
						type="number"
						min={ retMin }
						max={ retMax }
						value={ auditRetention }
						onChange={ ( ev ) => setAuditRetention( ev.target.value ) }
						className="small-text"
						disabled={ ! auditEnabled }
					/>{ ' ' }
					{ __( 'days.', 'acrossai-abilities-manager' ) }
				</label>
			</p>

			<p className="submit">
				<button type="button" className="button button-primary" disabled={ saving } onClick={ doSave }>
					{ saving ? __( 'Saving…', 'acrossai-abilities-manager' ) : __( 'Save backup & audit settings', 'acrossai-abilities-manager' ) }
				</button>
				{ status && <span className="acrossai-fm-status"> { status }</span> }
			</p>
		</section>
	);
};

export default BackupAuditPanel;
