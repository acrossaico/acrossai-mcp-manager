/**
 * F069 — Step 2: create a new MCP server.
 *
 * Reached only when Step 1 signalled `create_intent: true`. If the user
 * picked an existing server in Step 1, App.jsx's skip effect jumps
 * step 2 → 3 and this component never renders.
 *
 * The form's Continue button lives in <StepLayout>, not here — Step 2
 * registers a `beforeAdvance` callback via useAdvanceGuard. When the user
 * clicks Continue, App.jsx awaits the callback which posts /step 2 with
 * `new_server`; a truthy return lets the router walk to Step 3.
 *
 * Uses vanilla HTML inputs (not DataForm) — only 5 fields, no filter/sort/
 * pagination concern.
 *
 * Slug auto-derived from Name via a JS sanitize_title shim; route auto-
 * derived from slug. Both are editable. The server-side
 * MCPServerFieldSanitizer applies the authoritative sanitize_title on
 * receive — this is a UX helper only.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import useWizardState from '../hooks/useWizardState.js';
import useAdvanceGuard from '../hooks/useAdvanceGuard.js';

const jsSanitizeTitle = ( input ) => {
	return String( input )
		.toLowerCase()
		.replace( /[^a-z0-9\-_]+/g, '-' )
		.replace( /^-+|-+$/g, '' )
		.replace( /-{2,}/g, '-' );
};

const Step2_ServerCreate = () => {
	const { saveStep } = useWizardState();
	const [ form, setForm ] = useState( {
		server_name: '',
		server_slug: '',
		description: '',
		server_route_namespace: 'mcp',
		server_route: '',
		server_version: 'v1.0.0',
	} );
	const [ slugTouched, setSlugTouched ] = useState( false );
	const [ routeTouched, setRouteTouched ] = useState( false );
	const [ localError, setLocalError ] = useState( null );

	const handleNameChange = ( value ) => {
		setForm( ( prev ) => ( {
			...prev,
			server_name: value,
			server_slug: slugTouched ? prev.server_slug : jsSanitizeTitle( value ),
			server_route: routeTouched
				? prev.server_route
				: jsSanitizeTitle( value ),
		} ) );
	};

	const handleSlugChange = ( value ) => {
		setSlugTouched( true );
		setForm( ( prev ) => ( {
			...prev,
			server_slug: value,
			server_route: routeTouched
				? prev.server_route
				: jsSanitizeTitle( value ),
		} ) );
	};

	// beforeAdvance runs when the user clicks the wizard's Continue button.
	// Returning falsy cancels the transition and keeps the user on this step.
	const handleBeforeAdvance = useCallback( async () => {
		setLocalError( null );
		if ( ! form.server_name.trim() ) {
			setLocalError(
				__( 'Server name is required.', 'acrossai-mcp-manager' )
			);
			return false;
		}
		const response = await saveStep( 2, { new_server: form } );
		return !! (
			response &&
			response.wizardState &&
			response.wizardState.server_id
		);
	}, [ form, saveStep ] );

	useAdvanceGuard(
		form.server_name.trim().length > 0,
		handleBeforeAdvance
	);

	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'Create a new MCP server', 'acrossai-mcp-manager' ) }
			</h2>
			<p className="qs__step-subtitle">
				{ __(
					'Fill in a name — the rest is auto-filled. Click Continue when you\'re done.',
					'acrossai-mcp-manager'
				) }
			</p>

			<div style={ { marginBottom: 16 } }>
				<label>
					<strong>{ __( 'Name', 'acrossai-mcp-manager' ) }</strong>
					<span style={ { color: '#cc1818', marginLeft: 4 } }>*</span>
					<input
						type="text"
						value={ form.server_name }
						onChange={ ( e ) => handleNameChange( e.target.value ) }
						required
						style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4 } }
					/>
				</label>
			</div>

			<div style={ { marginBottom: 16 } }>
				<label>
					<strong>{ __( 'Slug', 'acrossai-mcp-manager' ) }</strong>
					<input
						type="text"
						value={ form.server_slug }
						onChange={ ( e ) => handleSlugChange( e.target.value ) }
						style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4, fontFamily: 'monospace' } }
					/>
				</label>
			</div>

			<div style={ { marginBottom: 16 } }>
				<label>
					<strong>{ __( 'Description', 'acrossai-mcp-manager' ) }</strong>
					<textarea
						value={ form.description }
						onChange={ ( e ) =>
							setForm( ( p ) => ( { ...p, description: e.target.value } ) )
						}
						rows={ 3 }
						style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4 } }
					/>
				</label>
			</div>

			<div style={ { display: 'flex', gap: 12, marginBottom: 16 } }>
				<label style={ { flex: 1 } }>
					<strong>{ __( 'Route namespace', 'acrossai-mcp-manager' ) }</strong>
					<input
						type="text"
						value={ form.server_route_namespace }
						onChange={ ( e ) =>
							setForm( ( p ) => ( {
								...p,
								server_route_namespace: e.target.value,
							} ) )
						}
						style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4, fontFamily: 'monospace' } }
					/>
				</label>
				<label style={ { flex: 1 } }>
					<strong>{ __( 'Route', 'acrossai-mcp-manager' ) }</strong>
					<input
						type="text"
						value={ form.server_route }
						onChange={ ( e ) => {
							setRouteTouched( true );
							setForm( ( p ) => ( { ...p, server_route: e.target.value } ) );
						} }
						style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4, fontFamily: 'monospace' } }
					/>
				</label>
				<label style={ { flex: '0 0 120px' } }>
					<strong>{ __( 'Version', 'acrossai-mcp-manager' ) }</strong>
					<input
						type="text"
						value={ form.server_version }
						onChange={ ( e ) =>
							setForm( ( p ) => ( { ...p, server_version: e.target.value } ) )
						}
						style={ { display: 'block', width: '100%', padding: '8px', marginTop: 4, fontFamily: 'monospace' } }
					/>
				</label>
			</div>

			{ localError && (
				<div style={ { color: '#cc1818', marginBottom: 16 } } role="alert">
					{ localError }
				</div>
			) }
		</div>
	);
};

export default Step2_ServerCreate;
