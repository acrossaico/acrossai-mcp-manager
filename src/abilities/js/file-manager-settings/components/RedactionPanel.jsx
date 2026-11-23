/**
 * RedactionPanel — toggle built-in secret patterns and add custom literals.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.1.0
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const AFFECTED_ABILITIES = [
	'file-manager/read-file',
	'file-manager/read-debug-log',
];

const RedactionPanel = ( { data, onSave } ) => {
	const [ patterns, setPatterns ] = useState( () => ( data.config?.patterns || {} ) );
	const [ literals, setLiterals ] = useState(
		() => ( data.config?.custom_literals || [] ).join( '\n' )
	);
	const [ saving, setSaving ]     = useState( false );
	const [ status, setStatus ]     = useState( '' );

	useEffect( () => {
		setPatterns( data.config?.patterns || {} );
		setLiterals( ( data.config?.custom_literals || [] ).join( '\n' ) );
	}, [ data ] );

	const togglePattern = ( id, next ) => {
		setPatterns( ( prev ) => ( { ...prev, [ id ]: next } ) );
	};

	const doSave = () => {
		setSaving( true );
		setStatus( '' );
		const custom_literals = literals
			.split( /\r?\n/ )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		onSave( { patterns, custom_literals } )
			.then( () => {
				setSaving( false );
				setStatus( __( 'Saved.', 'acrossai-abilities-manager' ) );
			} )
			.catch( ( err ) => {
				setSaving( false );
				setStatus( err.message || __( 'Save failed.', 'acrossai-abilities-manager' ) );
			} );
	};

	const available          = data.available || {};
	const connectorSources   = data.connector_sources || {};

	return (
		<section className="acrossai-fm-panel">
			<h2>{ __( 'Secret redaction', 'acrossai-abilities-manager' ) }</h2>
			<p className="description">
				{ __(
					'Every file the AI reads is scrubbed for the WordPress credentials in wp-config.php by default. If the WordPress AI plugin is installed, its OpenAI, Anthropic, and Google API keys are automatically scrubbed too — no action needed. Add any other secrets you want stripped from read responses as custom literal strings below.',
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
					{ __( 'The wp-config-specific ability (file-manager/read-wp-config) and get-wp-config-constant already redact their output separately — they are not affected by this configuration.', 'acrossai-abilities-manager' ) }
				</p>
			</div>

			<h3>{ __( 'Built-in patterns', 'acrossai-abilities-manager' ) }</h3>
			<ul className="acrossai-fm-patterns">
				{ Object.entries( available ).map( ( [ id, info ] ) => (
					<li key={ id }>
						<label>
							<input
								type="checkbox"
								checked={ !! patterns[ id ] }
								onChange={ ( ev ) => togglePattern( id, ev.target.checked ) }
							/>{ ' ' }
							<strong>{ info.label }</strong>
						</label>
						<p className="description">{ info.description }</p>
					</li>
				) ) }
			</ul>

			<h3>{ __( 'AI connector API keys (auto-scrubbed)', 'acrossai-abilities-manager' ) }</h3>
			<p className="description">
				{ __(
					'When the WordPress AI plugin stores an API key for one of these providers, its value is added to the redactor automatically. No configuration needed here — this section only shows current status.',
					'acrossai-abilities-manager'
				) }
			</p>
			<ul className="acrossai-fm-connectors">
				{ Object.entries( connectorSources ).map( ( [ id, info ] ) => (
					<li key={ id }>
						<strong>{ info.label }</strong>{ ' ' }
						<span className={ info.present ? 'acrossai-fm-badge is-active' : 'acrossai-fm-badge is-inactive' }>
							{ info.present
								? __( 'key detected — will be scrubbed', 'acrossai-abilities-manager' )
								: __( 'no key set', 'acrossai-abilities-manager' ) }
						</span>{ ' ' }
						<code>({ info.option })</code>
					</li>
				) ) }
			</ul>

			<h3>{ __( 'Custom literal strings', 'acrossai-abilities-manager' ) }</h3>
			<p className="description">
				{ __(
					'One string per line. Any exact match in read content is replaced with ***REDACTED***.',
					'acrossai-abilities-manager'
				) }
			</p>
			<textarea
				className="widefat code"
				rows={ 5 }
				value={ literals }
				onChange={ ( ev ) => setLiterals( ev.target.value ) }
				placeholder="my-secret-abc"
			/>

			<p className="submit">
				<button type="button" className="button button-primary" disabled={ saving } onClick={ doSave }>
					{ saving ? __( 'Saving…', 'acrossai-abilities-manager' ) : __( 'Save redaction settings', 'acrossai-abilities-manager' ) }
				</button>
				{ status && <span className="acrossai-fm-status"> { status }</span> }
			</p>
		</section>
	);
};

export default RedactionPanel;
