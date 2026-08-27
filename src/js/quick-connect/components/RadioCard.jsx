/**
 * F069 — Selectable card component (server picker rows + Step 5 method cards).
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
 * @package AcrossAI_MCP_Manager
 */

const RadioCard = ( {
	selected = false,
	onSelect,
	title,
	subtitle,
	badge = null,
	children = null,
	name = 'qs-card',
	value = '',
} ) => {
	const handleKeyDown = ( event ) => {
		if ( event.key === 'Enter' || event.key === ' ' ) {
			event.preventDefault();
			onSelect?.();
		}
	};

	return (
		<label
			className={ `qs-card${ selected ? ' qs-card--selected' : '' }` }
			role="radio"
			aria-checked={ selected }
			tabIndex={ 0 }
			onKeyDown={ handleKeyDown }
		>
			<input
				type="radio"
				name={ name }
				value={ value }
				checked={ selected }
				onChange={ () => onSelect?.() }
				style={ { position: 'absolute', opacity: 0, pointerEvents: 'none' } }
				tabIndex={ -1 }
			/>
			<span className="qs-card__radio" aria-hidden="true" />
			<div className="qs-card__body">
				<div className="qs-card__title">
					{ title }
					{ badge }
				</div>
				{ subtitle && (
					<div className="qs-card__subtitle">{ subtitle }</div>
				) }
				{ children }
			</div>
		</label>
	);
};

export default RadioCard;
