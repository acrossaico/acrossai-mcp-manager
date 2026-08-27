/**
 * F069 — Wizard inline notice with left-color-bar per design brief.
 *
 * Contract shape from `contracts/wizard-state.md` §Error surfacing:
 *   <Notice status="info|warning|success|error">content</Notice>
 *
 * Content renders as React children (text nodes or nested elements) —
 * NEVER via dangerouslySetInnerHTML (TASK-SEC-004 / SEC-004).
 *
 * @package AcrossAI_MCP_Manager
 */

const ALLOWED_STATUSES = [ 'info', 'warning', 'success', 'error' ];

const Notice = ( { status = 'info', children } ) => {
	const safeStatus = ALLOWED_STATUSES.includes( status ) ? status : 'info';
	return (
		<div className={ `qs-notice qs-notice--${ safeStatus }` } role={ safeStatus === 'error' ? 'alert' : 'status' }>
			{ children }
		</div>
	);
};

export default Notice;
