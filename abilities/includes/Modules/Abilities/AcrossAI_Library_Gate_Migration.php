<?php
/**
 * One-time translation of the retired registration gate into per-ability overrides.
 *
 * Feature 102 removes the gate that stopped abilities being registered at all. Whatever that gate
 * was blocking has to become an explicit `site_allowed = false` override, or every ability an
 * operator had switched off silently becomes reachable the moment the gate goes.
 *
 * ## Why this lives in the Abilities module
 *
 * It writes this module's override rows. Housing it under `Modules\Library` — where its *source*
 * option lives — would make Library depend on Abilities, which Constitution Module Contract #3
 * forbids. It reads the Library option through its own constant instead (see SOURCE_OPTION).
 *
 * ## Two decisions that shape this class, both deliberate
 *
 * - **It reports nothing.** No notice, no log. Correctness is established by tests before release,
 *   not observed afterwards. The only visible evidence it ran is that the abilities list shows the
 *   same abilities blocked that were previously unavailable.
 * - **It is attempted once, with no retry and no rollback.** The done flag is claimed *before* the
 *   work, so a request that dies mid-translation leaves the site marked done and partially
 *   translated. That is accepted: claiming afterwards would instead let every concurrent request on
 *   a busy site run a full translation simultaneously, which is strictly worse.
 *
 * ## Why the trigger is not admin-only
 *
 * An earlier draft ran this on `admin_init`. That is wrong: the exposure it closes is reachable via
 * REST and MCP, neither of which loads wp-admin, so a site nobody administers would serve every
 * previously blocked ability indefinitely. It runs on an all-request-path hook instead, guarded by
 * the flag — one cache-backed option read per request in the steady state.
 *
 * ## Trust boundary
 *
 * That trigger makes this reachable by an unauthenticated request. Everything it acts on therefore
 * comes from stored state — the site option and the definitions registry. It MUST NOT read request
 * input of any kind: with an unauthenticated trigger, any request-derived parameter would be an
 * unauthenticated write primitive.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Translates the retired library gate into per-ability access overrides.
 */
class AcrossAI_Library_Gate_Migration {

	/**
	 * Per-site flag recording that the translation has been claimed.
	 *
	 * Per-site, not network-wide, because the target — the override table — is per-site
	 * (`AcrossAI_Abilities_Table::$global = false`) while the source option is network-wide. A
	 * network-wide flag would mark the whole network complete the moment one site finished, which
	 * is precisely the failure it would be meant to prevent.
	 *
	 * @var string
	 */
	public const DONE_OPTION = 'acrossai_library_gate_migration_done';

	/**
	 * The retiring source option.
	 *
	 * Declared here rather than read from `AcrossAI_Ability_Library_Config::OPTION_KEY` on purpose:
	 * that class is deleted in the same feature, and on multisite a site may not run this
	 * translation until long after the deletion ships. Depending on a doomed symbol would leave
	 * late-migrating sites with no path to migrate at all.
	 *
	 * @var string
	 */
	public const SOURCE_OPTION = 'acrossai_library_config';

	/**
	 * Where third-party integration opt-ins move to.
	 *
	 * @var string
	 */
	public const INTEGRATIONS_OPTION = 'acrossai_integrations';

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Library_Gate_Migration|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- plugin-wide singleton convention.

	/**
	 * Retrieve the singleton.
	 *
	 * @since  0.0.34
	 * @return AcrossAI_Library_Gate_Migration
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.0.34
	 */
	private function __construct() {}

	/**
	 * Claim the translation for this site and run it exactly once.
	 *
	 * `add_option()` is a single INSERT that returns false when the row already exists, so of N
	 * concurrent requests exactly one wins the claim. A read-then-write guard
	 * (`get_option()` … `update_option()`) would be safe under `admin_init` and unsafe here: the
	 * window would be the entire duration of the translation, and every concurrent visitor on a
	 * freshly upgraded site would start one.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public function maybe_migrate(): void {
		if ( false === add_option( self::DONE_OPTION, '1', '', false ) ) {
			return;
		}

		$this->translate();
	}

	/**
	 * Perform the translation.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function translate(): void {
		$config = get_site_option( self::SOURCE_OPTION, array() );

		if ( ! is_array( $config ) || array() === $config ) {
			$this->finish();

			return;
		}

		/**
		 * Definition rows to translate.
		 *
		 * Read through the Library module's published filter, never by calling
		 * AcrossAI_Ability_Library_Registry directly — that class lives in a sibling module and
		 * Constitution Module Contract #3 forbids reaching into one (#4 makes filters the
		 * sanctioned seam). An empty result is handled by the caller, which refuses to claim the
		 * done-flag rather than translating nothing and deleting the source option.
		 *
		 * @since 0.0.34
		 * @param array<int, array<string, mixed>> $definitions Collected definition rows.
		 */
		$definitions = (array) apply_filters( 'acrossai_ability_library_definitions', array() );

		// A non-empty config with zero definitions is not "nothing to do" — it is "asked too early".
		// The registry is populated at init P99; before that it is empty, and translating against it
		// would silently conclude that nothing was blocked and then retire the source option, losing
		// the configuration outright. That happened during development, which is why this guard
		// exists and why the claim is released rather than kept.
		//
		// This is not the retry that clarification Q2 rules out. Q2 concerns a translation that
		// *fails part-way*; this is a precondition that is not yet met, so no work has begun.
		if ( array() === $definitions ) {
			delete_option( self::DONE_OPTION );

			return;
		}

		$plan = $this->build_plan( $config, $definitions );

		$this->block_abilities( $plan['block'] );
		$this->carry_integration_opt_ins( $plan['integrations'] );
		$this->finish();
	}

	/**
	 * Decide, per definition, what the retired gate was doing.
	 *
	 * The source option holds two kinds of entry with **opposite** defaults: an absent first-party
	 * category means *permitted*, while an absent integration means *disabled*. Reading it with a
	 * single default therefore mis-translates one of the two, so the kind is resolved from
	 * `card_variant` before any default is applied.
	 *
	 * Category membership comes from the definitions registry, never from `sub_keys`: the retired
	 * store capped itself at 50 sub-keys, so its tick list is incomplete for every category larger
	 * than that and cannot be treated as authoritative.
	 *
	 * Public and definition-injected so the rules can be unit-tested without WordPress: this is the
	 * only control on the translation's correctness, because it reports nothing at run time and is
	 * never retried. An instance method rather than a static one — a public static on a singleton
	 * bypasses the ::instance() contract (BUG-STATIC-METHOD-SINGLETON-BYPASS).
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed>             $config      Saved library config.
	 * @param  array<int, array<string, mixed>> $definitions Registry definitions.
	 * @return array{block: array<int, string>, integrations: array<string, bool>}
	 */
	public function build_plan( array $config, array $definitions ): array {
		$block        = array();
		$integrations = array();

		foreach ( $definitions as $definition ) {
			$category = isset( $definition['category'] ) ? (string) $definition['category'] : '';
			$sub_key  = isset( $definition['slug'] ) ? (string) $definition['slug'] : '';
			$name     = isset( $definition['name'] ) ? (string) $definition['name'] : '';
			$variant  = isset( $definition['card_variant'] ) ? (string) $definition['card_variant'] : '';

			if ( '' === $category || '' === $name ) {
				continue;
			}

			// Third-party integration rows are opt-ins, not access settings. They are carried
			// across unchanged; converting them to Force Block would be meaningless, because
			// without the opt-in the third-party plugin never registers anything to block.
			if ( 'integration' === $variant ) {
				$enabled                   = $this->entry_enabled( $config, $category, false );
				$integrations[ $category ] = ( $integrations[ $category ] ?? false ) || $enabled;

				continue;
			}

			// Absent first-party category: the gate permitted it. Nothing to record.
			if ( ! isset( $config[ $category ] ) || ! is_array( $config[ $category ] ) ) {
				continue;
			}

			if ( ! $this->entry_enabled( $config, $category, true ) ) {
				$block[] = $name;

				continue;
			}

			$entry = $config[ $category ];
			$mode  = ( isset( $entry['mode'] ) && 'specific' === $entry['mode'] ) ? 'specific' : 'all';

			if ( 'all' === $mode ) {
				continue;
			}

			// Mirrors the retired gate's own test exactly — `isset() && (bool) $value` — so a
			// truthy-but-not-true tick such as 1 or 'yes' still counts as ticked. SEC-04 would
			// argue for a strict `true ===` here, and that is deliberately NOT done: the
			// requirement is that effective access is identical before and after the upgrade
			// (FR-010). Tightening the rule would block abilities the gate permitted — safer in
			// isolation, but still a behaviour change, and a silent one.
			$ticked = isset( $entry['sub_keys'][ $sub_key ] ) && (bool) $entry['sub_keys'][ $sub_key ];

			if ( ! $ticked ) {
				$block[] = $name;
			}
		}

		return array(
			'block'        => array_values( array_unique( $block ) ),
			'integrations' => $integrations,
		);
	}

	/**
	 * Whether a config entry counts as enabled, given the default for its kind.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $config  Saved config.
	 * @param  string               $key     Entry key.
	 * @param  bool                 $fallback Value when the entry or its flag is absent.
	 * @return bool
	 */
	private function entry_enabled( array $config, string $key, bool $fallback ): bool {
		if ( ! isset( $config[ $key ] ) || ! is_array( $config[ $key ] ) ) {
			return $fallback;
		}

		if ( ! array_key_exists( 'enabled', $config[ $key ] ) ) {
			return $fallback;
		}

		return (bool) $config[ $key ]['enabled'];
	}

	/**
	 * Whether an administrator has already decided this ability's access.
	 *
	 * FR-008: the translation must never overwrite an explicit decision. The test is on
	 * `site_allowed` specifically, not on the row existing — a row may exist carrying only an MCP
	 * exposure or a user-access rule, with `site_allowed` still null, and that is not a decision
	 * about site access. Treating any row as a shield would leave those abilities reachable.
	 *
	 * Extracted from block_abilities() so the rule is unit-testable: persistence needs a database
	 * and runs under wp-env only, but this is the guarantee that makes a once-only, unattended,
	 * unreported migration safe, so it should fail the default suite if it ever changes.
	 *
	 * @since  0.0.34
	 * @param  object|null $existing Existing override row, or null when none exists.
	 * @return bool True when the migration must leave this ability alone.
	 */
	public static function is_already_decided( $existing ): bool {
		return null !== $existing && null !== $existing->site_allowed;
	}

	/**
	 * Write `site_allowed = false` for each ability the gate was blocking.
	 *
	 * An ability whose access an operator has already set explicitly is left alone — see
	 * {@see self::is_already_decided()} for that rule and why it tests `site_allowed` rather than
	 * the existence of a row.
	 *
	 * @since  0.0.34
	 * @param  array<int, string> $slugs Ability names to block.
	 * @return void
	 */
	private function block_abilities( array $slugs ): void {
		if ( array() === $slugs ) {
			return;
		}

		$query = AcrossAI_Abilities_Query::instance();

		foreach ( $slugs as $slug ) {
			$existing = $query->get_override_by_slug( $slug );

			if ( self::is_already_decided( $existing ) ) {
				continue;
			}

			/*
			 * `source` must be set by the caller. The column is `NOT NULL DEFAULT 'db'` and
			 * save_override() never sets it — it only strips a caller-supplied 'db' (its SEC-002
			 * guard), which the schema default then silently reinstates. Omitting it stamps every
			 * row this migration writes as a user-created ability, so the abilities list reports
			 * them as custom abilities with no label, callback or status.
			 *
			 * The REST write path satisfies this with AcrossAI_Ability_Source_Detector::detect()
			 * (RF-04), but that needs a registered ability's `provider`, and reading one here would
			 * mean calling wp_get_ability() at `init` P100 — forcing `wp_abilities_api_init` to fire
			 * early, which is exactly the hook-ordering dependency this migration resolves from the
			 * definitions registry to avoid. There is nothing to detect in any case: every slug in
			 * that registry is registered by this plugin, so the answer is always 'plugin'.
			 */
			$query->save_override(
				$slug,
				array(
					'site_allowed' => false,
					'source'       => 'plugin',
				)
			);
		}
	}

	/**
	 * Copy third-party integration opt-ins to their new home.
	 *
	 * OR-monotonic and independently idempotent: a truthy opt-in is never demoted. This diverges
	 * from the one-shot, no-rollback stance taken for the override translation, and deliberately so
	 * — losing an opt-in silently switches a third-party integration off, and the requirement is
	 * that it survives the upgrade.
	 *
	 * @since  0.0.34
	 * @param  array<string, bool> $integrations Opt-in state keyed by integration slug.
	 * @return void
	 */
	private function carry_integration_opt_ins( array $integrations ): void {
		if ( array() === $integrations ) {
			return;
		}

		$stored = get_site_option( self::INTEGRATIONS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		foreach ( $integrations as $slug => $enabled ) {
			$stored[ $slug ] = ! empty( $stored[ $slug ] ) || $enabled;
		}

		update_site_option( self::INTEGRATIONS_OPTION, $stored );
	}

	/**
	 * Retire the source option where it is safe to do so.
	 *
	 * Single-site only. On multisite the option is network-wide while translations are per-site, and
	 * nothing tracks which sites have finished — so deleting it after the first site completes would
	 * strand every other site. Leaving a dead option behind is much the cheaper mistake.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function finish(): void {
		if ( is_multisite() ) {
			return;
		}

		delete_site_option( self::SOURCE_OPTION );
	}
}
