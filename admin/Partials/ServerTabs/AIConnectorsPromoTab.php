<?php
/**
 * AI Connectors promo tab — fallback placeholder when the
 * acrossai-pro companion add-on is not installed / not active.
 *
 * Post-Feature 040, the real AI Connectors admin surface lives in the
 * companion plugin. When the companion is active, it registers its real
 * `AIConnectorsTab` via the `acrossai_mcp_manager_server_tabs` filter at
 * priority 35, and Registry's last-wins dedup (also introduced in F040)
 * replaces this built-in placeholder with the real tab automatically. This
 * class only renders when no such filter registration overrides it — i.e.
 * when the companion is missing OR installed-but-inactive.
 *
 * Visual language mirrors `AcrossAI_Main_Menu\ConsultationsPageRenderer`
 * (Space Grotesk + IBM Plex Sans, purple accent, dotted radial backdrop,
 * hero glow) so the promo feels like a first-party AcrossAI surface rather
 * than default admin chrome. All styles are scoped to `acai-aic-promo__*`
 * and emitted once per request via a static flag.
 *
 * Constitution v1.1.0 §IV Connector-picker card layout exception applies —
 * this is a branded promo landing, not a filterable/sortable data grid, so
 * hand-rolled markup is preferred over DataViews/DataForm.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.2.0
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * AI Connectors promo tab (placeholder for the companion plugin).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.2.0
 */
final class AIConnectorsPromoTab extends AbstractServerTab {

	/**
	 * Companion plugin slug — the folder name under wp-content/plugins/.
	 * Renamed from `acrossai-ai-connectors` to `acrossai-pro`; matches the
	 * vendor addons-registry slug the shared Add-ons page installs.
	 */
	private const SIBLING_SLUG = 'acrossai-pro';

	/**
	 * External landing page for the AI Connectors add-on.
	 */
	private const LANDING_URL = 'https://acrossai.co/ai-connectors/';

	/**
	 * Public pricing page — destination for the "Start free trial" CTA shown
	 * when the companion add-on is not installed on this site.
	 */
	private const PRICING_URL = 'https://acrossai.co/pricing/';

	/**
	 * Public docs — "Extending connector profiles" guide.
	 */
	private const DOCS_URL = 'https://github.com/acrossai-co/acrossai-mcp-manager/blob/main/docs/extending-connector-profiles.md';

	/**
	 * AcrossAI brand logo — matches the URL the vendor's `AddonsPageRenderer`
	 * uses for the shared Add-ons page add-on icons, so the promo card reads
	 * as first-party AcrossAI chrome rather than a plugin-specific graphic.
	 */
	private const LOGO_URL = 'https://acrossai.co/wp-content/uploads/2026/07/acrossai-logo-2.svg';

	/**
	 * Whether the scoped CSS has been emitted this request. Prevents
	 * duplicate `<style>` blocks when the tab renders more than once.
	 *
	 * @var bool
	 */
	private static $styles_emitted = false;

	/**
	 * Returns the tab slug.
	 *
	 * MUST match the companion plugin's slug so the URL
	 * `?page=acrossai_mcp_manager&action=edit&server=<id>&tab=ai-connectors`
	 * resolves either to this placeholder or (when the companion is active
	 * and its filter callback overrides via last-wins dedup) to the real tab.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'ai-connectors';
	}

	/**
	 * Returns the tab label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Connectors/Integrations/plugins', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot — matches the pre-F040 built-in position (between
	 * ClientsTab at 30 and WpCliTab at 40) and matches the companion's
	 * registration priority so replacement is visually seamless.
	 *
	 * @return int
	 */
	public function priority(): int {
		return 10;
	}

	/**
	 * Renders the promo landing.
	 *
	 * Bypasses the default `render_body()` scaffold from AbstractServerTab
	 * because the landing is a self-contained visual unit. Wrapped in the
	 * standard `<div class="mcp-tab-panel">` so admin CSS gutters stay
	 * consistent with sibling tabs — the inner `.acai-aic-promo` element
	 * negative-margins outward to reach the tab-content edge for the
	 * full-bleed dotted backdrop.
	 *
	 * @param array<string, mixed> $server Server row data (unused; kept for signature compat).
	 * @return void
	 */
	protected function render_body( array $server ): void {
		unset( $server ); // Promo does not vary by server.

		$state = $this->resolve_state();

		// Safety net: if somehow we get here with 'active', it means the
		// companion is loaded but its filter callback failed to run — bail
		// silently rather than shadow the real tab. Should not happen under
		// normal flow because last-wins dedup would have replaced us.
		if ( 'active' === $state ) {
			return;
		}

		$this->emit_styles_once();

		echo '<div class="mcp-tab-panel">';
		$this->render_hero( $state );
		echo '</div>';
	}

	/**
	 * Emits the promo sales card — small badge, icon, bold headline,
	 * one-line tagline, three feature bullets, primary CTA + Learn more.
	 * Sales-page polish, minimum copy.
	 *
	 * @param string $state Companion state — 'inactive' or 'missing'.
	 * @return void
	 */
	private function render_hero( string $state ): void {
		$is_inactive = 'inactive' === $state;
		$cta_label   = $is_inactive
			? __( 'Activate add-on', 'acrossai-mcp-manager' )
			: __( 'Start free trial', 'acrossai-mcp-manager' );
		$cta_url     = $this->resolve_cta_url( $state );

		// The not-installed CTA leaves wp-admin for the public pricing page —
		// open it in a new tab so the operator keeps their server-edit screen.
		$cta_target = $is_inactive ? '' : ' target="_blank" rel="noopener noreferrer"';
		?>
<div class="acai-aic-promo">
	<div class="acai-aic-promo__card">

		<?php $this->render_trial_banner(); ?>

		<img class="acai-aic-promo__logo" src="<?php echo esc_url( self::LOGO_URL ); ?>" alt="<?php esc_attr_e( 'AcrossAI', 'acrossai-mcp-manager' ); ?>" />


		<div class="acai-aic-promo__badge-row">
			<span class="acai-aic-promo__badge">
				<?php esc_html_e( 'Add-on', 'acrossai-mcp-manager' ); ?>
			</span>
		</div>

		<h2 class="acai-aic-promo__title">
			<?php esc_html_e( 'Connect WordPress to Claude, ChatGPT, Grok, Gemini & Cursor in one click', 'acrossai-mcp-manager' ); ?>
		</h2>

		<p class="acai-aic-promo__lede">
			<?php esc_html_e( 'Paste one URL, approve the consent screen, and manage every connection from a single dashboard.', 'acrossai-mcp-manager' ); ?>
		</p>

		<div class="acai-aic-promo__clients" aria-label="<?php esc_attr_e( 'Supported AI clients', 'acrossai-mcp-manager' ); ?>">
			<span class="acai-aic-promo__client-pill">Claude</span>
			<span class="acai-aic-promo__client-pill">ChatGPT</span>
			<span class="acai-aic-promo__client-pill">Grok</span>
			<span class="acai-aic-promo__client-pill">Gemini</span>
			<span class="acai-aic-promo__client-pill">Cursor</span>
		</div>

		<ul class="acai-aic-promo__features">
			<li>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
				<span><strong><?php esc_html_e( 'Zero setup', 'acrossai-mcp-manager' ); ?></strong> — <?php esc_html_e( 'no transport configuration, no server process to run.', 'acrossai-mcp-manager' ); ?></span>
			</li>
			<li>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
				<span><strong><?php esc_html_e( 'Managed authentication', 'acrossai-mcp-manager' ); ?></strong> — <?php esc_html_e( 'OAuth flows and token storage handled for you.', 'acrossai-mcp-manager' ); ?></span>
			</li>
			<li>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
				<span><strong><?php esc_html_e( 'Automatic tool discovery', 'acrossai-mcp-manager' ); ?></strong> — <?php esc_html_e( "your server's tools appear in the conversation.", 'acrossai-mcp-manager' ); ?></span>
			</li>
			<li>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
				<span><strong><?php esc_html_e( 'Granular control', 'acrossai-mcp-manager' ); ?></strong> — <?php esc_html_e( 'enable, disable, and revoke access anytime.', 'acrossai-mcp-manager' ); ?></span>
			</li>
		</ul>

		<div class="acai-aic-promo__actions">
			<a class="acai-aic-promo__cta" href="<?php echo esc_url( $cta_url ); ?>"<?php echo $cta_target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static literal, no user input. ?>>
				<?php echo esc_html( $cta_label ); ?>
			</a>
			<a class="acai-aic-promo__link" href="<?php echo esc_url( self::LANDING_URL ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Learn more', 'acrossai-mcp-manager' ); ?>
			</a>
		</div>

		<p class="acai-aic-promo__trust">
			<?php esc_html_e( '14-day money-back guarantee · Cancel anytime', 'acrossai-mcp-manager' ); ?>
		</p>

	</div>
</div>
		<?php
	}

	/**
	 * Emits the launch-offer banner that sits full-bleed across the top of
	 * the promo card. Rendered for both promo states — 'missing' (stage 1)
	 * and 'inactive' (stage 2) — since the trial is what unlocks the add-on
	 * in either case. Never reached in the 'active' state because the whole
	 * card is skipped there.
	 *
	 * Copy is evergreen (no fixed end date), so the banner has no expiry
	 * condition — it shows whenever the promo card itself shows.
	 *
	 * @return void
	 */
	private function render_trial_banner(): void {
		printf(
			'<div class="acai-aic-promo__banner">
				<p class="acai-aic-promo__banner-text">
					<strong>%1$s</strong>
					<span>%2$s</span>
				</p>
				<a class="acai-aic-promo__banner-cta" href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a>
			</div>',
			esc_html__( 'Start today — free for 30 days.', 'acrossai-mcp-manager' ),
			esc_html__( 'No credit card required.', 'acrossai-mcp-manager' ),
			esc_url( self::PRICING_URL ),
			esc_html__( 'Start free trial', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Resolves the destination URL for the primary CTA. Depends on state:
	 *
	 * - inactive : link to plugins.php with a nonce'd activate action.
	 * - missing  : link to the public pricing page, where the operator starts
	 *              the free trial before the add-on can be installed.
	 *
	 * @param string $state Companion state — 'inactive' or 'missing'.
	 * @return string
	 */
	private function resolve_cta_url( string $state ): string {
		if ( 'inactive' === $state ) {
			$plugin_file = $this->find_sibling_plugin_file();
			if ( null === $plugin_file ) {
				// Race: deleted between resolve_state() and here — fall through to the trial path.
				return self::PRICING_URL;
			}
			return wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'activate',
						'plugin' => rawurlencode( $plugin_file ),
					),
					admin_url( 'plugins.php' )
				),
				'activate-plugin_' . $plugin_file
			);
		}
		return self::PRICING_URL;
	}

	/**
	 * Resolves the companion plugin's install/active state.
	 *
	 * Returns one of:
	 *   - 'active'   — plugin file present AND `is_plugin_active()` true
	 *   - 'inactive' — plugin file present, not active
	 *   - 'missing'  — plugin file not present in wp-content/plugins
	 *
	 * @return string
	 */
	private function resolve_state(): string {
		$this->ensure_plugin_helpers_loaded();

		$plugin_file = $this->find_sibling_plugin_file();
		if ( null === $plugin_file ) {
			return 'missing';
		}
		if ( is_plugin_active( $plugin_file ) ) {
			return 'active';
		}
		return 'inactive';
	}

	/**
	 * Locates the companion plugin's main file (relative to wp-content/plugins/).
	 *
	 * Prefers `\AcrossAI_Main_Menu\AddonsInstaller::find_plugin_file()` when
	 * the vendor helper is loaded (handles edge cases where the plugin folder
	 * name differs from the slug). Falls back to a `get_plugins()` scan
	 * matching entries whose path starts with `acrossai-pro/`.
	 *
	 * @return string|null Plugin file path (e.g. `acrossai-pro/acrossai-pro.php`) or null when missing.
	 */
	private function find_sibling_plugin_file(): ?string {
		if ( class_exists( '\\AcrossAI_Main_Menu\\AddonsInstaller' ) ) {
			$installer = new \AcrossAI_Main_Menu\AddonsInstaller();
			$found     = $installer->find_plugin_file( array( 'slug' => self::SIBLING_SLUG ) );
			return null === $found ? null : (string) $found;
		}

		foreach ( (array) get_plugins() as $file => $data ) {
			unset( $data );
			if ( 0 === strpos( (string) $file, self::SIBLING_SLUG . '/' ) ) {
				return (string) $file;
			}
		}
		return null;
	}

	/**
	 * Ensures the admin plugin helpers (`is_plugin_active`, `get_plugins`)
	 * are loaded. They live in wp-admin/includes/plugin.php which is not
	 * autoloaded on every request.
	 *
	 * @return void
	 */
	private function ensure_plugin_helpers_loaded(): void {
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Emits the promo landing's scoped stylesheet once per request.
	 *
	 * Visual language matches `AcrossAI_Main_Menu\ConsultationsPageRenderer`
	 * so both surfaces read as the same product. All rules are prefixed
	 * with `.acai-aic-promo` so they can't leak into sibling tabs.
	 *
	 * @return void
	 */
	private function emit_styles_once(): void {
		if ( self::$styles_emitted ) {
			return;
		}
		self::$styles_emitted = true;
		?>
<style>
.acai-aic-promo {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: calc(100vh - 260px);
	padding: 32px 20px;
}
.acai-aic-promo *, .acai-aic-promo *::before, .acai-aic-promo *::after { box-sizing: border-box; }
.acai-aic-promo__card {
	position: relative;
	max-width: 520px;
	width: 100%;
	background: #ffffff;
	border: 1px solid #e5e7eb;
	border-radius: 14px;
	padding: 32px 36px 28px;
	text-align: center;
	overflow: hidden; /* Clips the full-bleed banner to the card's rounded top. */
	box-shadow: 0 2px 8px -4px rgba(85,56,238,0.12), 0 1px 3px rgba(0,0,0,.05);
}
.acai-aic-promo__card-glow { display: none; }
/* Launch-offer banner — full-bleed across the card's top edge. The negative
	margins cancel the card's 32px/36px padding; the radius is the card's 14px
	minus its 1px border so the corners sit flush inside it. */
.acai-aic-promo__banner {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	flex-wrap: wrap;
	margin: -32px -36px 24px;
	padding: 12px 20px;
	background: linear-gradient(90deg, #312e81 0%, #4338ca 55%, #4f46e5 100%);
	border-radius: 13px 13px 0 0;
	text-align: center;
}
/* Two stacked lines: bold offer on top, the no-card reassurance beneath. */
.acai-aic-promo__banner-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 0;
	color: #dbeafe;
	font-size: 13px;
	line-height: 1.4;
}
.acai-aic-promo__banner-text strong {
	color: #ffffff;
	font-weight: 700;
	font-size: 13.5px;
}
.acai-aic-promo__banner-cta {
	flex: 0 0 auto;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: #ffffff;
	color: #3730a3 !important;
	font-size: 13px;
	font-weight: 600;
	text-decoration: none;
	padding: 7px 16px;
	border-radius: 999px;
	transition: background .15s ease, color .15s ease;
}
.acai-aic-promo__banner-cta:hover,
.acai-aic-promo__banner-cta:focus {
	background: #eef2ff;
	color: #312e81 !important;
	text-decoration: none;
}
.acai-aic-promo__banner-cta:focus-visible {
	outline: 2px solid #ffffff;
	outline-offset: 2px;
}
.acai-aic-promo__logo {
	display: block;
	width: auto;
	height: 48px;
	max-width: 180px;
	margin: 0 auto 14px;
	object-fit: contain;
}
.acai-aic-promo__badge {
	display: inline-block;
	padding: 2px 10px;
	background: #eef2ff;
	color: #4f46e5;
	border-radius: 999px;
	font-size: 11px;
	font-weight: 600;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	margin: 0 0 10px;
}
.acai-aic-promo__title {
	margin: 0 auto 10px;
	padding: 0;
	max-width: 420px;
	font-size: 20px;
	line-height: 1.3;
	font-weight: 700;
	color: #111827;
	letter-spacing: -0.01em;
}
.acai-aic-promo__lede {
	margin: 0 auto 18px;
	max-width: 400px;
	color: #4b5563;
	font-size: 13.5px;
	line-height: 1.55;
}
.acai-aic-promo__clients {
	display: flex;
	justify-content: center;
	gap: 6px;
	flex-wrap: wrap;
	margin: 0 auto 22px;
}
.acai-aic-promo__client-pill {
	display: inline-block;
	padding: 3px 10px;
	background: #f3f4f6;
	color: #374151;
	border: 1px solid #e5e7eb;
	border-radius: 999px;
	font-size: 12px;
	font-weight: 500;
}
.acai-aic-promo__features {
	list-style: none;
	margin: 0 auto 24px;
	padding: 0;
	text-align: left;
	max-width: 400px;
	display: grid;
	gap: 10px;
}
.acai-aic-promo__features li {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	color: #1f2937;
	font-size: 13px;
	line-height: 1.5;
}
.acai-aic-promo__features li strong {
	color: #111827;
	font-weight: 600;
}
.acai-aic-promo__features li svg {
	flex: 0 0 16px;
	width: 16px;
	height: 16px;
	margin-top: 2px;
	color: #4f46e5;
}
.acai-aic-promo__actions {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 18px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}
.acai-aic-promo__cta {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: #4f46e5;
	color: #ffffff !important;
	font-size: 13.5px;
	font-weight: 600;
	text-decoration: none;
	padding: 10px 20px;
	border-radius: 8px;
	box-shadow: 0 4px 12px -6px rgba(85,56,238,0.55);
	transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
}
.acai-aic-promo__cta:hover,
.acai-aic-promo__cta:focus {
	background: #4338ca;
	color: #ffffff !important;
	transform: translateY(-1px);
	box-shadow: 0 8px 16px -6px rgba(85,56,238,0.6);
	outline: none;
}
.acai-aic-promo__cta:focus-visible {
	outline: 2px solid #4f46e5;
	outline-offset: 2px;
}
.acai-aic-promo__link {
	color: #4f46e5;
	text-decoration: none;
	font-weight: 500;
	font-size: 13px;
}
.acai-aic-promo__link:hover,
.acai-aic-promo__link:focus {
	color: #3730a3;
	text-decoration: underline;
}
.acai-aic-promo__trust {
	margin: 0;
	padding: 0;
	font-size: 12px;
	color: #6b7280;
}
</style>
		<?php
	}
}
