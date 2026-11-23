<?php
/**
 * Feature 117 — seed integration opt-ins that should start switched ON.
 *
 * {@see AcrossAI_Integration_Settings::is_enabled()} treats an absent entry as OFF, and its docblock
 * is explicit that the asymmetry is load-bearing — it is the containment for
 * BUG-SPARSE-STORAGE-UNIFORM-DEFAULT-ASSUMPTION, and that class is the only path that reads opt-in
 * state. Giving one integration a different default by teaching `is_enabled()` a per-slug default
 * would reintroduce exactly the mixed-default confusion that bug records.
 *
 * So a default-on integration is expressed as a stored `true` written once, rather than as an absent
 * entry that reads differently depending on the slug. Absent still means off; the seeded slug is
 * simply never absent.
 *
 * The seed is idempotent PER SLUG, and only fills slugs that have no stored value. That ordering is
 * what lets an administrator switch WPForms writing off and have it stay off: the slug then holds
 * `false`, which is a stored value, so a later run skips it.
 *
 * Per slug rather than per site, because the default-on set is no longer one hardcoded slug — it is
 * every integration the site can show, and that set grows whenever a plugin is activated. A single
 * "already run" flag would leave anything activated later absent, and absent reads as off with no
 * way to tell it from a deliberate opt-out.
 *
 * Which slugs seed ON is declared by the integration itself through
 * `acrossai_integration_seeds_enabled`. Two decline: Elementor and Jetpack, because switching those
 * on also switches on a third-party feature their own authors ship off.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Library
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds integration opt-ins whose default is ON, once per slug.
 *
 * @since 0.0.34
 */
final class AcrossAI_Integration_Default_Opt_Ins {

	/**
	 * Guard option. Presence means the seed has already run.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const DONE_OPTION = 'acrossai_integration_default_opt_ins_done';

	/**
	 * Integration slugs that start switched on.
	 *
	 * `wpforms` is on by default because it is already the site's behaviour: WPForms' own write gate
	 * lives in a `permission_callback` that the ability override processor replaces, so writes are
	 * permitted today whatever WPForms' switch says. Seeding `true` changes nothing that works; the
	 * value of the toggle is its OFF position, which currently exists nowhere. See
	 * {@see \AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\WPForms} and issue #210.
	 *
	 * `elementor` and `jetpack` are here rather than left to the discovery pass because of when each
	 * runs. Discovery seeds at `init` P1, while the opt-in base reads its toggle at `plugins_loaded`
	 * P20 — earlier — so an integration seeded only by discovery is correct from the *second*
	 * request of a site's life, not the first. For most integrations that is invisible: the toggle
	 * only decides whether we register our own abilities, and the gate treats an unseeded key as on.
	 * For these two it is not, because their toggle also flips the host plugin's own setting, and a
	 * site owner installing Abilities Manager would see 81 Elementor abilities on first load and 106
	 * on the next with nothing explaining the difference. Seeded here, they are right immediately.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	private const DEFAULT_ON = array( 'wpforms', 'elementor', 'jetpack' );

	/**
	 * Option recording which slugs the seed has already considered.
	 *
	 * Replaces a single "the seed has run" flag. That flag was correct while the default-on set was
	 * one hardcoded slug, but wrong the moment the set became every integration on the site: a
	 * plugin activated after the first request would never be considered, so its integration would
	 * sit absent — which reads as off — with no way to tell that from a deliberate opt-out.
	 *
	 * Recording per slug makes the seed idempotent per integration instead of per site. An operator
	 * switching something off still survives, because a stored `false` is a value and the seed only
	 * ever fills a hole.
	 *
	 * @since 0.0.42
	 * @var   string
	 */
	public const SEEDED_OPTION = 'acrossai_integration_seeded_slugs';

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Seed the default-on slugs, once.
	 *
	 * Hooked early enough to matter: the opt-in base attaches its enable filter at
	 * `plugins_loaded` P20, so a seed that ran later would leave the first request of the site's
	 * life with the integration reading as off.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function maybe_seed(): void {
		self::seed( self::DEFAULT_ON );
	}

	/**
	 * Seed the integrations discovered from the registry.
	 *
	 * A separate pass on a later hook, because neither registry can be read at `plugins_loaded` P0:
	 *
	 * - A host's `is_active()` probe is false that early. `class_exists( '\RankMath\Helper' )` does
	 *   not answer true until Rank Math has initialised, so discovery returns nothing.
	 * - `AcrossAI_Toolset_Integrations::all()` memoises on first call, and ACF and WPForms add
	 *   themselves to its filter from their own constructors — which run after P0. Reading it at P0
	 *   caches a set with those missing for the rest of the request.
	 *
	 * So the early pass stays on {@see self::DEFAULT_ON} alone, which needs neither registry, and
	 * this pass does the discovery once everything has loaded. Safe to run after the early one
	 * because seeding is idempotent per slug.
	 *
	 * @since  0.0.42
	 * @return void
	 */
	public static function maybe_seed_discovered(): void {
		self::seed( AcrossAI_Integration_Settings::candidate_slugs() );
	}

	/**
	 * Fill a stored `true` for any of the given slugs this site has not considered yet.
	 *
	 * @since  0.0.42
	 * @param  string[] $candidates Slugs to consider.
	 * @return void
	 */
	private static function seed( array $candidates ): void {
		$seeded = get_site_option( self::SEEDED_OPTION, array() );
		$seeded = is_array( $seeded ) ? $seeded : array();

		// First run on a site that predates per-slug tracking: the old flag means the original
		// hardcoded set was already considered, so record it rather than reconsidering it.
		if ( array() === $seeded && get_site_option( self::DONE_OPTION ) ) {
			$seeded = self::DEFAULT_ON;
		}

		$pending = array_values( array_diff( array_map( 'strval', $candidates ), $seeded ) );

		if ( array() === $pending ) {
			return;
		}

		$stored = get_site_option( AcrossAI_Integration_Settings::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$changed = false;

		foreach ( $pending as $slug ) {
			$seeded[] = $slug;

			// Only ever fills a HOLE. A slug already carrying false is a decision someone made, and
			// overwriting it would turn a deliberate opt-out back on behind their back.
			if ( array_key_exists( $slug, $stored ) ) {
				continue;
			}

			/**
			 * Filters whether an integration is seeded switched on.
			 *
			 * Consulted once per slug, the first time this site sees that integration, and never
			 * again — a stored value always wins afterwards. `AcrossAI_Integration_Ability_Base`
			 * publishes each subclass's `seeds_enabled()` here.
			 *
			 * @since 0.0.42
			 * @param bool   $seeds Whether to seed this integration on. Default true.
			 * @param string $slug  Integration slug.
			 */
			if ( ! (bool) apply_filters( 'acrossai_integration_seeds_enabled', true, $slug ) ) {
				continue;
			}

			$stored[ $slug ] = true;
			$changed         = true;
		}

		update_site_option( self::SEEDED_OPTION, array_values( array_unique( $seeded ) ) );

		if ( $changed ) {
			update_site_option( AcrossAI_Integration_Settings::OPTION_KEY, $stored );
		}
	}
}
