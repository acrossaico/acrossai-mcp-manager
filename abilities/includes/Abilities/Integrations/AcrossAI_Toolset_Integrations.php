<?php
/**
 * The set of toolset integrations known to this site.
 *
 * One place to ask "which integrations exist", consumed by the two things that need to agree: the
 * tagger, which assigns abilities to groups, and the bootstrap, which creates a dispatcher per group.
 * Those drifting apart is the failure this whole arrangement exists to prevent — a group with abilities
 * but no dispatcher gets a tab, a count and a REST filter while being unreachable over MCP, and nothing
 * reports it.
 *
 * Extend through the `acrossai_toolset_integrations` filter rather than by editing this file. A
 * companion plugin can then own its own integration entirely, which is Module Contract #4 and the
 * reason adding an integration is not a change here.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Collects and validates the registered toolset integrations.
 */
class AcrossAI_Toolset_Integrations {

	/**
	 * Group key of the built-in catch-all.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const CATCH_ALL_GROUP = 'other';

	/**
	 * Resolved integrations, keyed by group. Null until first resolve.
	 *
	 * Per-request only. The set depends on which plugins are active and on a filter, both of which are
	 * fixed for the life of a request but not between them.
	 *
	 * @since 0.0.34
	 * @var   array<string, AcrossAI_Toolset_Integration>|null
	 */
	private static $memo = null;

	/**
	 * Every registered integration, keyed by group.
	 *
	 * Inactive integrations are included. Their dispatcher suppresses itself when the group turns out
	 * empty, and excluding them here would mean the tagger silently stopped recognising a prefix the
	 * moment a plugin was deactivated mid-request — a harder thing to reason about than an empty group.
	 *
	 * @since  0.0.34
	 * @return array<string, AcrossAI_Toolset_Integration>
	 */
	public static function all(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		/**
		 * Filters the registered toolset integrations.
		 *
		 * Append an object implementing {@see AcrossAI_Toolset_Integration}. Entries that are not
		 * that, or that claim a group already taken, are discarded — see the validation below.
		 *
		 * @since 0.0.34
		 * @param array<int, AcrossAI_Toolset_Integration> $integrations Integrations collected so far.
		 */
		$declared = apply_filters( 'acrossai_toolset_integrations', self::built_in() );

		self::$memo = self::validate( is_array( $declared ) ? $declared : array() );

		return self::$memo;
	}

	/**
	 * The integration owning a group, if any.
	 *
	 * @since  0.0.34
	 * @param  string $group Group key.
	 * @return AcrossAI_Toolset_Integration|null
	 */
	public static function for_group( string $group ): ?AcrossAI_Toolset_Integration {
		return self::all()[ $group ] ?? null;
	}

	/**
	 * Map of ability-name prefix to group.
	 *
	 * Built from every integration's `ability_prefixes()`. A prefix claimed twice goes to the first
	 * integration that claimed it, so a later filter callback cannot quietly steal another
	 * integration's abilities.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function prefix_map(): array {
		$map = array();

		foreach ( self::all() as $group => $integration ) {
			foreach ( $integration->ability_prefixes() as $prefix ) {
				$prefix = (string) $prefix;

				if ( '' === $prefix || isset( $map[ $prefix ] ) ) {
					continue;
				}

				$map[ $prefix ] = $group;
			}
		}

		return $map;
	}

	/**
	 * Display labels declared by integrations, keyed by group.
	 *
	 * Only integrations appear here. Every other group's label is derived from its key by
	 * {@see \AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Tab_Group_Label}, and consumers
	 * fall back to that — so this map stays small and the derivation stays the default.
	 *
	 * It exists because the derivation cannot know an acronym: `acf` becomes "Acf", which reads as a
	 * typo. An integration already declares its own name, so the label is data rather than a second
	 * rule to keep in sync across PHP and JS.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function labels(): array {
		$labels = array();

		foreach ( self::all() as $group => $integration ) {
			$label = $integration->toolset_label();

			if ( '' === $label ) {
				continue;
			}

			$labels[ $group ] = $label;
		}

		return $labels;
	}

	/**
	 * Forget the resolved set.
	 *
	 * Tests, and any caller that adds a filter callback after the first resolve.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = null;
	}

	/**
	 * Integrations this plugin ships.
	 *
	 * @since  0.0.34
	 * @return array<int, AcrossAI_Toolset_Integration>
	 */
	private static function built_in(): array {
		// ACF is deliberately absent. Its constructor hooks `plugins_loaded` and
		// `acrossai_abilities_api_init` for the opt-in switch, so constructing a second one here
		// would push its Library rows twice. It is instantiated once in `Main::define_public_hooks()`
		// and adds itself through the filter above — the same route a third-party integration takes.
		return array(
			new Rank_Math(),
			new Site_Kit(),
			new Contact_Form_7(),
			new LiteSpeed_Cache(),
			new Yoast_Seo(),
			new All_In_One_Seo(),
			new Classic_Editor(),
			new Events_Calendar(),
			new Event_Tickets(),
			new WPCode(),
			new Loco_Translate(),
			new Redirection(),
			new Query_Monitor(),
			new Wp_Rollback(),
			new Wp_Downgrade(),
			new Consent_Banner(),
			new Email_Delivery(),
			new Anti_Spam(),
			new WooCommerce(),
			// These three have a hand-written dispatcher in `Toolset/` and are listed in the
			// bootstrap's `$claimed` array, so no `Integration_Toolset` is generated for them. They
			// are declared here purely to claim their ability-name prefix for the tagger — without
			// that, a host plugin's own abilities fall through to the catch-all (issue #255).
			new Elementor(),
			new UpdraftPlus(),
			new All_In_One(),
			// Last: it claims no prefixes and only ever receives what nothing else wanted, so its
			// position is immaterial — but reading it last matches how resolution actually works.
			new AcrossAI_Catch_All_Integration(),
		);
	}

	/**
	 * Reduce a declared list to a group-keyed set, dropping anything unusable.
	 *
	 * Dropping rather than throwing: this runs during boot on every request, and one malformed entry
	 * from a third-party filter must not take the toolsets down with it. A duplicate group is dropped
	 * too — first declaration wins — because two dispatchers on one group would both register and the
	 * second would lose the slug-collision check anyway.
	 *
	 * @since  0.0.34
	 * @param  array<int, mixed> $declared Raw filter output.
	 * @return array<string, AcrossAI_Toolset_Integration>
	 */
	private static function validate( array $declared ): array {
		$resolved = array();

		foreach ( $declared as $integration ) {
			if ( ! $integration instanceof AcrossAI_Toolset_Integration ) {
				continue;
			}

			$group = $integration->group();

			if ( '' === $group || isset( $resolved[ $group ] ) ) {
				continue;
			}

			$resolved[ $group ] = $integration;
		}

		return $resolved;
	}
}
