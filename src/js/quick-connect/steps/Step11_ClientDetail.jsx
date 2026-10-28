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
import { __, sprintf } from '@wordpress/i18n';
import { useCopyToClipboard } from '@wordpress/compose';
import apiFetch from '@wordpress/api-fetch';
import CodeBlock from '../components/CodeBlock.jsx';
import Notice from '../components/Notice.jsx';
import SetupRequiredGate from '../components/SetupRequiredGate.jsx';
import useWizardState from '../hooks/useWizardState.js';

/**
 * The literal PHP writes via AbstractMCPClient::safe_token().
 *
 * A contract, not a convenience: if this string ever stops matching the PHP
 * constant, credential substitution silently stops working on this surface —
 * password revealed beside the button, placeholder still sitting in the config
 * below, no error. A PHPUnit assertion checks both JS files against the PHP
 * constant on every run, because tests/jest has no runner in CI.
 */
const PASSWORD_PLACEHOLDER = '(paste generated password here)';

/**
 * OS slugs in display order with their labels.
 *
 * Mirrors Includes\Utilities\OperatingSystemLabels so both surfaces list
 * paths in the same order. Labels are proper nouns and are deliberately not
 * translated — a locale must not rename a heading that has to match what the
 * operator sees in their own file system.
 */
const OS_LABELS = [
	[ 'macos', 'macOS' ],
	[ 'windows', 'Windows' ],
	[ 'linux', 'Linux' ],
];

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

	// Which of the two routes the operator picked. Local state rather than a
	// URL parameter: this is a single-page wizard, so switching route must not
	// reload and lose the generated password. Resets with the client for the
	// same reason the password does.
	const [ route, setRoute ] = useState( 'prompt' );
	const [ generateError, setGenerateError ] = useState( null );
	const [ copied, setCopied ] = useState( false );

	useEffect( () => {
		setGeneratedPassword( '' );
		setGenerateError( null );
		setCopied( false );
		// Back to the recommended route on every client change: the previous
		// client's choice says nothing about this one, and leaving a manual
		// selection sticky hides the shorter path from someone who never
		// deliberately chose it.
		setRoute( 'prompt' );
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
			raw = raw.split( PASSWORD_PLACEHOLDER ).join( generatedPassword );
		}
		return raw;
	}, [ activeClient, generatedPassword ] );

	// F099 — the setup prompt comes from the DTO ALREADY ASSEMBLED. It is
	// never rebuilt here: D43 requires one producer for both surfaces, and the
	// prose is translated server-side, so reassembling it in JavaScript would
	// key the same sentences a second time and break byte-identity with the
	// manual block. The only client-side step is splicing the credential, the
	// same way configText does.
	const setupPrompt = useMemo( () => {
		let raw = activeClient?.setupPrompt || '';

		if ( raw && generatedPassword ) {
			raw = raw.split( PASSWORD_PLACEHOLDER ).join( generatedPassword );
		}
		return raw;
	}, [ activeClient, generatedPassword ] );

	const clientName = activeClient
		? activeClient.name || activeClient.slug
		: '';

	const revokeUrl = `${
		window.acrossaiMcpQuickConnect?.adminUrl || ''
	}profile.php#application-passwords`;

	const routeOptions = [
		{
			key: 'prompt',
			title: __( 'Let your AI client do it', 'acrossai-mcp-manager' ),
			desc: sprintf(
				/* translators: %s: MCP client display name. */
				__(
					'Copy one prompt and paste it into %s. It edits its own config file.',
					'acrossai-mcp-manager'
				),
				clientName
			),
			meta: __( '1 step · about 30 seconds', 'acrossai-mcp-manager' ),
			recommended: true,
		},
		{
			key: 'manual',
			title: __( 'Set it up yourself', 'acrossai-mcp-manager' ),
			desc: __(
				'Open the config file, find the key, and paste the configuration yourself.',
				'acrossai-mcp-manager'
			),
			meta: __(
				'3 steps · no password sent to a chat',
				'acrossai-mcp-manager'
			),
			recommended: false,
		},
	];

	// Per-OS config paths, ordered to match MCPClientsBlock::render_os_config_paths().
	// Step 11 previously read only `meta.config_file`, the single legacy value
	// — which is the macOS path for Claude Desktop, VS Code and Copilot, so a
	// Windows operator was told to edit a directory that does not exist on
	// their machine. The DTO has always carried `meta.config_files`; this
	// surface simply ignored it (D64).
	const configPaths = useMemo( () => {
		const perOs = activeClient?.meta?.config_files || {};
		const ordered = OS_LABELS.filter(
			( [ slug ] ) => typeof perOs[ slug ] === 'string' && perOs[ slug ] !== ''
		).map( ( [ slug, label ] ) => [ label, perOs[ slug ] ] );

		if ( ordered.length > 0 ) {
			return ordered;
		}

		const single = activeClient?.meta?.config_file;
		return single ? [ [ '', single ] ] : [];
	}, [ activeClient ] );

	return (
		<div>
			<SetupRequiredGate />
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
							{ /* F076 — picker button body is client name only; the
							     get_icon() DTO value is intentionally not rendered
							     here (companion plugins may still read it from
							     the ConnectionMethodRegistry DTO). */ }
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

			{ /* STEP 2 — the choice. Only the chosen route renders below it.
			     The wizard previously ran Step 1 then Steps 2-5 in one column
			     with the prompt wedged in, so the two ways of finishing read
			     as one long sequence instead of alternatives. */ }
			<div className="qs-step">
				<h3 className="qs-step-heading">
					<span className="qs-step-heading__num">
						{ __( 'Step 2', 'acrossai-mcp-manager' ) }
					</span>
					{ __(
						'How do you want to add it?',
						'acrossai-mcp-manager'
					) }
				</h3>
				<div className="qs-step-body">
					<div
						className="acrossai-mcp-route-picker"
						role="radiogroup"
						aria-label={ __(
							'How do you want to add it?',
							'acrossai-mcp-manager'
						) }
					>
						{ routeOptions.map( ( option ) => (
							<button
								key={ option.key }
								type="button"
								role="radio"
								aria-checked={ route === option.key }
								className={ `acrossai-mcp-route-card${
									route === option.key ? ' is-selected' : ''
								}` }
								onClick={ () => setRoute( option.key ) }
							>
								<span
									className="acrossai-mcp-route-card__mark"
									aria-hidden="true"
								/>
								<span className="acrossai-mcp-route-card__body">
									<span className="acrossai-mcp-route-card__title">
										{ option.title }
										{ option.recommended && (
											<span className="acrossai-mcp-route-card__badge">
												{ __(
													'Recommended',
													'acrossai-mcp-manager'
												) }
											</span>
										) }
									</span>
									<span className="acrossai-mcp-route-card__desc">
										{ option.desc }
									</span>
									<span className="acrossai-mcp-route-card__meta">
										{ option.meta }
									</span>
								</span>
							</button>
						) ) }
					</div>
				</div>
			</div>

			{ /* ROUTE — prompt. */ }
			{ route === 'prompt' && setupPrompt && (
				<div className="qs-step-body acrossai-mcp-route-body">
					<p className="acrossai-mcp-route-body__lead">
						{ sprintf(
							/* translators: %s: MCP client display name. */
							__(
								'Paste this into %s. It will edit its own config file — you do not need to find or open anything.',
								'acrossai-mcp-manager'
							),
							clientName
						) }
					</p>

					<div className="acrossai-mcp-callout acrossai-mcp-callout--warn">
						<p>
							<strong>
								{ __(
									'This prompt contains a working password for your site.',
									'acrossai-mcp-manager'
								) }
							</strong>{ ' ' }
							{ __(
								'It signs in as you across the whole site, not just this MCP server. Pasting it into a hosted assistant sends it to that provider, where it may be kept in your conversation history.',
								'acrossai-mcp-manager'
							) }{ ' ' }
							<a href={ revokeUrl }>
								{ __(
									'Revoke this password',
									'acrossai-mcp-manager'
								) }
							</a>
						</p>
					</div>

					<CodeBlock
						variant="pane"
						title={ `${ __(
							'Prompt',
							'acrossai-mcp-manager'
						) } · ${ clientName }` }
						copyLabel={ __(
							'Copy prompt',
							'acrossai-mcp-manager'
						) }
					>
						{ setupPrompt }
					</CodeBlock>
				</div>
			) }

			{ /* ROUTE — manual. Lettered a/b/c: these are parts of Step 2, and
			     numbering them 2-5 is what made the screen read as one
			     undifferentiated sequence. The reload instruction folds into
			     (c) because it is part of making the paste take effect. */ }
			{ route === 'manual' && (
				<div className="qs-step-body acrossai-mcp-route-body">
					{ configPaths.length > 0 && (
						<div className="acrossai-mcp-substep">
							<span className="acrossai-mcp-substep__mark">
								a
							</span>
							<div className="acrossai-mcp-substep__body">
								<span className="acrossai-mcp-substep__title">
									{ __(
										'Open the config file',
										'acrossai-mcp-manager'
									) }
								</span>
								{ configPaths.map( ( [ label, path ] ) => (
									<div
										key={ path }
										className="acrossai-mcp-path"
									>
										{ label && (
											<span className="acrossai-mcp-path__os">
												{ label }
											</span>
										) }
										<CodeBlock variant="inline">
											{ path }
										</CodeBlock>
									</div>
								) ) }
							</div>
						</div>
					) }

					{ activeClient?.meta?.top_level_key && (
						<div className="acrossai-mcp-substep">
							<span className="acrossai-mcp-substep__mark">
								b
							</span>
							<div className="acrossai-mcp-substep__body">
								<span className="acrossai-mcp-substep__title">
									{ __(
										'Find the top-level key',
										'acrossai-mcp-manager'
									) }
								</span>
								<div className="acrossai-mcp-substep__inline">
									<code className="acrossai-mcp-key">
										{ `"${ activeClient.meta.top_level_key }"` }
									</code>
									<span className="acrossai-mcp-substep__hint">
										{ __(
											'Create it if the file is empty.',
											'acrossai-mcp-manager'
										) }
									</span>
								</div>
							</div>
						</div>
					) }

					<div className="acrossai-mcp-substep">
						<span className="acrossai-mcp-substep__mark">c</span>
						<div className="acrossai-mcp-substep__body">
							<span className="acrossai-mcp-substep__title">
								{ activeClient?.restartStep
									? sprintf(
											/* translators: %s: client-specific reload instruction. */
											__(
												'Paste this under the key, save, then: %s',
												'acrossai-mcp-manager'
											),
											activeClient.restartStep
									  )
									: __(
											'Paste this under the key and save',
											'acrossai-mcp-manager'
									  ) }
							</span>

							{ window.acrossaiMcpQuickConnect?.tlsBypass
								?.enabled && (
								<div className="acrossai-mcp-callout acrossai-mcp-callout--warn">
									<p>
										{
											window.acrossaiMcpQuickConnect
												.tlsBypass.message
										}{ ' ' }
										<a
											href={
												window.acrossaiMcpQuickConnect
													.tlsBypass.docUrl
											}
											target="_blank"
											rel="noopener noreferrer"
										>
											{
												window.acrossaiMcpQuickConnect
													.tlsBypass.linkText
											}
										</a>
									</p>
								</div>
							) }

							<CodeBlock
								variant="pane"
								title={ `${ __(
									'JSON',
									'acrossai-mcp-manager'
								) } · ${ clientName }` }
								copyLabel={ __(
									'Copy config',
									'acrossai-mcp-manager'
								) }
							>
								{ configText }
							</CodeBlock>
						</div>
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
