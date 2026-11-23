<?php
/**
 * Library Registry — collects definitions via the acrossai_abilities_api_init filter.
 *
 * Add-ons hook into acrossai_abilities_api_init at init priority 10 (before P99 collection).
 * Registry fires at init P99 to ensure all add-ons have registered first.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage includes/Modules/Library
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Library;

use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Key_Sanitizer;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Tab_Group_Label;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Collects and validates add-on ability definitions at init P99.
 *
 * @since 0.1.0
 */
class AcrossAI_Ability_Library_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Ability_Library_Registry|null
	 */
	protected static $instance = null;

	/**
	 * Cached definitions after collection.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static $definitions = null;

	/**
	 * Required top-level fields in each definition.
	 *
	 * @var string[]
	 */
	private const REQUIRED_FIELDS = array(
		'category',
		'category_label',
		'slug',
		'slug_label',
		'name',
		'args',
	);

	/**
	 * Allowlist of permitted keys in the definition's args array (SC-027-02).
	 *
	 * Feature 041 removed the top-level 'sub_group', 'sub_group_label',
	 * and 'tab_group' entries from this allowlist. Those Library display
	 * fields now live under $args['meta']['acrossai'] (which passes through
	 * the 'meta' allowlist entry). Hard cut — old top-level shape is
	 * silently dropped by the allowlist filter. See
	 * PATTERN-META-ACROSSAI-NAMESPACE + DEC-META-ACROSSAI-NAMESPACE.
	 *
	 * @var string[]
	 */
	private const ALLOWED_ARGS_FIELDS = array(
		'label',
		'description',
		'category',
		'execute_callback',
		'permission_callback',
		'input_schema',
		'output_schema',
		'meta',
	);

	/**
	 * Allowlist of optional top-level fields on a definition row (Feature 033).
	 *
	 * Optional fields are sanitized and copied to the validated row when
	 * present; absence is acceptable. Feature 037 added 'tab_group'.
	 *
	 * @var string[]
	 */
	private const OPTIONAL_FIELDS = array(
		'sub_group',
		'sub_group_label',
		'tab_group',
		'card_variant',
	);

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.1.0
	 * @return AcrossAI_Ability_Library_Registry
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Fire the acrossai_abilities_api_init filter and cache validated definitions.
	 *
	 * Idempotent: subsequent calls within the same request are no-ops.
	 * Wired at init P99 via includes/Main.php.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function collect(): void {
		if ( ! is_null( self::$definitions ) ) {
			return;
		}

		/**
		 * Filter: collect ability definitions from add-ons.
		 *
		 * Add-ons hook here at standard init priority (10) and push definition arrays
		 * onto the accumulator. Each definition must include category, category_label,
		 * slug, slug_label, name, and args. Unknown args keys are stripped.
		 * Note: the top-level 'category' field is the Library card grouping key; it is
		 * distinct from the 'category' key permitted inside args (the WordPress Abilities
		 * API ability category). These two fields exist at different array depths and are
		 * validated independently.
		 *
		 * Optional (Feature 033): a row MAY include 'sub_group' (display-only sub-heading
		 * inside the Specific panel; sanitized to a key form) and 'sub_group_label'
		 * (overrides the auto-derived label). Both are display-only and never appear in
		 * saved configuration.
		 *
		 * Optional (Feature 037): a row MAY include 'tab_group' (page-level group
		 * identifier; sanitized to a key form). Never appears in saved configuration.
		 * The tab's UI label is derived from the identifier on the React side; there is
		 * no paired 'tab_group_label' field.
		 *
		 * NOT display-only any more. Feature 037 declared it presentational; Feature 101
		 * made it the product's organising concept and Feature 100 derives the MCP tool
		 * catalogue from it, so retagging an ability moves it between tools. See
		 * DEC-ABILITY-GROUP-IDENTIFIER-LOAD-BEARING, and Test_Ability_Group_Map, which
		 * pins every ability's group so such a change cannot pass silently.
		 *
		 * @since 0.1.0
		 * @param array<int, array<string, mixed>> $definitions Accumulated definitions.
		 */
		$raw = apply_filters( 'acrossai_abilities_api_init', array() );

		self::$definitions = $this->validate_and_normalize( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Return cached definitions (call after collect()).
	 *
	 * Feature 088: at read time, decorate each definition's
	 * `args.meta.acrossai.suggested_plugins[]` list — either strip it entirely
	 * when the site-wide kill-switch option `acrossai_disable_plugin_suggestions`
	 * is on, or enrich each entry with an `is_active` boolean via
	 * `is_plugin_active( "{slug}/{slug}.php" )`. Decoration happens on a copy of
	 * the cached definitions so the collect() cache stays pristine.
	 *
	 * @since  0.1.0
	 * @return array<int, array<string, mixed>>
	 */
	public function get_definitions(): array {
		$definitions = self::apply_suggested_plugins_decoration( self::$definitions ?? array() );
		$definitions = self::apply_suggested_abilities_decoration( $definitions );
		return $definitions;
	}

	/**
	 * Publish the validated definition rows as a filter value.
	 *
	 * The module-facing accessor for the full definition set, mirroring
	 * {@see self::get_tab_group_summary()}. Consumers outside the Library module MUST read the
	 * filter rather than calling this class: Constitution Module Contract #3 forbids
	 * sibling-module reach-through, and #4 designates actions and filters as the sanctioned
	 * integration point.
	 *
	 * The tab-group summary is not sufficient for every consumer — the gate translation
	 * (`AcrossAI_Library_Gate_Migration`) needs the whole row (`category`, `slug`, `name`,
	 * `card_variant`) to decide what a retired configuration blocked, so it reads this instead.
	 *
	 * Registered in `includes/Main.php` as the provider for
	 * `acrossai_ability_library_definitions`. Consumers call
	 * `apply_filters( 'acrossai_ability_library_definitions', array() )` and never reference this
	 * class, which is what keeps the dependency one-directional.
	 *
	 * @since  0.0.34
	 * @param  mixed $definitions Incoming filter value; ignored, this is the provider.
	 * @return array<int, array<string, mixed>> Validated definition rows.
	 */
	public function provide_definitions( $definitions = array() ): array {
		unset( $definitions );

		return $this->get_definitions();
	}

	/**
	 * Summarise registered abilities by tab group.
	 *
	 * Published as the module-facing accessor for catalogue summaries. Consumers
	 * outside this module MUST read the filter rather than calling this class
	 * directly — Constitution Module Contract #3 forbids sibling-module
	 * reach-through, and #4 designates actions and filters as the sanctioned
	 * integration point.
	 *
	 * Returns compact rows (~17 of them) instead of the full definition set
	 * (~440 rows), so consumers are decoupled from the definition shape as well
	 * as from this module. Labels come from the shared
	 * {@see AcrossAI_Tab_Group_Label} rule so server-side consumers agree with
	 * the Integrations page's own JS derivation
	 * (PATTERN-ABILITY-LIBRARY-TAB-AUTO-DERIVE).
	 *
	 * Definitions with no `tab_group` are skipped: they are not surfaced as tabs
	 * on the Integrations page either.
	 *
	 * Registered in `includes/Main.php` as the provider for the
	 * `acrossai_ability_library_tab_group_summary` filter. Consumers call
	 * `apply_filters( 'acrossai_ability_library_tab_group_summary', array() )`
	 * and never reference this class, which is what keeps the dependency
	 * one-directional.
	 *
	 * @since  0.0.34
	 * @param  mixed $summary Incoming filter value; ignored, this is the provider.
	 * @return array<int, array{key: string, label: string, count: int}> Sorted by
	 *         count descending, then key ascending, for stable rendering.
	 */
	public function get_tab_group_summary( $summary = array() ): array {
		unset( $summary );

		$counts = array();

		foreach ( $this->get_definitions() as $definition ) {
			$key = isset( $definition['tab_group'] ) ? (string) $definition['tab_group'] : '';

			if ( '' === $key ) {
				continue;
			}

			$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
		}

		$summary = array();

		foreach ( $counts as $key => $count ) {
			$summary[] = array(
				'key'   => $key,
				'label' => AcrossAI_Tab_Group_Label::format( $key ),
				'count' => (int) $count,
			);
		}

		usort(
			$summary,
			static function ( array $a, array $b ): int {
				if ( $a['count'] === $b['count'] ) {
					return strcmp( $a['key'], $b['key'] );
				}

				return $b['count'] <=> $a['count'];
			}
		);

		// No apply_filters() here: this method IS the filter provider. Firing the
		// same hook from inside its own callback would recurse infinitely.
		return $summary;
	}

	/**
	 * Feature 088: decorate the suggested_plugins list on every definition row.
	 *
	 * Two responsibilities in strict order:
	 *   1. If the site option `acrossai_disable_plugin_suggestions` is truthy,
	 *      strip the field from every row's args.meta.acrossai and return early.
	 *   2. Otherwise, for each row's args.meta.acrossai.suggested_plugins[],
	 *      attach `is_active` computed via `is_plugin_active()` per entry.
	 *
	 * @param array<int, array<string, mixed>> $definitions Cached rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function apply_suggested_plugins_decoration( array $definitions ): array {
		$kill_switch = (bool) get_option( 'acrossai_disable_plugin_suggestions', false );

		foreach ( $definitions as &$row ) {
			if ( ! isset( $row['args']['meta']['acrossai']['suggested_plugins'] ) ) {
				continue;
			}
			$list = $row['args']['meta']['acrossai']['suggested_plugins'];
			if ( ! is_array( $list ) ) {
				unset( $row['args']['meta']['acrossai']['suggested_plugins'] );
				continue;
			}
			if ( $kill_switch ) {
				unset( $row['args']['meta']['acrossai']['suggested_plugins'] );
				continue;
			}

			$decorated = array();
			foreach ( $list as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$slug   = isset( $entry['slug'] ) ? (string) $entry['slug'] : '';
				$name   = isset( $entry['name'] ) ? (string) $entry['name'] : '';
				$reason = isset( $entry['reason'] ) ? (string) $entry['reason'] : '';
				if ( '' === $slug || '' === $name || '' === $reason ) {
					// Malformed entry — drop silently (FR-009).
					continue;
				}
				$entry['plugin_provides_abilities']     = ! empty( $entry['plugin_provides_abilities'] );
				$entry['acrossai_provides_integration'] = ! empty( $entry['acrossai_provides_integration'] );
				$entry['is_active']                     = function_exists( 'is_plugin_active' )
					&& is_plugin_active( $slug . '/' . $slug . '.php' );
				$decorated[]                            = $entry;
			}

			if ( empty( $decorated ) ) {
				unset( $row['args']['meta']['acrossai']['suggested_plugins'] );
			} else {
				$row['args']['meta']['acrossai']['suggested_plugins'] = $decorated;
			}
		}
		unset( $row );

		return $definitions;
	}

	/**
	 * Feature 095: strip suggested_abilities from every row's payload when the
	 * kill-switch option is on.
	 *
	 * Behaviour:
	 *   - When `acrossai_disable_ability_suggestions` is truthy, every row's
	 *     `args.meta.acrossai.suggested_abilities` key is unset. Other
	 *     `meta.acrossai.*` keys (including Feature 088's `suggested_plugins`)
	 *     are untouched.
	 *   - When the option is unset or `0`, rows pass through unchanged —
	 *     the ability's declared entries surface verbatim in the response.
	 *
	 * Public + static so unit tests can call it against seeded rows without
	 * standing up the whole Registry state (Feature 088's equivalent is
	 * private because it enriches with runtime is_plugin_active data — this
	 * one is a pure filter).
	 *
	 * @since  0.0.34
	 * @param  array<int, array<string, mixed>> $definitions Cached rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function apply_suggested_abilities_decoration( array $definitions ): array {
		$kill_switch = (bool) get_option( 'acrossai_disable_ability_suggestions', 0 );

		if ( $kill_switch ) {
			foreach ( $definitions as &$row ) {
				if ( isset( $row['args']['meta']['acrossai']['suggested_abilities'] ) ) {
					unset( $row['args']['meta']['acrossai']['suggested_abilities'] );
				}
			}
			unset( $row );

			return $definitions;
		}

		return self::prune_unregistered_suggestions( $definitions );
	}

	/**
	 * Feature 128: drop suggestions that asked to be shown only when their target exists.
	 *
	 * Why it happens HERE and not in the ability's own `suggested_abilities()`: that method runs
	 * inside `push_definition()`, while definitions are still being COLLECTED.
	 * `AcrossAI_Ability_Library_Processor::register_abilities()` only calls
	 * `wp_register_ability()` afterwards, in a second loop -- so at `suggested_abilities()` time
	 * nothing is registered yet and `wp_get_ability()` returns null for everything, including
	 * abilities this plugin is about to register itself. `WP_Ability::$meta` is protected with
	 * getters only, so the meta cannot be amended after registration either. This pass is the one
	 * place that sees the complete set before any of it is registered.
	 *
	 * Opt-in per entry, via `'only_if_registered' => true`. Entries without the flag are left
	 * exactly as declared, which preserves the documented contract that a suggestion may point at
	 * an ability the caller's site does not have -- third-party ability plugins rely on that.
	 *
	 * The flag itself is stripped from the surviving entry: it is an instruction to this pass, not
	 * something a caller should have to read.
	 *
	 * @since  0.0.36
	 * @param  array<int, array<string, mixed>> $definitions Cached rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function prune_unregistered_suggestions( array $definitions ): array {
		$known = array();

		foreach ( $definitions as $row ) {
			if ( isset( $row['name'] ) && is_string( $row['name'] ) ) {
				$known[ $row['name'] ] = true;
			}
		}

		foreach ( $definitions as &$row ) {
			if ( empty( $row['args']['meta']['acrossai']['suggested_abilities'] ) || ! is_array( $row['args']['meta']['acrossai']['suggested_abilities'] ) ) {
				continue;
			}

			$kept = array();

			foreach ( $row['args']['meta']['acrossai']['suggested_abilities'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$conditional = ! empty( $entry['only_if_registered'] );
				unset( $entry['only_if_registered'] );

				if ( $conditional && ! isset( $known[ (string) ( $entry['slug'] ?? '' ) ] ) ) {
					continue;
				}

				$kept[] = $entry;
			}

			if ( empty( $kept ) ) {
				unset( $row['args']['meta']['acrossai']['suggested_abilities'] );
			} else {
				$row['args']['meta']['acrossai']['suggested_abilities'] = array_values( $kept );
			}
		}
		unset( $row );

		return $definitions;
	}

	/**
	 * Category slugs of every currently registered third-party integration.
	 *
	 * Reads the cached definitions and returns the unique category slugs of
	 * rows whose card_variant is 'integration'. Empty when no integrations
	 * are registered (or when their subclasses' is_plugin_active() returned
	 * false for the current request). Callers use this to distinguish
	 * integration categories — which invert the sparse-storage default per
	 * FR-008 — from regular library categories.
	 *
	 * Note: these slugs are purely internal to the Library UI (Registry +
	 * Config layers). They are intentionally NEVER registered as WP ability
	 * categories — the base class's `push_definition()` docblock explains
	 * why. Do not use this list to derive `wp_register_ability_category()`
	 * calls without first replacing the synthetic rows' fail-closed callbacks.
	 *
	 * @see AcrossAI_Abilities_Manager\Includes\Modules\Library\Integrations\AcrossAI_Integration_Ability_Base::push_definition() Design contract note.
	 *
	 * @since  0.1.0
	 * @return string[]
	 */
	public function get_integration_slugs(): array {
		$slugs = array();
		foreach ( self::$definitions ?? array() as $def ) {
			if ( isset( $def['card_variant'], $def['category'] ) && 'integration' === $def['card_variant'] ) {
				$slugs[ (string) $def['category'] ] = true;
			}
		}
		return array_keys( $slugs );
	}

	/**
	 * Validate and normalize raw filter output.
	 *
	 * Each entry must pass required-field checks. The args sub-array is filtered
	 * to ALLOWED_ARGS_FIELDS only (SC-027-02). Entries that fail required-field
	 * validation are logged (WP_DEBUG_LOG) and skipped.
	 *
	 * @since  0.1.0
	 * @param  array<int, mixed> $raw Raw filter output.
	 * @return array<int, array<string, mixed>>
	 */
	private function validate_and_normalize( array $raw ): array {
		$valid = array();

		foreach ( $raw as $index => $item ) {
			if ( ! is_array( $item ) ) {
				$this->log_invalid( $index, 'definition is not an array' );
				continue;
			}

			foreach ( self::REQUIRED_FIELDS as $field ) {
				if ( ! isset( $item[ $field ] ) || '' === $item[ $field ] ) {
					$this->log_invalid( $index, "missing or empty required field: {$field}" );
					continue 2;
				}
			}

			if ( ! is_array( $item['args'] ) ) {
				$this->log_invalid( $index, "'args' must be an array" );
				continue;
			}

			// Strip any args keys not in the allowlist (SC-027-02).
			$item['args'] = array_intersect_key(
				$item['args'],
				array_flip( self::ALLOWED_ARGS_FIELDS )
			);

			$category = AcrossAI_Key_Sanitizer::key( (string) $item['category'] );
			$slug     = AcrossAI_Key_Sanitizer::key( (string) $item['slug'] );
			// Preserve the namespace/name slash: sanitize_key() strips '/', corrupting names like 'plugin/ability'.
			$name = preg_replace( '/[^a-z0-9_\-\/]/', '', strtolower( (string) $item['name'] ) );

			if ( '' === $category || '' === $slug || '' === $name ) {
				$this->log_invalid( $index, 'category, slug, or name became empty after sanitization' );
				continue;
			}

			$entry = array(
				'category'       => $category,
				'category_label' => wp_kses_post( (string) $item['category_label'] ),
				'slug'           => $slug,
				'slug_label'     => wp_kses_post( (string) $item['slug_label'] ),
				'name'           => $name,
				'args'           => $item['args'],
			);

			// Feature 033 — optional sub_group / sub_group_label pass-through.
			// Display-only; never written to saved configuration.
			if ( isset( $item['sub_group'] ) && '' !== $item['sub_group'] ) {
				$clean_sub = self::sanitize_sub_group( (string) $item['sub_group'] );
				if ( '' !== $clean_sub ) {
					$entry['sub_group'] = $clean_sub;
					// sanitize_text_field(), not wp_kses_post(): the label reaches the
					// client as JSON and is rendered as a React text node, so it is
					// never parsed as HTML. wp_kses_post() entity-encodes a bare
					// ampersand, which then shows up literally as "Modules &amp;
					// Roles" wherever the label is displayed.
					$entry['sub_group_label'] = isset( $item['sub_group_label'] ) && '' !== $item['sub_group_label']
						? sanitize_text_field( (string) $item['sub_group_label'] )
						: ucwords( str_replace( '-', ' ', $clean_sub ) );
				}
			}

			// Feature 037 — optional tab_group pass-through.
			// Never written to saved configuration, but NOT display-only: the
			// MCP tool catalogue is derived from this value (Feature 100), so
			// changing it moves an ability between tools. See
			// DEC-ABILITY-GROUP-IDENTIFIER-LOAD-BEARING. Closes the
			// PATTERN-LIBRARY-ARGS-RAW-PASSTHROUGH gap for this field by
			// sanitizing at the Registry boundary via AcrossAI_Key_Sanitizer::key().
			if ( isset( $item['tab_group'] ) && '' !== $item['tab_group'] ) {
				$clean_tab = AcrossAI_Key_Sanitizer::key( (string) $item['tab_group'] );
				if ( '' !== $clean_tab ) {
					$entry['tab_group'] = $clean_tab;
				}
			}

			// Feature 060 — optional card_variant pass-through.
			// Display-only; never written to saved configuration. Sanitized at
			// the Registry boundary via AcrossAI_Key_Sanitizer::key() so the JS
			// receives a predictable key shape (e.g. 'integration'). Mirrors
			// the tab_group treatment above.
			if ( isset( $item['card_variant'] ) && '' !== $item['card_variant'] ) {
				$clean_variant = AcrossAI_Key_Sanitizer::key( (string) $item['card_variant'] );
				if ( '' !== $clean_variant ) {
					$entry['card_variant'] = $clean_variant;
				}
			}

			$valid[] = $entry;
		}

		return $valid;
	}

	/**
	 * The sub-group identifier sanitizer (Feature 033).
	 *
	 * Reuses the existing 100-char + sanitize_key() rule already proven on
	 * category/slug fields, so add-on authors face a single, consistent
	 * key-shape contract. Display-only — never persisted.
	 *
	 * @since  0.1.0
	 * @param  string $raw Raw sub-group identifier from a definition row.
	 * @return string Sanitized key; may be empty if input cannot be sanitized.
	 */
	private static function sanitize_sub_group( string $raw ): string {
		return AcrossAI_Key_Sanitizer::key( $raw );
	}

	/**
	 * Write a debug log entry for an invalid definition.
	 *
	 * @since  0.1.0
	 * @param  int|string $index Definition index.
	 * @param  string     $reason Reason for rejection.
	 * @return void
	 */
	private function log_invalid( $index, string $reason ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "AcrossAI Library Registry: skipping definition[{$index}] — {$reason}" );
		}
	}
}
