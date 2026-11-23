/**
 * AllowlistTree — the two-level folder picker + plugin/theme pickers + custom paths textarea.
 *
 * Shared between WriteAllowlistPanel and ReadAllowlistPanel.
 *
 * Props:
 *  - value: string[]                      ABSPATH-relative paths currently allowed.
 *  - available: { core, plugins, themes } enumeration payload from the REST GET.
 *  - onChange: (string[]) => void         emits the new selection every change.
 *  - disabled: bool                       when true, the whole tree is read-only.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.1.0
 */

import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const AllowlistTree = ( { value = [], available = {}, onChange, disabled = false } ) => {
	const [ expanded, setExpanded ] = useState( () => new Set( [ 'wp-content' ] ) );
	const selected                  = useMemo( () => new Set( value ), [ value ] );
	const [ custom, setCustom ]     = useState(
		() => value.filter( ( p ) => ! isKnownPath( p, available ) ).join( '\n' )
	);

	const emit = ( next ) => {
		// Merge known-tree selection with the custom textarea entries.
		const customEntries = custom
			.split( /\r?\n/ )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		onChange( Array.from( new Set( [ ...next, ...customEntries ] ) ) );
	};

	const toggle = ( path ) => {
		if ( disabled ) {
			return;
		}
		const next = new Set( selected );
		if ( next.has( path ) ) {
			next.delete( path );
		} else {
			next.add( path );
		}
		// Preserve the user's textarea entries too.
		const known = Array.from( next );
		const customEntries = custom
			.split( /\r?\n/ )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		onChange( Array.from( new Set( [ ...known, ...customEntries ] ) ) );
	};

	const toggleExpand = ( path ) => {
		const next = new Set( expanded );
		if ( next.has( path ) ) {
			next.delete( path );
		} else {
			next.add( path );
		}
		setExpanded( next );
	};

	const onCustomChange = ( ev ) => {
		const text = ev.target.value;
		setCustom( text );
		const known = value.filter( ( p ) => isKnownPath( p, available ) );
		const customEntries = text
			.split( /\r?\n/ )
			.map( ( s ) => s.trim() )
			.filter( Boolean );
		onChange( Array.from( new Set( [ ...known, ...customEntries ] ) ) );
	};

	const cores   = available.core   || [];
	const plugins = available.plugins || [];
	const themes  = available.themes  || [];

	return (
		<div className={ `acrossai-allowlist-tree${ disabled ? ' is-disabled' : '' }` }>
			<fieldset disabled={ disabled }>
				<legend className="acrossai-fm-legend">{ __( 'Core directories', 'acrossai-abilities-manager' ) }</legend>
				<ul className="acrossai-fm-tree">
					{ cores.map( ( entry ) => (
						<li key={ entry.path }>
							<label>
								<input
									type="checkbox"
									checked={ selected.has( entry.path ) }
									onChange={ () => toggle( entry.path ) }
								/>{ ' ' }
								<code>{ entry.path }/</code>
								{ entry.children && entry.children.length > 0 && (
									<button
										type="button"
										className="button-link acrossai-fm-expander"
										onClick={ () => toggleExpand( entry.path ) }
									>
										{ expanded.has( entry.path )
											? __( '▾', 'acrossai-abilities-manager' )
											: __( '▸', 'acrossai-abilities-manager' ) }
									</button>
								) }
							</label>
							{ entry.children && entry.children.length > 0 && expanded.has( entry.path ) && (
								<ul className="acrossai-fm-tree-children">
									{ entry.children.map( ( childPath ) => (
										<li key={ childPath }>
											<label>
												<input
													type="checkbox"
													checked={ selected.has( childPath ) }
													onChange={ () => toggle( childPath ) }
												/>{ ' ' }
												<code>{ childPath }/</code>
											</label>
										</li>
									) ) }
								</ul>
							) }
						</li>
					) ) }
				</ul>

				<legend className="acrossai-fm-legend">{ __( 'Installed plugins', 'acrossai-abilities-manager' ) }</legend>
				<ul className="acrossai-fm-picker">
					{ plugins.length === 0 && <li>{ __( '(no plugins detected)', 'acrossai-abilities-manager' ) }</li> }
					{ plugins.map( ( p ) => {
						const path = `wp-content/plugins/${ p.slug }`;
						return (
							<li key={ p.slug }>
								<label>
									<input
										type="checkbox"
										checked={ selected.has( path ) }
										onChange={ () => toggle( path ) }
									/>{ ' ' }
									{ p.name } <code>({ p.slug })</code>
								</label>
							</li>
						);
					} ) }
				</ul>

				<legend className="acrossai-fm-legend">{ __( 'Installed themes', 'acrossai-abilities-manager' ) }</legend>
				<ul className="acrossai-fm-picker">
					{ themes.length === 0 && <li>{ __( '(no themes detected)', 'acrossai-abilities-manager' ) }</li> }
					{ themes.map( ( t ) => {
						const path = `wp-content/themes/${ t.stylesheet }`;
						return (
							<li key={ t.stylesheet }>
								<label>
									<input
										type="checkbox"
										checked={ selected.has( path ) }
										onChange={ () => toggle( path ) }
									/>{ ' ' }
									{ t.name } <code>({ t.stylesheet })</code>
								</label>
							</li>
						);
					} ) }
				</ul>

				<legend className="acrossai-fm-legend">{ __( 'Custom paths', 'acrossai-abilities-manager' ) }</legend>
				<p className="description">
					{ __( 'One ABSPATH-relative path per line. Anything the pickers above cannot express.', 'acrossai-abilities-manager' ) }
				</p>
				<textarea
					className="widefat code"
					rows={ 4 }
					value={ custom }
					onChange={ onCustomChange }
					placeholder="wp-content/uploads/my-app"
				/>
			</fieldset>
		</div>
	);
};

const isKnownPath = ( path, available ) => {
	const cores   = ( available.core || [] ).flatMap( ( c ) => [ c.path, ...( c.children || [] ) ] );
	const plugins = ( available.plugins || [] ).map( ( p ) => `wp-content/plugins/${ p.slug }` );
	const themes  = ( available.themes || [] ).map( ( t ) => `wp-content/themes/${ t.stylesheet }` );
	return cores.includes( path ) || plugins.includes( path ) || themes.includes( path );
};

export default AllowlistTree;
