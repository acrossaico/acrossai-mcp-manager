/**
 * QueryLimitsPanel — row cap, time cap, redaction and mutation logging.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.0.45
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const QueryLimitsPanel = ( { data, onSave } ) => {
	const [ form, setForm ]     = useState( () => ( { ...data.policy } ) );
	const [ saving, setSaving ] = useState( false );
	const [ status, setStatus ] = useState( '' );

	useEffect( () => {
		setForm( { ...data.policy } );
	}, [ data ] );

	const set = ( key, value ) => setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const doSave = () => {
		setSaving( true );
		setStatus( '' );
		onSave( {
			max_rows: parseInt( form.max_rows, 10 ) || data.defaults.max_rows,
			max_seconds: parseInt( form.max_seconds, 10 ) || 0,
			redact_values: !! form.redact_values,
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

	return (
		<div className="acrossai-db-panel">
			<h2>{ __( 'Query limits and handling', 'acrossai-abilities-manager' ) }</h2>

			<table className="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label htmlFor="acrossai-db-max-rows">
								{ __( 'Maximum rows per query', 'acrossai-abilities-manager' ) }
							</label>
						</th>
						<td>
							<input
								id="acrossai-db-max-rows"
								type="number"
								min="1"
								max="10000"
								className="small-text"
								value={ form.max_rows }
								onChange={ ( e ) => set( 'max_rows', e.target.value ) }
							/>
							<p className="description">
								{ __(
									'A ceiling, not a default. An ability asking for fewer rows gets fewer; one asking for more gets this. Enforced by reading at most this many rows, rather than by appending LIMIT to the query — which can silently miss.',
									'acrossai-abilities-manager'
								) }
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label htmlFor="acrossai-db-max-seconds">
								{ __( 'Query time limit', 'acrossai-abilities-manager' ) }
							</label>
						</th>
						<td>
							<input
								id="acrossai-db-max-seconds"
								type="number"
								min="0"
								max="300"
								className="small-text"
								value={ form.max_seconds }
								onChange={ ( e ) => set( 'max_seconds', e.target.value ) }
							/>
							<span> { __( 'seconds', 'acrossai-abilities-manager' ) }</span>
							<p className="description">
								{ __( '0 means no limit. A query that exceeds it is stopped and reported as stopped, not returned as an empty result.', 'acrossai-abilities-manager' ) }
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">{ __( 'Returned values', 'acrossai-abilities-manager' ) }</th>
						<td>
							<label>
								<input
									type="checkbox"
									checked={ !! form.redact_values }
									onChange={ ( e ) => set( 'redact_values', e.target.checked ) }
								/>
								{ __( 'Redact secrets in query results', 'acrossai-abilities-manager' ) }
							</label>
							<p className="description">
								{ __( 'Applies the custom literals and AI connector keys from the File Manager tab to values returned by queries. Its built-in WordPress-credentials pattern matches the wp-config.php shape and so does not apply to table values — add anything you want masked in table output to the custom literals list.', 'acrossai-abilities-manager' ) }
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<p>
				<button type="button" className="button button-primary" disabled={ saving } onClick={ doSave }>
					{ saving ? __( 'Saving…', 'acrossai-abilities-manager' ) : __( 'Save limits', 'acrossai-abilities-manager' ) }
				</button>
				{ status && <span className="acrossai-db-panel__status"> { status }</span> }
			</p>
		</div>
	);
};

export default QueryLimitsPanel;
