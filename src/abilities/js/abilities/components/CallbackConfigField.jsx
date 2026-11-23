/**
 * CallbackConfigField — renders the .ecfg config block below the type chips.
 *
 * Matches Edit Form Wireframe structure:
 *   .ecfg > .ecfg-hdr (.ecfg-dot + .ecfg-title) > sub-form content
 *
 * Callback types:
 *   noop           → info notice (no user input)
 *   filter_hook    → hook_name text input
 *   wp_remote_post → url + method select + timeout number (.cluster layout)
 *   php_code       → dark monospace textarea with SEC-010-03 execution warning
 *
 * @since 0.2.0
 */
import { __ } from '@wordpress/i18n';

const DEFAULT_TIMEOUT = 30;

// ---------------------------------------------------------------------------
// Sub-forms
// ---------------------------------------------------------------------------

function NoopConfig() {
	return (
		<div className="desc">
			{__(
				'No code runs. Useful for declarative or schema-only abilities handled entirely by MCP clients.',
				'acrossai-abilities-manager'
			)}
		</div>
	);
}

function FilterHookConfig({ config, onChange }) {
	return (
		<div className="fr">
			<label htmlFor="cb-hook-name" className="fl">
				{__('Hook name', 'acrossai-abilities-manager')}
				<span className="req"> *</span>
			</label>
			<div className="ff">
				<input
					id="cb-hook-name"
					type="text"
					className="ri"
					value={config.hook_name || ''}
					placeholder="acrossai/my_hook"
					onChange={(e) =>
						onChange({ ...config, hook_name: e.target.value })
					}
				/>
				<div className="desc">
					{__('WordPress filter hook.', 'acrossai-abilities-manager')}{' '}
					<code>{'apply_filters()'}</code>{' '}
					{__(
						'is called with the input args.',
						'acrossai-abilities-manager'
					)}
				</div>
			</div>
		</div>
	);
}

function RemotePostConfig({ config, onChange }) {
	return (
		<>
			<div className="fr">
				<label htmlFor="cb-url" className="fl">
					{__('URL', 'acrossai-abilities-manager')}
					<span className="req"> *</span>
				</label>
				<div className="ff">
					<input
						id="cb-url"
						type="url"
						className="ri"
						value={config.url || ''}
						placeholder="https://…"
						onChange={(e) =>
							onChange({ ...config, url: e.target.value })
						}
					/>
					<div className="desc">
						{__(
							'HTTPS endpoint the input payload will be POSTed to.',
							'acrossai-abilities-manager'
						)}
					</div>
				</div>
			</div>
			<div className="fr">
				<label className="fl">
					{__('Request', 'acrossai-abilities-manager')}
				</label>
				<div className="ff">
					<div className="cluster">
						<div className="cluster-f">
							<div className="cluster-l">
								{__('Method', 'acrossai-abilities-manager')}
							</div>
							<select
								id="cb-method"
								className="rs"
								style={{ width: '100px' }}
								value={config.method || 'POST'}
								onChange={(e) =>
									onChange({
										...config,
										method: e.target.value,
									})
								}
							>
								<option value="POST">POST</option>
								<option value="GET">GET</option>
								<option value="PUT">PUT</option>
								<option value="PATCH">PATCH</option>
							</select>
						</div>
						<div className="cluster-f">
							<div className="cluster-l">
								{__(
									'Timeout (sec)',
									'acrossai-abilities-manager'
								)}
							</div>
							<input
								id="cb-timeout"
								type="number"
								className="ri"
								style={{ width: '100px' }}
								min={1}
								max={30}
								value={config.timeout || DEFAULT_TIMEOUT}
								onChange={(e) =>
									onChange({
										...config,
										timeout:
											parseInt(e.target.value, 10) ||
											DEFAULT_TIMEOUT,
									})
								}
							/>
						</div>
					</div>
				</div>
			</div>
		</>
	);
}

function PhpCodeConfig({ config, onChange }) {
	return (
		<>
			{/* SEC-010-03: execution warning */}
			<div className="notice n-warn">
				⚠{' '}
				{__(
					'Raw PHP executed server-side. Review carefully before publishing.',
					'acrossai-abilities-manager'
				)}
			</div>
			<textarea
				id="cb-php-code"
				className="rt dark"
				value={config.code || ''}
				placeholder={'// $args contains decoded input\nreturn $args;'}
				onChange={(e) => onChange({ ...config, code: e.target.value })}
			/>
		</>
	);
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

const LABEL_MAP = {
	noop: 'noop — no execution',
	filter_hook: 'filter_hook configuration',
	wp_remote_post: 'wp_remote_post configuration',
	php_code: 'php_code configuration',
};

/**
 * CallbackConfigField component.
 *
 * @param {Object}   props
 * @param {string}   props.callbackType One of: noop | filter_hook | wp_remote_post | php_code
 * @param {Object}   props.config       Current callback_config object (parsed from JSON).
 * @param {Function} props.onChange     Called with new config object on any change.
 * @return {JSX.Element}
 */
export default function CallbackConfigField({
	callbackType,
	config,
	onChange,
}) {
	const cfg = config || {};

	return (
		<div className="ecfg">
			<div className="ecfg-hdr">
				<div className="ecfg-dot" />
				<div className="ecfg-title">
					{LABEL_MAP[callbackType] || callbackType}
				</div>
			</div>

			{'noop' === callbackType && <NoopConfig />}
			{'filter_hook' === callbackType && (
				<FilterHookConfig config={cfg} onChange={onChange} />
			)}
			{'wp_remote_post' === callbackType && (
				<RemotePostConfig config={cfg} onChange={onChange} />
			)}
			{'php_code' === callbackType && (
				<PhpCodeConfig config={cfg} onChange={onChange} />
			)}
		</div>
	);
}
