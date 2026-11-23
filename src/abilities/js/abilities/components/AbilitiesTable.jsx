/**
 * Abilities table — classic WP-admin markup.
 *
 * Extracted verbatim from AbilitiesList.jsx in Feature 102 (task T013). The JSX below is
 * byte-identical to what lived inline; only its surroundings changed. That is deliberate — the
 * extraction is required to be a pixel no-op, and re-indenting or re-shaping the markup would
 * forfeit the strongest evidence available for that.
 *
 * `dispatch` is passed through whole rather than decomposed into onEdit/onDelete/onClearOverrides
 * callbacks, for the same reason: the row actions call dispatch.* inline, and keeping them inline
 * keeps the markup identical. Decomposing it is a reasonable follow-up once the visual gate in T016
 * can actually be trusted (see issue #183).
 *
 * @since 0.2.0
 */
import { __ } from '@wordpress/i18n';
import {
	SourceBadge,
	ToolsetCell,
	SlugCell,
	LabelCell,
	CategoryCell,
	StatusCell,
	TypeCell,
	McpCell,
	DescriptionCell,
	ShowInRestCell,
} from './cells';

/**
 * AbilitiesTable component.
 *
 * @param {Object}   props                      Component props.
 * @param {Array}    props.abilities            Rows to render.
 * @param {Object}   props.visibleColumns       Column visibility map.
 * @param {Set}      props.selected             Selected ability slugs.
 * @param {boolean}  props.allChecked           Whether every visible row is selected.
 * @param {Function} props.toggleAll            Select-all handler.
 * @param {Function} props.toggleOne            Per-row select handler.
 * @param {string}   props.sortDir              'asc' | 'desc'.
 * @param {Function} props.toggleSort           Slug sort handler.
 * @param {boolean}  props.isLoading            Whether a fetch is in flight.
 * @param {number}   props.tableColSpan         Colspan for the empty/loading rows.
 * @param {Function} props.handleStatusDropdown Inline status change handler.
 * @param {Object}   props.dispatch             Store dispatch, used by the row actions.
 * @return {import('react').ReactElement} Rendered table.
 */
export default function AbilitiesTable({
	abilities,
	visibleColumns,
	selected,
	allChecked,
	toggleAll,
	toggleOne,
	sortDir,
	toggleSort,
	isLoading,
	tableColSpan,
	handleStatusDropdown,
	dispatch,
}) {
	return (
		<table className="wptable">
			<colgroup>
				<col style={{ width: '32px' }} />
				<col className="col-slug" />
				{!!visibleColumns.label && <col className="col-lbl" />}
				{!!visibleColumns.category && <col className="col-cat" />}
				{!!visibleColumns.toolset && <col className="col-tls" />}
				{!!visibleColumns.source && <col className="col-src" />}
				{!!visibleColumns.status && <col className="col-sta" />}
				{!!visibleColumns.type && <col className="col-typ" />}
				{!!visibleColumns.description && <col className="col-desc" />}
				{!!visibleColumns.show_in_rest && <col className="col-rest" />}
				{!!visibleColumns.mcp && <col className="col-mcp" />}
				<col className="col-act" />
			</colgroup>
			<thead>
				<tr>
					<th className="chk-col">
						<input
							type="checkbox"
							checked={allChecked}
							onChange={toggleAll}
							aria-label={__(
								'Select all',
								'acrossai-abilities-manager'
							)}
						/>
					</th>
					<th
						className="sorted"
						style={{ cursor: 'pointer' }}
						onClick={toggleSort}
					>
						{__('Slug', 'acrossai-abilities-manager')}{' '}
						{'asc' === sortDir ? '↑' : '↓'}
					</th>
					{!!visibleColumns.label && (
						<th>{__('Label', 'acrossai-abilities-manager')}</th>
					)}
					{!!visibleColumns.category && (
						<th>{__('Category', 'acrossai-abilities-manager')}</th>
					)}
					{!!visibleColumns.toolset && (
						<th>{__('Toolset', 'acrossai-abilities-manager')}</th>
					)}
					{!!visibleColumns.source && (
						<th>{__('Source', 'acrossai-abilities-manager')}</th>
					)}
					{!!visibleColumns.status && (
						<th>{__('Status', 'acrossai-abilities-manager')}</th>
					)}
					{!!visibleColumns.type && (
						<th>{__('Type', 'acrossai-abilities-manager')}</th>
					)}
					{!!visibleColumns.description && (
						<th>
							{__('Description', 'acrossai-abilities-manager')}
						</th>
					)}
					{!!visibleColumns.show_in_rest && (
						<th>
							{__('Show in REST', 'acrossai-abilities-manager')}
						</th>
					)}
					{!!visibleColumns.mcp && (
						<th>{__('MCP', 'acrossai-abilities-manager')}</th>
					)}
					<th>{__('Actions', 'acrossai-abilities-manager')}</th>
				</tr>
			</thead>
			<tbody>
				{isLoading && (
					<tr>
						<td
							colSpan={tableColSpan}
							style={{
								textAlign: 'center',
								padding: '20px',
								color: '#646970',
							}}
						>
							{__('Loading…', 'acrossai-abilities-manager')}
						</td>
					</tr>
				)}
				{!isLoading && 0 === abilities.length && (
					<tr>
						<td
							colSpan={tableColSpan}
							style={{
								textAlign: 'center',
								padding: '20px',
								color: '#646970',
							}}
						>
							{__(
								'No abilities found.',
								'acrossai-abilities-manager'
							)}
						</td>
					</tr>
				)}
				{abilities.map((item) => {
					const isCustom = 'db' === (item.source || 'db');
					const itemSlug = item.ability_slug;
					const isChecked = selected.has(itemSlug);
					const statusCls = 'publish' === item.status ? 'e' : 'd';

					return (
						<tr
							key={item.ability_slug}
							className={isCustom ? '' : 'inh-row'}
						>
							<td className="chk-col">
								<input
									type="checkbox"
									checked={isChecked}
									onChange={() => toggleOne(itemSlug)}
									aria-label={`${__('Select', 'acrossai-abilities-manager')} ${item.ability_slug}`}
								/>
							</td>
							<td>
								<SlugCell item={item} />
							</td>
							{!!visibleColumns.label && (
								<td>
									<LabelCell item={item} />
								</td>
							)}
							{!!visibleColumns.category && (
								<td>
									<CategoryCell item={item} />
								</td>
							)}
							{!!visibleColumns.toolset && (
								<td>
									<ToolsetCell item={item} />
								</td>
							)}
							{!!visibleColumns.source && (
								<td>
									<SourceBadge source={item.source || 'db'} />
								</td>
							)}
							{!!visibleColumns.status && (
								<td>
									<StatusCell item={item} />
								</td>
							)}
							{!!visibleColumns.type && (
								<td>
									<TypeCell item={item} />
								</td>
							)}
							{!!visibleColumns.description && (
								<td>
									<DescriptionCell item={item} />
								</td>
							)}
							{!!visibleColumns.show_in_rest && (
								<td>
									<ShowInRestCell item={item} />
								</td>
							)}
							{!!visibleColumns.mcp && (
								<td>
									<McpCell item={item} />
								</td>
							)}
							<td>
								<div className="racts">
									{isCustom ? (
										<>
											<button
												type="button"
												className="ra"
												onClick={() =>
													dispatch.setView({
														mode: 'edit',
														slug: item.ability_slug,
														ability: item,
													})
												}
											>
												{__(
													'Edit',
													'acrossai-abilities-manager'
												)}
											</button>
											<span className="ra-sep">|</span>
											<select
												className={`sdd ${statusCls}`}
												value={statusCls}
												onChange={(e) =>
													handleStatusDropdown(
														item,
														e.target.value
													)
												}
												aria-label={__(
													'Change status',
													'acrossai-abilities-manager'
												)}
											>
												<option value="e">
													{__(
														'Enabled',
														'acrossai-abilities-manager'
													)}
												</option>
												<option value="d">
													{__(
														'Disabled',
														'acrossai-abilities-manager'
													)}
												</option>
											</select>
											<span className="ra-sep">|</span>
											<button
												type="button"
												className="ra del"
												onClick={() => {
													if (
														// eslint-disable-next-line no-alert
														window.confirm(
															__(
																'Delete this ability? This cannot be undone.',
																'acrossai-abilities-manager'
															)
														)
													) {
														dispatch.deleteAbility(
															item.ability_slug
														);
													}
												}}
											>
												{__(
													'Delete',
													'acrossai-abilities-manager'
												)}
											</button>
										</>
									) : (
										<>
											<button
												type="button"
												className="ra"
												onClick={() =>
													dispatch.setView({
														mode: 'edit',
														slug: item.ability_slug,
														ability: item,
													})
												}
											>
												{__(
													'Edit',
													'acrossai-abilities-manager'
												)}
											</button>
											{item.has_override && (
												<>
													<span className="ra-sep">
														|
													</span>
													<button
														type="button"
														className="ra"
														onClick={() => {
															if (
																// eslint-disable-next-line no-alert
																window.confirm(
																	__(
																		'Clear all overrides for this ability? This cannot be undone.',
																		'acrossai-abilities-manager'
																	)
																)
															) {
																dispatch.clearOverrides(
																	item.ability_slug
																);
															}
														}}
													>
														{__(
															'Clear All Overrides',
															'acrossai-abilities-manager'
														)}
													</button>
												</>
											)}
										</>
									)}
								</div>
							</td>
						</tr>
					);
				})}
			</tbody>
		</table>
	);
}
