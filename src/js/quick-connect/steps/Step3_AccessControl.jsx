/**
 * F069 — Step 3: Access Control editor.
 *
 * Mounts the shared <AccessControlEditor> component scoped to the wizard's
 * currently-selected server. The apiFetch nonce middleware is already
 * wired at src/js/quick-connect.js entry (TASK-SEC-005), so this component
 * doesn't re-wire it (avoids double-header per F015 bootstrap rationale).
 *
 * Save integration (vs. the per-server-edit tab): the vendor's
 * "Save Access Control" button is hidden here and replaced by a wizard
 * footer button registered via useFooterAction. Footer layout on this
 * step is Back · Continue · Save and Continue:
 *   - Continue          → advance without touching the AC rule (leaves
 *                          whatever's saved as-is; safe skip per FR-014)
 *   - Save and Continue → PUT / DELETE the AC rule via the vendor's
 *                          endpoint, then advance
 * Both are valid — the "no rule" state keeps the server admin-only, which
 * is a valid final state per F042.
 *
 * @package AcrossAI_MCP_Manager
 */

import { useCallback, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import AccessControlEditor from '../../access-control/AccessControlEditor.jsx';
import Notice from '../components/Notice.jsx';
import useWizardState from '../hooks/useWizardState.js';
import { useFooterAction, useWizardAdvance } from '../hooks/useAdvanceGuard.js';

// Vendor sentinel — an AC rule with key === NO_ACCESS means "no access
// configured". MUST match wpb-access-control/js/AccessControl.js:48
// (`const NO_ACCESS = '';`). Empty string, NOT '_no_access' — the earlier
// wrong value silently mis-branched every Save-and-Continue click into
// the PUT path with an empty ac_key, which the vendor endpoint rejects,
// blocking the wizard from advancing.
const NO_ACCESS = '';

const Step3_AccessControl = () => {
	const { state } = useWizardState();
	const advance = useWizardAdvance();
	const bootstrap = window.acrossaiMcpQuickConnect || {};
	const [ saveError, setSaveError ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	const server = useMemo(
		() =>
			state.servers.find(
				( s ) => s.id === state.wizardState.server_id
			),
		[ state.servers, state.wizardState.server_id ]
	);

	// Vendor emits onChange once after load AND on every user change. We store
	// the latest tuple in a ref so the footer action sees fresh values without
	// re-registering the callback on every keystroke (which would churn the
	// context's setFooterAction).
	const selectionRef = useRef( { key: null, options: [] } );

	const handleAcChange = useCallback( ( key, options ) => {
		selectionRef.current = {
			key,
			options: Array.isArray( options ) ? options : [],
		};
	}, [] );

	const handleSaveAndContinue = useCallback( async () => {
		setSaveError( null );

		if (
			! server ||
			selectionRef.current.key === null ||
			selectionRef.current.key === NO_ACCESS
		) {
			advance();
			return;
		}

		const pluginSlug = bootstrap.acPluginSlug || 'mcp';
		const namespace = bootstrap.acNamespace || 'acrossai-mcp-manager';
		const restApiRoot = bootstrap.restApiRoot || '/wp-json';
		const url = `${ restApiRoot }/wpb-ac/v1/${ pluginSlug }/rules/${ encodeURIComponent(
			namespace
		) }/${ server.slug }`;

		setSaving( true );
		try {
			await apiFetch( {
				url,
				method: 'PUT',
				data: {
					ac_key: selectionRef.current.key,
					ac_options: selectionRef.current.options,
				},
			} );
			advance();
		} catch ( err ) {
			setSaveError(
				( err && err.message ) ||
					__(
						'Failed to save access control. Try again.',
						'acrossai-mcp-manager'
					)
			);
		} finally {
			setSaving( false );
		}
	}, [
		server,
		bootstrap.acPluginSlug,
		bootstrap.acNamespace,
		bootstrap.restApiRoot,
		advance,
	] );

	const footerAction = useMemo(
		() =>
			server
				? {
					label: __( 'Save and Continue', 'acrossai-mcp-manager' ),
					onClick: handleSaveAndContinue,
					isLoading: saving,
					disabled: saving,
				  }
				: null,
		[ server, handleSaveAndContinue, saving ]
	);
	useFooterAction( footerAction );

	if ( ! server ) {
		return (
			<div>
				<h2 className="qs__step-title">
					{ __( 'Who can reach it?', 'acrossai-mcp-manager' ) }
				</h2>
				<p>
					{ __(
						'No server selected — go back to Step 1.',
						'acrossai-mcp-manager'
					) }
				</p>
			</div>
		);
	}

	return (
		<div>
			<h2 className="qs__step-title">
				{ __( 'Who can reach this server?', 'acrossai-mcp-manager' ) }
			</h2>

			{ /* F042 admin-only banner. Keep this string in sync with the
			     one in admin/Partials/ServerTabs/AccessControlTab.php. */ }
			<Notice status="info">
				{ __(
					'Only administrators can reach this server until you add an access rule below.',
					'acrossai-mcp-manager'
				) }
			</Notice>

			{ saveError && (
				<Notice status="error">{ saveError }</Notice>
			) }

			<AccessControlEditor
				pluginSlug={ bootstrap.acPluginSlug || 'mcp' }
				namespace={ bootstrap.acNamespace || 'acrossai-mcp-manager' }
				resourceKey={ server.slug }
				restApiRoot={ bootstrap.restApiRoot || '/wp-json' }
				nonce={ bootstrap.restNonce || '' }
				hideSaveButton={ true }
				onChange={ handleAcChange }
			/>

			<p className="qs__step-subtitle" style={ { marginTop: 24 } }>
				{ __(
					'Continue skips saving (leaves current rule as-is). Save and Continue writes your changes and moves on. You can change this anytime under the server\'s Access Control tab.',
					'acrossai-mcp-manager'
				) }
			</p>
		</div>
	);
};

export default Step3_AccessControl;
