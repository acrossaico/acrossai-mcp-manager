/**
 * Abilities table cell renderers.
 *
 * Extracted verbatim from AbilitiesList.jsx in Feature 102 (task T015) so the table can be
 * split out of a 1128-line component without the renderers travelling with it. No rendering
 * behaviour changed in the move — this file must remain a pixel no-op against the original.
 *
 * SourceBadge is re-exported here so consumers have a single import site for cells.
 *
 * @since 0.2.0
 */
import { __ } from '@wordpress/i18n';

export { default as SourceBadge } from './SourceBadge';

const SLUG_PREFIX = 'acrossai/';

export function SlugCell({ item }) {
	const slug = item.ability_slug || '';
	const hasPrefix = slug.startsWith(SLUG_PREFIX);
	const dimPart = hasPrefix ? SLUG_PREFIX : '';
	const namePart = hasPrefix ? slug.slice(SLUG_PREFIX.length) : slug;
	return (
		<div className="slug-cell">
			{dimPart && <span className="slug-dim">{dimPart}</span>}
			<span className="slug-name">{namePart}</span>
		</div>
	);
}

export function LabelCell({ item }) {
	return (
		<>
			<div style={{ fontSize: '13px', fontWeight: 600 }}>
				{item.label || '—'}
			</div>
			{item.provider && (
				<div className="lbl-by">
					{__('by', 'acrossai-abilities-manager')} {item.provider}
				</div>
			)}
		</>
	);
}

export function CategoryCell({ item }) {
	if (!item.category) {
		return <span>—</span>;
	}
	return <span className="cpill">{item.category}</span>;
}

export function StatusCell({ item }) {
	const isCustom = 'db' === (item.source || 'db');
	if (isCustom) {
		return 'publish' === item.status ? (
			<div className="sta sta-on">
				<div className="sta-dot" />
				{__('Enabled', 'acrossai-abilities-manager')}
			</div>
		) : (
			<div className="sta sta-off">
				<div className="sta-dot" />
				{__('Disabled', 'acrossai-abilities-manager')}
			</div>
		);
	}
	const sa = item.site_allowed;
	if (true === sa || 1 === sa) {
		return (
			<span className="ibadge ib-a">
				{__('Allowed', 'acrossai-abilities-manager')}
			</span>
		);
	}
	if (false === sa || 0 === sa) {
		return (
			<span className="ibadge ib-b">
				{__('Blocked', 'acrossai-abilities-manager')}
			</span>
		);
	}
	return (
		<span className="ibadge ib-d">
			{__('Default', 'acrossai-abilities-manager')}
		</span>
	);
}

const TYPE_MAP = {
	noop: { cls: 'tb-n', label: 'noop' },
	filter_hook: { cls: 'tb-f', label: 'filter_hook' },
	wp_remote_post: { cls: 'tb-r', label: 'wp_remote_post' },
	php_code: { cls: 'tb-p', label: 'php_code' },
};

export function TypeCell({ item }) {
	const type = item.callback_type || item._registry?.callback_type;
	if (!type) {
		return <span>—</span>;
	}
	const { cls, label } = TYPE_MAP[type] || TYPE_MAP.noop;
	return <span className={`tbadge ${cls}`}>{label}</span>;
}

export function ToolsetCell({ item }) {
	const group = item.tab_group || '';

	if (!group) {
		return <span>—</span>;
	}

	return <span className="tspill">toolset/{group}</span>;
}

export function McpCell({ item }) {
	return item.show_in_mcp ? (
		<span className="mcp-y">
			{__('✓ Yes', 'acrossai-abilities-manager')}
		</span>
	) : (
		<span className="mcp-n">
			{__('○ No', 'acrossai-abilities-manager')}
		</span>
	);
}

export function DescriptionCell({ item }) {
	const desc = item.description || item._registry?.description || '';
	if (!desc) {
		return <span>—</span>;
	}
	const short = desc.length > 80 ? desc.slice(0, 80) + '…' : desc;
	return <span title={desc}>{short}</span>;
}

export function ShowInRestCell({ item }) {
	const val = item.show_in_rest ?? item._registry?.show_in_rest ?? false;
	return val ? (
		<span className="mcp-y">
			{__('✓ Yes', 'acrossai-abilities-manager')}
		</span>
	) : (
		<span className="mcp-n">
			{__('○ No', 'acrossai-abilities-manager')}
		</span>
	);
}
