/**
 * The integration directory.
 *
 * Built from the design at claude.ai/design "Integrations Directory", section 1, the one titled
 * "Built with @wordpress/components". Two deliberate departures from that mock:
 *
 * 1. Blocked rows link out instead of acting. The mock offers "Install" and "Activate" as in-page
 *    actions; here they are links to core's own plugin-install and plugins screens. Installing or
 *    activating plugins is out of scope for this screen, and a WordPress.org-hosted plugin must not
 *    install software from elsewhere.
 * 2. There is a stale-catalogue line the mock has no state for, because the mock had no network.
 *
 * "Turned off" means this plugin stops contributing abilities to that integration. It does not
 * reach into the host plugin: several integrations sit on software that publishes abilities of its
 * own under the same namespace — WooCommerce reserves `woocommerce/`, Akismet ships its own,
 * Elementor and Jetpack have their own switches — and those keep working. When any are still live,
 * the row says how many rather than implying the group is empty.
 *
 * @package AcrossAI_Abilities_Manager
 * @since   0.0.42
 */

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	ExternalLink,
	FormToggle,
	Notice,
	SearchControl,
	Snackbar,
	Spinner,
	Tooltip,
} from '@wordpress/components';
import { Icon, check, lock, plugins } from '@wordpress/icons';

const ALL = 'all';

/**
 * Free / Pro label.
 *
 * `Badge` is private in several WordPress versions, so this is the styled span the design's own
 * component map nominates as the fallback.
 *
 * @param {Object} props         Component props.
 * @param {string} props.product Either 'pro' or 'free'.
 * @return {import('react').ReactElement} The badge.
 */
function ProductBadge({ product }) {
	const isPro = 'pro' === product;

	return (
		<span className={`wpc-badge is-${isPro ? 'info' : 'success'}`}>
			{isPro
				? __('AcrossAI Pro', 'acrossai-abilities-manager')
				: __('Free', 'acrossai-abilities-manager')}
		</span>
	);
}

/**
 * One integration card.
 *
 * @param {Object}   props              Component props.
 * @param {Object}   props.row          Joined integration row from the REST response.
 * @param {Function} props.onToggle     Called with the row when its switch is flipped.
 * @param {Function} props.onCategory   Called with a category name to filter by it.
 * @param {string}   props.abilitiesUrl Admin URL of the abilities list.
 * @param {boolean}  props.busy         Whether a save for this row is in flight.
 * @return {import('react').ReactElement} The card.
 */
function IntegrationCard({ row, onToggle, onCategory, abilitiesUrl, busy }) {
	const blocked = !!row.block;
	const classes = ['wpc-card', 'wpc-int'];

	if (blocked) {
		classes.push('is-blocked');
	} else if (!row.enabled) {
		classes.push('is-off');
	}

	const toggle = (
		<FormToggle
			checked={row.enabled}
			disabled={blocked || busy}
			onChange={() => onToggle(row)}
			aria-label={sprintf(
				/* translators: %s is an integration name. */
				__('Enable %s', 'acrossai-abilities-manager'),
				row.name
			)}
		/>
	);

	return (
		<Card className={classes.join(' ')}>
			<CardBody className="wpc-card__body">
				<div className="wpc-int__top">
					<ProductBadge product={row.product} />
					{blocked ? (
						<Tooltip text={row.block.text}>
							<span className="wpc-int__toggle">{toggle}</span>
						</Tooltip>
					) : (
						<span className="wpc-int__toggle">
							{busy ? <Spinner /> : toggle}
						</span>
					)}
				</div>

				<h3 className="wpc-h2">{row.name}</h3>
				<p className="wpc-desc">{row.desc}</p>

				<div className="wpc-int__status">
					{blocked && (
						<>
							<span
								className={`wpc-status is-${
									'pro' === row.block.kind ? 'pro' : 'warn'
								}`}
							>
								<Icon
									icon={
										'pro' === row.block.kind
											? lock
											: plugins
									}
									size={16}
								/>
								{row.block.text}
							</span>
							{!!row.block.action_url && (
								<a
									className="wpc-link"
									href={row.block.action_url}
								>
									{row.block.action}
								</a>
							)}
						</>
					)}

					{!blocked && row.enabled && (
						<>
							<span className="wpc-status is-ok">
								<Icon icon={check} size={16} />
								{__('Enabled', 'acrossai-abilities-manager')}
							</span>
							{!!abilitiesUrl && (
								<a
									className="wpc-link"
									href={`${abilitiesUrl}&group=${encodeURIComponent(
										row.slug
									)}`}
								>
									{__(
										'View abilities',
										'acrossai-abilities-manager'
									)}
								</a>
							)}
						</>
					)}

					{!blocked && !row.enabled && (
						<span className="wpc-status">
							{row.host_n > 0
								? sprintf(
										/* translators: 1: ability count, 2: plugin name. */
										__(
											'Turned off \u2014 %1$d still provided by %2$s',
											'acrossai-abilities-manager'
										),
										row.host_n,
										row.name
									)
								: __(
										'Turned off',
										'acrossai-abilities-manager'
									)}
						</span>
					)}

					{/*
					 * Something the HOST plugin still gates, that we deliberately do not switch on.
					 * Sits beside the "still provided by" line above because it answers the same
					 * kind of question: true about this row, and not a failure of it.
					 *
					 * Only ever present on an enabled row — see `host_todo` in
					 * Integrations_Directory_Controller.
					 */}
					{!blocked && !!row.host_todo && (
						<span className="wpc-status">
							{row.host_todo.label}{' '}
							<a href={row.host_todo.url} className="wpc-link">
								{__(
									'Open the setting',
									'acrossai-abilities-manager'
								)}
							</a>
						</span>
					)}
				</div>
			</CardBody>

			<CardFooter size="small" className="wpc-card__footer">
				<Button
					variant="link"
					className="wpc-btn is-link"
					onClick={() => onCategory(row.cat)}
				>
					{row.cat}
				</Button>
				<span className="wpc-n">
					{sprintf(
						/* translators: %d is a count of abilities. */
						_n(
							'%d ability',
							'%d abilities',
							row.n,
							'acrossai-abilities-manager'
						),
						row.n
					)}
				</span>
			</CardFooter>
		</Card>
	);
}

/**
 * The screen.
 *
 * @param {Object} props               Component props.
 * @param {string} props.restNamespace REST namespace.
 * @param {string} props.restBase      REST base.
 * @param {string} props.proUrl        Where to buy the paid add-on.
 * @param {string} props.requestUrl    Where to request a new integration.
 * @param {string} props.abilitiesUrl  Admin URL of the abilities list.
 * @return {import('react').ReactElement} The screen.
 */
export default function IntegrationsDirectory({
	restNamespace,
	restBase,
	proUrl,
	requestUrl,
	abilitiesUrl,
}) {
	const [rows, setRows] = useState([]);
	const [stale, setStale] = useState(false);
	const [proActive, setProActive] = useState(false);
	const [proCount, setProCount] = useState(0);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState('');
	const [cat, setCat] = useState(ALL);
	const [query, setQuery] = useState('');
	const [busySlug, setBusySlug] = useState('');
	const [snackbar, setSnackbar] = useState(null);

	const path = `/${restNamespace}/${restBase}`;

	useEffect(() => {
		let cancelled = false;

		apiFetch({ path })
			.then((data) => {
				if (cancelled) {
					return;
				}

				setRows(
					Array.isArray(data?.integrations) ? data.integrations : []
				);
				setStale(!!data?.stale);
				setProActive(!!data?.pro_active);
				setProCount(parseInt(data?.pro_count, 10) || 0);
				setLoading(false);
			})
			.catch((err) => {
				if (cancelled) {
					return;
				}

				setError(
					err?.message ||
						__(
							'The integration list could not be loaded.',
							'acrossai-abilities-manager'
						)
				);
				setLoading(false);
			});

		return () => {
			cancelled = true;
		};
	}, [path]);

	const categories = useMemo(() => {
		const seen = [];

		rows.forEach((row) => {
			if (row.cat && !seen.includes(row.cat)) {
				seen.push(row.cat);
			}
		});

		return seen.sort((a, b) => a.localeCompare(b));
	}, [rows]);

	const shown = useMemo(() => {
		const needle = query.trim().toLowerCase();

		return rows.filter(
			(row) =>
				(ALL === cat || row.cat === cat) &&
				('' === needle || row.name.toLowerCase().includes(needle))
		);
	}, [rows, cat, query]);

	const live = useMemo(() => rows.filter((row) => row.enabled), [rows]);
	const liveAbilities = live.reduce((total, row) => total + row.n, 0);

	/**
	 * Flip one row, optimistically, and reconcile with the server.
	 *
	 * The optimistic write is reverted on failure rather than left in place: this switch controls
	 * whether abilities are refused, so showing it in a state the site is not actually in would be
	 * worse than a moment's lag.
	 */
	const toggle = useCallback(
		(row) => {
			const next = !row.enabled;

			setBusySlug(row.slug);
			setSnackbar(null);
			setRows((current) =>
				current.map((item) =>
					item.slug === row.slug ? { ...item, enabled: next } : item
				)
			);

			apiFetch({
				path: `${path}/${encodeURIComponent(row.slug)}`,
				method: 'POST',
				data: { enabled: next },
			})
				.then((result) => {
					setRows((current) =>
						current.map((item) =>
							item.slug === row.slug
								? { ...item, enabled: !!result?.enabled }
								: item
						)
					);
					setBusySlug('');
					setSnackbar({
						slug: row.slug,
						text: next
							? sprintf(
									/* translators: 1: integration name, 2: number of abilities. */
									_n(
										'%1$s enabled. %2$d ability added.',
										'%1$s enabled. %2$d abilities added.',
										row.n,
										'acrossai-abilities-manager'
									),
									row.name,
									row.n
								)
							: sprintf(
									/* translators: %s is an integration name. */
									__(
										'%s turned off. Its abilities stay listed and report why they are refused.',
										'acrossai-abilities-manager'
									),
									row.name
								),
					});
				})
				.catch((err) => {
					setRows((current) =>
						current.map((item) =>
							item.slug === row.slug
								? { ...item, enabled: row.enabled }
								: item
						)
					);
					setBusySlug('');
					setError(
						err?.message ||
							sprintf(
								/* translators: %s is an integration name. */
								__(
									'%s could not be changed.',
									'acrossai-abilities-manager'
								),
								row.name
							)
					);
				});
		},
		[path]
	);

	if (loading) {
		return (
			<div className="wpc wpc--loading">
				<Spinner />
				<span className="wpc-muted">
					{__('Loading integrations…', 'acrossai-abilities-manager')}
				</span>
			</div>
		);
	}

	const undoRow = snackbar
		? rows.find((row) => row.slug === snackbar.slug)
		: null;

	return (
		<div className="wpc">
			<div className="wpc-head">
				<div className="wpc-head__text">
					<h1 className="wpc-h1">
						{__('Integrations', 'acrossai-abilities-manager')}
					</h1>
					<p className="wpc-muted">
						{__(
							'Abilities for other plugins on this site. An integration can be turned on once the plugin it depends on is active.',
							'acrossai-abilities-manager'
						)}
					</p>
				</div>
				<span className="wpc-summary">
					{sprintf(
						/* translators: 1: enabled count, 2: total count, 3: ability count. */
						__(
							'%1$d of %2$d enabled · %3$d abilities',
							'acrossai-abilities-manager'
						),
						live.length,
						rows.length,
						liveAbilities
					)}
				</span>
			</div>

			{!!error && (
				<Notice status="error" onRemove={() => setError('')}>
					{error}
				</Notice>
			)}

			{stale && (
				<p className="wpc-stale">
					{__(
						'Showing the integration list bundled with the plugin — acrossai.co could not be reached, so this may be out of date.',
						'acrossai-abilities-manager'
					)}
				</p>
			)}

			{!proActive && proCount > 0 && (
				<Notice
					status="info"
					isDismissible={false}
					className="wpc-notice"
				>
					<p>
						{sprintf(
							/* translators: %d is a count of integrations. */
							_n(
								'%d integration needs AcrossAI Pro. It stays listed and unlocks once Pro is active.',
								'%d integrations need AcrossAI Pro. They stay listed and unlock once Pro is active.',
								proCount,
								'acrossai-abilities-manager'
							),
							proCount
						)}
					</p>
					<Button
						variant="secondary"
						className="wpc-btn is-secondary"
						href={proUrl}
						target="_blank"
						rel="noopener noreferrer"
					>
						{__('Get AcrossAI Pro', 'acrossai-abilities-manager')}
					</Button>
				</Notice>
			)}

			<div className="wpc-layout">
				<aside className="wpc-rail">
					<div className="wpc-menu">
						<div className="wpc-menu__label">
							{__('Categories', 'acrossai-abilities-manager')}
						</div>
						{[
							[
								ALL,
								__(
									'All integrations',
									'acrossai-abilities-manager'
								),
								rows.length,
							],
							...categories.map((name) => [
								name,
								name,
								rows.filter((row) => row.cat === name).length,
							]),
						].map(([id, label, count]) => (
							<button
								key={id}
								type="button"
								className={`wpc-menuitem${
									cat === id ? ' is-pressed' : ''
								}`}
								aria-pressed={cat === id}
								onClick={() => setCat(id)}
							>
								<span>{label}</span>
								<span className="wpc-menuitem__suffix">
									{count}
								</span>
							</button>
						))}
					</div>

					<Card size="small" className="wpc-card wpc-card--ask">
						<CardBody className="wpc-card__body">
							<h3 className="wpc-h3">
								{__(
									"Don't see your plugin?",
									'acrossai-abilities-manager'
								)}
							</h3>
							<p className="wpc-muted">
								{__(
									'Tell us which plugin you want your AI to manage next.',
									'acrossai-abilities-manager'
								)}
							</p>
							<ExternalLink
								href={requestUrl}
								rel="noopener noreferrer"
							>
								{__(
									'Request an integration',
									'acrossai-abilities-manager'
								)}
							</ExternalLink>
						</CardBody>
					</Card>
				</aside>

				<main className="wpc-main">
					<div className="wpc-toolbar">
						<SearchControl
							__nextHasNoMarginBottom
							className="wpc-search"
							value={query}
							onChange={setQuery}
							placeholder={__(
								'Search integrations',
								'acrossai-abilities-manager'
							)}
							label={__(
								'Search integrations',
								'acrossai-abilities-manager'
							)}
						/>
						<span className="wpc-spacer" />
						<span className="wpc-muted">
							{sprintf(
								/* translators: %d is a count of integrations. */
								_n(
									'%d integration',
									'%d integrations',
									shown.length,
									'acrossai-abilities-manager'
								),
								shown.length
							)}
						</span>
					</div>

					{0 === shown.length ? (
						<Card className="wpc-card wpc-empty">
							<CardBody className="wpc-card__body">
								<h3 className="wpc-h3">
									{query.trim()
										? sprintf(
												/* translators: %s is a search term. */
												__(
													'No integration matches “%s”',
													'acrossai-abilities-manager'
												),
												query.trim()
											)
										: __(
												'No integrations in this category',
												'acrossai-abilities-manager'
											)}
								</h3>
								<p className="wpc-muted">
									{__(
										'Try another plugin name, or ask us to add it.',
										'acrossai-abilities-manager'
									)}
								</p>
								<div className="wpc-empty__actions">
									<Button
										variant="secondary"
										className="wpc-btn is-secondary"
										onClick={() => {
											setQuery('');
											setCat(ALL);
										}}
									>
										{__(
											'Clear search',
											'acrossai-abilities-manager'
										)}
									</Button>
									<ExternalLink
										href={requestUrl}
										rel="noopener noreferrer"
									>
										{__(
											'Request an integration',
											'acrossai-abilities-manager'
										)}
									</ExternalLink>
								</div>
							</CardBody>
						</Card>
					) : (
						<div className="wpc-grid">
							{shown.map((row) => (
								<IntegrationCard
									key={row.slug}
									row={row}
									busy={busySlug === row.slug}
									onToggle={toggle}
									onCategory={setCat}
									abilitiesUrl={abilitiesUrl}
								/>
							))}
						</div>
					)}
				</main>
			</div>

			{!!snackbar && (
				<div className="wpc-snackbars">
					<Snackbar
						onRemove={() => setSnackbar(null)}
						actions={
							undoRow
								? [
										{
											label: __(
												'Undo',
												'acrossai-abilities-manager'
											),
											onClick: () => toggle(undoRow),
										},
									]
								: []
						}
					>
						{snackbar.text}
					</Snackbar>
				</div>
			)}
		</div>
	);
}
