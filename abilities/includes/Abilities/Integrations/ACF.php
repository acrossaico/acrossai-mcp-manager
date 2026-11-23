<?php
/**
 * Advanced Custom Fields (AI) — first concrete integration for Feature 060.
 *
 * ACF ships its own WP Abilities API abilities under
 * advanced-custom-fields/src/AI/Abilities/{FieldGroup,PostType,Taxonomy}.php,
 * but only registers them when the filter `acf/settings/enable_acf_ai`
 * returns true (see advanced-custom-fields/acf.php:184 and
 * advanced-custom-fields/src/AI/AI.php:58 as of ACF 6.x). This subclass
 * exposes that master switch as a checkbox in AcrossAI Settings → Abilities.
 *
 * Toggle state persists in the `acrossai_integrations` option via the base class and the Settings
 * API — no ACF-specific storage. Feature 102 moved it there from the shared
 * `acrossai_library_config` option when the Ability Integrations page was retired; the switch
 * itself is unchanged, and absent still means off.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage includes/Abilities/Integrations
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Integrations\AcrossAI_Integration_Ability_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Third-party integration wrapper for Advanced Custom Fields' AI abilities.
 *
 * ### Extending the "Acf" tab from another plugin
 *
 * Any WordPress plugin can add additional ability cards next to the integration
 * toggle on the "Acf" tab by doing THREE things (all required):
 *
 *   1. **Register the ability category** on `wp_abilities_api_categories_init`.
 *      WP core rejects any `wp_register_ability()` call whose `category` slug
 *      is not pre-registered — see WP_Abilities_Registry::register() in
 *      wp-includes/abilities-api/class-wp-abilities-registry.php. Without this
 *      step the ability is silently dropped by wp_register_ability() and never
 *      reaches wp_get_abilities(), so it will NOT appear in the Custom
 *      Abilities table or MCP discover-abilities (the Library UI card would
 *      still render because the Library UI reads from the Registry, not from
 *      wp_get_abilities()).
 *   2. Extend `AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition`.
 *   3. Set `meta.acrossai.tab_group` on the ability to `ACF::TAB_GROUP`
 *      (recommended — stable reference) or the literal `'acf'`.
 *
 * Then instantiate the subclass once during plugin bootstrap. The base class's
 * constructor auto-registers the ability into the shared `acrossai_abilities_api_init`
 * filter, so the card appears on the "Acf" tab automatically.
 *
 * Example — a hypothetical add-on plugin adding a "My ACF Add-on" card:
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
 *                     'category'            => 'my-plugin-acf',   // separate card
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
 *     }
 *     add_action( 'plugins_loaded', static fn () => new My_ACF_Addon_Ability(), 25 );
 *
 * The add-on's card renders as a REGULAR library card (toggle, All/Specific
 * radio, per-ability checkboxes) alongside the read-only integration card.
 * Cards are grouped by their `category` field — use a distinct category slug
 * (e.g. `'my-plugin-acf'`) to get a separate card; reusing `'acf'` would merge
 * the add-on's abilities into the integration card, which is not intended.
 *
 * @since 0.1.0
 */
class ACF extends AcrossAI_Integration_Ability_Base implements AcrossAI_Toolset_Integration {

	/**
	 * The tab_group identifier for the "Acf" tab on the Ability Library page.
	 *
	 * Publish as a `public const` so third-party plugins can reference a
	 * stable identifier from their Ability_Definition subclasses instead of
	 * hardcoding the string. See the class docblock for the extension pattern.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const TAB_GROUP = 'acf';

	/**
	 * Category / tab_group identifier.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	protected function slug(): string {
		return self::TAB_GROUP;
	}

	/**
	 * Register the opt-in hooks, then declare this integration's toolset.
	 *
	 * Self-registration rather than a `new ACF()` inside the registry: the base constructor hooks
	 * `plugins_loaded` and `acrossai_abilities_api_init`, so a second instance would push the
	 * display-only Library rows twice. One instance is created in `Main::define_public_hooks()`, and
	 * this is the route a third-party integration would use too.
	 *
	 * @since 0.0.34
	 */
	public function __construct() {
		parent::__construct();

		add_filter(
			'acrossai_toolset_integrations',
			function ( $integrations ) {
				$integrations   = is_array( $integrations ) ? $integrations : array();
				$integrations[] = $this;

				return $integrations;
			}
		);
	}

	/**
	 * Toolset key.
	 *
	 * Same value as {@see self::slug()}, reached through the toolset contract rather than the opt-in
	 * one. The two happen to coincide for ACF and need not for every integration: an integration with
	 * no opt-in has no slug at all.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * Display name for the toolset.
	 *
	 * Drops the "(AI)" the opt-in label carries. That suffix distinguishes ACF's AI feature from ACF
	 * itself, which matters next to a switch that turns that feature on; in a list of toolsets it
	 * reads as a different product.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'Advanced Custom Fields', 'acrossai-abilities-manager' );
	}

	/**
	 * What this toolset covers, for an MCP client.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Advanced Custom Fields: inspect and create field groups, and register custom post types and taxonomies through ACF. Use this for anything about ACF field structure — the shape of the fields themselves, not the values stored against a post. Registering post types or taxonomies changes site structure and requires administrator rights. These abilities come from ACF and appear only when its AI feature is enabled. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Ability names ACF registers.
	 *
	 * ACF namespaces its own abilities `acf/*`. This plugin registers none of them — the rows in
	 * {@see self::abilities()} are display-only and never reach `wp_get_abilities()` — so every
	 * ability in this group arrives through the tagger.
	 *
	 * Because they are display-only, nothing at runtime forces them to match what ACF registers, and
	 * they drifted: two named abilities that do not exist (`acf/post-types`, `acf/taxonomies` rather
	 * than `acf/custom-post-types` and `acf/custom-taxonomies`) while the three `register-*` writers
	 * were missing entirely. Test_Integration_Row_Accuracy now asserts every declared slug resolves
	 * whenever the host plugin is active.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array( 'acf' );
	}

	/**
	 * Whether ACF is present.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return $this->is_plugin_active();
	}

	/**
	 * Human-readable label for the card and tab.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	protected function label(): string {
		return __( 'Advanced Custom Fields (AI)', 'acrossai-abilities-manager' );
	}

	/**
	 * Whether ACF is loaded on the current site.
	 *
	 * Uses a compound check on TWO stable ACF public symbols per SEC-002
	 * (specs/060-library-third-party-integration-toggles/security-review-plan.md).
	 * A single-symbol check like `class_exists('ACF')` alone is spoofable by
	 * any other plugin that happens to define a class of the same name; the
	 * combined `defined()` + `function_exists()` predicate closes that gap
	 * without adding meaningful runtime cost.
	 *
	 * `acf_get_setting()` is used because it is the function the target
	 * filter (`acf/settings/enable_acf_ai`) is read through on ACF's own
	 * init path, so this check aligns with the same code path our toggle
	 * ultimately affects.
	 *
	 * @since  0.1.0
	 * @return bool
	 */
	protected function is_plugin_active(): bool {
		return defined( 'ACF_VERSION' ) && function_exists( 'acf_get_setting' );
	}

	/**
	 * Attach ACF's own AI-enable filter.
	 *
	 * Called by the base class from maybe_enable() ONLY when the toggle is on
	 * AND is_plugin_active() returns true. Runs at plugins_loaded @ P20 which
	 * is before ACF's own `acf/init` hook, so ACF picks up
	 * `enable_acf_ai` as truthy on the same request.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	protected function enable_filter(): void {
		add_filter( 'acf/settings/enable_acf_ai', '__return_true' );
	}

	/**
	 * Fixed readonly list of the abilities ACF will expose when enabled.
	 *
	 * Sourced from advanced-custom-fields/src/AI/Abilities/{FieldGroup,PostType,
	 * Taxonomy}.php. Descriptions are paraphrased so the card remains informative
	 * even before the toggle is flipped. Not authoritative — ACF is the source of
	 * truth for actual runtime ability registration.
	 *
	 * @since  0.1.0
	 * @return array<int, array{slug: string, label: string, description: string}>
	 */
	protected function abilities(): array {
		return array(
			array(
				'slug'        => 'acf/field-groups',
				'label'       => __( 'List Field Groups', 'acrossai-abilities-manager' ),
				'description' => __(
					'List the ACF field groups that allow AI access. A field group only appears once its post type or taxonomy has "Allow AI access" enabled in ACF.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'acf/register-field-group',
				'label'       => __( 'Register Field Group', 'acrossai-abilities-manager' ),
				'description' => __(
					'Create a new ACF field group with its field definitions. This builds the field structure that appears on an edit screen, not the values; use the ACF value abilities for those.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'acf/custom-post-types',
				'label'       => __( 'List Custom Post Types', 'acrossai-abilities-manager' ),
				'description' => __(
					'List the custom post types registered through ACF.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'acf/register-custom-post-type',
				'label'       => __( 'Register Custom Post Type', 'acrossai-abilities-manager' ),
				'description' => __(
					'Register a new post type definition through ACF. This creates the post type itself, not posts within it.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'acf/custom-taxonomies',
				'label'       => __( 'List Custom Taxonomies', 'acrossai-abilities-manager' ),
				'description' => __(
					'List the taxonomies registered through ACF.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'acf/register-custom-taxonomy',
				'label'       => __( 'Register Custom Taxonomy', 'acrossai-abilities-manager' ),
				'description' => __(
					'Register a new taxonomy definition through ACF. This creates the taxonomy itself, not terms within it.',
					'acrossai-abilities-manager'
				),
			),
		);
	}
}
