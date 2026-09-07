<?php
/**
 * The Connect tab — one home for every way to connect an AI client.
 *
 * Feature 084 — collapses five sibling top-level tabs (`npm`, `clients`,
 * `ai-connectors`, `n8n`, `wp-cli`) into a single `?tab=connect` container with
 * a level-2 `?method=` navigation. The five all answered the same operator
 * question and spread it across an eleven-tab strip; the strip is now eight.
 *
 * Three navigation levels are reachable from here:
 *
 *   ?tab=connect            level 1 — this tab
 *     &method=<slug>        level 2 — `Connect\MethodRegistry`
 *       &panel= / &client=  level 3 — owned by each method, untouched
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.4.0
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Connect\MethodRegistry;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;
use AcrossAI_MCP_Manager\Includes\Utilities\LocalEnvironment;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * ConnectTab — container tab that dispatches to a level-2 connection method.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.4.0
 */
final class ConnectTab extends AbstractServerTab {

	/**
	 * Legacy top-level tab slug → the method that replaced it.
	 *
	 * The single source of truth for Feature 084 backwards compatibility, with
	 * two readers and no duplication:
	 *
	 * - `Settings::render_edit_page()` consults it to rewrite `$tab` to
	 *   `'connect'` IN PLACE, so dispatch finds this class and the strip
	 *   highlights Connect.
	 * - `self::resolve_active_method()` consults it against the PRE-REWRITE
	 *   `?tab=` value to decide which method opens.
	 *
	 * Never a redirect: `admin_enqueue_scripts` fires before render, and the
	 * acrossai-pro companion gates its assets on the address the browser
	 * actually requested. Rewriting the address before those gates run would
	 * leave its panels unstyled with inert buttons (FR-009).
	 *
	 * @since 0.4.0
	 * @var array<string, string>
	 */
	public const LEGACY_TAB_METHODS = array(
		'ai-connectors' => 'ai-connectors',
		'clients'       => 'clients',
		'npm'           => 'npm',
		'n8n'           => 'n8n',
		'wp-cli'        => 'wp-cli',
	);

	/**
	 * The tab's URL slug.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public function slug(): string {
		return 'connect';
	}

	/**
	 * The tab's operator-visible label.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public function label(): string {
		return __( 'Connect', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot — immediately after Overview (10).
	 *
	 * @since 0.4.0
	 * @return int
	 */
	public function priority(): int {
		return 20;
	}

	/**
	 * Builds a level-2 method URL.
	 *
	 * RETURNS A RAW, UNESCAPED URL BY CONTRACT. Callers MUST `esc_url()` at
	 * their own output site.
	 *
	 * This is deliberate, not an oversight. `public/Renderers/MCPClientsBlock.php:146`
	 * chains `add_query_arg( 'client', $slug, … )` onto the value this returns;
	 * escaping inside the builder would encode the `&` separator as `&#038;`
	 * and break every level-3 client link. Security constraint S5 is satisfied
	 * at each OUTPUT site instead — the complete list of those sites is
	 * enumerated in
	 * `specs/084-connect-tab-merge/contracts/connect-method-registration.md` §2.
	 * Matches the same contract on `AbstractServerTab::server_edit_url()`.
	 *
	 * Public and static so the acrossai-pro companion can build its own
	 * level-3 panel URLs on top of it without instantiating the tab.
	 *
	 * @since 0.4.0
	 * @param array<string, mixed> $server Server row data.
	 * @param string               $method Method slug (e.g. 'clients', 'npm').
	 * @return string Raw URL — call esc_url() at the output boundary.
	 */
	public static function method_url( array $server, string $method ): string {
		return add_query_arg(
			array(
				'page'   => AdminPageSlugs::PARENT,
				'action' => 'edit',
				'server' => (int) $server['id'],
				'tab'    => 'connect',
				'method' => sanitize_key( $method ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Resolves which method is active for this request.
	 *
	 * Evaluated once per render against the capability- and visibility-filtered
	 * set from `MethodRegistry::visible_methods()`. First match wins:
	 *
	 *   1. `?method=` present and in the filtered set.
	 *   2. The PRE-REWRITE `?tab=` is a `LEGACY_TAB_METHODS` key and its mapped
	 *      method is in the filtered set.
	 *   3. The site is local and `clients` is in the filtered set.
	 *   4. The first method in the filtered set, in priority order.
	 *
	 * Every step — including the step-4 fallback — draws from the FILTERED set,
	 * so a restricted user can never land on a method they may not see.
	 * Checking capability after resolution would make step 4 a bypass: the
	 * first method in priority order is `ai-connectors`, a paid one (C2).
	 *
	 * Unknown, removed and permission-excluded all converge here on the same
	 * path, so their observable behaviour is identical and withheld methods
	 * cannot be enumerated. The fallback is SILENT — the requested value is
	 * never rendered, echoed into a notice, or written to a user-visible
	 * surface (C3, FR-007).
	 *
	 * Step 3 reuses `LocalEnvironment::needs_tls_bypass()` verbatim: it is the
	 * sole gate for "is this site local" (D46). No second notion of local, no
	 * admin toggle.
	 *
	 * @since 0.4.0
	 * @param AbstractServerTab[] $methods The filtered method list.
	 * @return AbstractServerTab|null Null only when no method is visible at all.
	 */
	private function resolve_active_method( array $methods ): ?AbstractServerTab {
		if ( empty( $methods ) ) {
			return null;
		}

		$by_slug = array();
		foreach ( $methods as $method ) {
			$by_slug[ $method->slug() ] = $method;
		}

		// 1. Explicit request wins, when permitted.
		$requested = $this->read_query_key( 'method' );
		if ( '' !== $requested && isset( $by_slug[ $requested ] ) ) {
			return $by_slug[ $requested ];
		}

		// 2. Legacy address. Re-check filtered-set membership — this is a
		// different branch from step 1, and a bare LEGACY_TAB_METHODS lookup
		// here would be a capability bypass reachable by typing an ordinary URL.
		$legacy_tab = $this->read_query_key( 'tab' );
		if ( '' !== $legacy_tab && isset( self::LEGACY_TAB_METHODS[ $legacy_tab ] ) ) {
			$mapped = self::LEGACY_TAB_METHODS[ $legacy_tab ];
			if ( isset( $by_slug[ $mapped ] ) ) {
				return $by_slug[ $mapped ];
			}
		}

		// 3. Local-install default — a local developer's next action is almost
		// always copying a client config, and on local sites those configs
		// carry a TLS-bypass setting plus a warning worth surfacing early.
		if ( LocalEnvironment::needs_tls_bypass() && isset( $by_slug['clients'] ) ) {
			return $by_slug['clients'];
		}

		// 4. First visible method in priority order.
		return $methods[0];
	}

	/**
	 * Reads one sanitized query key.
	 *
	 * Navigation routing only — no state is mutated, so there is no nonce to
	 * verify. The suppression is scoped to the read itself, never a wider
	 * block, and matches `Settings::render_edit_page()`'s existing read.
	 *
	 * "Pre-rewrite" elsewhere in this class means "before `Settings` normalized
	 * its own copy of `$tab` to 'connect'" — never "unsanitized" (C5).
	 *
	 * @since 0.4.0
	 * @param string $key Query key to read.
	 * @return string Sanitized value, or '' when absent.
	 */
	private function read_query_key( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation routing only, no state mutation.
		return isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	/**
	 * Renders the level-2 navigation.
	 *
	 * Suppressed entirely when fewer than two methods are visible (FR-018) — a
	 * single lonely control is worse than none. Emits exactly ONE URL shape;
	 * there is no legacy-shaped branch, because Feature 084 ships no
	 * compatibility layer for an un-migrated companion.
	 *
	 * Uses WordPress core's `.nav-tab-wrapper` / `.nav-tab` classes so this row
	 * is the same control as the tab strip above it, one step down (FR-017 /
	 * SC-007): core supplies the geometry, focus and responsive behaviour, and
	 * `backend.scss` only scales it. This tab renders exclusively in wp-admin,
	 * so depending on admin-only core classes is sound here — the level-3
	 * client picker, which is emitted by a public renderer, deliberately
	 * restates the same idiom under its own classes instead.
	 *
	 * The `acrossai-connect-method*` classes are retained alongside the core
	 * ones as this plugin's own styling and test hooks.
	 *
	 * @since 0.4.0
	 * @param array<string, mixed> $server Server row data.
	 * @param AbstractServerTab[]  $methods Filtered method list.
	 * @param AbstractServerTab    $active  The active method.
	 * @return void
	 */
	private function render_method_nav( array $server, array $methods, AbstractServerTab $active ): void {
		if ( count( $methods ) < 2 ) {
			return;
		}

		echo '<nav class="nav-tab-wrapper acrossai-connect-methods-nav">';
		foreach ( $methods as $method ) {
			$is_active = $method->slug() === $active->slug();
			printf(
				'<a href="%1$s" class="%2$s"%3$s>%4$s</a>',
				esc_url( self::method_url( $server, $method->slug() ) ),
				esc_attr(
					$is_active
						? 'nav-tab nav-tab-active acrossai-connect-method acrossai-connect-method-active'
						: 'nav-tab acrossai-connect-method'
				),
				$is_active ? ' aria-current="page"' : '',
				esc_html( $method->label() )
			);
		}
		echo '</nav>';
	}

	/**
	 * Renders the navigation plus the active method's body.
	 *
	 * Error containment (FR-013, constraint C4): the active method's render is
	 * wrapped in `catch ( \Throwable )` — not `\Exception`, because a
	 * `TypeError` from a mis-registered third-party callback is the likeliest
	 * real failure and would otherwise escape containment and white-screen the
	 * page, defeating the guarantee exactly when it matters. The operator-facing
	 * message is fixed, translated and escaped, and names only the method slug;
	 * the exception detail goes to `error_log()`, never to the page. Mirrors
	 * `FilteredServerTab`'s established containment pattern.
	 *
	 * @since 0.4.0
	 * @param array<string, mixed> $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$methods = MethodRegistry::instance()->visible_methods( $server );
		$active  = $this->resolve_active_method( $methods );

		if ( null === $active ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html__(
					'No connection methods are available for your account on this server.',
					'acrossai-mcp-manager'
				)
			);
			return;
		}

		$this->render_method_nav( $server, $methods, $active );

		echo '<div class="acrossai-connect-method-panel">';
		try {
			$active->render( $server );
		} catch ( \Throwable $t ) {
			$this->log_method_throwable( $active->slug(), $t );
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: connection method slug */
						__(
							'The "%s" connection method could not render. The problem has been logged for the site administrator.',
							'acrossai-mcp-manager'
						),
						$active->slug()
					)
				)
			);
		}
		echo '</div>';
	}

	/**
	 * Logs a caught `\Throwable` from a connection method's render.
	 *
	 * Server-side only. The exception message, file and line MUST NOT reach the
	 * rendered page (C4) — they go here and nowhere else.
	 *
	 * @since 0.4.0
	 * @param string     $method_slug Slug of the method that threw.
	 * @param \Throwable $t           Caught throwable.
	 * @return void
	 */
	private function log_method_throwable( string $method_slug, \Throwable $t ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: render failures MUST reach the site administrator's debug.log.
		error_log(
			sprintf(
				'[acrossai-mcp-manager] Feature 084 — connection method "%1$s" threw %2$s: %3$s in %4$s:%5$d',
				$method_slug,
				get_class( $t ),
				$t->getMessage(),
				$t->getFile(),
				$t->getLine()
			)
		);
	}
}
