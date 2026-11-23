/**
 * ContentFiltersPanel — extension blocklist, filename filters, size cap,
 * sensitive-read denylist.
 *
 * SCAFFOLD ONLY. Saves persist to wp_options but no file-manager ability
 * consults these keys yet — that lands in feature 093. The panel renders
 * a prominent notice so admins don't mistake saved values for active
 * enforcement.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.1.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const AFFECTED_ABILITIES = [
	'file-manager/create-file',
	'file-manager/edit-file',
	'file-manager/append-file',
	'file-manager/copy-file',
	'file-manager/move-file',
	'file-manager/read-file',
];

const ContentFiltersPanel = ( { data, onSave } ) => {
	const cfg = data.config || {};

	const [ dangerous, setDangerous ]           = useState( () => ( cfg.dangerous_extensions || [] ).join( ', ' ) );
	const [ blockDouble, setBlockDouble ]       = useState( !! cfg.block_double_extensions );
	const [ htaccessScan, setHtaccessScan ]     = useState( !! cfg.htaccess_directive_scan );
	const [ sanitizeName, setSanitizeName ]     = useState( !! cfg.sanitize_filename_check );
	const [ writeMax, setWriteMax ]             = useState( cfg.write_max_bytes || 10485760 );
	const [ denylist, setDenylist ]             = useState( () => ( cfg.sensitive_read_denylist || [] ).join( '\n' ) );
	const [ strictName, setStrictName ]         = useState( !! cfg.strict_filename_filter );
	const [ mimeCheck, setMimeCheck ]           = useState( !! cfg.mime_type_check );
	const [ saving, setSaving ]                 = useState( false );
	const [ status, setStatus ]                 = useState( '' );
	const [ skipped, setSkipped ]               = useState( [] );

	useEffect( () => {
		const c = data.config || {};
		setDangerous( ( c.dangerous_extensions || [] ).join( ', ' ) );
		setBlockDouble( !! c.block_double_extensions );
		setHtaccessScan( !! c.htaccess_directive_scan );
		setSanitizeName( !! c.sanitize_filename_check );
		setWriteMax( c.write_max_bytes || 10485760 );
		setDenylist( ( c.sensitive_read_denylist || [] ).join( '\n' ) );
		setStrictName( !! c.strict_filename_filter );
		setMimeCheck( !! c.mime_type_check );
		setSkipped( data.skipped || [] );
	}, [ data ] );

	// Human-readable rejection reasons — kept in sync with Hardening_Settings.
	const reasonLabel = ( r ) => {
		switch ( r ) {
			case 'invalid_format':
				return __( 'must be lowercase a–z 0–9, up to 16 chars, no dots or spaces', 'acrossai-abilities-manager' );
			case 'path_segment_not_allowed':
				return __( 'basenames only — no `/` or `\\` allowed', 'acrossai-abilities-manager' );
			case 'control_char':
				return __( 'contains a NUL or control character', 'acrossai-abilities-manager' );
			case 'duplicate':
				return __( 'duplicate of another entry in the same list', 'acrossai-abilities-manager' );
			case 'list_cap_reached':
				return __( 'exceeds the per-list entry limit', 'acrossai-abilities-manager' );
			case 'not_a_string':
				return __( 'not a string value', 'acrossai-abilities-manager' );
			default:
				return r;
		}
	};

	const fieldLabel = ( f ) => {
		switch ( f ) {
			case 'dangerous_extensions':
				return __( 'Extension blocklist', 'acrossai-abilities-manager' );
			case 'sensitive_read_denylist':
				return __( 'Sensitive-read denylist', 'acrossai-abilities-manager' );
			default:
				return f;
		}
	};

	const doSave = () => {
		setSaving( true );
		setStatus( '' );
		setSkipped( [] );
		// Deliberately DON'T pre-filter here — send everything the admin
		// typed and let the backend sanitiser report which entries got
		// dropped. Pre-filtering would silently hide bad input.
		const dangerous_extensions = dangerous
			.split( /[,\n]+/ )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		const sensitive_read_denylist = denylist
			.split( /\r?\n/ )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		onSave( {
			dangerous_extensions,
			block_double_extensions: blockDouble,
			htaccess_directive_scan: htaccessScan,
			sanitize_filename_check: sanitizeName,
			write_max_bytes: parseInt( writeMax, 10 ) || 10485760,
			sensitive_read_denylist,
			strict_filename_filter: strictName,
			mime_type_check: mimeCheck,
		} )
			.then( ( resp ) => {
				setSaving( false );
				const drops = resp?.skipped || [];
				if ( drops.length ) {
					setSkipped( drops );
					setStatus( sprintf(
						/* translators: %d: number of dropped entries */
						__( 'Saved with %d entry dropped.', 'acrossai-abilities-manager' ),
						drops.length
					) );
				} else {
					setStatus( __( 'Saved.', 'acrossai-abilities-manager' ) );
				}
			} )
			.catch( ( err ) => {
				setSaving( false );
				setStatus( err.message || __( 'Save failed.', 'acrossai-abilities-manager' ) );
			} );
	};

	const writeMaxMB = ( parseInt( writeMax, 10 ) || 0 ) / ( 1024 * 1024 );

	return (
		<section className="acrossai-fm-panel">
			<h2>{ __( 'Content filters', 'acrossai-abilities-manager' ) }</h2>

			<div className="notice notice-info inline" style={ { padding: '8px 12px', marginTop: 0 } }>
				<p style={ { margin: 0 } }>
					{ __( 'This list now gates create-file / edit-file / append-file / copy-file / move-file. Saved values take effect on the next ability call.', 'acrossai-abilities-manager' ) }
				</p>
			</div>

			<div className="acrossai-fm-affects">
				<ul className="acrossai-fm-affects-list">
					{ AFFECTED_ABILITIES.map( ( slug ) => (
						<li key={ slug }><code>{ slug }</code></li>
					) ) }
				</ul>
			</div>

			<h3>{ __( 'Extension blocklist', 'acrossai-abilities-manager' ) }</h3>
			<p className="description">
				{ __( 'Comma-separated extensions (no leading dot). Writes to files matching any of these are refused.', 'acrossai-abilities-manager' ) }
			</p>
			<input
				type="text"
				className="widefat code"
				value={ dangerous }
				onChange={ ( ev ) => setDangerous( ev.target.value ) }
				placeholder="exe, sh, bat, phar"
			/>

			<h3>{ __( 'Filename filters', 'acrossai-abilities-manager' ) }</h3>
			<ul className="acrossai-fm-toggles">
				<li>
					<label>
						<input type="checkbox" checked={ blockDouble } onChange={ ( ev ) => setBlockDouble( ev.target.checked ) } />{ ' ' }
						<strong>{ __( 'Block double extensions', 'acrossai-abilities-manager' ) }</strong>
					</label>
					<p className="description">{ __( 'Refuse writes with names like foo.php.jpg where a PHP-like extension is followed by another.', 'acrossai-abilities-manager' ) }</p>
				</li>
				<li>
					<label>
						<input type="checkbox" checked={ htaccessScan } onChange={ ( ev ) => setHtaccessScan( ev.target.checked ) } />{ ' ' }
						<strong>{ __( 'Scan .htaccess content', 'acrossai-abilities-manager' ) }</strong>
					</label>
					<p className="description">{ __( 'Refuse .htaccess writes containing AddType, SetHandler, php_value, php_flag, auto_prepend, or auto_append directives.', 'acrossai-abilities-manager' ) }</p>
				</li>
				<li>
					<label>
						<input type="checkbox" checked={ sanitizeName } onChange={ ( ev ) => setSanitizeName( ev.target.checked ) } />{ ' ' }
						<strong>{ __( 'Sanitize filename check', 'acrossai-abilities-manager' ) }</strong>
					</label>
					<p className="description">{ __( 'Refuse writes when WordPress\'s sanitize_file_name() would rename the target — a proxy for suspicious characters.', 'acrossai-abilities-manager' ) }</p>
				</li>
				<li>
					<label>
						<input type="checkbox" checked={ strictName } onChange={ ( ev ) => setStrictName( ev.target.checked ) } />{ ' ' }
						<strong>{ __( 'Strict filename filter (webshell markers)', 'acrossai-abilities-manager' ) }</strong>
					</label>
					<p className="description">{ __( 'Refuse writes whose basename contains any of c99, r57, wso, b374k, weevely, shell, alfa, bypass, backdoor. May produce false positives — off by default.', 'acrossai-abilities-manager' ) }</p>
				</li>
				<li>
					<label>
						<input type="checkbox" checked={ mimeCheck } onChange={ ( ev ) => setMimeCheck( ev.target.checked ) } />{ ' ' }
						<strong>{ __( 'MIME type check', 'acrossai-abilities-manager' ) }</strong>
					</label>
					<p className="description">{ __( 'Validate write extensions against wp_check_filetype() / get_allowed_mime_types(). Blocks unusual formats — off by default.', 'acrossai-abilities-manager' ) }</p>
				</li>
			</ul>

			<h3>{ __( 'Write size cap', 'acrossai-abilities-manager' ) }</h3>
			<p className="description">
				{ sprintf(
					/* translators: %s: human-readable size */
					__( 'Refuse writes larger than this many bytes. Current: %s MiB.', 'acrossai-abilities-manager' ),
					writeMaxMB.toFixed( 2 )
				) }
			</p>
			<input
				type="number"
				min={ data.limits?.write_max_bytes_min || 1024 }
				max={ data.limits?.write_max_bytes_max || 104857600 }
				step="1024"
				value={ writeMax }
				onChange={ ( ev ) => setWriteMax( ev.target.value ) }
				className="regular-text code"
			/>

			<h3>{ __( 'Sensitive-read denylist', 'acrossai-abilities-manager' ) }</h3>
			<p className="description">
				{ __( 'One basename per line. Reads of files matching any entry are refused even when the read allowlist would otherwise permit them. Use *.ext for extension globs (e.g. *.key).', 'acrossai-abilities-manager' ) }
			</p>
			<textarea
				className="widefat code"
				rows={ 6 }
				value={ denylist }
				onChange={ ( ev ) => setDenylist( ev.target.value ) }
				placeholder=".env&#10;id_rsa&#10;*.key"
			/>

			<p className="submit">
				<button type="button" className="button button-primary" disabled={ saving } onClick={ doSave }>
					{ saving ? __( 'Saving…', 'acrossai-abilities-manager' ) : __( 'Save content filters', 'acrossai-abilities-manager' ) }
				</button>
				{ status && <span className="acrossai-fm-status"> { status }</span> }
			</p>

			{ skipped.length > 0 && (
				<div className="notice notice-warning inline" style={ { padding: '8px 12px' } }>
					<p style={ { margin: '0 0 6px' } }>
						<strong>
							{ sprintf(
								/* translators: %d: number of skipped entries */
								__( '%d entry did not save:', 'acrossai-abilities-manager' ),
								skipped.length
							) }
						</strong>
					</p>
					<ul style={ { margin: '0 0 0 1.5em', listStyle: 'disc' } }>
						{ skipped.map( ( s, i ) => (
							<li key={ i }>
								<code>{ s.value }</code>
								{ ' — ' }
								<em>{ fieldLabel( s.field ) }</em>
								{ ': ' }
								{ reasonLabel( s.reason ) }
							</li>
						) ) }
					</ul>
				</div>
			) }
		</section>
	);
};

export default ContentFiltersPanel;
