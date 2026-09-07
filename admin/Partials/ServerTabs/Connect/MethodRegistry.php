<?php
/**
 * Singleton enumeration for the Connect tab's level-2 connection methods.
 *
 * Feature 084 — the five connection methods (`ai-connectors`, `clients`,
 * `npm`, `n8n`, `wp-cli`) previously lived as sibling top-level tabs. They now
 * live one level down, inside `ConnectTab`, selected by a `?method=` query
 * parameter. This class is to methods what `ServerTabs\Registry` is to tabs:
 * it seeds the built-ins, fires one filter, normalizes, dedups, hydrates and
 * sorts — exactly one canonical enumeration path (D35). `ConnectTab` consumes
 * the result and never re-fires the filter.
 *
 * NOT to be confused with `AcrossAI_MCP_Manager\Public\Discovery\ConnectionMethodRegistry`,
 * which is an entirely different layer (the public discovery DTO producer) and
 * is deliberately untouched by Feature 084. Similar name, unrelated concern.
 *
 * ## Accessor naming — deliberately NOT mirrored from the sibling
 *
 * In `ServerTabs\Registry`, `for_server()` is the UNFILTERED accessor and
 * `visible_tabs()` is the filtered one, and `render()` dispatches off the
 * unfiltered list. Reusing the name `for_server()` here for a
 * capability-FILTERED set would give one name opposite meanings one level
 * apart — the mismatch captured as decision D53. This class therefore exposes
 * `visible_methods()` as its SOLE public read path, keeps collection private,
 * and offers no unfiltered public accessor at all, so the ambiguity cannot be
 * reintroduced by a later caller reaching for the "raw" list.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs/Connect
 * @since      0.4.0
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Connect;

use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractServerTab;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AIConnectorsPromoTab;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\ClientsTab;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\FilteredServerTab;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\NpmTab;
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\WpCliTab;
use AcrossAI_MCP_Manager\Includes\Utilities\RegistryEntryNormalizer;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * MethodRegistry — ordered, validated, permission-filtered connection methods.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs/Connect
 * @since      0.4.0
 */
final class MethodRegistry {

	/**
	 * Filter fired to collect level-2 connection methods.
	 *
	 * Entry shape mirrors `ServerTabs\Registry::FILTER_NAME` key-for-key, so a
	 * developer who knows one knows the other. Reserved priority scale:
	 * `ai-connectors` 10, `clients` 20, `npm` 30, **40 reserved for the
	 * acrossai-pro companion's n8n**, `wp-cli` 50. Third parties should
	 * register at >= 60.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	public const FILTER_NAME = 'acrossai_mcp_manager_connect_methods';

	/**
	 * Singleton instance.
	 *
	 * @since 0.4.0
	 * @var self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Plugin-wide singleton convention.

	/**
	 * Returns the singleton instance.
	 *
	 * @since 0.4.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor — singleton (S6).
	 *
	 * @since 0.4.0
	 */
	private function __construct() {}

	/**
	 * The built-in method classes, in registration order.
	 *
	 * Feature 084 keeps all four classes exactly where they were and changes
	 * only their `priority()` slot and their membership: they are no longer in
	 * `ServerTabs\Registry::all_tabs()` (D48 — subtract UI usage, keep the
	 * extension surface).
	 *
	 * @since 0.4.0
	 * @return AbstractServerTab[]
	 */
	public function all_methods(): array {
		return array(
			// F040 placeholder — the acrossai-pro companion overrides this via
			// last-wins dedup (D41) when active.
			new AIConnectorsPromoTab(),
			new ClientsTab(),
			new NpmTab(),
			// Priority 40 is reserved for the companion's n8n method.
			new WpCliTab(),
		);
	}

	/**
	 * The ordered, validated, capability- and visibility-filtered method list.
	 *
	 * THE ONLY public read path. There is deliberately no unfiltered public
	 * accessor — see the class docblock and decision D53. Every consumer
	 * (`ConnectTab`'s navigation, its active-method resolution, and its
	 * dispatch) reads this one list, so no code path can select or render a
	 * method the current user may not see. Security constraint C2.
	 *
	 * Not memoized (constraint C8): the returned set embeds
	 * `current_user_can()` results, so a cache keyed on server id alone would
	 * serve one user's permitted set to another. `ServerTabs\Registry` does not
	 * memoize either.
	 *
	 * @since 0.4.0
	 * @param array<string, mixed> $server Server row data.
	 * @return AbstractServerTab[] Sorted ascending by `->priority()`.
	 */
	public function visible_methods( array $server ): array {
		return array_values(
			array_filter(
				$this->collect( $server ),
				static function ( AbstractServerTab $method ) use ( $server ): bool {
					return $method->visible_for( $server );
				}
			)
		);
	}

	/**
	 * Fires the filter, normalizes, dedups, filters by capability, hydrates
	 * and sorts.
	 *
	 * Sole call site of `apply_filters( self::FILTER_NAME, … )`.
	 *
	 * Private on purpose: exposing an unfiltered list publicly is the C2
	 * failure this class is shaped to prevent.
	 *
	 * @since 0.4.0
	 * @param array<string, mixed> $server Server row data.
	 * @return AbstractServerTab[] Sorted ascending by `->priority()`.
	 */
	private function collect( array $server ): array {
		$seeded = $this->builtin_entries();

		/**
		 * Filter the Connect tab's level-2 connection-method list.
		 *
		 * @since 0.4.0
		 * @param array<int, array<string, mixed>> $methods Normalized entry arrays. Built-ins are marked `_builtin => true`.
		 * @param array<string, mixed>             $server  Server row array (id, name, slug, registered_from, …).
		 */
		$raw = apply_filters( self::FILTER_NAME, $seeded, $server );

		if ( ! is_array( $raw ) ) {
			$raw = $seeded;
		}

		$normalized = RegistryEntryNormalizer::normalize( $raw, self::FILTER_NAME, '0.4.0' );

		// C2 — capability gate BEFORE hydration, so a method the current user
		// may not see never becomes an object, never reaches the navigation,
		// and can never be selected as the resolution fallback. Third-party
		// entries are gated again inside `FilteredServerTab::visible_for()`;
		// this pass additionally covers built-ins, whose concrete classes do
		// not re-check the entry capability themselves.
		$permitted = array_values(
			array_filter(
				$normalized,
				static function ( array $entry ): bool {
					return current_user_can( (string) ( $entry['capability'] ?? 'manage_options' ) );
				}
			)
		);

		$builtin_map = array();
		foreach ( $this->all_methods() as $method ) {
			$builtin_map[ $method->slug() ] = $method;
		}

		$hydrated = FilteredServerTab::hydrate_entries( $permitted, $builtin_map );

		usort(
			$hydrated,
			static function ( AbstractServerTab $a, AbstractServerTab $b ): int {
				return $a->priority() <=> $b->priority();
			}
		);

		return $hydrated;
	}

	/**
	 * Converts the built-in class list into the entry-array shape used by the
	 * filter. Marks each with `_builtin => true` so hydration routes them back
	 * to their concrete class instances instead of wrapping them in
	 * `FilteredServerTab`.
	 *
	 * @since 0.4.0
	 * @return array<int, array<string, mixed>>
	 */
	private function builtin_entries(): array {
		$entries = array();
		foreach ( $this->all_methods() as $method ) {
			$entries[] = array(
				'slug'             => $method->slug(),
				'label'            => $method->label(),
				'priority'         => $method->priority(),
				'capability'       => 'manage_options',
				'render_callback'  => null, // Built-ins render through their own class instance.
				'visible_callback' => null, // Built-ins gate through their own `visible_for()`.
				'_builtin'         => true,
			);
		}
		return $entries;
	}
}
