/**
 * Feature 099 — Selectable card component (server picker rows + Step 5 method cards).
 *
 * Renders as a `<label>` wrapping a hidden native `<input type="radio">` so
 * keyboard nav (Tab + arrow keys) works out of the box. `aria-checked`
 * announces state to screen readers.
 *
 * Contract shape from `contracts/wizard-state.md`:
 *   <RadioCard selected={bool} onSelect={fn} title=".." subtitle=".." badge={element?}>
 *     {children}
 *   </RadioCard>
 *
 * SECURITY: `title` + `subtitle` render as text nodes (React auto-escapes).
 * `badge` + `children` render as-is; the caller is responsible for passing
 * only safe React elements (no dangerouslySetInnerHTML anywhere in-tree
 * per TASK-SEC-004).
 *
 * @package
 */

const RadioCard = ({
	selected = false,
	onSelect,
	title,
	subtitle,
	badge = null,
	children = null,
	name = 'qs-card',
	value = '',
	className = '',
}) => {
	// Explicit id/htmlFor pairing. The input is already nested inside the label,
	// which is a valid association on its own, but an explicit pair is what
	// jsx-a11y checks for and it survives any future restructuring of the card.
	const inputId = `qs-card-${name}-${String(value).replace(/[^a-zA-Z0-9_-]/g, '-')}`;

	return (
		<label
			className={`qs-card${selected ? ' qs-card--selected' : ''}${
				className ? ` ${className}` : ''
			}`}
			htmlFor={inputId}
		>
			{/*
			   Feature 099 diverges from the sibling here, deliberately.
			   The sibling puts role="radio" + aria-checked + tabIndex on the
			   <label> and makes the real input unfocusable. That trips two
			   jsx-a11y rules (a <label> is non-interactive, so it may not take
			   an interactive role, and once it has one it stops being a label)
			   and — more importantly — it hand-rolls keyboard behaviour that
			   the platform already provides.

			   Instead the native input stays focusable and merely visually
			   hidden, so arrow-key navigation within the group, checked state,
			   and screen-reader announcement all come from the platform. The
			   card is styled via :focus-within so the focus ring still appears
			   on the card itself. Rendered appearance is unchanged (SC-005).
			*/}
			<input
				id={inputId}
				className="qs-card__input"
				type="radio"
				name={name}
				value={value}
				checked={selected}
				onChange={() => onSelect?.()}
			/>
			<span className="qs-card__radio" aria-hidden="true" />
			<div className="qs-card__body">
				<div className="qs-card__title">
					{title}
					{badge}
				</div>
				{subtitle && (
					<div className="qs-card__subtitle">{subtitle}</div>
				)}
				{children}
			</div>
		</label>
	);
};

export default RadioCard;
