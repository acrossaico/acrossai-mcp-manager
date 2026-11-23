/**
 * Abilities list toolbar and pagination.
 *
 * Extracted verbatim from AbilitiesList.jsx in Feature 102 (task T014). As with AbilitiesTable,
 * the JSX is unchanged from what lived inline — only its surroundings moved — because the
 * extraction has to be a pixel no-op.
 *
 * The columns panel closes on outside click via a DOM check (`.columns-toggle`) rather than a ref,
 * so that effect stays in AbilitiesList and nothing needs forwarding here.
 *
 * @since 0.2.0
 */
import { __ } from '@wordpress/i18n';
import { COLUMN_DEFAULTS, COLUMN_LABELS } from '../columns';

/**
 * Top toolbar: bulk actions, filters, search, column picker, pagination.
 *
 * @param {Object}   props                   Component props.
 * @param {string}   props.bulkAction        Selected bulk action value.
 * @param {Function} props.setBulkAction     Bulk action setter.
 * @param {Function} props.handleBulkApply   Applies the selected bulk action.
 * @param {string}   props.sourceFilter      Active source filter.
 * @param {Function} props.setSourceFilter   Source filter setter.
 * @param {string}   props.statusFilter      Active status filter.
 * @param {Function} props.setStatusFilter   Status filter setter.
 * @param {string}   props.search            Current search term.
 * @param {Function} props.setSearch         Search term setter.
 * @param {boolean}  props.columnsOpen       Whether the column picker is open.
 * @param {Function} props.setColumnsOpen    Column picker open-state setter.
 * @param {Object}   props.visibleColumns    Column visibility map.
 * @param {Function} props.toggleColumn      Per-column visibility toggle.
 * @param {number}   props.page              Current page number.
 * @param {Function} props.setPage           Page setter.
 * @param {number}   props.total             Total row count.
 * @param {number}   props.totalPages        Total page count.
 * @param {string}   props.searchPlaceholder Placeholder naming the active toolset.
 * @return {import('react').ReactElement} Rendered toolbar.
 */
export default function AbilitiesToolbar({
	bulkAction,
	setBulkAction,
	handleBulkApply,
	sourceFilter,
	setSourceFilter,
	statusFilter,
	setStatusFilter,
	search,
	setSearch,
	columnsOpen,
	setColumnsOpen,
	visibleColumns,
	toggleColumn,
	page,
	setPage,
	total,
	totalPages,
	searchPlaceholder,
}) {
	return (
		<div className="tablenav">
			<div className="bulk-row">
				<select
					className="acrossai-abilities-list__bulk-select"
					value={bulkAction}
					onChange={(e) => setBulkAction(e.target.value)}
					aria-label={__(
						'Bulk actions',
						'acrossai-abilities-manager'
					)}
				>
					<option value="">
						{__('Bulk Actions', 'acrossai-abilities-manager')}
					</option>
					<optgroup
						label={__('Site Access', 'acrossai-abilities-manager')}
					>
						<option value="site_access:force_allow">
							{__('Force Allow', 'acrossai-abilities-manager')}
						</option>
						<option value="site_access:inherit">
							{__('Inherit', 'acrossai-abilities-manager')}
						</option>
						<option value="site_access:force_block">
							{__('Force Block', 'acrossai-abilities-manager')}
						</option>
					</optgroup>
					<optgroup
						label={__('MCP Exposure', 'acrossai-abilities-manager')}
					>
						<option value="mcp:enable">
							{__('Enable', 'acrossai-abilities-manager')}
						</option>
						<option value="mcp:default">
							{__('Default', 'acrossai-abilities-manager')}
						</option>
						<option value="mcp:disable">
							{__('Disable', 'acrossai-abilities-manager')}
						</option>
					</optgroup>
					<optgroup
						label={__('User Access', 'acrossai-abilities-manager')}
					>
						<option value="user_access:configure">
							{__(
								'Add / edit access rule…',
								'acrossai-abilities-manager'
							)}
						</option>
						<option value="user_access:reset">
							{__(
								'Reset to Default (allow everyone)',
								'acrossai-abilities-manager'
							)}
						</option>
					</optgroup>
					<optgroup
						label={__('Overrides', 'acrossai-abilities-manager')}
					>
						<option value="overrides:reset">
							{__(
								'Force Reset (clear all overrides)',
								'acrossai-abilities-manager'
							)}
						</option>
					</optgroup>
				</select>
				<button
					type="button"
					className="button"
					onClick={handleBulkApply}
				>
					{__('Apply', 'acrossai-abilities-manager')}
				</button>
			</div>

			<select
				value={sourceFilter}
				onChange={(e) => setSourceFilter(e.target.value)}
				aria-label={__(
					'Filter by source',
					'acrossai-abilities-manager'
				)}
			>
				<option value="">
					{__('All Sources', 'acrossai-abilities-manager')}
				</option>
				<option value="db">
					{__('Custom', 'acrossai-abilities-manager')}
				</option>
				<option value="plugin">
					{__('Plugin', 'acrossai-abilities-manager')}
				</option>
				<option value="core">
					{__('Core', 'acrossai-abilities-manager')}
				</option>
				<option value="theme">
					{__('Theme', 'acrossai-abilities-manager')}
				</option>
			</select>

			<select
				value={statusFilter}
				onChange={(e) => setStatusFilter(e.target.value)}
				aria-label={__(
					'Filter by status',
					'acrossai-abilities-manager'
				)}
			>
				<option value="">
					{__('All Statuses', 'acrossai-abilities-manager')}
				</option>
				<option value="publish">
					{__('Published', 'acrossai-abilities-manager')}
				</option>
				<option value="draft">
					{__('Draft', 'acrossai-abilities-manager')}
				</option>
			</select>

			<div className="tablenav-search">
				<span className="search-icon" aria-hidden="true">
					🔍
				</span>
				<input
					type="text"
					value={search}
					placeholder={searchPlaceholder}
					onChange={(e) => setSearch(e.target.value)}
					aria-label={__(
						'Search abilities',
						'acrossai-abilities-manager'
					)}
				/>
			</div>

			<div className="columns-toggle" style={{ position: 'relative' }}>
				<button
					type="button"
					className="button"
					onClick={() => setColumnsOpen((o) => !o)}
				>
					{__('Columns', 'acrossai-abilities-manager')}{' '}
					{columnsOpen ? '▴' : '▾'}
				</button>
				{columnsOpen && (
					<div className="columns-panel">
						{Object.keys(COLUMN_DEFAULTS).map((key) => (
							<label
								key={key}
								htmlFor={`col-toggle-${key}`}
								className="columns-panel-item"
							>
								<input
									id={`col-toggle-${key}`}
									type="checkbox"
									checked={!!visibleColumns[key]}
									onChange={() => toggleColumn(key)}
								/>{' '}
								{COLUMN_LABELS[key]}
							</label>
						))}
					</div>
				)}
			</div>

			<div className="tablenav-pages">
				<span className="displaying-num">
					{total} {__('items', 'acrossai-abilities-manager')}
				</span>
				<span className="pagination-links">
					<button
						className="button"
						disabled={1 === page}
						onClick={() => setPage(1)}
					>
						«
					</button>
					<button
						className="button"
						disabled={1 === page}
						onClick={() => setPage((p) => p - 1)}
					>
						‹
					</button>
					<span className="paging-input">
						{page} {__('of', 'acrossai-abilities-manager')}{' '}
						{totalPages}
					</span>
					<button
						className="button"
						disabled={page >= totalPages}
						onClick={() => setPage((p) => p + 1)}
					>
						›
					</button>
					<button
						className="button"
						disabled={page >= totalPages}
						onClick={() => setPage(totalPages)}
					>
						»
					</button>
				</span>
			</div>
		</div>
	);
}

/**
 * Pagination repeated below the table.
 *
 * @param {Object}   props            Component props.
 * @param {number}   props.page       Current page number.
 * @param {Function} props.setPage    Page setter.
 * @param {number}   props.total      Total row count.
 * @param {number}   props.totalPages Total page count.
 * @return {import('react').ReactElement} Rendered pagination.
 */
export function AbilitiesPagerBelow({ page, setPage, total, totalPages }) {
	return (
		<div className="tablenav-pages tablenav-pages-below">
			<span className="displaying-num">
				{total} {__('items', 'acrossai-abilities-manager')}
			</span>
			<span className="pagination-links">
				<button
					className="button"
					disabled={1 === page}
					onClick={() => setPage(1)}
				>
					«
				</button>
				<button
					className="button"
					disabled={1 === page}
					onClick={() => setPage((p) => p - 1)}
				>
					‹
				</button>
				<span className="paging-input">
					{page} {__('of', 'acrossai-abilities-manager')} {totalPages}
				</span>
				<button
					className="button"
					disabled={page >= totalPages}
					onClick={() => setPage((p) => p + 1)}
				>
					›
				</button>
				<button
					className="button"
					disabled={page >= totalPages}
					onClick={() => setPage(totalPages)}
				>
					»
				</button>
			</span>
		</div>
	);
}
