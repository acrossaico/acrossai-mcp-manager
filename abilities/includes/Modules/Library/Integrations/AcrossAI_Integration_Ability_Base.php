<?php
/**
 * Abstract base class for third-party plugin integrations on the Ability Library page.
 *
 * Feature 060. A concrete subclass represents one third-party WordPress plugin
 * whose WP Abilities API abilities are gated behind a plugin-specific filter
 * (e.g. Advanced Custom Fields requires add_filter('acf/settings/enable_acf_ai',
 * '__return_true') before it registers its FieldGroup / PostType / Taxonomy
 * abilities).
 *
 * ## Contract (all abstract)
 *
 *   - slug()              — key used as BOTH category AND tab_group
 *   - label()             — display label for the card / tab
 *   - is_plugin_active()  — the plugin-existence gate; called per-request
 *   - enable_filter()     — subclass attaches its add_filter(...) here
 *   - abilities()         — fixed readonly list rendered inside the card
 *
 * The constructor wires two hooks:
 *   - plugins_loaded @ P20        → maybe_enable()
 *   - acrossai_abilities_api_init → push_definition()
 *
 * Both callbacks call is_plugin_active() first and return early when false, so
 * a deactivated third-party plugin never surfaces a card, a tab, or a filter
 * attachment on the current request. Because the check runs per-request rather
 * than being cached at construction, activating or deactivating the target
 * plugin takes effect on the very next page load.
 *
 * ## How to add a new integration (subclass)
 *
 *   1. Create a new class under `includes/Abilities/Integrations/` extending
 *      this base class. Add a `public const TAB_GROUP` for other plugins to
 *      reference (see includes/Abilities/Integrations/ACF.php as the canonical
 *      example).
 *   2. Implement the five abstract methods.
 *   3. Instantiate the class once from `includes/Main.php::define_public_hooks()`
 *      near the ACF instantiation. Instantiation must happen BEFORE
 *      plugins_loaded @ P20 fires — instantiating from define_public_hooks
 *      (which runs at Main construction time, i.e. plugins_loaded @ P0) is
 *      correct.
 *   4. There is intentionally no auto-discovery of integrations — each new
 *      integration is one hardcoded line in Main.php. This keeps the tab bar
 *      auditable and predictable.
 *
 * ## How third-party plugins add custom cards to an integration tab
 *
 * A separate WordPress plugin (e.g. "acrossai-acf-extras") can add its own
 * ability cards next to an integration's toggle card on the same tab. This is
 * how the tab becomes an extensibility surface, not just a single-card view.
 *
 * The pattern requires THREE things, all of them:
 *
 *   1. **Register the ability CATEGORY** on `wp_abilities_api_categories_init`.
 *      WP core (WP_Abilities_Registry::register() in
 *      wp-includes/abilities-api/class-wp-abilities-registry.php lines 132-146)
 *      REJECTS any ability whose category slug is not pre-registered. Skip
 *      this step and your abilities are silently dropped by
 *      wp_register_ability() — the Library UI card WILL still render (it
 *      reads from our Registry, not from wp_get_abilities()), but the ability
 *      will NEVER appear in the Custom Abilities table or MCP
 *      `discover-abilities`. This has bitten us before; the requirement is
 *      strict.
 *   2. Extend `AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition`
 *      and implement `ability()` returning the ability spec.
 *   3. Set `meta.acrossai.tab_group` on the ability's args to the integration's
 *      published `TAB_GROUP` constant (e.g. `ACF::TAB_GROUP`) so the card
 *      routes onto the integration's tab.
 *
 * Complete working example (in a third-party plugin's mu-plugin or main file):
 *
 *     use AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\ACF;
 *     use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
 *
 *     // Step 1 — REQUIRED — register the category BEFORE wp_register_ability runs.
 *     add_action( 'wp_abilities_api_categories_init', static function () {
 *         wp_register_ability_category( 'my-plugin-acf', array(
 *             'label'       => __( 'My ACF Add-on', 'my-plugin' ),
 *             'description' => __( 'Adds custom ACF helper abilities.', 'my-plugin' ),
 *         ) );
 *     } );
 *
 *     // Steps 2 + 3 — extend Ability_Definition and target the ACF tab.
 *     class My_ACF_Addon_Ability extends Ability_Definition {
 *         protected function ability(): array {
 *             return array(
 *                 'name' => 'my-plugin/my-acf-helper',
 *                 'args' => array(
 *                     'label'               => __( 'My ACF Helper', 'my-plugin' ),
 *                     'description'         => __( 'Does something with ACF.', 'my-plugin' ),
 *                     'category'            => 'my-plugin-acf',   // matches the registered category slug
 *                     'execute_callback'    => array( $this, 'execute' ),
 *                     'permission_callback' => static function () {
 *                         return current_user_can( 'manage_options' );
 *                     },
 *                     'meta' => array(
 *                         'acrossai' => array(
 *                             'tab_group' => ACF::TAB_GROUP,   // routes onto the "Acf" tab
 *                         ),
 *                     ),
 *                 ),
 *             );
 *         }
 *         public function execute( array $input = array() ) { return array( 'ok' => true ); }
 *     }
 *
 *     add_action( 'plugins_loaded', static function () {
 *         if ( ! class_exists( ACF::class ) ) { return; }
 *         new My_ACF_Addon_Ability();
 *     }, 25 );
 *
 * The add-on's card renders as a REGULAR library card (toggle + expand + All/
 * Specific radio + per-ability checkboxes), styled like the "Core" cards on
 * other tabs, and shows up ALONGSIDE the integration's read-only toggle card.
 *
 * Use a DISTINCT category slug per third-party plugin (e.g. `'my-plugin-acf'`)
 * so it renders as its own card. Reusing the integration's own slug
 * (`'acf'`) would fail-register (category not pre-registered from the
 * third-party's side) AND would attempt to merge the add-on abilities into the
 * integration card, which is not intended.
 *
 * A worked demo mu-plugin exercising this pattern with two categories +
 * three abilities is captured in Feature 060's quickstart doc:
 * `specs/060-library-third-party-integration-toggles/quickstart.md`.
 *
 * ## Boot Flow Rule deviation
 *
 * This class registers hooks in its constructor rather than being wired via
 * Main.php's Loader. This mirrors the existing
 * AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition
 * pattern (~176 concrete subclasses). See DEC-ABILITY-DEFINITION-CTOR-HOOKS
 * (Feature 060) — accepted deviation sibling to DEC-EXTERNAL-PACKAGE-HOOK-CTOR.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage includes/Modules/Library/Integrations
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Library\Integrations;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Integration_Settings;

use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Key_Sanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base for third-party integration cards on the Library page.
 *
 * @since 0.1.0
 */
abstract class AcrossAI_Integration_Ability_Base {

	/**
	 * Register the hook callbacks the base class owns.
	 *
	 * The enable hook is asked for rather than hardcoded because hosts read their gate at different
	 * times. ACF reads its setting at `acf/init`, well after `plugins_loaded`, so P20 is comfortable.
	 * A host whose packages read the gate while they load needs the filter in place sooner — see
	 * {@see self::enable_hook()}.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		list( $hook, $priority ) = $this->enable_hook();

		add_action( (string) $hook, array( $this, 'maybe_enable' ), (int) $priority );
		add_filter( 'acrossai_abilities_api_init', array( $this, 'push_definition' ), 10 );

		// Declared per integration rather than listed in a constant elsewhere, so an integration that
		// must not start on says so in its own file. Read by
		// `AcrossAI_Integration_Default_Opt_Ins`, which seeds a stored value — NOT by
		// `AcrossAI_Integration_Settings::is_enabled()`, where absent still means off.
		add_filter( 'acrossai_integration_seeds_enabled', array( $this, 'declare_seeds_enabled' ), 10, 2 );
	}

	/**
	 * Hook and priority on which {@see self::maybe_enable()} runs.
	 *
	 * Override when the host reads its own gate earlier than `plugins_loaded` P20. The gate must be
	 * in place before the host asks, and a host that asks during its own load will have asked
	 * already by P20 — the switch then appears to work while doing nothing.
	 *
	 * @since  0.0.42
	 * @return array{0: string, 1: int}
	 */
	protected function enable_hook(): array {
		return array( 'plugins_loaded', 20 );
	}

	/**
	 * Whether this integration should be seeded switched ON for a site that has never been asked.
	 *
	 * On by default: an integration's abilities are expected to work once its plugin is present, and
	 * the switch exists so an operator can take them away rather than having to grant them.
	 *
	 * Return `false` where switching on also switches on a third-party feature the site owner has
	 * not asked for. Our own abilities for a host are ours to enable; the host's own are not, and an
	 * opt-in that seeds itself on is not an opt-in.
	 *
	 * This decides only the seed. Once a value is stored — by the seed or by someone using the
	 * screen — it is what counts, and this is never consulted again for that slug.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function seeds_enabled(): bool {
		return true;
	}

	/**
	 * Filter callback publishing {@see self::seeds_enabled()} for this integration's slug.
	 *
	 * @since  0.0.42
	 * @param  mixed  $seeds Decision so far.
	 * @param  string $slug  Integration slug being asked about.
	 * @return mixed
	 */
	public function declare_seeds_enabled( $seeds, $slug = '' ) {
		if ( (string) $slug !== $this->slug() ) {
			return $seeds;
		}

		return $this->seeds_enabled();
	}

	/**
	 * Satisfy a host that gates on an option rather than on a filter.
	 *
	 * Short-circuits `get_option()` through `pre_option_{$option}` instead of writing the value.
	 * Two reasons that matters: the host's own stored setting is left exactly as the site owner left
	 * it, so switching our row back off restores their choice rather than ours; and nothing we do
	 * here survives this plugin being deactivated.
	 *
	 * **Unused since 0.0.42, and think hard before reaching for it.** It is defensible for a host
	 * option with no user interface — an internal flag, where no one has expressed a preference for
	 * us to override. It is the wrong instrument for an option the host SURFACES IN ITS OWN
	 * SETTINGS SCREEN, because that value is the site owner's saved answer rather than an extension
	 * point.
	 *
	 * Elementor is the case that proved it. `elementor_mcp_enabled` is shown at
	 * Elementor → Elementor MCP, and forcing it left that screen displaying a switch nobody had
	 * set, turned on site-wide MCP exposure Elementor ships off, and made this plugin's own
	 * transport detector report Elementor MCP "active" on the strength of our own filter — a
	 * self-fulfilling answer. See
	 * {@see \AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\Elementor_Opt_In},
	 * which now calls the host's public registration method instead and leaves the option alone.
	 *
	 * Before using this, look for a public method or a published filter on the host. There usually
	 * is one, and it is always the better answer: it asks for the behaviour you want rather than
	 * lying to the host about its own configuration.
	 *
	 * @since  0.0.42
	 * @param  string $option Option name the host reads.
	 * @return void
	 */
	protected function force_option_true( string $option ): void {
		if ( '' === $option ) {
			return;
		}

		add_filter(
			'pre_option_' . $option,
			static function () {
				return true;
			}
		);
	}

	/**
	 * Sanitized identifier for this integration.
	 *
	 * Used as BOTH the Library category slug AND the tab_group. Must survive
	 * AcrossAI_Key_Sanitizer::key() unchanged for the
	 * integration to render.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * Human-readable display label for the card and tab.
	 *
	 * Should be pre-translated by the subclass via __() with its own text domain.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	abstract protected function label(): string;

	/**
	 * Whether the target third-party plugin is installed AND active on the current site.
	 *
	 * Called on EVERY request from both hook callbacks. Subclasses MUST NOT
	 * cache the result — the target plugin's activation state can change
	 * between requests, and the UI/filter attachment behaviour must reflect
	 * that on the very next page load.
	 *
	 * Recommended check: a compound `defined()` + `function_exists()` on
	 * stable public symbols of the target plugin (survives WP-CLI, cron, and
	 * REST contexts where is_plugin_active() may need
	 * wp-admin/includes/plugin.php to be loaded first). See SEC-002 in
	 * specs/060-library-third-party-integration-toggles/security-review-plan.md
	 * for why a single-symbol check is spoofable.
	 *
	 * @since  0.1.0
	 * @return bool
	 */
	abstract protected function is_plugin_active(): bool;

	/**
	 * Attach the third-party plugin's own AI-enable filter.
	 *
	 * Called by the base class from maybe_enable() ONLY when the toggle is on
	 * AND is_plugin_active() returns true. Subclass calls add_filter( ... )
	 * with the plugin-specific filter name here.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	abstract protected function enable_filter(): void;

	/**
	 * Fixed readonly list of the abilities the target plugin will expose when enabled.
	 *
	 * Display-only. Each entry MUST be an array with:
	 *   - 'slug'         (string) unique ability slug shown in the card row
	 *   - 'label'        (string) row label
	 *   - 'description'  (string) optional short description
	 *
	 * These entries are NOT registered as WP abilities. The target plugin
	 * remains the authoritative source of actual ability registration; this
	 * list is only used to inform the admin what enabling the toggle unlocks.
	 *
	 * @since  0.1.0
	 * @return array<int, array{slug: string, label: string, description?: string}>
	 */
	abstract protected function abilities(): array;

	/**
	 * Callback for the plugins_loaded @ P20 hook.
	 *
	 * Reads the saved library config; if this integration is toggled on AND
	 * the target plugin is currently active, invokes enable_filter() on the
	 * subclass. Wraps the subclass call in try/catch so a third-party integration
	 * bug never fatals the site (Constitution §V Integration Resilience; SEC-001).
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function maybe_enable(): void {
		if ( ! $this->is_plugin_active() ) {
			return;
		}

		if ( ! AcrossAI_Integration_Settings::is_enabled( $this->slug() ) ) {
			return;
		}

		try {
			$this->enable_filter();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'AcrossAI integration [%s] enable_filter threw: %s',
						$this->slug(),
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Callback for the acrossai_abilities_api_init filter.
	 *
	 * When the target plugin is active, appends one synthetic definition row
	 * per subclass-declared ability — all sharing the same category, tab_group,
	 * and card_variant='integration'. The Library page's groupDefinitions()
	 * helper folds them into a single card automatically. When the target
	 * plugin is missing, returns $definitions unchanged (no tab, no card, no
	 * dereference of target-plugin symbols anywhere in this class).
	 *
	 * Each synthetic row includes a fail-closed execute_callback that returns
	 * WP_Error if it is somehow invoked. These rows are display-only; the
	 * target plugin is responsible for actually registering the abilities via
	 * its own wp_abilities_api_init path.
	 *
	 * ## Design contract — synthetic rows intentionally fail wp_register_ability()
	 *
	 * Every synthetic row emitted by this method carries the integration slug
	 * as its `category` (e.g. `'acf'`). That slug is intentionally NEVER
	 * registered as a WP ability category via `wp_register_ability_category()`
	 * on `wp_abilities_api_categories_init`. WP core (see
	 * `wp-includes/abilities-api/class-wp-abilities-registry.php` lines
	 * 132-146) silently rejects any ability whose category is not
	 * pre-registered, so `wp_register_ability()` returns null and the
	 * synthetic rows never enter `wp_get_abilities()`. This is the desired
	 * behavior: the rows exist SOLELY to drive the Library UI card (which
	 * reads from our Registry, not from wp_get_abilities()), and their
	 * `execute_callback`/`permission_callback` are fail-closed as
	 * defence-in-depth in case that WP-core rule ever changes.
	 *
	 * **Do NOT register the integration slug as an ability category.** Doing
	 * so would cause every synthetic row's fail-closed callbacks to
	 * successfully register into WP core, leaking N broken abilities per
	 * integration into `wp_get_abilities()`, MCP `discover-abilities`, and
	 * the Custom Abilities admin table. If a future integration needs its
	 * synthetic rows to appear in wp_get_abilities(), replace the fail-closed
	 * callbacks with real implementations FIRST, then register the category.
	 *
	 * (This design intent is verified in production: the ACF integration's
	 * synthetic `acf/*` rows are absent from wp_get_abilities() even when
	 * the toggle is on — only the target plugin's real abilities register.)
	 *
	 * @since  0.1.0
	 * @param  array<int, array<string, mixed>> $definitions Existing definitions collected so far.
	 * @return array<int, array<string, mixed>>
	 */
	public function push_definition( array $definitions ): array {
		if ( ! $this->is_plugin_active() ) {
			return $definitions;
		}

		$slug     = AcrossAI_Key_Sanitizer::key( $this->slug() );
		$label    = $this->label();
		$fail_msg = __( 'This is a display-only integration row and cannot be executed directly.', 'acrossai-abilities-manager' );

		foreach ( $this->abilities() as $ability ) {
			if ( ! is_array( $ability ) || empty( $ability['slug'] ) || empty( $ability['label'] ) ) {
				continue;
			}

			$ability_slug  = (string) $ability['slug'];
			$ability_label = (string) $ability['label'];
			$description   = isset( $ability['description'] ) ? (string) $ability['description'] : '';

			$definitions[] = array(
				'category'       => $slug,
				'category_label' => $label,
				'slug'           => $ability_slug,
				'slug_label'     => $ability_label,
				'name'           => $ability_slug,
				'tab_group'      => $slug,
				'card_variant'   => 'integration',
				'args'           => array(
					'label'               => $ability_label,
					'description'         => $description,
					'category'            => $slug,
					'execute_callback'    => static function () use ( $fail_msg ) {
						return new \WP_Error( 'acrossai_integration_synthetic_row', $fail_msg );
					},
					'permission_callback' => static function () {
						return false;
					},
				),
			);
		}

		return $definitions;
	}
}
