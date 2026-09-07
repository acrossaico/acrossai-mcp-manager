<?php
/**
 * Adapter — wraps a filter-contributed tab entry as an AbstractServerTab.
 *
 * Feature 019 — third-party plugins hook the
 * `acrossai_mcp_manager_server_tabs` filter with a plain array entry
 * `[slug, label, priority, capability, render_callback, visible_callback]`.
 * `Registry::for_server()` normalizes the filter output and wraps each
 * non-built-in entry in an instance of this class so the rest of the
 * dispatch pipeline (`Registry::render()`, `visible_tabs()`,
 * `SettingsRenderer::render_tab_nav()`) treats third-party tabs identically
 * to built-ins.
 *
 * The safety guarantee: a `\Throwable` from either callback is caught,
 * logged via `error_log()`, and (for `render_body`) rendered as an inline
 * `notice notice-error` — the outer request completes with a 200 and the
 * other tabs on the page render normally. Mirrors the `safeApplyFilters`
 * JS pattern established by Feature 017 in `src/js/abilities.js`.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.7
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * FilteredServerTab — adapts an entry array to the AbstractServerTab contract.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.0.7
 */
final class FilteredServerTab extends AbstractServerTab {

	/**
	 * Normalized entry array.
	 *
	 * @since 0.0.7
	 * @var array<string, mixed>
	 */
	private array $entry;

	/**
	 * Constructor.
	 *
	 * The entry MUST already be normalized by `Registry::normalize_entries()` —
	 * required keys present and typed, callables verified. This class does
	 * not re-validate; feed it garbage and you will get an inline error at
	 * render time.
	 *
	 * @since 0.0.7
	 * @param array<string, mixed> $entry Normalized entry array.
	 */
	public function __construct( array $entry ) {
		$this->entry = $entry;
	}

	/**
	 * Hydrates normalized entries into `AbstractServerTab[]`.
	 *
	 * Feature 084 — shared by `ServerTabs\Registry` (level-1 tabs) and
	 * `ServerTabs\Connect\MethodRegistry` (level-2 Connect methods), which
	 * differ only in where their built-in map comes from.
	 *
	 * Built-in entries (`_builtin === true`) map to the concrete class instance
	 * supplied in `$builtin_map` by slug. Everything else is wrapped in this
	 * adapter.
	 *
	 * This lives here rather than in `Includes\Utilities\RegistryEntryNormalizer`
	 * alongside the normalization it pairs with: it instantiates this class and
	 * maps `AbstractServerTab` instances, both admin-layer types, and A3 forbids
	 * admin-specific logic in `includes/`.
	 *
	 * @since 0.4.0
	 * @param array<int, array<string, mixed>> $entries     Entries already normalized by `RegistryEntryNormalizer::normalize()`.
	 * @param array<string, AbstractServerTab> $builtin_map Slug → concrete built-in instance.
	 * @return AbstractServerTab[]
	 */
	public static function hydrate_entries( array $entries, array $builtin_map ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			$slug = (string) ( $entry['slug'] ?? '' );
			if ( ! empty( $entry['_builtin'] ) && isset( $builtin_map[ $slug ] ) ) {
				$out[] = $builtin_map[ $slug ];
				continue;
			}
			$out[] = new self( $entry );
		}
		return $out;
	}

	/**
	 * The tab's URL slug.
	 *
	 * @since 0.0.7
	 * @return string
	 */
	public function slug(): string {
		return (string) ( $this->entry['slug'] ?? '' );
	}

	/**
	 * The tab's operator-visible label.
	 *
	 * @since 0.0.7
	 * @return string
	 */
	public function label(): string {
		return (string) ( $this->entry['label'] ?? '' );
	}

	/**
	 * Priority slot for the sort in `Registry::for_server()`.
	 *
	 * @since 0.0.7
	 * @return int
	 */
	public function priority(): int {
		return (int) ( $this->entry['priority'] ?? 100 );
	}

	/**
	 * Visibility gate.
	 *
	 * Two-step check:
	 *   1. `current_user_can( capability )` — false short-circuits.
	 *   2. If a `visible_callback` is set, invoke it (inside try/catch); on
	 *      throw, log + return false.
	 *
	 * @since 0.0.7
	 * @param array<string, mixed> $server Server row data.
	 * @return bool
	 */
	public function visible_for( array $server ): bool {
		$capability = (string) ( $this->entry['capability'] ?? 'manage_options' );
		if ( ! current_user_can( $capability ) ) {
			return false;
		}

		$visible_callback = $this->entry['visible_callback'] ?? null;
		if ( ! is_callable( $visible_callback ) ) {
			return true;
		}

		try {
			return (bool) call_user_func( $visible_callback, $server );
		} catch ( \Throwable $t ) {
			$this->log_throwable( 'visible_callback', $t );
			return false;
		}
	}

	/**
	 * Renders the tab body via the entry's `render_callback`.
	 *
	 * Wraps invocation in `try { … } catch ( \Throwable $t ) { … }` — on
	 * catch, `error_log()` a diagnostic line and echo an inline
	 * `notice notice-error` so the operator sees the failure without a
	 * white-screen. The exception does NOT propagate.
	 *
	 * @since 0.0.7
	 * @param array<string, mixed> $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$render_callback = $this->entry['render_callback'] ?? null;
		if ( ! is_callable( $render_callback ) ) {
			$this->render_error_notice(
				__( 'This tab is missing a render callback.', 'acrossai-mcp-manager' )
			);
			return;
		}

		try {
			call_user_func( $render_callback, $server );
		} catch ( \Throwable $t ) {
			$this->log_throwable( 'render_callback', $t );
			$this->render_error_notice(
				sprintf(
					/* translators: %s: tab slug */
					__( 'The "%s" tab could not render. The problem has been logged for the site administrator.', 'acrossai-mcp-manager' ),
					$this->slug()
				)
			);
		}
	}

	/**
	 * Emits a `notice notice-error` block explaining a callback failure to
	 * the operator without exposing the stack trace.
	 *
	 * @since 0.0.7
	 * @param string $message Already-translated message body.
	 * @return void
	 */
	private function render_error_notice( string $message ): void {
		printf(
			'<div class="notice notice-error inline"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Logs a caught `\Throwable` from a third-party callback.
	 *
	 * Line format: `[acrossai-mcp-manager] Feature 019 — third-party
	 * <origin> for tab "<slug>" threw <ExceptionClass>: <message> in
	 * <file>:<line>`.
	 *
	 * @since 0.0.7
	 * @param string     $origin Which callback threw (`render_callback` or `visible_callback`).
	 * @param \Throwable $t      Caught throwable.
	 * @return void
	 */
	private function log_throwable( string $origin, \Throwable $t ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: third-party callback failures MUST reach the site administrator's debug.log.
		error_log(
			sprintf(
				'[acrossai-mcp-manager] Feature 019 — third-party %1$s for tab "%2$s" threw %3$s: %4$s in %5$s:%6$d',
				$origin,
				$this->slug(),
				get_class( $t ),
				$t->getMessage(),
				$t->getFile(),
				$t->getLine()
			)
		);
	}
}
