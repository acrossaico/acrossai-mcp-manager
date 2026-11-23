<?php
/**
 * Abstract base class for ability definitions.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage includes/Modules/Library
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for ability definitions.
 *
 * Subclasses implement one abstract method (ability()) — the grouping fields
 * (category, slug, labels) are derived from it automatically.
 * The constructor hooks acrossai_abilities_api_init automatically.
 *
 * Feature 041: plugin-specific display fields live under
 * $args['meta']['acrossai']. Sibling of $args['meta']['mcp'] and
 * $args['meta']['annotations']. See PATTERN-META-ACROSSAI-NAMESPACE.
 *
 * Optional: $args['meta']['acrossai']['sub_group'] adds a display-only
 * sub-heading inside the Library Specific panel. Does NOT affect saved
 * config or execution.
 *
 * Optional: $args['meta']['acrossai']['sub_group_label'] overrides the
 * auto-derived ucwords(str_replace('-', ' ', sub_group)) label.
 *
 * Optional: $args['meta']['acrossai']['tab_group'] groups the ability
 * under a page-level tab on the Library admin page. Display-only — never
 * persisted, never affects execution or REST. Sanitized at the Registry
 * boundary.
 */
abstract class Ability_Definition {

	/**
	 * Register the push_definition filter callback.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		add_filter( 'acrossai_abilities_api_init', array( $this, 'push_definition' ) );
	}

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * Must return an array with:
	 *   - 'name'  (string) the unique ability name, e.g. 'plugin-slug/ability-slug'
	 *   - 'args'  (array)  the args passed to wp_register_ability:
	 *                      label, description, category, execute_callback,
	 *                      permission_callback, input_schema, output_schema, meta
	 *
	 * Display fields are derived from this return value:
	 *   - Grouping key:  args['category']
	 *   - Per-row label: args['label']
	 *   - Unique slug:   name
	 */
	abstract protected function ability(): array;

	/**
	 * Return external WordPress plugins the site admin may consider as
	 * alternatives or specialists for this ability's scope.
	 *
	 * Optional; default is an empty list. Override in subclasses that want to
	 * surface plugin suggestions on their Library card and in agent discovery.
	 *
	 * Each entry SHOULD include:
	 *   - 'slug'   (string, required)  wordpress.org plugin slug
	 *   - 'name'   (string, required)  display name
	 *   - 'reason' (string, required)  one-line "why this plugin for this ability"
	 *
	 * And MAY include:
	 *   - 'url'                           (string) override link target; defaults
	 *                                     to https://wordpress.org/plugins/{slug}/
	 *   - 'covers'                        (string) short scope summary
	 *   - 'plugin_provides_abilities'     (bool)   does the suggested plugin ship
	 *                                     its own wp_register_ability() abilities?
	 *   - 'acrossai_provides_integration' (bool)   does our plugin ship an
	 *                                     AcrossAI_Integration_Ability_Base for it?
	 *
	 * Feature 088. See specs/088-ability-suggested-plugins-framework/ for the
	 * full data model and behavioural contracts. Author-curated at declaration
	 * time; runtime install-status enrichment happens in the Registry.
	 *
	 * @since 0.0.33
	 * @return array<int,array<string,scalar>>
	 */
	protected function suggested_plugins(): array {
		return array();
	}

	/**
	 * Return other abilities an AI caller might use instead of this one.
	 *
	 * Optional; default is an empty list. Override in subclasses that want to
	 * hint AI callers at cheaper alternatives — e.g. `content/update-page`
	 * pointing at `blocks/outline-post-blocks` + `blocks/update-post-block`
	 * for narrow edits that don't need a full-body rewrite.
	 *
	 * Each entry SHOULD include:
	 *   - 'slug'   (string, required) another ability's registered name
	 *   - 'reason' (string, required) one-line hint the AI reads
	 *
	 * And MAY include:
	 *   - 'saves'  (string) free-form savings hint, e.g.
	 *              "~29K tokens vs full page rewrite on a 97 KB page".
	 *
	 * The framework does NOT validate that the slug points at a registered
	 * ability (third-party ability plugins ship suggestions pointing at
	 * abilities their users may not have installed — validation would break
	 * that pattern). It also does NOT enforce field-level shape; ability
	 * tests should guard entries with empty slug or reason.
	 *
	 * Feature 095. Mirror of `suggested_plugins()` (Feature 088). Advisory
	 * only — no ability's execution depends on which suggestion the AI does
	 * or does not take.
	 *
	 * @since 0.0.34
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array();
	}

	/**
	 * Every ability must say whether it reads, destroys, and can be repeated.
	 *
	 * Feature 128. An AI caller uses these three to decide whether an ability is safe to try, safe
	 * to retry, and safe to run without asking. A null is not "unknown" to a caller -- it reads as
	 * "not destructive", which is the dangerous direction to be wrong in. Measured live:
	 * `mailerpress/list-campaigns` reported null for all three while its own description said
	 * "Read-only".
	 *
	 * Deliberately advisory at runtime. This runs for every ability on every request, so it must
	 * not throw and must cost nothing in production: the whole check is skipped unless WP_DEBUG is
	 * on. The test suite enforces it properly by iterating the full collected set.
	 *
	 * @since  0.0.36
	 * @param  string               $name Ability name, for the message.
	 * @param  array<string, mixed> $args Ability args.
	 * @return void
	 */
	private static function assert_annotations( string $name, array $args ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$missing = self::missing_annotations( $args );

		if ( empty( $missing ) ) {
			return;
		}

		_doing_it_wrong(
			__METHOD__,
			sprintf(
				/* translators: 1: ability name, 2: comma-separated annotation keys. */
				esc_html__( 'Ability "%1$s" does not declare %2$s. An AI caller reads a missing annotation as "not destructive", so every ability must set readonly, destructive and idempotent explicitly.', 'acrossai-abilities-manager' ),
				esc_html( $name ),
				esc_html( implode( ', ', $missing ) )
			),
			'0.0.36'
		);
	}

	/**
	 * Which of the three required annotations are missing or null.
	 *
	 * Public + static so the test suite can iterate the collected definitions without standing up
	 * a definition object per ability.
	 *
	 * @since  0.0.36
	 * @param  array<string, mixed> $args Ability args.
	 * @return string[] Missing keys, empty when all three are present.
	 */
	public static function missing_annotations( array $args ): array {
		$annotations = ( isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] ) )
			? $args['meta']['annotations']
			: array();

		$missing = array();

		foreach ( array( 'readonly', 'destructive', 'idempotent' ) as $key ) {
			// array_key_exists, not isset: a key present with a null value is exactly the case
			// this guard exists to catch, and isset() would call it absent either way but
			// array_key_exists keeps the distinction honest if the message ever needs it.
			if ( ! array_key_exists( $key, $annotations ) || null === $annotations[ $key ] ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Filter callback — wired automatically by the constructor.
	 *
	 * Derives Library grouping fields from ability() so subclasses only need
	 * to implement the single ability() method.
	 *
	 * @param array $definitions Existing definitions collected so far.
	 * @return array
	 */
	public function push_definition( array $definitions ): array {
		$spec = $this->ability();
		$name = $spec['name'] ?? '';
		$args = $spec['args'] ?? array();

		self::assert_annotations( (string) $name, $args );

		// Feature 088: auto-inject subclass-declared suggested plugins into
		// meta.acrossai.suggested_plugins. Only writes when the subclass
		// override returned a non-empty array; abilities that do not override
		// suggested_plugins() see zero payload change.
		$suggested = $this->suggested_plugins();
		if ( ! empty( $suggested ) && is_array( $suggested ) ) {
			if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
				$args['meta'] = array();
			}
			if ( ! isset( $args['meta']['acrossai'] ) || ! is_array( $args['meta']['acrossai'] ) ) {
				$args['meta']['acrossai'] = array();
			}
			$args['meta']['acrossai']['suggested_plugins'] = array_values( $suggested );
		}

		// Feature 095: auto-inject subclass-declared suggested abilities into
		// meta.acrossai.suggested_abilities. Same silent-default behaviour —
		// empty override yields byte-identical payload to pre-Feature-095.
		$suggested_abilities = $this->suggested_abilities();
		if ( ! empty( $suggested_abilities ) && is_array( $suggested_abilities ) ) {
			if ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) {
				$args['meta'] = array();
			}
			if ( ! isset( $args['meta']['acrossai'] ) || ! is_array( $args['meta']['acrossai'] ) ) {
				$args['meta']['acrossai'] = array();
			}
			$args['meta']['acrossai']['suggested_abilities'] = array_values( $suggested_abilities );
		}

		$category = $args['category'] ?? '';

		// Feature 041: plugin-specific Library display fields live under
		// $args['meta']['acrossai']. Hard cut — top-level $args['sub_group']
		// / $args['tab_group'] / $args['sub_group_label'] no longer read.
		// See PATTERN-META-ACROSSAI-NAMESPACE.
		$meta_acrossai = ( isset( $args['meta']['acrossai'] ) && is_array( $args['meta']['acrossai'] ) )
			? $args['meta']['acrossai']
			: array();

		$sub_group = isset( $meta_acrossai['sub_group'] ) ? (string) $meta_acrossai['sub_group'] : '';
		$tab_group = isset( $meta_acrossai['tab_group'] ) ? (string) $meta_acrossai['tab_group'] : '';

		$row = array(
			'category'       => $category,
			'category_label' => ucwords( str_replace( '-', ' ', $category ) ),
			'slug'           => $name,
			'slug_label'     => $args['label'] ?? $name,
			'name'           => $name,
			'args'           => $args,
		);

		if ( '' !== $sub_group ) {
			$row['sub_group']       = $sub_group;
			$row['sub_group_label'] = isset( $meta_acrossai['sub_group_label'] ) && '' !== $meta_acrossai['sub_group_label']
				? (string) $meta_acrossai['sub_group_label']
				: ucwords( str_replace( '-', ' ', $sub_group ) );
		}

		if ( '' !== $tab_group ) {
			$row['tab_group'] = $tab_group;
		}

		$definitions[] = $row;

		return $definitions;
	}
}
