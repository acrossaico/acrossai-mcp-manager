/**
 * AbilityForm — full-page form for create / edit.
 *
 * Variant A (source=db): create + edit modes — editable identity, callback, schema, MCP, annotations.
 * Variant B (source≠db, isNonDb=true): editable override fields only (identity inherited from registry).
 *
 * Layout: unified single `.panel` with numbered `.sect` sections, matching Edit Form Wireframe.
 *
 * Save model (explicit, not auto-save):
 *   - savedAbility: last-fetched server state (null for Add New)
 *   - draftAbility: current form state (merged from store)
 *   - isDirty: JSON.stringify(draft) !== JSON.stringify(saved)
 *   - "● Unsaved changes" indicator shown when isDirty
 *   - beforeunload guard registered in AbilitiesManager when isDirty
 *   - Save triggers POST /abilities (create) or POST /abilities/{id} (update)
 *
 * SC-007 override identity lock: override save payload never includes
 *   ability_slug, slug_suffix, source, status, input_schema, output_schema.
 *
 * @since 0.2.0
 */
import {
	useState,
	useEffect,
	useCallback,
	useMemo,
	useRef,
	Fragment,
} from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters, doAction } from '@wordpress/hooks';
import { STORE_NAME } from '../store/index';
import SourceBadge from './cells/SourceBadge';
import CallbackConfigField from './CallbackConfigField';
import { AccessControl } from '@wpb/access-control';

const SLUG_PREFIX = 'acrossai/';
const SLUG_PATTERN = /^[a-z0-9-]+$/;
const abilitiesConfig = window.acrossaiAbilitiesManager || {};

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function jsonParseOrNull(str) {
	if (!str) {
		return null;
	}
	try {
		return JSON.parse(str);
	} catch {
		return null;
	}
}

function formatDate(iso) {
	if (!iso) {
		return '—';
	}
	try {
		return new Date(iso).toLocaleString();
	} catch {
		return iso;
	}
}

/**
 * Validate all four required fields for create/edit mode.
 *
 * Returns an error-string object; empty string means no error.
 * This is a pure module-level helper with no side-effects.
 *
 * @param {Object} ability    Current draftAbility store value.
 * @param {string} slugSuffix Current slug suffix local state.
 * @return {Object} Error map for slug_suffix, label, description, category.
 */
export function validateRequiredFields(ability, slugSuffix) {
	const required = __(
		'This field is required.',
		'acrossai-abilities-manager'
	);
	return {
		slug_suffix: (slugSuffix || '').trim() ? '' : required,
		label: (ability.label || '').trim() ? '' : required,
		description: (ability.description || '').trim() ? '' : required,
		category: (ability.category || '').trim() ? '' : required,
	};
}

// ---------------------------------------------------------------------------
// Site permission segmented control (TGC) for Variant B
// ---------------------------------------------------------------------------
const PERMISSION_CHIPS = [
	{
		value: 0,
		label: __('Force Block', 'acrossai-abilities-manager'),
	},
	{
		value: null,
		label: __('Inherit', 'acrossai-abilities-manager'),
	},
	{
		value: 1,
		label: __('Force Allow', 'acrossai-abilities-manager'),
	},
];

function SitePermissionTGC({ value, onChange }) {
	// Normalize: true→1, false→0
	let normalized = value;
	if (true === value) {
		normalized = 1;
	} else if (false === value) {
		normalized = 0;
	}
	return (
		<div className="tgc">
			{PERMISSION_CHIPS.map((chip) => {
				let chipNorm = chip.value;
				if (true === chip.value) {
					chipNorm = 1;
				} else if (false === chip.value) {
					chipNorm = 0;
				}
				const isOn = chipNorm === normalized;
				return (
					<button
						key={String(chip.value)}
						type="button"
						className={`tgc-opt${isOn ? ' on' : ''}`}
						onClick={() => onChange(chip.value)}
					>
						{chip.label}
					</button>
				);
			})}
		</div>
	);
}

// ---------------------------------------------------------------------------
// Callback type chips for Variant A
// ---------------------------------------------------------------------------
const CALLBACK_CHIPS = [
	{ value: null, label: 'keep as default' },
	{ value: 'noop', label: 'noop' },
	{ value: 'filter_hook', label: 'filter_hook' },
	{ value: 'wp_remote_post', label: 'wp_remote_post' },
	{ value: 'php_code', label: 'php_code' },
];

function CallbackTypeChips({ value, onChange, disabled = false }) {
	return (
		<div className="chips">
			{CALLBACK_CHIPS.map((chip) => (
				<button
					key={chip.value}
					type="button"
					className={`chip${chip.value === value ? ' on' : ''}${disabled ? ' chip--locked' : ''}`}
					disabled={disabled}
					onClick={disabled ? undefined : () => onChange(chip.value)}
				>
					{chip.label}
				</button>
			))}
		</div>
	);
}

// ---------------------------------------------------------------------------
// TriChips: generic chip-button row for tri/multi-state fields
// options = [{ value: null|bool|string, label: string }, ...]
// "default" option should carry value: null
// ---------------------------------------------------------------------------
function TriChips({ label, value, onChange, hint, options, disabled }) {
	return (
		<div className="fr">
			<label className="fl">{label}</label>
			<div className="ff">
				<div className="chips">
					{options.map((opt, i) => (
						<button
							key={i}
							type="button"
							className={`chip${opt.value === value ? ' on' : ''}`}
							disabled={!!disabled}
							onClick={() => !disabled && onChange(opt.value)}
						>
							{opt.label}
						</button>
					))}
				</div>
				{hint && <div className="desc">{hint}</div>}
			</div>
		</div>
	);
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

/**
 * AbilityForm component.
 *
 * @param {Object} props
 * @param {string} props.mode           'create' | 'edit'
 * @param {string} [props.slug]         Ability slug (required for edit mode)
 * @param          props.initialAbility
 * @return {JSX.Element}
 */
export default function AbilityForm({ mode, slug, initialAbility }) {
	const dispatch = useDispatch(STORE_NAME);

	const {
		savedAbility,
		draftAbility,
		isDirty,
		isSaving,
		saveError,
		categories,
	} = useSelect(
		(select) => ({
			savedAbility: select(STORE_NAME).getSavedAbility(),
			draftAbility: select(STORE_NAME).getDraftAbility(),
			isDirty: select(STORE_NAME).getIsDirty(),
			isSaving: select(STORE_NAME).getIsSaving(),
			saveError: select(STORE_NAME).getSaveError(),
			categories: select(STORE_NAME).getCategories(),
		}),
		[]
	);

	// Local state for slug suffix (strips prefix for display)
	const [slugSuffix, setSlugSuffix] = useState('');
	const [slugError, setSlugError] = useState('');
	const [formErrors, setFormErrors] = useState({
		slug_suffix: '',
		label: '',
		description: '',
		category: '',
	});

	// JSON validation states for schema fields
	const [inputSchemaError, setInputSchemaError] = useState('');
	const [outputSchemaError, setOutputSchemaError] = useState('');

	// Raw string values for schema textareas
	const [inputSchemaRaw, setInputSchemaRaw] = useState('');
	const [outputSchemaRaw, setOutputSchemaRaw] = useState('');

	// Tracks current AC provider key + options as reported by the AccessControl component.
	// null = component not yet mounted or not yet reported its first state (T025).
	const [acState, setAcState] = useState(null);
	const acInitialRef = useRef(null); // baseline set on first onChange call (initial load)
	const [isAcDirty, setIsAcDirty] = useState(false);

	// Callback config as parsed object (kept in sync with draftAbility.callback_config)
	const callbackType = draftAbility.callback_type || 'noop';
	const callbackConfig = draftAbility.callback_config || {};

	// ---------------------------------------------------------------------------
	// On mount: load ability data, fetch categories
	// ---------------------------------------------------------------------------
	useEffect(() => {
		dispatch.fetchCategories();
		if ('create' === mode) {
			dispatch.clearDraft();
			dispatch.setSaved(null);
		} else if (slug) {
			// RT-9: Pre-seed from list data to prevent blank flash.
			if (initialAbility) {
				dispatch.setSaved(initialAbility);
			}
			dispatch.fetchAbility(slug);
		}
	}, [mode, slug]); // eslint-disable-line react-hooks/exhaustive-deps

	// Sync slug suffix when savedAbility changes (edit/override: pre-populate)
	useEffect(() => {
		// Reset stale validation errors when ability loads or after a successful save
		// (FR-016, CLARIFY-Q2/B: no errors on page load).
		setFormErrors({
			slug_suffix: '',
			label: '',
			description: '',
			category: '',
		});
		if (savedAbility?.ability_slug) {
			const slug = savedAbility.ability_slug;
			setSlugSuffix(
				slug.startsWith(SLUG_PREFIX)
					? slug.slice(SLUG_PREFIX.length)
					: slug
			);
		} else {
			setSlugSuffix('');
		}
	}, [savedAbility]);

	// Sync schema textarea raw strings from draftAbility
	useEffect(() => {
		const inRaw = draftAbility.input_schema
			? JSON.stringify(draftAbility.input_schema, null, 2)
			: '';
		const outRaw = draftAbility.output_schema
			? JSON.stringify(draftAbility.output_schema, null, 2)
			: '';
		setInputSchemaRaw(inRaw);
		setOutputSchemaRaw(outRaw);
	}, [savedAbility]); // Only reset on ability load, not on every draft change

	// Fire draft_changed action on every React commit where draft reference changes.
	// No internal debouncing — subscribers own their own throttle/debounce policy (FR-005).
	useEffect(() => {
		doAction('acrossai_abilities.form.draft_changed', draftAbility);
	}, [draftAbility]);

	// ---------------------------------------------------------------------------
	// Patch helpers
	// ---------------------------------------------------------------------------
	const patch = useCallback(
		(changes) => {
			dispatch.updateDraft(changes);
		},
		[dispatch]
	);

	// Receives current AC selection from the AccessControl component (T025).
	// First call = initial data load; subsequent calls = user interaction.
	const handleAcChange = useCallback((key, options) => {
		setAcState({ key, options });
		if (acInitialRef.current === null) {
			// Baseline: store what was loaded so we can detect real changes.
			acInitialRef.current = { key, options: [...options] };
		} else {
			const initKey = acInitialRef.current.key;
			const initOpts = acInitialRef.current.options;
			setIsAcDirty(
				key !== initKey ||
					JSON.stringify(options) !== JSON.stringify(initOpts)
			);
		}
	}, []);

	// ---------------------------------------------------------------------------
	// Slug input handler
	// ---------------------------------------------------------------------------
	function handleSlugChange(e) {
		const raw = e.target.value;
		setSlugSuffix(raw);
		if (raw.trim()) {
			setFormErrors((prev) => ({ ...prev, slug_suffix: '' }));
		}
		if (raw && !SLUG_PATTERN.test(raw)) {
			setSlugError(
				__(
					'Slug may only contain lowercase letters, numbers, and hyphens.',
					'acrossai-abilities-manager'
				)
			);
		} else {
			setSlugError('');
		}
		patch({ ability_slug: raw ? SLUG_PREFIX + raw : '' });
	}

	// ---------------------------------------------------------------------------
	// Schema validation on blur
	// ---------------------------------------------------------------------------
	function handleInputSchemaBlur() {
		if (!inputSchemaRaw) {
			setInputSchemaError('');
			patch({ input_schema: null });
			return;
		}
		const parsed = jsonParseOrNull(inputSchemaRaw);
		if (null === parsed) {
			setInputSchemaError(
				__('Invalid JSON.', 'acrossai-abilities-manager')
			);
		} else {
			setInputSchemaError('');
			patch({ input_schema: parsed });
		}
	}

	function handleOutputSchemaBlur() {
		if (!outputSchemaRaw) {
			setOutputSchemaError('');
			patch({ output_schema: null });
			return;
		}
		const parsed = jsonParseOrNull(outputSchemaRaw);
		if (null === parsed) {
			setOutputSchemaError(
				__('Invalid JSON.', 'acrossai-abilities-manager')
			);
		} else {
			setOutputSchemaError('');
			patch({ output_schema: parsed });
		}
	}

	// ---------------------------------------------------------------------------
	// Required-field blur validators
	// ---------------------------------------------------------------------------
	function handleSlugBlur() {
		if ('create' !== mode && 'edit' !== mode) {
			return;
		}
		if (isNonDb && isEdit) {
			return;
		}
		setFormErrors((prev) => ({
			...prev,
			slug_suffix: (slugSuffix || '').trim()
				? ''
				: __('This field is required.', 'acrossai-abilities-manager'),
		}));
	}
	function handleLabelBlur() {
		if ('create' !== mode && 'edit' !== mode) {
			return;
		}
		if (isNonDb && isEdit) {
			return;
		}
		setFormErrors((prev) => ({
			...prev,
			label: (draftAbility.label || '').trim()
				? ''
				: __('This field is required.', 'acrossai-abilities-manager'),
		}));
	}
	function handleDescriptionBlur() {
		if ('create' !== mode && 'edit' !== mode) {
			return;
		}
		if (isNonDb && isEdit) {
			return;
		}
		setFormErrors((prev) => ({
			...prev,
			description: (draftAbility.description || '').trim()
				? ''
				: __('This field is required.', 'acrossai-abilities-manager'),
		}));
	}
	function handleCategoryBlur() {
		if ('create' !== mode && 'edit' !== mode) {
			return;
		}
		if (isNonDb && isEdit) {
			return;
		}
		setFormErrors((prev) => ({
			...prev,
			category: (draftAbility.category || '').trim()
				? ''
				: __('This field is required.', 'acrossai-abilities-manager'),
		}));
	}

	// ---------------------------------------------------------------------------
	// Save handlers
	// ---------------------------------------------------------------------------
	async function handleSave(forceDraft = false) {
		// Required-field gate — applies to ALL save paths in create/edit mode,
		// including forceDraft=true (CLARIFY-Q5/A: no bypass).
		if (('create' === mode || 'edit' === mode) && !isNonDb) {
			const errors = validateRequiredFields(draftAbility, slugSuffix);
			setFormErrors(errors);
			if (Object.values(errors).some(Boolean)) {
				return;
			}
		}

		const data = { ...draftAbility };
		if (forceDraft) {
			data.status = 'draft';
		} else if ('create' === mode) {
			// US3: Primary save creates as published, not draft.
			data.status = 'publish';
		}

		if ('create' === mode) {
			// REST create endpoint expects slug_suffix (without prefix), not ability_slug.
			const fullSlug = data.ability_slug || '';
			data.slug_suffix = fullSlug.startsWith(SLUG_PREFIX)
				? fullSlug.slice(SLUG_PREFIX.length)
				: fullSlug;
			delete data.ability_slug;
			const payload = applyFilters(
				'acrossai_abilities.form.save_payload',
				data,
				{
					abilityId: savedAbility?.id ?? null,
					slug: savedAbility?.ability_slug ?? null,
					isNonDb,
				}
			);
			const ability = await dispatch.createAbility(payload);
			if (ability) {
				dispatch.setSaved(ability);
				dispatch.setView({ mode: 'edit', slug: ability.ability_slug });
			}
			return;
		}

		if ('edit' === mode) {
			let payload;
			if (isNonDb) {
				// Non-db: send only overridable fields (never identity)
				payload = {
					label: data.label,
					description: data.description,
					category: data.category,
					site_allowed: data.site_allowed,
					show_in_rest: data.show_in_rest,
					show_in_mcp: data.show_in_mcp,
					mcp_type: data.mcp_type,
					readonly: data.readonly,
					destructive: data.destructive,
					idempotent: data.idempotent,
				};
			} else {
				payload = data;
			}
			payload = applyFilters(
				'acrossai_abilities.form.save_payload',
				payload,
				{
					abilityId: savedAbility?.id ?? null,
					slug: savedAbility?.ability_slug ?? null,
					isNonDb,
				}
			);
			await dispatch.updateAbility(slug, payload);

			// Save AC state as part of the single save (T026).
			// Only runs in edit/override mode — no slug exists in create mode (FR-014).
			// SEC-006 hardening: guard against `access_control_slug` being undefined
			// (stale browser cache serving pre-Feature 039 HTML) — silently skip the AC
			// save rather than issuing a request to /wpb-ac/v1/undefined/... The ability
			// save itself has already succeeded above; RT-AR-001 allows retry.
			if (
				!isCreate &&
				savedAbility?.ability_slug &&
				acState !== null &&
				abilitiesConfig.access_control_slug
			) {
				const acUrl = `${abilitiesConfig.rest_url}/wpb-ac/v1/${abilitiesConfig.access_control_slug}/rules/acrossai-abilities/${savedAbility.ability_slug}`;
				let acSaveOk = false;
				try {
					if (acState.key === '') {
						await apiFetch({ url: acUrl, method: 'DELETE' });
					} else {
						await apiFetch({
							url: acUrl,
							method: 'PUT',
							data: {
								ac_key: acState.key,
								ac_options: acState.options,
							},
						});
					}
					acSaveOk = true;
				} catch (acErr) {
					// AC save failed — ability was saved successfully. Log but do not
					// block the success flow; the user can retry by saving again (RT-AR-001).
					// eslint-disable-next-line no-console
					console.error('Access control save failed:', acErr);
				}
				// Reset dirty state only on success — preserves retry ability on failure (RT-AR-001).
				if (acSaveOk) {
					acInitialRef.current = acState;
					setIsAcDirty(false);
				}
			}
		}
	}

	async function handleDelete() {
		if (
			// eslint-disable-next-line no-alert
			window.confirm(
				__(
					'Delete this ability? This cannot be undone.',
					'acrossai-abilities-manager'
				)
			)
		) {
			await dispatch.deleteAbility(slug);
		}
	}

	async function handleClearOverrides() {
		if (
			// eslint-disable-next-line no-alert
			window.confirm(
				__(
					'Clear all overrides? This will restore inherited values.',
					'acrossai-abilities-manager'
				)
			)
		) {
			await dispatch.clearOverrides(slug);
		}
	}

	function handleCancel() {
		dispatch.setView('list');
	}

	// ---------------------------------------------------------------------------
	// Derived state
	// ---------------------------------------------------------------------------
	const breadcrumbSlug =
		savedAbility?.ability_slug ||
		__('Add New', 'acrossai-abilities-manager');
	const isCreate = 'create' === mode;
	const isEdit = 'edit' === mode;
	// isNonDb: ability is from plugin/theme/core (non-db source).
	const isNonDb = !!(savedAbility && 'db' !== savedAbility.source);

	// True when any required field is missing — used for CSS-only button dimming.
	// Always false for non-db abilities (isNonDb=true): identity comes from registry.
	const hasRequiredErrors =
		isCreate || (isEdit && !isNonDb)
			? !slugSuffix.trim() ||
				!(draftAbility.label || '').trim() ||
				!(draftAbility.description || '').trim() ||
				!(draftAbility.category || '').trim()
			: false;

	// Save button label (non-db abilities show 'Actions')
	let saveBtnLabel;
	if (isSaving) {
		saveBtnLabel = __('Saving…', 'acrossai-abilities-manager');
	} else if (isCreate) {
		saveBtnLabel = __('✓ Add Ability', 'acrossai-abilities-manager');
	} else if (isNonDb) {
		saveBtnLabel = __('Actions', 'acrossai-abilities-manager');
	} else if (isEdit) {
		saveBtnLabel = __('✓ Save Changes', 'acrossai-abilities-manager');
	} else {
		saveBtnLabel = __('✓ Save Changes', 'acrossai-abilities-manager');
	}

	// ---------------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------------
	return (
		<div className="wrap">
			{/* Breadcrumb */}
			<div className="abilities-breadcrumb">
				<button
					type="button"
					className="button-link"
					onClick={() => dispatch.setView('list')}
				>
					{__('All Abilities', 'acrossai-abilities-manager')}
				</button>
				<span className="bc-sep">›</span>
				<span className="bc-slug">{breadcrumbSlug}</span>
			</div>

			{/* Page title row with inline hactions */}
			<div className="abilities-page-title">
				<div>
					<h1>
						{isCreate &&
							__('Add New Ability', 'acrossai-abilities-manager')}
						{(isEdit || isNonDb) &&
							__('Edit Ability', 'acrossai-abilities-manager')}
						{(isEdit || isNonDb) && savedAbility && (
							<span className="h1-meta">
								<SourceBadge
									source={savedAbility.source || 'db'}
								/>
								{(isDirty || isAcDirty) && (
									<span className="unsaved">
										<span className="udot" />
										{__(
											'Unsaved changes',
											'acrossai-abilities-manager'
										)}
									</span>
								)}
							</span>
						)}
					</h1>
				</div>
				<div className="hactions">
					<button
						type="button"
						className="button"
						onClick={handleCancel}
					>
						{__('Cancel', 'acrossai-abilities-manager')}
					</button>
					<button
						type="button"
						className="button button-primary"
						style={
							hasRequiredErrors
								? { opacity: 0.5, pointerEvents: 'none' }
								: undefined
						}
						aria-disabled={hasRequiredErrors}
						disabled={
							isSaving || (!isCreate && !isDirty && !isAcDirty)
						}
						onClick={() => handleSave(false)}
					>
						{saveBtnLabel}
					</button>
				</div>
			</div>

			{isCreate && (
				<p className="abilities-subtitle">
					{__(
						'Define a new custom ability. Fields marked',
						'acrossai-abilities-manager'
					)}{' '}
					<span style={{ color: '#d63638' }}>*</span>{' '}
					{__('are required.', 'acrossai-abilities-manager')}
				</p>
			)}

			{isNonDb && savedAbility && (
				<p className="abilities-subtitle">
					{__('Identity defined by', 'acrossai-abilities-manager')}{' '}
					<strong>
						{savedAbility.provider ||
							__(
								'an external source',
								'acrossai-abilities-manager'
							)}
					</strong>
					{' — '}
					{__(
						'read-only. You can override site permission, MCP exposure, annotations, and access control.',
						'acrossai-abilities-manager'
					)}
				</p>
			)}

			{/* Save error notice */}
			{saveError && (
				<div className="notice notice-error">
					<p>{saveError}</p>
				</div>
			)}

			{/* Form layout: main + sticky sidebar */}
			<div className="form-layout">
				{/* ===== MAIN COLUMN ===== */}
				<div className="form-main">
					{/* ——— Unified panel ——— */}
					<div className="panel">
						{/* ── VARIANT A: Section 1 — Identity ── */}
						<div className="sect">
							<div className="sect-hdr">
								<div className="sect-title">
									<span className="sect-num">1</span>
									{__(
										'Identity',
										'acrossai-abilities-manager'
									)}
								</div>
								<div className="sect-desc">
									{__(
										"What this ability is called and how it's looked up across REST routes and MCP manifests.",
										'acrossai-abilities-manager'
									)}
								</div>
							</div>

							{/* Slug */}
							<div className="fr">
								<label
									htmlFor="ability-slug-suffix"
									className="fl"
								>
									{__('Slug', 'acrossai-abilities-manager')}
									<span className="req"> *</span>
								</label>
								<div className="ff">
									<div className="pxwrap">
										<div className="pxtxt">
											{SLUG_PREFIX}
										</div>
										<input
											id="ability-slug-suffix"
											type="text"
											className="pxinp"
											value={slugSuffix}
											placeholder={__(
												'my-ability-name',
												'acrossai-abilities-manager'
											)}
											onChange={handleSlugChange}
											onBlur={handleSlugBlur}
											readOnly={!isCreate}
										/>
									</div>
									{slugError && (
										<div className="field-error">
											{slugError}
										</div>
									)}
									{('create' === mode || 'edit' === mode) &&
										formErrors.slug_suffix && (
											<div
												className="field-error"
												role="alert"
												aria-live="polite"
											>
												{formErrors.slug_suffix}
											</div>
										)}
									<div className="desc">
										{__(
											'Lowercase letters, numbers, and dashes only.',
											'acrossai-abilities-manager'
										)}
									</div>
								</div>
							</div>

							{/* Label */}
							<div className="fr">
								<label htmlFor="ability-label" className="fl">
									{__('Label', 'acrossai-abilities-manager')}
									<span className="req"> *</span>
								</label>
								<div className="ff">
									<input
										id="ability-label"
										type="text"
										className="ri"
										placeholder={__(
											'e.g. Summarize Post',
											'acrossai-abilities-manager'
										)}
										value={draftAbility.label || ''}
										onChange={(e) => {
											patch({ label: e.target.value });
											if (e.target.value.trim()) {
												setFormErrors((prev) => ({
													...prev,
													label: '',
												}));
											}
										}}
										onBlur={handleLabelBlur}
									/>
									{('create' === mode || 'edit' === mode) &&
										formErrors.label && (
											<div
												className="field-error"
												role="alert"
												aria-live="polite"
											>
												{formErrors.label}
											</div>
										)}
									{isNonDb &&
										savedAbility?._registry?.label && (
											<div className="desc">
												{__(
													'Plugin declares:',
													'acrossai-abilities-manager'
												)}{' '}
												{savedAbility._registry.label}
											</div>
										)}
								</div>
							</div>

							{/* Category */}
							<div className="fr">
								<label
									htmlFor="ability-category"
									className="fl"
								>
									{__(
										'Category',
										'acrossai-abilities-manager'
									)}
									<span className="req"> *</span>
								</label>
								<div className="ff">
									<select
										id="ability-category"
										className="rs"
										style={{ maxWidth: '280px' }}
										value={draftAbility.category || ''}
										onChange={(e) => {
											patch({ category: e.target.value });
											if (e.target.value.trim()) {
												setFormErrors((prev) => ({
													...prev,
													category: '',
												}));
											}
										}}
										onBlur={handleCategoryBlur}
									>
										<option value="">
											{__(
												'Default',
												'acrossai-abilities-manager'
											)}
										</option>
										{categories.map((cat) => (
											<option
												key={cat.slug}
												value={cat.slug}
											>
												{cat.label || cat.slug}
											</option>
										))}
									</select>
									{('create' === mode || 'edit' === mode) &&
										formErrors.category && (
											<div
												className="field-error"
												role="alert"
												aria-live="polite"
											>
												{formErrors.category}
											</div>
										)}
									{isNonDb &&
										savedAbility?._registry?.category && (
											<div className="desc">
												{__(
													'Plugin declares:',
													'acrossai-abilities-manager'
												)}{' '}
												{
													savedAbility._registry
														.category
												}
											</div>
										)}
								</div>
							</div>

							{/* Description */}
							<div className="fr">
								<label
									htmlFor="ability-description"
									className="fl"
								>
									{__(
										'Description',
										'acrossai-abilities-manager'
									)}
									<span className="req"> *</span>
								</label>
								<div className="ff">
									<textarea
										id="ability-description"
										className="rt"
										rows="3"
										maxLength={1000}
										placeholder={__(
											'Describe what this ability does. This will appear to AI agents during discovery.',
											'acrossai-abilities-manager'
										)}
										value={draftAbility.description || ''}
										onChange={(e) => {
											patch({
												description: e.target.value,
											});
											if (e.target.value.trim()) {
												setFormErrors((prev) => ({
													...prev,
													description: '',
												}));
											}
										}}
										onBlur={handleDescriptionBlur}
									/>
									{('create' === mode || 'edit' === mode) &&
										formErrors.description && (
											<div
												className="field-error"
												role="alert"
												aria-live="polite"
											>
												{formErrors.description}
											</div>
										)}
									{isNonDb &&
										savedAbility?._registry
											?.description && (
											<div className="desc">
												{__(
													'Plugin declares:',
													'acrossai-abilities-manager'
												)}{' '}
												{
													savedAbility._registry
														.description
												}
											</div>
										)}
								</div>
							</div>

							{/* Status (publish/draft) */}
							<div className="fr">
								<label htmlFor="ability-status" className="fl">
									{__('Status', 'acrossai-abilities-manager')}
								</label>
								<div className="ff">
									{isNonDb ? (
										<div className="togrow">
											<span className="toglbl">
												<strong>
													{__(
														'Published',
														'acrossai-abilities-manager'
													)}
												</strong>
											</span>
										</div>
									) : (
										<>
											<div className="togrow">
												<button
													type="button"
													id="ability-status"
													role="switch"
													aria-checked={
														'draft' !==
														(draftAbility.status ??
															'publish')
															? 'true'
															: 'false'
													}
													className={`wptog${
														'draft' !==
														(draftAbility.status ??
															'publish')
															? ' on'
															: ''
													}`}
													onClick={() =>
														patch({
															status:
																'draft' !==
																(draftAbility.status ??
																	'publish')
																	? 'draft'
																	: 'publish',
														})
													}
												/>
												<span className="toglbl">
													{'draft' !==
													(draftAbility.status ??
														'publish') ? (
														<>
															<strong>
																{__(
																	'Published',
																	'acrossai-abilities-manager'
																)}
															</strong>
															{' — '}
															{__(
																'registered on every page load',
																'acrossai-abilities-manager'
															)}
														</>
													) : (
														<>
															<strong>
																{__(
																	'Draft',
																	'acrossai-abilities-manager'
																)}
															</strong>
															{' — '}
															{__(
																'saved but not registered',
																'acrossai-abilities-manager'
															)}
														</>
													)}
												</span>
											</div>
											<div className="desc">
												{__(
													'When draft, the ability is saved but not registered with WordPress on each request.',
													'acrossai-abilities-manager'
												)}
											</div>
										</>
									)}
								</div>
							</div>
						</div>

						{/* ── VARIANT B: Section 2 — Site Permission ── */}
						{isNonDb && (
							<div className="sect">
								<div className="sect-hdr">
									<div className="sect-title">
										<span className="sect-num">2</span>
										{__(
											'Site Permission',
											'acrossai-abilities-manager'
										)}
									</div>
									<div className="sect-desc">
										{__(
											'Override whether this ability is allowed or blocked site-wide.',
											'acrossai-abilities-manager'
										)}
									</div>
								</div>
								<div className="fr">
									<label className="fl">
										{__(
											'Site Access',
											'acrossai-abilities-manager'
										)}
									</label>
									<div className="ff">
										<SitePermissionTGC
											value={draftAbility.site_allowed}
											onChange={(v) =>
												patch({ site_allowed: v })
											}
										/>
										<div className="desc">
											<strong>
												{__(
													'Inherit',
													'acrossai-abilities-manager'
												)}
											</strong>{' '}
											{__(
												"respects the plugin's own setting. Force Allow/Block override regardless.",
												'acrossai-abilities-manager'
											)}
										</div>
									</div>
								</div>
							</div>
						)}

						{/* ── Section 3 — MCP Exposure ── */}
						<div className="sect">
							<div className="sect-hdr">
								<div className="sect-title">
									<span className="sect-num">3</span>
									{isNonDb
										? __(
												'MCP Exposure',
												'acrossai-abilities-manager'
											)
										: __(
												'MCP Exposure',
												'acrossai-abilities-manager'
											)}
								</div>
								<div className="sect-desc">
									{isNonDb
										? __(
												'Override how this ability appears to MCP clients. Leave as "inherit" to use the plugin\'s declared values.',
												'acrossai-abilities-manager'
											)
										: __(
												'How this ability appears to MCP clients.',
												'acrossai-abilities-manager'
											)}
								</div>
								<div className="sect-note-warn" role="note">
									<strong>
										{__(
											'Heads up:',
											'acrossai-abilities-manager'
										)}
									</strong>{' '}
									{__(
										'Enabling MCP here applies this ability to all MCP servers on this site.',
										'acrossai-abilities-manager'
									)}
								</div>
								{!abilitiesConfig.mcp_manager_active && (
									<div
										className="sect-note-promo"
										role="note"
									>
										<span className="sect-note-promo__title">
											{__(
												'Want more control over your MCP Adapter?',
												'acrossai-abilities-manager'
											)}
										</span>
										<span className="sect-note-promo__body">
											{__(
												'Install the AcrossAI MCP Manager plugin to create and manage multiple MCP servers, fine-tune exposure per server, and unlock advanced adapter controls.',
												'acrossai-abilities-manager'
											)}
										</span>
										<span className="sect-note-promo__actions">
											{abilitiesConfig.mcp_manager_addons_url && (
												<a
													className="sect-note-promo__btn sect-note-promo__btn--primary"
													href={
														abilitiesConfig.mcp_manager_addons_url
													}
												>
													{__(
														'Install from Add-ons',
														'acrossai-abilities-manager'
													)}
												</a>
											)}
											{abilitiesConfig.mcp_manager_info_url && (
												<a
													className="sect-note-promo__btn sect-note-promo__btn--link"
													href={
														abilitiesConfig.mcp_manager_info_url
													}
													target="_blank"
													rel="noopener noreferrer"
												>
													{__(
														'Learn more',
														'acrossai-abilities-manager'
													)}
												</a>
											)}
										</span>
									</div>
								)}
							</div>

							{/* Show in MCP */}
							<TriChips
								label={__(
									'Show in MCP',
									'acrossai-abilities-manager'
								)}
								value={draftAbility.show_in_mcp ?? null}
								onChange={(v) => patch({ show_in_mcp: v })}
								options={[
									{
										value: null,
										label: __(
											'default',
											'acrossai-abilities-manager'
										),
									},
									{
										value: true,
										label: __(
											'enable',
											'acrossai-abilities-manager'
										),
									},
									{
										value: false,
										label: __(
											'disable',
											'acrossai-abilities-manager'
										),
									},
								]}
								hint={
									isNonDb &&
									null !==
										savedAbility?._registry?.show_in_mcp
										? `${__('Plugin declares:', 'acrossai-abilities-manager')} ${true === savedAbility._registry.show_in_mcp ? 'yes' : 'no'}`
										: null
								}
							/>

							{/* MCP Type */}
							<TriChips
								label={__(
									'MCP Type',
									'acrossai-abilities-manager'
								)}
								value={draftAbility.mcp_type ?? null}
								onChange={(v) => patch({ mcp_type: v })}
								options={[
									{
										value: null,
										label: __(
											'default',
											'acrossai-abilities-manager'
										),
									},
									{ value: 'tool', label: 'tool' },
									{ value: 'resource', label: 'resource' },
									{ value: 'prompt', label: 'prompt' },
								]}
								hint={
									isNonDb && savedAbility?._registry?.mcp_type
										? `${__('Plugin declares:', 'acrossai-abilities-manager')} ${savedAbility._registry.mcp_type}`
										: null
								}
							/>

							{applyFilters(
								'acrossai_abilities.form.extra_sections',
								[],
								{
									abilityId: savedAbility?.id ?? null,
									slug: savedAbility?.ability_slug ?? null,
									draft: draftAbility,
									isNonDb,
								}
							).map((node, i) => (
								<Fragment key={i}>{node}</Fragment>
							))}
						</div>

						{/* ── Section 4 — Annotations ── */}
						<div className="sect">
							<div className="sect-hdr">
								<div className="sect-title">
									<span className="sect-num">4</span>
									{isNonDb
										? __(
												'Annotation Overrides',
												'acrossai-abilities-manager'
											)
										: __(
												'Annotations',
												'acrossai-abilities-manager'
											)}
								</div>
								<div className="sect-desc">
									{isNonDb
										? __(
												"inherit defers to the plugin's declared value; yes/no force the annotation regardless.",
												'acrossai-abilities-manager'
											)
										: __(
												"Tri-state metadata hints for MCP clients. inherit defers to the callback type's default.",
												'acrossai-abilities-manager'
											)}
								</div>
							</div>

							<TriChips
								label={__(
									'Readonly',
									'acrossai-abilities-manager'
								)}
								value={draftAbility.readonly ?? null}
								onChange={(v) => patch({ readonly: v })}
								options={[
									{
										value: null,
										label: __(
											'default',
											'acrossai-abilities-manager'
										),
									},
									{
										value: true,
										label: __(
											'yes',
											'acrossai-abilities-manager'
										),
									},
									{
										value: false,
										label: __(
											'no',
											'acrossai-abilities-manager'
										),
									},
								]}
								hint={
									isNonDb &&
									null !== savedAbility?._registry?.readonly
										? `${__('Plugin declares:', 'acrossai-abilities-manager')} ${true === savedAbility._registry.readonly ? 'yes' : 'no'}`
										: __(
												'Does this ability mutate state?',
												'acrossai-abilities-manager'
											)
								}
							/>
							<TriChips
								label={__(
									'Destructive',
									'acrossai-abilities-manager'
								)}
								value={draftAbility.destructive ?? null}
								onChange={(v) => patch({ destructive: v })}
								options={[
									{
										value: null,
										label: __(
											'default',
											'acrossai-abilities-manager'
										),
									},
									{
										value: true,
										label: __(
											'yes',
											'acrossai-abilities-manager'
										),
									},
									{
										value: false,
										label: __(
											'no',
											'acrossai-abilities-manager'
										),
									},
								]}
								hint={
									isNonDb &&
									null !==
										savedAbility?._registry?.destructive
										? `${__('Plugin declares:', 'acrossai-abilities-manager')} ${true === savedAbility._registry.destructive ? 'yes' : 'no'}`
										: __(
												'Can data be permanently lost?',
												'acrossai-abilities-manager'
											)
								}
							/>
							<TriChips
								label={__(
									'Idempotent',
									'acrossai-abilities-manager'
								)}
								value={draftAbility.idempotent ?? null}
								onChange={(v) => patch({ idempotent: v })}
								options={[
									{
										value: null,
										label: __(
											'default',
											'acrossai-abilities-manager'
										),
									},
									{
										value: true,
										label: __(
											'yes',
											'acrossai-abilities-manager'
										),
									},
									{
										value: false,
										label: __(
											'no',
											'acrossai-abilities-manager'
										),
									},
								]}
								hint={
									isNonDb &&
									null !== savedAbility?._registry?.idempotent
										? `${__('Plugin declares:', 'acrossai-abilities-manager')} ${true === savedAbility._registry.idempotent ? 'yes' : 'no'}`
										: __(
												'Safe to call multiple times with the same input?',
												'acrossai-abilities-manager'
											)
								}
							/>
							{isNonDb && (
								<TriChips
									label={__(
										'Show in REST',
										'acrossai-abilities-manager'
									)}
									value={draftAbility.show_in_rest ?? null}
									onChange={(v) => patch({ show_in_rest: v })}
									options={[
										{
											value: null,
											label: __(
												'default',
												'acrossai-abilities-manager'
											),
										},
										{
											value: true,
											label: __(
												'yes',
												'acrossai-abilities-manager'
											),
										},
										{
											value: false,
											label: __(
												'no',
												'acrossai-abilities-manager'
											),
										},
									]}
									hint={
										null !==
										savedAbility?._registry?.show_in_rest
											? `${__('Plugin declares:', 'acrossai-abilities-manager')} ${true === savedAbility._registry.show_in_rest ? 'yes' : 'no'}`
											: null
									}
								/>
							)}
						</div>
						{/* ── Section 5 — User Access ── */}
						<div className="sect">
							<div className="sect-hdr">
								<div className="sect-title">
									<span className="sect-num">5</span>
									{__(
										'User Access',
										'acrossai-abilities-manager'
									)}
								</div>
								<div className="sect-desc">
									{__(
										'Who can use this ability.',
										'acrossai-abilities-manager'
									)}
								</div>
							</div>

							{isCreate && (
								<p className="desc">
									{__(
										'Save this ability first to configure user access.',
										'acrossai-abilities-manager'
									)}
								</p>
							)}

							{!isCreate && (
								<p className="description">
									{abilitiesConfig.default_ability_capability
										? sprintf(
												/* translators: %s is a WordPress capability such as manage_options. */
												__(
													'With no rule set, this ability requires the %s capability — it is not unrestricted. A rule below replaces that requirement rather than adding to it.',
													'acrossai-abilities-manager'
												),
												abilitiesConfig.default_ability_capability
										  )
										: __(
												'With no rule set, this ability requires the site default capability — it is not unrestricted.',
												'acrossai-abilities-manager'
										  )}
								</p>
							)}

							{!isCreate &&
								!abilitiesConfig.access_control_available && (
									<p className="notice notice-warning inline-notice">
										{__(
											'User Access is inactive — the wpb-access-control library is not loaded.',
											'acrossai-abilities-manager'
										)}
									</p>
								)}

							{/* SEC-006 hardening: require access_control_slug to be present
							     (stale browser cache guard) before mounting the vendor component,
							     otherwise <AccessControl> would fetch /wpb-ac/v1/undefined/... */}
							{!isCreate &&
								savedAbility?.ability_slug &&
								abilitiesConfig.access_control_available &&
								abilitiesConfig.access_control_slug && (
									<AccessControl
										pluginSlug={
											abilitiesConfig.access_control_slug
										}
										namespace="acrossai-abilities"
										resourceKey={savedAbility.ability_slug}
										restApiRoot={
											abilitiesConfig.rest_url ||
											'/wp-json'
										}
										nonce={abilitiesConfig.nonce || ''}
										hideHeader={true}
										hideSaveButton={true}
										onChange={handleAcChange}
									/>
								)}
						</div>
						{/* ── VARIANT A: Section 6 — Callback ── */}
						<div className="sect">
							<div className="sect-hdr">
								<div className="sect-title">
									<span className="sect-num">6</span>
									{__(
										'Callback',
										'acrossai-abilities-manager'
									)}
								</div>
								<div className="sect-desc">
									{__(
										'How this ability resolves at runtime.',
										'acrossai-abilities-manager'
									)}
								</div>
							</div>
							<div className="fr">
								<label className="fl">
									{__('Type', 'acrossai-abilities-manager')}
									{!isNonDb && (
										<span className="req"> *</span>
									)}
								</label>
								<div className="ff">
									{isNonDb ? (
										<>
											<CallbackTypeChips
												value={
													savedAbility?._registry
														?.callback_type ?? null
												}
												onChange={() => {}}
												disabled={true}
											/>
											{savedAbility?._registry
												?.callback_type && (
												<div
													className="ecfg-disabled"
													style={{
														pointerEvents: 'none',
														opacity: 0.7,
													}}
												>
													<CallbackConfigField
														callbackType={
															savedAbility
																._registry
																.callback_type
														}
														config={
															savedAbility
																._registry
																.callback_config ||
															{}
														}
														onChange={() => {}}
													/>
												</div>
											)}
										</>
									) : (
										<>
											<CallbackTypeChips
												value={callbackType}
												onChange={(type) =>
													patch({
														callback_type: type,
														callback_config: {},
													})
												}
											/>
											<CallbackConfigField
												callbackType={callbackType}
												config={callbackConfig}
												onChange={(cfg) =>
													patch({
														callback_config: cfg,
													})
												}
											/>
										</>
									)}
								</div>
							</div>
						</div>

						{/* ── VARIANT A: Section 7 — Schema (optional) ── */}
						{(() => {
							const regInput =
								savedAbility?._registry?.input_schema ?? null;
							const regOutput =
								savedAbility?._registry?.output_schema ?? null;
							return (
								<div className="sect">
									<div className="sect-hdr">
										<div className="sect-title">
											<span className="sect-num">7</span>
											{__(
												'Schema',
												'acrossai-abilities-manager'
											)}
											{!isNonDb && (
												<span className="sect-opt">
													{__(
														'optional',
														'acrossai-abilities-manager'
													)}
												</span>
											)}
										</div>
										<div className="sect-desc">
											{__(
												'JSON Schema definitions for input and output. Used for validation and surfaced to MCP clients.',
												'acrossai-abilities-manager'
											)}
										</div>
									</div>

									{isNonDb ? (
										<>
											<div className="fr">
												<label className="fl">
													{__(
														'Input Schema',
														'acrossai-abilities-manager'
													)}
												</label>
												<div className="ff">
													{regInput !== null ? (
														<pre className="rt code readonly-schema">
															{JSON.stringify(
																regInput,
																null,
																2
															)}
														</pre>
													) : (
														<span className="desc">
															{__(
																'Not defined',
																'acrossai-abilities-manager'
															)}
														</span>
													)}
												</div>
											</div>
											<div className="fr">
												<label className="fl">
													{__(
														'Output Schema',
														'acrossai-abilities-manager'
													)}
												</label>
												<div className="ff">
													{regOutput !== null ? (
														<pre className="rt code readonly-schema">
															{JSON.stringify(
																regOutput,
																null,
																2
															)}
														</pre>
													) : (
														<span className="desc">
															{__(
																'Not defined',
																'acrossai-abilities-manager'
															)}
														</span>
													)}
												</div>
											</div>
										</>
									) : (
										<>
											<div className="fr">
												<label
													htmlFor="input-schema"
													className="fl"
												>
													{__(
														'Input Schema',
														'acrossai-abilities-manager'
													)}
												</label>
												<div className="ff">
													<textarea
														id="input-schema"
														className="rt code"
														value={inputSchemaRaw}
														placeholder='{ "param": { "type": "string" } }'
														onChange={(e) =>
															setInputSchemaRaw(
																e.target.value
															)
														}
														onBlur={
															handleInputSchemaBlur
														}
													/>
													{inputSchemaError && (
														<div className="field-error">
															{inputSchemaError}
														</div>
													)}
												</div>
											</div>

											<div className="fr">
												<label
													htmlFor="output-schema"
													className="fl"
												>
													{__(
														'Output Schema',
														'acrossai-abilities-manager'
													)}
												</label>
												<div className="ff">
													<textarea
														id="output-schema"
														className="rt code"
														value={outputSchemaRaw}
														placeholder='{ "result": { "type": "string" } }'
														onChange={(e) =>
															setOutputSchemaRaw(
																e.target.value
															)
														}
														onBlur={
															handleOutputSchemaBlur
														}
													/>
													{outputSchemaError && (
														<div className="field-error">
															{outputSchemaError}
														</div>
													)}
												</div>
											</div>
										</>
									)}
								</div>
							);
						})()}
					</div>
					{/* end .panel */}
				</div>
				{/* end .form-main */}

				{/* ===== SIDEBAR COLUMN ===== */}
				<div className="form-side">
					{/* Add New: Publish box */}
					{isCreate && (
						<div className="sbox">
							<div className="sbhdr">
								<span className="sbhdr-ic">↑</span>
								{__('Publish', 'acrossai-abilities-manager')}
							</div>
							<div className="sbbody">
								<div
									style={{
										display: 'flex',
										flexDirection: 'column',
										gap: '8px',
									}}
								>
									<button
										type="button"
										className="button button-primary button-large"
										style={
											hasRequiredErrors
												? {
														width: '100%',
														justifyContent:
															'center',
														opacity: 0.5,
														pointerEvents: 'none',
													}
												: {
														width: '100%',
														justifyContent:
															'center',
													}
										}
										disabled={isSaving}
										aria-disabled={hasRequiredErrors}
										onClick={() => handleSave(false)}
									>
										{isSaving
											? __(
													'Saving…',
													'acrossai-abilities-manager'
												)
											: __(
													'✓ Add Ability',
													'acrossai-abilities-manager'
												)}
									</button>
									<button
										type="button"
										className="button"
										style={{
											width: '100%',
											justifyContent: 'center',
											fontSize: '12px',
										}}
										disabled={isSaving}
										onClick={() => handleSave(true)}
									>
										{__(
											'Save as Draft',
											'acrossai-abilities-manager'
										)}
									</button>
								</div>
							</div>
						</div>
					)}

					{/* Edit: Update box — DB abilities only */}
					{isEdit && !isNonDb && (
						<div className="sbox">
							<div className="sbhdr">
								<span className="sbhdr-ic">↑</span>
								{__('Update', 'acrossai-abilities-manager')}
							</div>
							<div className="sbbody">
								<div
									style={{
										display: 'flex',
										flexDirection: 'column',
										gap: '8px',
									}}
								>
									<button
										type="button"
										className="button button-primary button-large"
										style={
											hasRequiredErrors
												? {
														width: '100%',
														justifyContent:
															'center',
														opacity: 0.5,
														pointerEvents: 'none',
													}
												: {
														width: '100%',
														justifyContent:
															'center',
													}
										}
										disabled={
											isSaving || (!isDirty && !isAcDirty)
										}
										aria-disabled={hasRequiredErrors}
										onClick={() => handleSave(false)}
									>
										{isSaving
											? __(
													'Saving…',
													'acrossai-abilities-manager'
												)
											: __(
													'✓ Save Changes',
													'acrossai-abilities-manager'
												)}
									</button>
									<button
										type="button"
										className="button"
										style={{
											width: '100%',
											justifyContent: 'center',
											fontSize: '12px',
										}}
										disabled={isSaving}
										onClick={() => handleSave(true)}
									>
										{__(
											'Save as Draft',
											'acrossai-abilities-manager'
										)}
									</button>
								</div>
								<div
									style={{
										borderTop: '1px dashed #ddd',
										marginTop: '14px',
										paddingTop: '12px',
										textAlign: 'center',
									}}
								>
									<button
										type="button"
										className="button-link link-delete"
										style={{ fontSize: '12px' }}
										onClick={handleDelete}
									>
										{__(
											'🗑 Delete Ability',
											'acrossai-abilities-manager'
										)}
									</button>
								</div>
							</div>
						</div>
					)}

					{/* Override: Actions box */}
					{isNonDb && (
						<div className="sbox">
							<div className="sbhdr">
								<span className="sbhdr-ic">↑</span>
								{__('Actions', 'acrossai-abilities-manager')}
							</div>
							<div className="sbbody">
								<div
									style={{
										display: 'flex',
										flexDirection: 'column',
										gap: '8px',
									}}
								>
									<button
										type="button"
										className="button button-primary button-large"
										style={{
											width: '100%',
											justifyContent: 'center',
										}}
										disabled={
											isSaving || (!isDirty && !isAcDirty)
										}
										onClick={() => handleSave(false)}
									>
										{isSaving
											? __(
													'Saving…',
													'acrossai-abilities-manager'
												)
											: __(
													'✓ Save Changes',
													'acrossai-abilities-manager'
												)}
									</button>
								</div>
								{savedAbility?.has_override && (
									<div
										style={{
											borderTop: '1px dashed #ddd',
											marginTop: '14px',
											paddingTop: '12px',
											textAlign: 'center',
										}}
									>
										<button
											type="button"
											className="button-link"
											style={{ fontSize: '12px' }}
											onClick={handleClearOverrides}
										>
											{__(
												'↩ Clear All Overrides',
												'acrossai-abilities-manager'
											)}
										</button>
									</div>
								)}
							</div>
						</div>
					)}

					{/* Preview box */}
					<div className="sbox">
						<div className="sbhdr">
							<span className="sbhdr-ic">●</span>
							{__('Preview', 'acrossai-abilities-manager')}
						</div>
						<div className="sbbody">
							<div className="prev-grid">
								<div className="prev-row">
									<div className="prk">
										{__(
											'Slug',
											'acrossai-abilities-manager'
										)}
									</div>
									<div className="prv mono">
										{draftAbility.ability_slug ? (
											<>{draftAbility.ability_slug}</>
										) : (
											<em style={{ color: '#646970' }}>
												{__(
													'(not set)',
													'acrossai-abilities-manager'
												)}
											</em>
										)}
									</div>
								</div>
								{(isEdit || isNonDb) && savedAbility?.label && (
									<div className="prev-row">
										<div className="prk">
											{__(
												'Label',
												'acrossai-abilities-manager'
											)}
										</div>
										<div className="prv">
											{savedAbility.label}
										</div>
									</div>
								)}
								<div className="prev-row">
									<div className="prk">
										{__(
											'Source',
											'acrossai-abilities-manager'
										)}
									</div>
									<div className="prv">
										<SourceBadge
											source={
												draftAbility.source ||
												savedAbility?.source ||
												'db'
											}
										/>
									</div>
								</div>
								{!isNonDb && (
									<div className="prev-row">
										<div className="prk">
											{__(
												'Callback',
												'acrossai-abilities-manager'
											)}
										</div>
										<div className="prv">
											{callbackType}
										</div>
									</div>
								)}
							</div>
						</div>
					</div>

					{/* Activity box (edit mode only) */}
					{!isCreate && savedAbility && savedAbility.created_at && (
						<div className="sbox">
							<div className="sbhdr">
								<span className="sbhdr-ic">⊙</span>
								{__('Activity', 'acrossai-abilities-manager')}
							</div>
							<div className="sbbody">
								{savedAbility.updated_at && (
									<div className="aitem">
										<div className="adot on" />
										<div>
											<div className="at">
												{__(
													'Updated',
													'acrossai-abilities-manager'
												)}
											</div>
											<div className="adt">
												{formatDate(
													savedAbility.updated_at
												)}
											</div>
										</div>
									</div>
								)}
								{savedAbility.created_at && (
									<div className="aitem">
										<div className="adot off" />
										<div>
											<div className="at muted">
												{__(
													'Created',
													'acrossai-abilities-manager'
												)}
											</div>
											<div className="adt">
												{formatDate(
													savedAbility.created_at
												)}
											</div>
										</div>
									</div>
								)}
							</div>
						</div>
					)}

					{/* Active overrides box (override mode only) */}
					{isNonDb && (
						<div className="sbox">
							<div className="sbhdr">
								<span className="sbhdr-ic">⊙</span>
								{__(
									'Active Overrides',
									'acrossai-abilities-manager'
								)}
							</div>
							<div className="sbbody">
								{(() => {
									const overrideFields = [
										'site_allowed',
										'show_in_rest',
										'show_in_mcp',
										'mcp_type',
										'readonly',
										'destructive',
										'idempotent',
									];
									const active = overrideFields.filter(
										(f) =>
											null !==
												savedAbility?._override?.[f] &&
											undefined !==
												savedAbility?._override?.[f]
									);
									if (0 === active.length) {
										return (
											<p
												style={{
													color: '#646970',
													fontStyle: 'italic',
													fontSize: '12px',
												}}
											>
												{__(
													'No overrides set — all values inherited from plugin.',
													'acrossai-abilities-manager'
												)}
											</p>
										);
									}
									return (
										<ul className="hlist">
											{active.map((f) => (
												<li key={f}>
													{f}:{' '}
													<strong>
														{String(
															savedAbility
																._override[f]
														)}
													</strong>
												</li>
											))}
										</ul>
									);
								})()}
							</div>
						</div>
					)}

					{/* On Save box */}
					<div className="sbox">
						<div className="sbhdr">
							<span className="sbhdr-ic">✓</span>
							{__('On Save', 'acrossai-abilities-manager')}
						</div>
						<div className="sbbody">
							<ul className="hlist">
								{isCreate && (
									<>
										<li>
											{__(
												'Written to',
												'acrossai-abilities-manager'
											)}{' '}
											<code>wp_acrossai_abilities</code>
										</li>
										<li>
											{__(
												'Registered every page load while Auto-register is on',
												'acrossai-abilities-manager'
											)}
										</li>
										<li>
											{__(
												'Appears in MCP manifest if enabled',
												'acrossai-abilities-manager'
											)}
										</li>
									</>
								)}
								{isEdit && (
									<>
										<li>
											{__(
												'Updated in',
												'acrossai-abilities-manager'
											)}{' '}
											<code>wp_acrossai_abilities</code>
										</li>
										<li>
											{__(
												'MCP manifest refreshes on next request',
												'acrossai-abilities-manager'
											)}
										</li>
										<li>
											{__(
												'Existing integrations continue (slug unchanged)',
												'acrossai-abilities-manager'
											)}
										</li>
									</>
								)}
								{isNonDb && (
									<>
										<li>
											{__(
												'Override saved to',
												'acrossai-abilities-manager'
											)}{' '}
											<code>wp_acrossai_abilities</code>
										</li>
										<li>
											{__(
												"Plugin's own definition is not modified",
												'acrossai-abilities-manager'
											)}
										</li>
										<li>
											{__(
												'Overrides persist across plugin updates',
												'acrossai-abilities-manager'
											)}
										</li>
									</>
								)}
							</ul>
						</div>
					</div>
				</div>
				{/* end .form-side */}
			</div>
			{/* end .form-layout */}

			{/* Sticky save bar */}
			<div className="sbar">
				<div className="snote">
					{(isDirty || isAcDirty) && !isCreate && (
						<>
							<span className="udot" />
							{isNonDb
								? __(
										'Changes affect this site only — the plugin definition is not modified.',
										'acrossai-abilities-manager'
									)
								: __(
										'Unsaved changes — leaving this page will discard them.',
										'acrossai-abilities-manager'
									)}
						</>
					)}
					{isCreate && (
						<>
							💾{' '}
							{__(
								'Fill in required fields to enable Save.',
								'acrossai-abilities-manager'
							)}
						</>
					)}
				</div>
				<div className="brow">
					<button
						type="button"
						className="button"
						onClick={handleCancel}
					>
						{__('Cancel', 'acrossai-abilities-manager')}
					</button>
					<button
						type="button"
						className="button button-primary"
						disabled={
							isSaving || (!isCreate && !isDirty && !isAcDirty)
						}
						onClick={() => handleSave(false)}
					>
						{saveBtnLabel}
					</button>
				</div>
			</div>
		</div>
	);
}
