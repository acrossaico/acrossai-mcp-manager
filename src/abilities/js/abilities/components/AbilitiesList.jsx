/**
 * Abilities list view — Classic WP-admin HTML table.
 *
 * Matches "Abilities Manager — Final Design.html" pixel-for-pixel:
 * a .wptable with checkboxes, inline row actions, subsubsub quick-links,
 * tablenav with bulk-actions + source/status filters + search.
 *
 * SEC-010-02: Bulk delete requires window.confirm before dispatching.
 *
 * @since 0.2.0
 */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../store/index';
import AbilitiesTable from './AbilitiesTable';
import GroupTabs from './GroupTabs';
import { ALL_TABS_KEY, MAX_PER_PAGE } from '../constants';
import { titleCaseTabLabel } from '../../shared/titleCaseTabLabel';
import AbilitiesToolbar, { AbilitiesPagerBelow } from './AbilitiesToolbar';
import { loadColumnPrefs, LS_KEY } from '../columns';
import UserAccessBulkModal from './UserAccessBulkModal';
import BulkBusyOverlay from './BulkBusyOverlay';

// ---------------------------------------------------------------------------
// Column visibility constants
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

/**
 * AbilitiesList component.
 *
 * @return {import('react').ReactElement} Rendered abilities table.
 */
export default function AbilitiesList() {
	// ---- filter / sort / search state ----
	const [search, setSearch] = useState('');
	const [sourceFilter, setSourceFilter] = useState('');
	const [statusFilter, setStatusFilter] = useState('');
	const [sortDir, setSortDir] = useState('asc');
	const [page, setPage] = useState(1);
	// Upper bound mirrors the REST `per_page` maximum and SettingsMenu::MAX_PER_PAGE. A stored
	// value above it (saved before the bound was aligned) is clamped here rather than sent and
	// silently reduced server-side, so the pager and the data agree (issue #185).
	const perPage = Math.min(
		MAX_PER_PAGE,
		Math.max(
			1,
			parseInt(window.acrossaiAbilitiesManager?.perPage, 10) || 20
		)
	);

	// ---- checkbox state ----
	const [selected, setSelected] = useState(new Set());
	const [bulkAction, setBulkAction] = useState('');
	const [userAccessModalOpen, setUserAccessModalOpen] = useState(false);
	const [bulkBusy, setBulkBusy] = useState(false);

	// Lock body scroll and prevent interaction while a bulk dispatch is in flight.
	useEffect(() => {
		if (!bulkBusy) {
			return undefined;
		}
		const prevOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		return () => {
			document.body.style.overflow = prevOverflow;
		};
	}, [bulkBusy]);

	// ---- column visibility state ----
	const [visibleColumns, setVisibleColumns] = useState(loadColumnPrefs);
	const [columnsOpen, setColumnsOpen] = useState(false);

	const {
		abilities,
		total,
		isLoading,
		error,
		activeTab,
		toolsetCounts,
		toolsetLabels,
		toolsetTotal,
		serverPages,
	} = useSelect(
		(select) => ({
			abilities: select(STORE_NAME).getAbilities(),
			total: select(STORE_NAME).getTotal(),
			isLoading: select(STORE_NAME).getIsLoading(),
			error: select(STORE_NAME).getError(),
			activeTab: select(STORE_NAME).getActiveTab(),
			toolsetCounts: select(STORE_NAME).getToolsetCounts(),
			toolsetLabels: select(STORE_NAME).getToolsetLabels(),
			toolsetTotal: select(STORE_NAME).getToolsetTotal(),
			serverPages: select(STORE_NAME).getPages(),
		}),
		[]
	);

	const dispatch = useDispatch(STORE_NAME);

	// ---- derived pagination values ----
	// Prefer the server's X-WP-TotalPages over recomputing from `perPage`. The two can disagree:
	// the REST `per_page` argument is capped, so a larger requested page size is served at the cap
	// while the client-side arithmetic would still describe the size that was asked for. Reporting
	// a page count the data does not match is worse than reporting a smaller one (issue #185).
	const totalPages = serverPages || Math.ceil(total / perPage) || 1;

	// ---- column visibility helpers ----
	function toggleColumn(key) {
		setVisibleColumns((prev) => {
			const next = { ...prev, [key]: !prev[key] };
			try {
				localStorage.setItem(LS_KEY, JSON.stringify(next));
			} catch {
				// localStorage unavailable — silent fallback
			}
			return next;
		});
	}

	const visibleCount = Object.values(visibleColumns).filter(Boolean).length;
	const tableColSpan = visibleCount + 3; // +1 checkbox, +1 Slug, +1 Actions

	// The search box names what it will search, so it is obvious the term is scoped to the toolset.
	const searchPlaceholder =
		ALL_TABS_KEY === activeTab
			? __('Search abilities…', 'acrossai-abilities-manager')
			: sprintf(
					/* translators: %s is a toolset name, e.g. "Cache". */
					__('Search %s abilities…', 'acrossai-abilities-manager'),
					// Same precedence as the strip: a declared name beats the derived one, so the
					// placeholder and the tab it belongs to never disagree.
					toolsetLabels[activeTab] || titleCaseTabLabel(activeTab)
				);

	// Fetch whenever filters change.
	useEffect(() => {
		dispatch.fetchAbilities({
			page,
			per_page: perPage,
			search: search || undefined,
			orderby: 'ability_slug',
			order: sortDir,
			source: sourceFilter || undefined,
			status: statusFilter || undefined,
			tab_group: ALL_TABS_KEY === activeTab ? undefined : activeTab,
		});
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [page, search, sourceFilter, statusFilter, sortDir, activeTab]);

	// Changing toolset resets paging AND clears the selection. Without the clear, rows ticked on one
	// toolset stay selected while invisible on another, and Apply would act on slugs the operator can
	// no longer see.
	useEffect(() => {
		setPage(1);
		setSelected(new Set());
	}, [activeTab]);

	// Reset to page 1 whenever filters/search/sort change.
	useEffect(() => {
		setPage(1);
	}, [search, sourceFilter, statusFilter, sortDir]);

	// Close columns panel when clicking outside.
	useEffect(() => {
		if (!columnsOpen) {
			return;
		}
		const handler = (e) => {
			if (!e.target.closest('.columns-toggle')) {
				setColumnsOpen(false);
			}
		};
		document.addEventListener('mousedown', handler);
		return () => document.removeEventListener('mousedown', handler);
	}, [columnsOpen]);

	// ---- counts from current page (approximate) ----
	const publishedCount = abilities.filter(
		(a) => 'publish' === a.status
	).length;
	const draftCount = abilities.filter((a) => 'draft' === a.status).length;

	// ---- checkbox helpers ----
	// Feature 056: bulk actions write tri-state overrides that apply to any
	// source (Plugin / Core / Theme / Custom), so every visible row is
	// selectable — not just db-source rows.
	const allSlugs = new Set(abilities.map((a) => a.ability_slug));
	const allChecked =
		allSlugs.size > 0 && [...allSlugs].every((s) => selected.has(s));

	const toggleAll = useCallback(() => {
		if (allChecked) {
			setSelected(new Set());
		} else {
			setSelected(new Set(allSlugs));
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [allChecked, JSON.stringify([...allSlugs])]);

	const toggleOne = useCallback((slug) => {
		setSelected((prev) => {
			const next = new Set(prev);
			if (next.has(slug)) {
				next.delete(slug);
			} else {
				next.add(slug);
			}
			return next;
		});
	}, []);

	// ---- inline status dropdown ----
	function handleStatusDropdown(item, value) {
		const newStatus = 'e' === value ? 'publish' : 'draft';
		dispatch.updateAbility(item.ability_slug, { status: newStatus });
	}

	// ---- bulk apply ----
	// Feature 056: parse-and-dispatch shape. Bulk action values are
	// "<domain>:<value>" strings (e.g. "site_access:force_block"). Destructive
	// transitions (site_access:force_block, mcp:disable) prompt for
	// window.confirm() before dispatching. See docs/planning/056-bulk-actions-overhaul.md.
	async function handleBulkApply() {
		if (!bulkAction || !selected.size) {
			return;
		}
		const slugs = [...selected];

		// User Access "Configure…" opens a modal; nothing dispatches here.
		// Selection + dropdown state persist until the modal completes.
		if ('user_access:configure' === bulkAction) {
			setUserAccessModalOpen(true);
			return;
		}

		// Destructive-transition confirms (SEC-010-02 shape).
		// Force Block / MCP Disable / User Access Reset / Force Reset all prompt.
		const DESTRUCTIVE_LABELS = {
			'site_access:force_block': __(
				'force-block',
				'acrossai-abilities-manager'
			),
			'mcp:disable': __('disable MCP on', 'acrossai-abilities-manager'),
			'user_access:reset': __(
				'reset User Access to default (allow everyone) on',
				'acrossai-abilities-manager'
			),
			'overrides:reset': __(
				'reset all overrides on',
				'acrossai-abilities-manager'
			),
		};
		if (bulkAction in DESTRUCTIVE_LABELS) {
			const msg = sprintf(
				/* translators: 1: action label, 2: count of selected abilities */
				__('%1$s %2$d abilities?', 'acrossai-abilities-manager'),
				DESTRUCTIVE_LABELS[bulkAction],
				slugs.length
			);
			// eslint-disable-next-line no-alert
			if (!window.confirm(msg)) {
				return;
			}
		}

		// Value maps — raw JSON true/false/null; string aliases forbidden
		// (guards BUG-MERGER-BOOL-STRING-CAST).
		const SITE_ACCESS_MAP = {
			force_allow: true,
			force_block: false,
			inherit: null,
		};
		const MCP_MAP = {
			enable: true,
			disable: false,
			default: null,
		};
		const [domain, value] = bulkAction.split(':');

		setBulkBusy(true);
		try {
			if ('site_access' === domain && value in SITE_ACCESS_MAP) {
				await dispatch.bulkUpdateTristate(
					slugs,
					'site_allowed',
					SITE_ACCESS_MAP[value]
				);
			} else if ('mcp' === domain && value in MCP_MAP) {
				await dispatch.bulkUpdateTristate(
					slugs,
					'show_in_mcp',
					MCP_MAP[value]
				);
			} else if ('user_access' === domain && 'reset' === value) {
				// Empty acKey + empty acOptions = clear rule (Everyone allowed).
				await dispatch.bulkSetUserAccessRule(slugs, '', []);
			} else if ('overrides' === domain && 'reset' === value) {
				await dispatch.bulkClearOverrides(slugs);
			}
		} catch {
			// SEC-001: per-slug failures surface via the store's SET_SAVE_ERROR
			// path (rendered by the top-of-page error notice). Keep selection
			// intact so the operator can retry without re-selecting.
			setBulkBusy(false);
			return;
		}
		setBulkBusy(false);

		setSelected(new Set());
		setBulkAction('');
	}

	// ---- sort toggle ----
	function toggleSort() {
		setSortDir((d) => ('asc' === d ? 'desc' : 'asc'));
	}

	return (
		<div className="wrap">
			{/* Error notice */}
			{error && (
				<div className="notice notice-error">
					<p>{error}</p>
					<button
						type="button"
						className="notice-dismiss"
						aria-label={__('Dismiss', 'acrossai-abilities-manager')}
						onClick={() => dispatch.clearError()}
					/>
				</div>
			)}

			{/* Page title */}
			<div className="pg-title">
				<h1 className="wp-heading-inline">
					{__('Custom Abilities', 'acrossai-abilities-manager')}
				</h1>
			</div>

			<p className="abilities-subtitle">
				{__(
					'Manage abilities created on this site and override how plugin, theme and core abilities behave.',
					'acrossai-abilities-manager'
				)}
			</p>

			{/* Quick-links: All | Published | Draft */}
			<ul className="subsubsub">
				<li>
					<a
						href="#all"
						className={`ssl${'' === statusFilter ? ' on' : ''}`}
						onClick={(e) => {
							e.preventDefault();
							setStatusFilter('');
						}}
					>
						{__('All', 'acrossai-abilities-manager')}{' '}
						<span className="ct">({total})</span>
					</a>
					<span className="ssp">|</span>
				</li>
				<li>
					<a
						href="#published"
						className={`ssl${'publish' === statusFilter ? ' on' : ''}`}
						onClick={(e) => {
							e.preventDefault();
							setStatusFilter('publish');
						}}
					>
						{__('Published', 'acrossai-abilities-manager')}{' '}
						<span className="ct">({publishedCount})</span>
					</a>
					<span className="ssp">|</span>
				</li>
				<li>
					<a
						href="#draft"
						className={`ssl${'draft' === statusFilter ? ' on' : ''}`}
						onClick={(e) => {
							e.preventDefault();
							setStatusFilter('draft');
						}}
					>
						{__('Draft', 'acrossai-abilities-manager')}{' '}
						<span className="ct">({draftCount})</span>
					</a>
				</li>
			</ul>

			{/* Tablenav */}
			<GroupTabs
				counts={toolsetCounts}
				labels={toolsetLabels}
				activeTab={activeTab}
				total={toolsetTotal}
				onSelect={(key) => dispatch.setActiveTab(key)}
			/>

			<AbilitiesToolbar
				bulkAction={bulkAction}
				setBulkAction={setBulkAction}
				handleBulkApply={handleBulkApply}
				sourceFilter={sourceFilter}
				setSourceFilter={setSourceFilter}
				statusFilter={statusFilter}
				setStatusFilter={setStatusFilter}
				search={search}
				setSearch={setSearch}
				columnsOpen={columnsOpen}
				setColumnsOpen={setColumnsOpen}
				visibleColumns={visibleColumns}
				toggleColumn={toggleColumn}
				page={page}
				setPage={setPage}
				total={total}
				totalPages={totalPages}
				searchPlaceholder={searchPlaceholder}
			/>

			{/* WP-style table */}
			<AbilitiesTable
				abilities={abilities}
				visibleColumns={visibleColumns}
				selected={selected}
				allChecked={allChecked}
				toggleAll={toggleAll}
				toggleOne={toggleOne}
				sortDir={sortDir}
				toggleSort={toggleSort}
				isLoading={isLoading}
				tableColSpan={tableColSpan}
				handleStatusDropdown={handleStatusDropdown}
				dispatch={dispatch}
			/>

			<AbilitiesPagerBelow
				page={page}
				setPage={setPage}
				total={total}
				totalPages={totalPages}
			/>
			{userAccessModalOpen && (
				<UserAccessBulkModal
					slugs={[...selected]}
					onClose={() => setUserAccessModalOpen(false)}
					onApplied={() => {
						setUserAccessModalOpen(false);
						setBulkAction('');
						setSelected(new Set());
					}}
				/>
			)}
			{bulkBusy && (
				<BulkBusyOverlay
					label={__(
						'Applying bulk action…',
						'acrossai-abilities-manager'
					)}
				/>
			)}
		</div>
	);
}
