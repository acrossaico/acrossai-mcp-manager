<?php
/**
 * Feature 117 — WPForms: adopt the eight abilities it already ships, and give its write gate an
 * off switch that works.
 *
 * WPForms Lite registers eight abilities of its own under `wpforms/*`
 * (`src/Integrations/Abilities/Abilities.php` plus the Lite subclass), unconditionally: the only
 * gate is `function_exists( 'wp_register_ability' )`. Nothing here claimed that namespace, so the
 * tagger dropped all eight into the catch-all group — live on the site and over REST, absent from
 * our tabs and our MCP tools. This integration claims the namespace so they land in a `wpforms`
 * tab, exactly as ACF's and Yoast's own abilities do.
 *
 * Nothing is re-registered. Adoption is tagging: registering a name WPForms already owns would meet
 * the Abilities API duplicate refusal, and which side survives depends only on load order.
 *
 * **The toggle, and why it defaults to on.** WPForms gates its four write abilities behind
 * `ai-mcp-write-enabled` in the shared `wpforms_settings` option, read through the
 * `wpforms_integrations_abilities_allow_write` filter and defaulting to OFF. That gate lives inside
 * its `permission_callback`, and `AcrossAI_Ability_Override_Processor` replaces the
 * `permission_callback` of every non-router ability without consulting the original — so the gate is
 * discarded, and WPForms' own execute callbacks do not re-check it. Measured on a real site with the
 * WPForms switch OFF: `create-form`, `add-field`, `update-field` and `update-form-settings` all
 * returned ALLOWED.
 *
 * So writes are already permitted here with no way to stop them. This toggle therefore defaults to
 * ON — that is the behaviour the site already has, not a widening — and its value is the OFF
 * position, which currently exists nowhere: switch it off and we stop attaching the allow-write
 * filter, and WPForms' own refusal becomes reachable again.
 *
 * Whether the floor should wrap third-party callbacks rather than replace them is issue #210; it is
 * the more general fix and is deliberately not attempted here.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Integration_Settings;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Integrations\AcrossAI_Integration_Ability_Base;

defined( 'ABSPATH' ) || exit;

/**
 * WPForms integration: one tab, eight adopted abilities, one switch.
 *
 * @since 0.0.34
 */
class WPForms extends AcrossAI_Integration_Ability_Base implements AcrossAI_Toolset_Integration {

	/**
	 * The tab_group identifier for the "WPForms" tab on the Ability Library page.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'wpforms';

	/**
	 * WPForms' own filter for permitting writes through its abilities.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const WRITE_FILTER = 'wpforms_integrations_abilities_allow_write';

	/**
	 * The four WPForms abilities that change a form.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const WRITE_ABILITIES = array(
		'wpforms/create-form',
		'wpforms/update-form-settings',
		'wpforms/add-field',
		'wpforms/update-field',
	);

	/**
	 * Category / tab_group identifier.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return self::TAB_GROUP;
	}

	/**
	 * Register the opt-in hooks, then declare this integration's toolset.
	 *
	 * Self-registration through the filter rather than a `new WPForms()` in the registry's
	 * `built_in()`: the base constructor hooks `plugins_loaded` and `acrossai_abilities_api_init`,
	 * so a second instance would push the display-only Library rows twice. One instance is created
	 * in `Main::define_public_hooks()`, the same route ACF takes.
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

		// Attached ALWAYS, unlike enable_filter(), which the base class calls only when the toggle
		// is ON. The refusal is the OFF behaviour, so hanging it off the same hook would mean it
		// could never fire.
		add_filter( 'acrossai_ability_access_refused', array( $this, 'refuse_writes_when_disabled' ), 10, 2 );
	}

	/**
	 * Refuse WPForms' four write abilities while the toggle is off.
	 *
	 * Without this the switch would be decorative, and measurably so. WPForms' own gate lives inside
	 * a `permission_callback` that `AcrossAI_Ability_Override_Processor` replaces, and its execute
	 * callbacks do not re-check — so turning the switch off correctly stops us attaching
	 * {@see self::WRITE_FILTER}, WPForms' `write_enabled()` correctly returns false, and the ability
	 * runs anyway because nothing consults it. Measured before this filter existed: toggle off,
	 * `wpforms_integrations_abilities_allow_write` false, and `wpforms/create-form` still ALLOWED.
	 *
	 * So the refusal is enforced in the layer that actually decides. Deny-only by construction: it
	 * returns the incoming value unless it is refusing.
	 *
	 * @since  0.0.34
	 * @param  bool   $refused Whether access is already refused.
	 * @param  string $slug    Ability slug.
	 * @return bool
	 */
	public function refuse_writes_when_disabled( $refused, $slug ): bool {
		if ( (bool) $refused || ! in_array( (string) $slug, self::WRITE_ABILITIES, true ) ) {
			return (bool) $refused;
		}

		// Nothing to refuse when WPForms is not here; the ability does not exist.
		if ( ! $this->is_plugin_active() ) {
			return (bool) $refused;
		}

		return ! AcrossAI_Integration_Settings::is_enabled( $this->slug() );
	}

	/**
	 * Toolset key.
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
	 * Drops the qualifier the opt-in label carries: that suffix names what the switch governs, which
	 * matters beside the switch and reads as a different product in a list of toolsets.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'WPForms', 'acrossai-abilities-manager' );
	}

	/**
	 * What this toolset covers, for an MCP client.
	 *
	 * States the write switch plainly. An assistant that meets `wpforms_writes_disabled` without
	 * knowing a switch exists either gives up or retries the same call.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'WPForms: list and read forms, inspect the form editing schema, read form statistics, and create forms, add and update fields, and change form settings. These abilities come from WPForms itself, not from this plugin. The four writing abilities depend on the WPForms write switch on the Integrations settings screen; with it off they are refused with wpforms_writes_disabled and the form is unchanged. Form entries, notifications and confirmations are not covered here. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Ability-name prefix WPForms registers under.
	 *
	 * BARE, with no trailing slash. `AcrossAI_Ability_Group_Tagger` takes the segment BEFORE the
	 * first slash and looks that value up, so `'wpforms/'` would never match anything and all eight
	 * abilities would stay in the catch-all while appearing to be claimed. That is not hypothetical:
	 * it is issue #209, where `WPCode` declares `'wpcode/'` and its five abilities are in `other`.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array( 'wpforms' );
	}

	/**
	 * Whether WPForms is present.
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
	 * @since  0.0.34
	 * @return string
	 */
	protected function label(): string {
		return __( 'WPForms (form writing)', 'acrossai-abilities-manager' );
	}

	/**
	 * Whether WPForms is loaded on the current site.
	 *
	 * A compound check on two stable public symbols per SEC-002: a single `class_exists()` is
	 * spoofable by any plugin that happens to define the same name. `wpforms_setting()` is the
	 * second symbol because it is the function WPForms reads the write switch through, so this test
	 * follows the same code path the toggle ultimately affects — the reasoning ACF uses for
	 * `acf_get_setting()`.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function is_plugin_active(): bool {
		return defined( 'WPFORMS_VERSION' ) && function_exists( 'wpforms_setting' );
	}

	/**
	 * Permit WPForms' write abilities.
	 *
	 * Called by the base class from `maybe_enable()` ONLY when the toggle is on AND
	 * `is_plugin_active()` is true. Runs at `plugins_loaded` P20; WPForms reads the filter inside a
	 * permission callback at request time, so attaching here is early enough on the same request.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	protected function enable_filter(): void {
		add_filter( self::WRITE_FILTER, '__return_true' );
	}

	/**
	 * Fixed readonly list of the eight abilities WPForms registers.
	 *
	 * Copied from the registration calls in `src/Integrations/Abilities/Abilities.php` and the Lite
	 * subclass. Display-only: these rows never reach `wp_get_abilities()`, so nothing at runtime
	 * forces them to match, and ACF's equivalent list drifted to two abilities that do not exist.
	 * `Test_Integration_Row_Accuracy` asserts every declared slug resolves whenever WPForms is
	 * active, which is what keeps this honest.
	 *
	 * @since  0.0.34
	 * @return array<int, array{slug: string, label: string, description: string}>
	 */
	protected function abilities(): array {
		return array(
			array(
				'slug'        => 'wpforms/list-forms',
				'label'       => __( 'List Forms', 'acrossai-abilities-manager' ),
				'description' => __(
					'List the WPForms forms on this site with their metadata, filtered by published, draft or trashed status.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'wpforms/get-form',
				'label'       => __( 'Get Form', 'acrossai-abilities-manager' ),
				'description' => __(
					'Read one form: its fields, settings and notifications as WPForms stores them.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'wpforms/describe-editing-schema',
				'label'       => __( 'Describe Editing Schema', 'acrossai-abilities-manager' ),
				'description' => __(
					'Describe the shape WPForms expects when a form is edited — the field types available and the properties each one accepts. Read this before adding or updating a field.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'wpforms/get-form-stats',
				'label'       => __( 'Get Form Stats', 'acrossai-abilities-manager' ),
				'description' => __(
					'Read the entry and conversion figures WPForms records for one form.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'wpforms/create-form',
				'label'       => __( 'Create Form', 'acrossai-abilities-manager' ),
				'description' => __(
					'Create a new WPForms form. Requires the WPForms write switch to be on.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'wpforms/update-form-settings',
				'label'       => __( 'Update Form Settings', 'acrossai-abilities-manager' ),
				'description' => __(
					'Change one form\'s settings, such as its title or confirmation behaviour. Requires the WPForms write switch to be on.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'wpforms/add-field',
				'label'       => __( 'Add Field', 'acrossai-abilities-manager' ),
				'description' => __(
					'Add a field to an existing form. Requires the WPForms write switch to be on.',
					'acrossai-abilities-manager'
				),
			),
			array(
				'slug'        => 'wpforms/update-field',
				'label'       => __( 'Update Field', 'acrossai-abilities-manager' ),
				'description' => __(
					'Change a field on an existing form. Requires the WPForms write switch to be on.',
					'acrossai-abilities-manager'
				),
			),
		);
	}
}
