/**
 * F069 — Step 11: MCP Client detail (pill row + JSON config).
 *
 * Only shown when user picked "MCP Client" on Step 7.
 *
 * Reads state.methods.clients (populated by T020 from
 * ConnectionMethodRegistry::get_all()). Client count derived from DTO
 * array — NOT hardcoded — so companion plugins that register additional
 * AbstractMCPClient subclasses via the acrossai_mcp_client_classes filter
 * (per DEC-F034) auto-appear as extra pills.
 *
 * Selecting a pill reveals a <CodeBlock variant="pane"> with the JSON
 * config for that client. Config template comes from the DTO's `config`
 * field (WordPress `home_url()` + server slug substituted by
 * ConnectionMethodRegistry at read time).
 *
 * F073 — Adds Config File + Top-Level Key readonly inputs, per-client
 * Instructions + shared Access Control paragraph, and an in-wizard
 * Generate New Application Password button (POSTs to the existing
 * /generate-app-password REST endpoint with a wizard-scoped name).
 *
 * @package AcrossAI_MCP_Manager
 */

import { useState, useMemo, useCallback, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useCopyToClipboard } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';
import CodeBlock from '../components/CodeBlock.jsx';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';

const Step11_ClientDetail = () => {
	const { state } = useWizardState();
	const clients = state.methods.clients || [];
	const [ activeSlug, setActiveSlug ] = useState(
		clients[ 0 ] ? clients[ 0 ].slug : ''
	);

	// Generated-password state — reset whenever the operator switches client
	// pills so a stale reveal from a prior client can't be mistaken for the
	// currently-picked one.
	const [ generating, setGenerating ] = useState( false );
	const [ generatedPassword, setGeneratedPassword ] = useState( '' );
	const [ generateError, setGenerateError ] = useState( null );
	const [ copied, setCopied ] = useState( false );

	useEffect( () => {
		setGeneratedPassword( '' );
		setGenerateError( null );
		setCopied( false );
	}, [ activeSlug ] );

	useEffect( () => {
		if ( ! copied ) return undefined;
		const t = setTimeout( () => setCopied( false ), 2000 );
		return () => clearTimeout( t );
	}, [ copied ] );

	const copyRef = useCopyToClipboard(
		generatedPassword,
		() => setCopied( true )
	);

	const server = useMemo(
		() =>
			state.servers.find( ( s ) => s.id === state.wizardState.server_id ),
		[ state.servers, state.wizardState.server_id ]
	);

	const activeClient = useMemo(
		() => clients.find( ( c ) => c.slug === activeSlug ),
		[ clients, activeSlug ]
	);

	const handleGenerate = useCallback( async () => {
		if ( ! server || ! activeClient ) return;
		setGenerating( true );
		setGenerateError( null );
		setGeneratedPassword( '' );
		try {
			const response = await apiFetch( {
				path: '/acrossai-mcp-manager/v1/generate-app-password',
				method: 'POST',
				data: {
					server_id: server.id,
					// Per-client name so a subsequent audit of Users → Profile →
					// Application Passwords can tell which wizard-generated
					// token pairs with which client.
					name: `Quick Edit - ${ activeClient.name || activeClient.slug }`,
				},
			} );
			if ( response && response.password ) {
				setGeneratedPassword( String( response.password ) );
			} else {
				setGenerateError(
					__( 'Password generation succeeded but the response was malformed.', 'acrossai-mcp-manager' )
				);
			}
		} catch ( err ) {
			setGenerateError(
				( err && err.message ) ||
					__( 'Could not generate the Application Password.', 'acrossai-mcp-manager' )
			);
		} finally {
			setGenerating( false );
		}
	}, [ server, activeClient ] );

	if ( ! server ) {
		return (
			<Notice status="warning">
				{ __( 'No server selected.', 'acrossai-mcp-manager' ) }
			</Notice>
		);
	}

	if ( clients.length === 0 ) {
		return (
			<Notice status="warning">
				{ __(
					'No MCP clients registered on this site.',
					'acrossai-mcp-manager'
				) }
			</Notice>
		);
	}

	// The DTO's config field carries the JSON template. If missing (older
	// F035 shape), fall back to a raw dump. Once the operator generates a
	// password, splice it into every placeholder occurrence so the copyable
	// config below is already paste-ready (matches what the Clients tab does
	// on the backend, but done client-side to keep the round-trip cost off
	// the wire).
	const configText = useMemo( () => {
		let raw =
			activeClient && activeClient.config
				? typeof activeClient.config === 'string'
					? activeClient.config
					: JSON.stringify( activeClient.config, null, 2 )
				: JSON.stringify( activeClient || {}, null, 2 );

		if ( generatedPassword ) {
			// Split/join handles multiple occurrences without needing a regex
			// (WP application passwords are `[A-Za-z0-9 ]+` — no regex meta
			// chars to escape, and no JSON-breaking chars to encode).
			raw = raw.split( '(paste generated password here)' ).join( generatedPassword );
		}
		return raw;
	}, [ activeClient, generatedPassword ] );

	const configTitle = activeClient
		? `json · ${ activeClient.name || activeClient.slug }`
		: 'json';

	return (
		<div>
			<div style={ { marginBottom: 12 } }>
				<span
					style={ {
						fontSize: 11,
						fontWeight: 600,
						letterSpacing: '0.06em',
						textTransform: 'uppercase',
						color: '#757575',
					} }
				>
					{ __( 'Choose your client', 'acrossai-mcp-manager' ) }
				</span>
			</div>

			<div
				role="tablist"
				aria-label={ __( 'MCP clients', 'acrossai-mcp-manager' ) }
				style={ { display: 'flex', gap: 7, flexWrap: 'wrap', marginBottom: 16 } }
			>
				{ clients.map( ( c ) => {
					const isActive = c.slug === activeSlug;
					return (
						<button
							key={ c.slug }
							type="button"
							role="tab"
							aria-selected={ isActive }
							onClick={ () => setActiveSlug( c.slug ) }
							className={ isActive ? 'qs-btn' : 'qs-btn qs-btn--secondary' }
						>
							{ c.icon && (
								<span style={ { marginRight: 6 } }>{ c.icon }</span>
							) }
							{ c.name || c.slug }
						</button>
					);
				} ) }
			</div>

			{ /* F073 — 4 numbered steps: Generate → Config File → Top-Level Key
			     → Copy config. The visual walkthrough replaces the earlier
			     free-form Instructions paragraph; the Access Control notice
			     stays at the bottom as a security caveat. */ }

			{ /* Step 1 — Generate the password. Wizard-scoped name so the
			     operator can distinguish these tokens from tab-generated ones. */ }
			<div className="qs-step">
				<h3 className="qs-step-heading">
					<span className="qs-step-heading__num">
						{ __( 'Step 1', 'acrossai-mcp-manager' ) }
					</span>
					{ __( 'Generate the password', 'acrossai-mcp-manager' ) }
				</h3>
				<div className="qs-step-body qs-generate-section">
					<button
						type="button"
						className="qs-btn qs-generate-section__btn"
						onClick={ handleGenerate }
						disabled={ generating }
					>
						{ generating
							? __( 'Generating…', 'acrossai-mcp-manager' )
							: __( 'Generate New Application Password', 'acrossai-mcp-manager' )
						}
					</button>
					<p className="qs-generate-section__desc">
						{ __(
							'Creates a one-time password via WordPress Application Passwords. Shown only once — store it safely.',
							'acrossai-mcp-manager'
						) }
					</p>

					{ generatedPassword && (
						<div className="qs-generate-section__reveal">
							<div className="qs-generate-section__reveal-row">
								<input
									type="text"
									readOnly
									value={ generatedPassword }
									className="qs-generate-section__reveal-input"
									aria-label={ __( 'Generated Application Password', 'acrossai-mcp-manager' ) }
								/>
								<button
									ref={ copyRef }
									type="button"
									className="qs-btn qs-btn--secondary"
								>
									{ copied
										? __( 'Copied!', 'acrossai-mcp-manager' )
										: __( 'Copy', 'acrossai-mcp-manager' )
									}
								</button>
							</div>
							<p className="qs-generate-section__reveal-warning">
								{ __(
									'Shown once. The config below now includes this password — copy that to grab both at once.',
									'acrossai-mcp-manager'
								) }
							</p>
						</div>
					) }

					{ generateError && (
						<div style={ { marginTop: 12 } }>
							<Notice status="error">{ generateError }</Notice>
						</div>
					) }
				</div>
			</div>

			{ /* Step 2 — Open the config file at this path. */ }
			{ activeClient?.meta?.config_file && (
				<div className="qs-step">
					<h3 className="qs-step-heading">
						<span className="qs-step-heading__num">
							{ __( 'Step 2', 'acrossai-mcp-manager' ) }
						</span>
						{ __( 'Open the config file', 'acrossai-mcp-manager' ) }
					</h3>
					<div className="qs-step-body">
						<input
							type="text"
							readOnly
							value={ activeClient.meta.config_file }
							className="qs-meta-input qs-meta-input--full"
							aria-label={ __( 'Config File path', 'acrossai-mcp-manager' ) }
						/>
					</div>
				</div>
			) }

			{ /* Step 3 — Locate this top-level key inside the config file. */ }
			{ activeClient?.meta?.top_level_key && (
				<div className="qs-step">
					<h3 className="qs-step-heading">
						<span className="qs-step-heading__num">
							{ __( 'Step 3', 'acrossai-mcp-manager' ) }
						</span>
						{ __( 'Locate the top-level key', 'acrossai-mcp-manager' ) }
					</h3>
					<div className="qs-step-body">
						<input
							type="text"
							readOnly
							value={ `"${ activeClient.meta.top_level_key }"` }
							className="qs-meta-input qs-meta-input--full"
							aria-label={ __( 'Top-Level Key', 'acrossai-mcp-manager' ) }
						/>
					</div>
				</div>
			) }

			{ /* Step 4 — Copy the JSON and paste under that key. */ }
			<div className="qs-step">
				<h3 className="qs-step-heading">
					<span className="qs-step-heading__num">
						{ __( 'Step 4', 'acrossai-mcp-manager' ) }
					</span>
					{ __( 'Copy this config and paste it under the top-level key', 'acrossai-mcp-manager' ) }
				</h3>
				<div className="qs-step-body">
					{ /* F075 — Local-dev TLS bypass callout. When the server-
					     side detection fires, the JSON below already contains
					     NODE_TLS_REJECT_UNAUTHORIZED (baked in by
					     ConnectionMethodRegistry → AbstractMCPClient::build_env).
					     This callout tells the operator what we did and why.
					     Copy sourced from window.acrossaiMcpQuickSetup.tlsBypass
					     so admin PHP (MCPClientsBlock.php) and this JSX never
					     drift on translation edits. */ }
					{ window.acrossaiMcpQuickSetup?.tlsBypass?.enabled && (
						<div style={ { marginBottom: 12 } }>
							<Notice status="warning">
								{ window.acrossaiMcpQuickSetup.tlsBypass.message }
								{ ' ' }
								<a
									href={ window.acrossaiMcpQuickSetup.tlsBypass.docUrl }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ window.acrossaiMcpQuickSetup.tlsBypass.linkText }
								</a>
							</Notice>
						</div>
					) }
					<CodeBlock variant="pane" title={ configTitle }>
						{ configText }
					</CodeBlock>
				</div>
			</div>

			{ /* Step 5 — Restart / reload the MCP client. Copy is client-specific
			     (Restart Claude Desktop; Reload VS Code; Cline hot-reloads …)
			     sourced from AbstractMCPClient::get_restart_step_text() via the
			     DTO's `restartStep` field. Only rendered when the DTO carries a
			     non-empty value — companion plugins that inherit the abstract
			     default always will; strictly-typed bare subclasses may not. */ }
			{ activeClient?.restartStep && (
				<div className="qs-step">
					<h3 className="qs-step-heading">
						<span className="qs-step-heading__num">
							{ __( 'Step 5', 'acrossai-mcp-manager' ) }
						</span>
						{ __( 'Restart the MCP client', 'acrossai-mcp-manager' ) }
					</h3>
					<div className="qs-step-body">
						<p style={ { margin: 0 } }>
							{ activeClient.restartStep }
						</p>
					</div>
				</div>
			) }

			{ /* Shared Access Control caveat — verbatim to MCPClientsBlock.php:256
			     so translators only key it once. */ }
			<div style={ { marginTop: 20 } }>
				<Notice status="info">
					{ __(
						'The generated password belongs to your current WordPress user. Access Control still applies to every MCP request, so a user who is not allowed for this server will receive an access denied response even if they have a saved config.',
						'acrossai-mcp-manager'
					) }
				</Notice>
			</div>
		</div>
	);
};

export default Step11_ClientDetail;
