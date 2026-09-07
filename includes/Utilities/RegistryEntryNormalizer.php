<?php
/**
 * Shared normalization + dedup for filter-contributed registry entries.
 *
 * Feature 084 — extracted from `Admin\Partials\ServerTabs\Registry::normalize_entries()`
 * so the level-1 tab registry (`acrossai_mcp_manager_server_tabs`) and the
 * level-2 Connect method registry (`acrossai_mcp_manager_connect_methods`)
 * validate entries with identical semantics. Constitution §VI: extracted
 * BEFORE its second use, never copied.
 *
 * Why the hydration half did NOT move here: `Registry::hydrate()` instantiates
 * `FilteredServerTab` and maps built-in `AbstractServerTab` instances — both
 * admin-layer types. The Architecture & UI Standards rule (A3) is explicit
 * that classes in `includes/` are context-neutral and MUST NOT contain
 * admin-specific logic, so hydration lives on `FilteredServerTab` instead.
 * Only the array work — which touches no WordPress admin state — belongs here.
 *
 * Dedup is slug-keyed LAST-WINS (D41): a later registration replaces an
 * earlier one with the same slug, including built-in placeholders. That is what
 * enables the "built-in placeholder → companion overrides when active" pattern
 * at both levels, so both registries MUST obtain it from this one
 * implementation. Two independently-maintained dedup implementations would
 * silently break the promo→real swap.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes/Utilities
 * @since      0.4.0
 */

namespace AcrossAI_MCP_Manager\Includes\Utilities;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * RegistryEntryNormalizer — validates, coerces and dedups registry entries.
 *
 * Stateless pure service: static methods only, no singleton (A11 exemption).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes/Utilities
 * @since      0.4.0
 */
final class RegistryEntryNormalizer {

	/**
	 * Normalizes + dedups a registry filter's raw output.
	 *
	 * Behaviour is unchanged from the Feature 019 implementation this was
	 * extracted from:
	 * - `sanitize_key()` on slug — dropped when empty.
	 * - Missing `label` OR missing/non-callable `render_callback` on a
	 *   non-built-in entry → dropped with `_doing_it_wrong` under `WP_DEBUG`.
	 * - Duplicate slug → LAST registration wins; the winner keeps the earlier
	 *   insertion index so priority-tiebreak sorts stay stable.
	 * - `priority` coerced to int (default 100).
	 * - `capability` sanitized via `sanitize_key()`; empty → `'manage_options'`.
	 * - `visible_callback` must be callable or null; anything else → null.
	 *
	 * @since 0.4.0
	 * @param array<int, mixed> $raw         Filter output.
	 * @param string            $filter_name Name of the filter that produced $raw, for diagnostics.
	 * @param string            $since       Version string passed to `_doing_it_wrong()`.
	 * @return array<int, array<string, mixed>> Normalized entries, zero-indexed.
	 */
	public static function normalize( array $raw, string $filter_name, string $since ): array {
		$normalized = array();
		$index      = 0;

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$is_builtin = ! empty( $entry['_builtin'] );

			$slug = isset( $entry['slug'] ) ? sanitize_key( (string) $entry['slug'] ) : '';
			if ( '' === $slug ) {
				if ( ! $is_builtin ) {
					self::doing_it_wrong( $filter_name, 'entry missing slug', $since );
				}
				continue;
			}

			$label = isset( $entry['label'] ) ? (string) $entry['label'] : '';
			if ( '' === $label && ! $is_builtin ) {
				self::doing_it_wrong( $filter_name, sprintf( 'entry "%s" missing label', $slug ), $since );
				continue;
			}

			$render_callback = $entry['render_callback'] ?? null;
			if ( ! $is_builtin && ! is_callable( $render_callback ) ) {
				self::doing_it_wrong( $filter_name, sprintf( 'entry "%s" missing callable render_callback', $slug ), $since );
				continue;
			}

			$capability = isset( $entry['capability'] ) ? sanitize_key( (string) $entry['capability'] ) : 'manage_options';
			if ( '' === $capability ) {
				$capability = 'manage_options';
			}

			$visible_callback = $entry['visible_callback'] ?? null;
			if ( null !== $visible_callback && ! is_callable( $visible_callback ) ) {
				$visible_callback = null;
			}

			// Keyed by slug so later registrations REPLACE earlier ones with the
			// same slug (D41 last-wins). See the class docblock.
			$normalized[ $slug ] = array(
				'slug'             => $slug,
				'label'            => $label,
				'priority'         => isset( $entry['priority'] ) ? (int) $entry['priority'] : 100,
				'capability'       => $capability,
				'render_callback'  => $render_callback,
				'visible_callback' => $visible_callback,
				'_builtin'         => $is_builtin,
				'_index'           => $index++,
			);
		}

		// Convert back to a numeric array — downstream sort + iteration expects
		// zero-indexed sequential entries, not slug-keyed.
		return array_values( $normalized );
	}

	/**
	 * `_doing_it_wrong()` wrapper for malformed filter entries.
	 *
	 * PRESERVED INVARIANTS (Feature 084, security constraint C7 — these are
	 * deliberate protections, not incidental detail; do not drop them in a
	 * future refactor):
	 *
	 * 1. The `WP_DEBUG` early return. Malformed third-party registrations MUST
	 *    NOT surface as notices to production administrators — this matches the
	 *    signal-in-development-only pattern the extracted original used.
	 * 2. `esc_html()` on `$filter_name`. Post-extraction this argument is
	 *    CALLER-supplied rather than read from a class constant, so it is newly
	 *    untrusted by shape even though both current callers pass constants.
	 * 3. `esc_html()` on `$reason`. `$reason` interpolates a third-party-supplied
	 *    slug; that slug is already `sanitize_key()`-clean at every call site,
	 *    and it is escaped here anyway. "Sanitized upstream" is not a reason to
	 *    drop this — see bug pattern B8; `esc_*` is idempotent.
	 *
	 * @since 0.4.0
	 * @param string $filter_name Name of the filter that produced the entry.
	 * @param string $reason      Human-readable description of the malformation.
	 * @param string $since       Version string passed to `_doing_it_wrong()`.
	 * @return void
	 */
	private static function doing_it_wrong( string $filter_name, string $reason, string $since ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		_doing_it_wrong(
			esc_html( $filter_name ),
			esc_html( $reason ),
			esc_html( $since )
		);
	}
}
