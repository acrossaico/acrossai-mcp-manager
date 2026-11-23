<?php
/**
 * Third-party integration opt-ins.
 *
 * An integration opt-in is not an access setting and is not interchangeable with one. It asks a
 * third-party plugin to register its abilities in the first place — `ACF::enable_filter()` is
 * `add_filter( 'acf/settings/enable_acf_ai', '__return_true' )` — so without it there is nothing for
 * the abilities table to allow or block. That is why Feature 102 removed the per-category
 * registration gate but kept this, and moved it to the settings screen rather than deleting it with
 * the Integrations page.
 *
 * **The default is off.** An absent entry means disabled, which is the opposite of the retired
 * category config, where an absent entry meant permitted. That asymmetry is the reason
 * BUG-SPARSE-STORAGE-UNIFORM-DEFAULT-ASSUMPTION exists, and it is contained here: this class is the
 * only PHP code path that reads integration opt-in state.
 *
 * An integration that should start switched ON does not change this rule. It is seeded with a stored
 * `true` by {@see AcrossAI_Integration_Default_Opt_Ins} instead, so absent still means off and the
 * slug is simply never absent — see that class for why a per-slug default here was rejected.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Library
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Modules\Library;

use AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\AcrossAI_Toolset_Integrations;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Group;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and describes third-party integration opt-ins.
 *
 * **Deliberately static, not a singleton.** Constitution Module Contract #1/#2 require the
 * `instance()` pattern of every *feature* class, so that dependencies are reachable without
 * constructor injection. This holds no state and has no dependencies to reach: it reads one option
 * and one filter. A singleton would add a lifecycle to something that has none, and the shape it
 * replaces (`AcrossAI_Ability_Library_Config`) was static for the same reason.
 *
 * It stays in the Library module rather than moving to `includes/Utilities/` because `discover()`
 * reads the Library definition registry — relocating it would invert the dependency and put a
 * module reference inside Utilities, which is worse than the deviation it would cure.
 *
 * Sealed with a private constructor so the static intent is enforced rather than merely implied.
 * Recorded as an accepted deviation in `specs/102-abilities-toolset-tabs/plan.md`.
 */
class AcrossAI_Integration_Settings {

	/**
	 * Option holding the opt-in map, keyed by integration slug.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'acrossai_integrations';

	/**
	 * Not instantiable — every member is static.
	 *
	 * @since 0.0.34
	 */
	private function __construct() {}

	/**
	 * Whether the given integration is switched on.
	 *
	 * Absent means off. Callers must not re-implement this test — the inverted default is the whole
	 * reason this helper exists.
	 *
	 * @since  0.0.34
	 * @param  string $slug Integration slug.
	 * @return bool
	 */
	public static function is_enabled( string $slug ): bool {
		$stored = get_site_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) || ! array_key_exists( $slug, $stored ) ) {
			return false;
		}

		return (bool) $stored[ $slug ];
	}

	/**
	 * Whether an integration has been deliberately switched off.
	 *
	 * The inverse of `is_enabled()` in result but not in meaning, and the difference is the entire
	 * reason this exists. `is_enabled()` answers "is this switched on", and reads an absent key as
	 * no — correct for a control that must not act until it has been told to. This answers "has
	 * someone turned this off", and reads an absent key as no as well, which for a default-on
	 * integration means yes-it-runs.
	 *
	 * That distinction only matters because of *when* the registration gate asks. Default-on is
	 * expressed as a seeded stored `true` (Feature 117), and the seeding pass that writes it runs at
	 * `init` P1 — while `wp_abilities_api_init` fires lazily, on whatever first touches the
	 * registry, which can be earlier. A gate built on `is_enabled()` would therefore read "absent,
	 * so off" on a fresh site and register no integration abilities at all, silently, with the
	 * option filling in correctly a moment later. Asking for an explicit `false` cannot fail that
	 * way: a stored `false` is a decision somebody made, and an unseeded key is not.
	 *
	 * Use this for registration. Use `is_enabled()` for everything else.
	 *
	 * @since  0.0.42
	 * @param  string $slug Integration slug.
	 * @return bool True only when a `false` is stored for this slug.
	 */
	public static function is_switched_off( string $slug ): bool {
		$stored = get_site_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) || ! array_key_exists( $slug, $stored ) ) {
			return false;
		}

		return ! (bool) $stored[ $slug ];
	}

	/**
	 * Integrations available on this site.
	 *
	 * Derived from the definitions registry rather than from the integration classes: their
	 * slug/label/abilities accessors are `protected`, and widening them purely to build a settings
	 * screen would be a worse trade than reading data that already exists. Synthetic integration
	 * rows are only pushed when the target plugin is active, so an integration whose plugin is
	 * missing does not appear here at all — which is the behaviour the retired page had.
	 *
	 * @since  0.0.34
	 * @return array<string, array{label: string, count: int}> Keyed by integration slug.
	 */
	public static function discover(): array {
		$found = self::from_toolset_integrations();

		foreach ( AcrossAI_Ability_Library_Registry::instance()->get_definitions() as $definition ) {
			$variant = isset( $definition['card_variant'] ) ? (string) $definition['card_variant'] : '';

			if ( 'integration' !== $variant ) {
				continue;
			}

			$slug = isset( $definition['category'] ) ? (string) $definition['category'] : '';

			if ( '' === $slug ) {
				continue;
			}

			if ( ! isset( $found[ $slug ] ) ) {
				$label = isset( $definition['category_label'] ) ? (string) $definition['category_label'] : $slug;

				$found[ $slug ] = array(
					'label' => $label,
					'count' => 0,
				);
			}

			++$found[ $slug ]['count'];
		}

		ksort( $found );

		return $found;
	}

	/**
	 * Integration slugs this site could show a row for, without resolving ability counts.
	 *
	 * Deliberately separate from {@see self::discover()}, which counts. Counting resolves
	 * `AcrossAI_Ability_Group`, and that memoises for the rest of the request — so asking for counts
	 * before `wp_abilities_api_init` has fired caches an empty group map and takes the admin tab
	 * strip's counts down with it. {@see AcrossAI_Integration_Default_Opt_Ins} runs at
	 * `plugins_loaded` P0, long before any ability exists, and needs slugs only.
	 *
	 * Descriptors are safe to ask that early: the registry reads a filter and each integration's own
	 * `is_active()` probe, neither of which touches the ability registry.
	 *
	 * @since  0.0.42
	 * @return string[]
	 */
	public static function candidate_slugs(): array {
		$slugs = array();

		foreach ( AcrossAI_Toolset_Integrations::all() as $group => $integration ) {
			if ( AcrossAI_Toolset_Integrations::CATCH_ALL_GROUP === $group || ! $integration->is_active() ) {
				continue;
			}

			$slugs[] = (string) $group;
		}

		return $slugs;
	}

	/**
	 * Rows for every integration whose plugin is active, from the toolset registry.
	 *
	 * The Library definitions this method supplements only describe opt-in integrations — the three
	 * or four hosts that keep their own abilities switched off until asked. Every other integration
	 * is ours and already registered, so it pushed no `card_variant => 'integration'` row and never
	 * appeared on the screen. That is why the tab listed three of twenty-odd.
	 *
	 * Counts come from {@see AcrossAI_Ability_Group::counts()}, which resolves once per request and
	 * is the same source the admin tab strip counts from, so the two cannot disagree. A group with no
	 * registered abilities is skipped: an integration whose plugin is active but which contributes
	 * nothing has nothing to switch.
	 *
	 * @since  0.0.42
	 * @return array<string, array{label: string, count: int}>
	 */
	private static function from_toolset_integrations(): array {
		$rows   = array();
		$counts = AcrossAI_Ability_Group::counts();

		foreach ( AcrossAI_Toolset_Integrations::all() as $group => $integration ) {
			if ( AcrossAI_Toolset_Integrations::CATCH_ALL_GROUP === $group ) {
				continue;
			}

			if ( ! $integration->is_active() ) {
				continue;
			}

			$count = isset( $counts[ $group ] ) ? (int) $counts[ $group ] : 0;

			if ( 0 === $count ) {
				continue;
			}

			$label = $integration->toolset_label();

			$rows[ $group ] = array(
				'label' => '' !== $label ? $label : $group,
				'count' => $count,
			);
		}

		return $rows;
	}

	/**
	 * Capability required to change a given integration's opt-in.
	 *
	 * A SINGLE filtered `current_user_can()` behind a surface already gated at `manage_options` is
	 * inherently raise-only: a filter can demand more, never less, because the outer gate still
	 * applies (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY). Splitting this into two checks, or making
	 * it the only gate, would let a filter returning a weaker capability lower the requirement.
	 *
	 * @since  0.0.34
	 * @param  string $slug Integration slug.
	 * @return string Capability name.
	 */
	public static function required_capability( string $slug ): string {
		/**
		 * Filters the capability required to toggle a third-party integration.
		 *
		 * Raise-only in practice — see the note above. Returning a weaker capability does not grant
		 * access, because the settings page itself is registered at `manage_options`.
		 *
		 * @since 0.1.0
		 * @param string $capability Default 'manage_options'.
		 * @param string $slug       Integration slug.
		 */
		return (string) apply_filters( 'acrossai_integration_toggle_capability', 'manage_options', $slug );
	}
}
