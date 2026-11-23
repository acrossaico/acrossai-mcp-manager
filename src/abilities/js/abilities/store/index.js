/**
 * Redux store for the Custom Abilities Manager.
 *
 * State tracks abilities list, pagination, categories, view routing,
 * and savedAbility/draftAbility for unsaved-changes detection.
 *
 * @since 0.2.0
 */
import { createReduxStore, register } from '@wordpress/data';
import { ALL_TABS_KEY } from '../constants';
import * as api from '../api/client';

export const STORE_NAME = 'acrossai/abilities';

// ---------------------------------------------------------------------------
// Action types
// ---------------------------------------------------------------------------
const SET_ABILITIES = 'SET_ABILITIES';
const SET_LOADING = 'SET_LOADING';
const SET_SAVING = 'SET_SAVING';
const SET_ERROR = 'SET_ERROR';
const SET_SAVE_ERROR = 'SET_SAVE_ERROR';
const CLEAR_ERROR = 'CLEAR_ERROR';
const SET_CATEGORIES = 'SET_CATEGORIES';
const SET_VIEW = 'SET_VIEW';
const SET_ACTIVE_TAB = 'SET_ACTIVE_TAB';
const SET_LIST_PARAMS = 'SET_LIST_PARAMS';
const SET_TOOLSET_COUNTS = 'SET_TOOLSET_COUNTS';
const SET_SAVED = 'SET_SAVED';
const UPDATE_DRAFT = 'UPDATE_DRAFT';
const CLEAR_DRAFT = 'CLEAR_DRAFT';
const REMOVE_ABILITY = 'REMOVE_ABILITY';
const PATCH_ABILITY = 'PATCH_ABILITY';

// ---------------------------------------------------------------------------
// Overridable fields (mirrors AcrossAI_Ability_Merger::$overridable_fields)
// ---------------------------------------------------------------------------
const OVERRIDABLE_FIELDS = [
	'label',
	'description',
	'category',
	'callback_type',
	'callback_config',
	'site_allowed',
	'readonly',
	'destructive',
	'idempotent',
	'show_in_rest',
	'show_in_mcp',
	'mcp_type',
];

// ---------------------------------------------------------------------------
// Initial state
// ---------------------------------------------------------------------------
const DEFAULT_STATE = {
	abilities: [],
	// Feature 102: the selected toolset tab. ALL_TABS_KEY means "no toolset filter".
	activeTab: ALL_TABS_KEY,
	// The query behind the rows currently on screen — page, per_page, search, filters, tab_group.
	// Kept so `fetchAbilities()` with no arguments can mean "re-fetch what is displayed" rather
	// than "fetch the server defaults". See the action for why that distinction matters.
	listParams: {},
	toolsetCounts: {},
	// Group => display name, for the groups whose integration declares one. Everything else falls
	// back to the derived label, so this map is usually tiny (issue #184).
	toolsetLabels: {},
	// Unfiltered ability total, for the strip's "All" entry. Distinct from `total`, which is the
	// current query's filtered total and therefore equals the toolset's own count on a toolset view.
	toolsetTotal: 0,
	total: 0,
	pages: 1,
	categories: [],
	isLoading: false,
	isSaving: false,
	error: null,
	saveError: null,
	view: 'list',
	savedAbility: null,
	draftAbility: {},
	isDirty: false,
};

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function computeIsDirty( draft, saved ) {
	return JSON.stringify( draft ) !== JSON.stringify( saved );
}

// ---------------------------------------------------------------------------
// Reducer
// ---------------------------------------------------------------------------
function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case SET_ABILITIES:
			return {
				...state,
				abilities: action.abilities,
				total: action.total,
				pages: action.pages,
				isLoading: false,
				error: null,
			};

		case SET_LOADING:
			return { ...state, isLoading: action.isLoading };

		case SET_SAVING:
			return { ...state, isSaving: action.isSaving };

		case SET_ERROR:
			return { ...state, error: action.error, isLoading: false };

		case SET_SAVE_ERROR:
			return { ...state, saveError: action.error, isSaving: false };

		case CLEAR_ERROR:
			return { ...state, error: null, saveError: null };

		case SET_CATEGORIES:
			return { ...state, categories: action.categories };

		case SET_VIEW:
			return { ...state, view: action.view };

		case SET_ACTIVE_TAB:
			return { ...state, activeTab: action.activeTab };

		case SET_LIST_PARAMS:
			return { ...state, listParams: action.params };

		case SET_TOOLSET_COUNTS:
			return {
				...state,
				toolsetCounts: action.counts,
				toolsetTotal: action.total,
				toolsetLabels: action.labels || {},
			};

		case SET_SAVED: {
			const saved = action.ability;
			return {
				...state,
				savedAbility: saved,
				draftAbility: ( () => {
					if ( ! saved ) {
						return {};
					}
					const draft = { ...saved };
					// Bug 3: seed overridable fields from _override (null = Inherit)
					// so TriChip controls show the correct DB state, not merged values.
					if ( saved._override ) {
						OVERRIDABLE_FIELDS.forEach( ( f ) => {
							draft[ f ] =
								saved._override[ f ] !== undefined
									? saved._override[ f ]
									: null;
						} );
					}
					return draft;
				} )(),
				isDirty: false,
				isSaving: false,
				saveError: null,
			};
		}

		case UPDATE_DRAFT: {
			const newDraft = { ...state.draftAbility, ...action.patch };
			return {
				...state,
				draftAbility: newDraft,
				isDirty: computeIsDirty( newDraft, state.savedAbility ),
			};
		}

		case CLEAR_DRAFT:
			return {
				...state,
				savedAbility: null,
				draftAbility: {},
				isDirty: false,
				saveError: null,
			};

		case REMOVE_ABILITY:
			return {
				...state,
				abilities: state.abilities.filter(
					( a ) => a.ability_slug !== action.slug,
				),
				total: Math.max( 0, state.total - 1 ),
				isSaving: false,
			};

		case PATCH_ABILITY:
			return {
				...state,
				abilities: state.abilities.map( ( a ) =>
					a.ability_slug === action.slug
						? { ...a, ...action.patch }
						: a,
				),
				isSaving: false,
			};

		default:
			return state;
	}
}

// ---------------------------------------------------------------------------
// Action creators
// ---------------------------------------------------------------------------
/**
 * Monotonic id for list requests.
 *
 * `fetchAbilities` had no ordering guard, so whichever response landed last won. Feature 102 made
 * that reliably visible: a deep-linked toolset fires an unfiltered fetch (before the toolset list
 * has loaded and the tab can be validated) and then a filtered one, and the unfiltered response —
 * bigger, so usually slower — arrived second and replaced the filtered rows. The strip said
 * "Cache 7" while the table showed all 419.
 *
 * The hazard is not new and is not specific to toolsets: typing quickly in the search box could
 * always land an older response last. Sequencing every list request fixes both.
 *
 * @type {number}
 */
let listRequestId = 0;

const actions = {
	setView: ( view ) => ( { type: SET_VIEW, view } ),
	setActiveTab: ( activeTab ) => ( { type: SET_ACTIVE_TAB, activeTab } ),
	setSaved: ( ability ) => ( { type: SET_SAVED, ability } ),
	updateDraft: ( patch ) => ( { type: UPDATE_DRAFT, patch } ),
	clearDraft: () => ( { type: CLEAR_DRAFT } ),
	clearError: () => ( { type: CLEAR_ERROR } ),

	// Thunks
	setListParams: ( params ) => ( { type: SET_LIST_PARAMS, params } ),

	/**
	 * Fetch the abilities list.
	 *
	 * Passing no arguments means **re-fetch what is currently displayed**, not "fetch the server
	 * defaults". The distinction is the whole point of this signature: every bulk action ends by
	 * refreshing the list, and each one called `fetchAbilities()` bare. Because the parameters used
	 * to live only in the component, that produced an empty query string — so applying a bulk
	 * action silently dropped the toolset filter, the page size, the current page, the search term
	 * and the source/status filters, and the table jumped back to an unfiltered page 1 at the
	 * server's default page size while the tab strip and pager kept claiming the old state
	 * (issue #185).
	 *
	 * Remembering the last query here fixes all five call sites at once and, more importantly,
	 * removes the trap for the sixth: a future bulk action gets correct behaviour by default.
	 *
	 * @param {Object} [params] Query params. Omit to reuse the last set.
	 * @return {Function} Thunk.
	 */
	fetchAbilities( params ) {
		return async ( { dispatch, select } ) => {
			// `undefined` means "whatever is on screen". An explicit `{}` is still an explicit
			// request for no parameters, and is honoured as such.
			const effective =
				undefined === params ? select.getListParams() : params;

			if ( undefined !== params ) {
				dispatch( { type: SET_LIST_PARAMS, params } );
			}

			listRequestId += 1;
			const requestId = listRequestId;

			dispatch( { type: SET_LOADING, isLoading: true } );
			try {
				const { abilities, total, pages } =
					await api.getAbilities( effective );

				// Drop a response that a later request has already superseded.
				if ( requestId !== listRequestId ) {
					return;
				}

				dispatch( { type: SET_ABILITIES, abilities, total, pages } );
			} catch ( err ) {
				if ( requestId !== listRequestId ) {
					return;
				}

				// FR-036: keep last-loaded data, show error notice
				dispatch( { type: SET_ERROR, error: err.message } );
			}
		};
	},

	fetchAbility( slug ) {
		return async ( { dispatch } ) => {
			dispatch( { type: SET_LOADING, isLoading: true } );
			try {
				const ability = await api.getAbility( slug );
				dispatch( { type: SET_LOADING, isLoading: false } );
				dispatch( { type: SET_SAVED, ability } );
			} catch ( err ) {
				dispatch( { type: SET_ERROR, error: err.message } );
			}
		};
	},

	createAbility( data ) {
		return async ( { dispatch } ) => {
			dispatch( { type: SET_SAVING, isSaving: true } );
			try {
				const ability = await api.createAbility( data );
				dispatch( { type: SET_SAVING, isSaving: false } );
				return ability;
			} catch ( err ) {
				// FR-037: form stays open, save button re-enabled
				dispatch( { type: SET_SAVE_ERROR, error: err.message } );
				return null;
			}
		};
	},

	updateAbility( slug, data ) {
		return async ( { dispatch } ) => {
			dispatch( { type: SET_SAVING, isSaving: true } );
			try {
				const ability = await api.updateAbility( slug, data );
				dispatch( { type: SET_SAVED, ability } );
				dispatch( {
					type: PATCH_ABILITY,
					slug: ability.ability_slug,
					patch: ability,
				} );
				return ability;
			} catch ( err ) {
				// FR-037: form stays open, isDirty stays true
				dispatch( { type: SET_SAVE_ERROR, error: err.message } );
				return null;
			}
		};
	},

	deleteAbility( slug ) {
		return async ( { dispatch } ) => {
			dispatch( { type: SET_SAVING, isSaving: true } );
			try {
				await api.deleteAbility( slug );
				// Optimistic: remove from list
				dispatch( { type: REMOVE_ABILITY, slug } );
				dispatch( { type: SET_VIEW, view: 'list' } );
			} catch ( err ) {
				dispatch( { type: SET_SAVE_ERROR, error: err.message } );
			}
		};
	},

	bulkDeleteAbilities( slugs ) {
		return async ( { dispatch } ) => {
			dispatch( { type: SET_SAVING, isSaving: true } );
			try {
				await Promise.all( slugs.map( ( slug ) => api.deleteAbility( slug ) ) );
				// Re-fetch list after bulk delete
				dispatch( actions.fetchAbilities() );
			} catch ( err ) {
				dispatch( { type: SET_SAVE_ERROR, error: err.message } );
			} finally {
				dispatch( { type: SET_SAVING, isSaving: false } );
			}
		};
	},

	bulkUpdateStatus( slugs, status ) {
		return async ( { dispatch } ) => {
			dispatch( { type: SET_SAVING, isSaving: true } );
			try {
				await Promise.all(
					slugs.map( ( slug ) => api.updateAbility( slug, { status } ) ),
				);
				// Re-fetch list after bulk status update
				dispatch( actions.fetchAbilities() );
			} catch ( err ) {
				dispatch( { type: SET_SAVE_ERROR, error: err.message } );
			} finally {
				dispatch( { type: SET_SAVING, isSaving: false } );
			}
		};
	},

	/**
	 * Bulk-apply a tri-state override to many abilities.
	 *
	 * Loops the existing per-slug POST /abilities/{slug} endpoint under
	 * Promise.all — mirrors the shape of bulkUpdateStatus (Feature 056).
	 *
	 * @param {string[]}                     slugs Ability slugs.
	 * @param {'site_allowed'|'show_in_mcp'} field Tri-state field to write.
	 * @param {boolean|null}                 value true | false | null.
	 */
	bulkUpdateTristate( slugs, field, value ) {
		return async ( { dispatch } ) => {
			dispatch( { type: SET_SAVING, isSaving: true } );
			try {
				await Promise.all(
					slugs.map( ( slug ) =>
						api.updateAbility( slug, { [ field ]: value } ),
					),
				);
				dispatch( actions.fetchAbilities() );
			} catch ( err ) {
				dispatch( { type: SET_SAVE_ERROR, error: err.message } );
				// Re-throw so the caller in AbilitiesList.jsx handleBulkApply
				// can surface a notice + keep the selection intact (SEC-001).
				throw err;
			} finally {
				dispatch( { type: SET_SAVING, isSaving: false } );
			}
		};
	},

	/**
	 * Bulk clear overrides on many abilities — returns each to source defaults.
	 *
	 * Loops the existing per-slug DELETE /abilities/{slug}/override endpoint
	 * under Promise.all. Feature 056 "Force Reset" bulk action.
	 *
	 * @param {string[]} slugs Ability slugs.
	 */
	bulkClearOverrides( slugs ) {
		return async ( { dispatch } ) => {
			dispatch( { type: SET_SAVING, isSaving: true } );
			try {
				await Promise.all(
					slugs.map( ( slug ) => api.deleteOverride( slug ) ),
				);
				dispatch( actions.fetchAbilities() );
			} catch ( err ) {
				dispatch( { type: SET_SAVE_ERROR, error: err.message } );
				throw err;
			} finally {
				dispatch( { type: SET_SAVING, isSaving: false } );
			}
		};
	},

	/**
	 * Bulk-apply a wpb-access-control rule to many abilities (Feature 056).
	 *
	 * Loops the composer's per-slug PUT rule endpoint under Promise.all.
	 * Sending `acKey=''` clears the rule for every selected slug
	 * (equivalent to "Everyone allowed").
	 *
	 * @param {string[]} slugs     Ability slugs.
	 * @param {string}   acKey     Provider id or '' to clear.
	 * @param {string[]} acOptions Provider-specific options; empty when clearing.
	 */
	bulkSetUserAccessRule( slugs, acKey, acOptions ) {
		return async ( { dispatch } ) => {
			dispatch( { type: SET_SAVING, isSaving: true } );
			try {
				const results = await Promise.all(
					slugs.map( ( slug ) =>
						api.setAccessControlRule( slug, acKey, acOptions ),
					),
				);
				// SEC-006 / BUG-AC-NULL-RETURN-SILENT-FAIL: the composer PUT
				// endpoint may resolve with `null` on a soft failure rather
				// than throwing. Treat any null/undefined response as failure
				// so the operator sees an error notice instead of false success.
				const failedCount = results.filter(
					( r ) => null === r || undefined === r,
				).length;
				if ( failedCount > 0 ) {
					throw new Error(
						`${ failedCount } of ${ slugs.length } User Access rules did not save.`,
					);
				}
				dispatch( actions.fetchAbilities() );
			} catch ( err ) {
				dispatch( { type: SET_SAVE_ERROR, error: err.message } );
				throw err;
			} finally {
				dispatch( { type: SET_SAVING, isSaving: false } );
			}
		};
	},

	clearOverrides( slug ) {
		return async ( { dispatch } ) => {
			dispatch( { type: SET_SAVING, isSaving: true } );
			try {
				const ability = await api.deleteOverride( slug );
				dispatch( { type: SET_SAVED, ability } );
				dispatch( {
					type: PATCH_ABILITY,
					slug: ability.ability_slug,
					patch: ability,
				} );
			} catch ( err ) {
				dispatch( { type: SET_SAVE_ERROR, error: err.message } );
			} finally {
				dispatch( { type: SET_SAVING, isSaving: false } );
			}
		};
	},

	fetchToolsetCounts() {
		return async ( { dispatch } ) => {
			try {
				const payload = await api.getToolsetCounts();
				dispatch( {
					type: SET_TOOLSET_COUNTS,
					counts:
						payload && 'object' === typeof payload.counts
							? payload.counts
							: {},
					total: payload ? Number( payload.total ) || 0 : 0,
					labels:
						payload && 'object' === typeof payload.labels
							? payload.labels
							: {},
				} );
			} catch {
				// Non-fatal — the toolset strip degrades to "All" only.
				dispatch( {
					type: SET_TOOLSET_COUNTS,
					counts: {},
					total: 0,
					labels: {},
				} );
			}
		};
	},

	fetchCategories() {
		return async ( { dispatch } ) => {
			try {
				const categories = await api.getCategories();
				dispatch( {
					type: SET_CATEGORIES,
					categories: Array.isArray( categories ) ? categories : [],
				} );
			} catch {
				// Non-fatal — category dropdown shows placeholder only
				dispatch( { type: SET_CATEGORIES, categories: [] } );
			}
		};
	},
};

// ---------------------------------------------------------------------------
// Selectors
// ---------------------------------------------------------------------------
const selectors = {
	getAbilities: ( state ) => state.abilities,
	getTotal: ( state ) => state.total,
	getPages: ( state ) => state.pages,
	getCategories: ( state ) => state.categories,
	getIsLoading: ( state ) => state.isLoading,
	getIsSaving: ( state ) => state.isSaving,
	getError: ( state ) => state.error,
	getSaveError: ( state ) => state.saveError,
	getView: ( state ) => state.view,
	getActiveTab: ( state ) => state.activeTab,
	getListParams: ( state ) => state.listParams,
	getToolsetCounts: ( state ) => state.toolsetCounts,
	getToolsetLabels: ( state ) => state.toolsetLabels,
	getToolsetTotal: ( state ) => state.toolsetTotal,
	getSavedAbility: ( state ) => state.savedAbility,
	getDraftAbility: ( state ) => state.draftAbility,
	getIsDirty: ( state ) => state.isDirty,
};

// ---------------------------------------------------------------------------
// Register store
// ---------------------------------------------------------------------------
export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
} );

register( store );
