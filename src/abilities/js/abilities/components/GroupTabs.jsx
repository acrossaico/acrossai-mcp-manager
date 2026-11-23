/**
 * Toolset strip for the abilities list.
 *
 * These are **navigation links, not tabs** (spec FR-012a). Each entry is a real anchor with a real
 * href, so it is keyboard-reachable in sequence, activates on Enter, and can be middle-clicked or
 * opened in a new browser tab like any other link.
 *
 * Deliberately NOT `role="tablist"`. Declaring the ARIA tabs pattern commits to arrow-key roving
 * focus, which links do not implement — announcing a pattern you have not built is worse than not
 * claiming it. `@wordpress/components` TabPanel is avoided for the same reason plus one more: this
 * screen is hand-rolled classic WP-admin markup throughout, and TabPanel would inject
 * `components-tab-panel__*` classes and its own focus model into a screen that uses none of it.
 *
 * Counts come from `GET /acrossai/v1/abilities/toolsets`, never from a value localised into the
 * page. The override processor prunes `site_allowed = false` abilities on every request except this
 * plugin's own REST namespace, so a count taken at page render would omit exactly the blocked
 * abilities the table then lists — the strip would say 0 while showing rows
 * (BUG-PATH-B-AGGREGATE-UNDERCOUNT).
 *
 * @since 0.2.0
 */
import { __ } from '@wordpress/i18n';
import { titleCaseTabLabel } from '../../shared/titleCaseTabLabel';
import { ALL_TABS_KEY } from '../constants';
import { buildUrlFromTab } from '../urlSync';

/**
 * GroupTabs component.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.counts    Map of toolset id => ability count.
 * @param {Object}   props.labels    Map of toolset id => declared display name, where one exists.
 * @param {string}   props.activeTab Currently selected toolset, or ALL_TABS_KEY.
 * @param {number}   props.total     Total ability count, for the "All" entry.
 * @param {Function} props.onSelect  Called with the toolset id when an entry is chosen.
 * @return {import('react').ReactElement} Rendered strip.
 */
export default function GroupTabs({
	counts,
	labels,
	activeTab,
	total,
	onSelect,
}) {
	const groups = Object.keys(counts || {}).sort();

	const entries = [
		{
			key: ALL_TABS_KEY,
			label: __('All', 'acrossai-abilities-manager'),
			count: total,
		},
		...groups.map((key) => ({
			key,
			// A declared name wins over the derived one. The derivation cannot know an acronym —
			// `acf` becomes "Acf", which reads as a typo — so an integration supplies its own
			// (issue #184). Everything else still derives, which is why this is a fallback and not
			// a lookup table.
			label: (labels || {})[key] || titleCaseTabLabel(key),
			count: counts[key],
		})),
	];

	const href = (key) =>
		buildUrlFromTab(key, window.location.href, ALL_TABS_KEY);

	return (
		<nav
			className="toolset-strip"
			aria-label={__('Filter by toolset', 'acrossai-abilities-manager')}
		>
			{entries.map(({ key, label, count }) => {
				const isActive = key === activeTab;

				return (
					<a
						key={key}
						href={href(key)}
						className={
							'toolset-strip__item' +
							(isActive ? ' is-active' : '')
						}
						aria-current={isActive ? 'page' : undefined}
						onClick={(e) => {
							// Let modified clicks (new tab, new window) behave like any link.
							if (
								e.metaKey ||
								e.ctrlKey ||
								e.shiftKey ||
								e.altKey ||
								1 === e.button
							) {
								return;
							}
							e.preventDefault();
							onSelect(key);
						}}
					>
						{label}
						{undefined !== count && null !== count && (
							<span className="toolset-strip__count">
								{count}
							</span>
						)}
					</a>
				);
			})}
		</nav>
	);
}
