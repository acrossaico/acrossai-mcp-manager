/**
 * Source badge cell — maps source value to a styled span.
 *
 * CSS classes: .src-c (Custom/db), .src-p (Plugin), .src-k (Core), .src-t (Theme)
 *
 * @since 0.2.0
 */

const SOURCE_MAP = {
	db: { cls: 'src-c', label: 'Custom' },
	plugin: { cls: 'src-p', label: 'Plugin' },
	core: { cls: 'src-k', label: 'Core' },
	theme: { cls: 'src-t', label: 'Theme' },
};

/**
 * SourceBadge component.
 *
 * @param {Object} props
 * @param {string} props.source Ability source value.
 * @return {JSX.Element}
 */
export default function SourceBadge({ source }) {
	const { cls, label } = SOURCE_MAP[source] ?? SOURCE_MAP.db;
	return <span className={`src ${cls}`}>{label}</span>;
}
