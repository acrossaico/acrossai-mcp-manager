<?php
/**
 * Elementor's own abilities — the switch that makes them exist.
 *
 * Elementor 4.x ships an MCP module at `elementor/modules/mcp/` declaring 27 abilities of its own,
 * and registers none of them until a site setting says so. `modules/mcp/module.php:151`:
 *
 *     add_action( 'wp_abilities_api_categories_init', [ $this, 'register_ability_category' ] );
 *
 *     if ( self::is_site_mcp_exposure_enabled() ) {
 *         add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
 *     }
 *
 * Note the asymmetry: the category registers unconditionally while the abilities do not. That is why
 * a site can show `elementor` among the registered ability categories and still hold none of
 * Elementor's abilities — which reads as a bug in our grouping and is not one.
 *
 * The setting is the `elementor_mcp_enabled` option, read by
 * `Elementor\MCP\Composer\Admin\McpSettingsController::is_enabled()`, which returns false when the
 * option is absent. Its own docblock claims the opposite ("Missing or invalid values default to
 * enabled"); the code is authoritative, and the default is off. Elementor surfaces it at
 * Elementor → Elementor MCP (`page=elementor-mcp`).
 *
 * **That option gates two things, and we only want one of them.** It decides whether the abilities
 * register AND whether Elementor creates its MCP server at `/wp-json/elementor/mcp`
 * (`Server_Bootstrap::register_server()`). The abilities are already on the site and reaching them
 * is what this plugin is for. The server is site-wide MCP exposure, a feature Elementor ships off,
 * and switching it on is the site owner's call to make on Elementor's own screen — not a side
 * effect of installing this plugin.
 *
 * So this row does **not** touch the option. Until 0.0.42 it called
 * {@see AcrossAI_Integration_Ability_Base::force_option_true()} to short-circuit the read, which
 * got the abilities and the server together. Three things were wrong with that, and the first is
 * the one that matters: an option a host stores and surfaces in its own settings UI is the site
 * owner's saved answer, not an extension point. Overriding it left Elementor's screen showing a
 * switch nobody had set; it turned on site-wide exposure silently; and because our own transport
 * detector asks `Module::is_site_mcp_exposure_enabled()`, Quick Connect reported Elementor MCP
 * "active" on the strength of our own filter — a self-fulfilling answer.
 *
 * `Module::register_abilities()` is public, so the abilities can be registered directly with no
 * override at all. See {@see register_host_abilities()}.
 *
 * Note the asymmetry in Elementor's module that makes that possible: the `elementor` ability
 * CATEGORY is registered unconditionally while the abilities are not. It is also why a site can
 * list `elementor` among its ability categories and hold none of Elementor's abilities — which
 * reads as a bug in our grouping and is not one.
 *
 * Two of Elementor's 27 never register even with this on: `elementor/create-page` and
 * `elementor/update-page-settings` are names this plugin also declares, and we load first. See
 * issue #261 for that decision; it is not something this switch can fix.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage includes/Abilities/Integrations
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Integrations\AcrossAI_Integration_Ability_Base;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Group;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Elementor's own MCP abilities, leaving its server switch to the site owner.
 *
 * Not `final`, and only for testing. The two host seams — {@see host_mcp_enabled()} and
 * {@see host_module()} — are `protected` so a test can substitute them by subclassing. The
 * alternative was declaring fake `\Elementor\Plugin` and `\Elementor\Modules\Mcp\Module`
 * classes in the suite, and `class_exists( '\Elementor\Plugin' )` is consulted in nine places in
 * this plugin: the whole Elementor ability suite, its category registrar and the bootstrap gate all
 * switch on it. In a shared test process those fakes would convince unrelated suites that Elementor
 * is installed. One keyword is cheaper than that.
 *
 * Nothing outside tests should subclass this. Third-party integrations extend
 * {@see AcrossAI_Integration_Ability_Base} instead.
 */
class Elementor_Opt_In extends AcrossAI_Integration_Ability_Base {

	/**
	 * Group and Library category this integration owns.
	 *
	 * The same key `Toolset\Elementor::group()` uses, so our 81 Elementor abilities and Elementor's
	 * own share one row and one switch.
	 *
	 * @since 0.0.42
	 * @var   string
	 */
	public const TAB_GROUP = 'elementor';

	/**
	 * The option Elementor reads.
	 *
	 * @since 0.0.42
	 * @var   string
	 */
	private const HOST_OPTION = 'elementor_mcp_enabled';

	/**
	 * @since  0.0.42
	 * @return string
	 */
	protected function slug(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.42
	 * @return string
	 */
	protected function label(): string {
		return __( 'Elementor (its own MCP abilities)', 'acrossai-abilities-manager' );
	}

	/**
	 * Seeded on, like every other integration.
	 *
	 * This returned false until 0.0.42, on the argument that switching it on turned on Elementor's
	 * own site-wide MCP exposure — a feature its authors ship off — and that making that decision
	 * from inside an unrelated plugin's update was overreach. That argument was right about the
	 * overreach; it was aimed at the implementation rather than the switch.
	 *
	 * Since 0.0.42 the objection no longer applies: this row registers abilities Elementor already
	 * ships and does not enable its server or write its option, so switching on grants nothing the
	 * site owner has not already installed. The exposure decision stayed with them.
	 *
	 * That leaves the reason to seed on, which is about describing the site honestly. Somebody who
	 * installs Abilities Manager has asked for their site's abilities to be usable; an Elementor
	 * row reading "Enabled · 109 abilities" while quietly withholding 25 of them describes nothing.
	 *
	 * Still reversible in one click, and switching off leaves Elementor's own setting exactly as it
	 * was — now trivially so, because this never touches it.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function seeds_enabled(): bool {
		return true;
	}

	/**
	 * Whether Elementor is present, with the MCP module this switch drives.
	 *
	 * Checks the module rather than just `Elementor\Plugin`: on an Elementor old enough to lack
	 * `modules/mcp/` there is no setting to flip, and a switch that cannot do anything should not
	 * appear.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function is_plugin_active(): bool {
		return class_exists( '\Elementor\Plugin' )
			&& class_exists( '\Elementor\Modules\Mcp\Module' );
	}

	/**
	 * Register Elementor's own abilities, without switching on Elementor's MCP server.
	 *
	 * Elementor gates two separate things behind one option. Its 27 abilities register at
	 * `modules/mcp/module.php:152`, and its MCP server at `/wp-json/elementor/mcp` is created in
	 * `Server_Bootstrap::register_server()` — both on `elementor_mcp_enabled`. We want the
	 * abilities and not the server: the abilities are already on the site and reaching them is what
	 * this plugin is for, while the server is site-wide exposure the owner should switch on
	 * themselves at Elementor → Elementor MCP.
	 *
	 * Until 0.0.42 this called `force_option_true( self::HOST_OPTION )`, which got both. See the
	 * class docblock for why overriding that particular option was the wrong instrument.
	 *
	 * **Priority 15, and it is pinned between two things.**
	 *
	 * Later than our own abilities, which register at 5 (Library processor) and 10 (absorbed
	 * definitions, {@see \AcrossAI_Abilities_Manager\Includes\Main}). Two slugs collide —
	 * `elementor/create-page` and `elementor/update-page-settings` are names we also declare — and
	 * today OURS win because whoever registers first keeps the name. Registering Elementor's set
	 * any earlier silently flips that and changes what those two abilities do: a behaviour change
	 * disguised as a refactor. Issue #261 is about whether that answer is right; this only
	 * preserves it.
	 *
	 * Earlier than the Toolsets at 20, and this half is easy to get wrong. A Toolset's `register()`
	 * calls `has_any_member()`, which resolves group membership through
	 * `AcrossAI_Ability_Group::members()` — and that **memoises the whole grouping for the rest of
	 * the request**. Registering after 20 therefore puts the abilities in the registry while every
	 * Toolset keeps working from a grouping taken before they arrived. Measured at priority 30:
	 * `wp_get_abilities()` held all 27, and `toolset/integrations` with `plugin=elementor` returned
	 * 81 of 106 with only the two colliding slugs — a silent, total miss for anything reached
	 * through a Toolset, which is how this plugin is used.
	 *
	 * The flush below makes that ordering explicit rather than load-bearing, so a future priority
	 * change degrades into a wasted rebuild instead of a silent miss.
	 *
	 * @since  0.0.42
	 * @since  0.0.42 Registers the host's abilities directly instead of forcing its option.
	 * @return void
	 */
	protected function enable_filter(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_host_abilities' ), 15 );
		add_filter( 'acrossai_integration_host_todo', array( $this, 'declare_host_todo' ), 10, 2 );
	}

	/**
	 * Tell the Integrations screen that Elementor's own server switch is still off.
	 *
	 * The cost of not switching it on for the owner is that nothing says where it lives. Quick
	 * Connect already points at it, which is the right place when the goal is connecting a client;
	 * this covers the other route in, where somebody is configuring the Elementor integration and
	 * reasonably expects that screen to mention it.
	 *
	 * Only when it is actually off, so a site that has enabled it sees nothing.
	 *
	 * @since  0.0.42
	 * @param  mixed $todo Outstanding setting collected so far.
	 * @param  mixed $slug The integration slug being described.
	 * @return mixed
	 */
	public function declare_host_todo( $todo, $slug = '' ) {
		if ( self::TAB_GROUP !== $slug || null !== $todo ) {
			return $todo;
		}

		if ( ! $this->is_plugin_active() || $this->host_mcp_enabled() ) {
			return $todo;
		}

		return array(
			'label' => __( "Elementor's own MCP server is switched off. Your abilities work without it; turn it on only if you want an AI client to connect to Elementor's endpoint.", 'acrossai-abilities-manager' ),
			'url'   => admin_url( 'admin.php?page=elementor-mcp' ),
		);
	}

	/**
	 * Ask Elementor's MCP module to register its abilities.
	 *
	 * `Module::register_abilities()` is public and does nothing but loop its own registry calling
	 * `register()` on each entry, so this invokes exactly what Elementor invokes — no
	 * reimplementation to drift, and no ability of theirs declared by us.
	 *
	 * The one thing that makes this possible is an asymmetry in Elementor's own module: the
	 * `elementor` ability CATEGORY is registered unconditionally (`module.php:150`, outside the
	 * `if`), while the abilities are not. WP core silently rejects an ability whose category is
	 * unregistered, so without that the registration would no-op and this whole approach would
	 * fail quietly.
	 *
	 * Resolved lazily rather than captured when the hook was added: `maybe_enable()` runs at
	 * `plugins_loaded` P20 ({@see AcrossAI_Integration_Ability_Base::enable_hook()}) and Elementor
	 * builds its modules later, so there is no instance to hold at that point.
	 *
	 * Every dereference is guarded and the call is wrapped. This reaches into another plugin's
	 * internals, so the failure mode must be "Elementor's abilities are absent", never a fatal on
	 * a site that updated Elementor.
	 *
	 * @since  0.0.42
	 * @return void
	 */
	public function register_host_abilities(): void {
		// Elementor registers its own set when its switch is on. Doing it again would mean 27
		// "already registered" notices on every request for no gain.
		if ( $this->host_mcp_enabled() ) {
			return;
		}

		try {
			$module = $this->host_module();

			if ( ! is_object( $module ) || ! is_callable( array( $module, 'register_abilities' ) ) ) {
				return;
			}

			$module->register_abilities();

			/*
			 * Belt and braces for the priority note on `enable_filter()`. Nothing should have
			 * resolved the grouping this early, so this is normally a no-op that costs one null
			 * assignment. If something ever does resolve it first, this turns a silent "Elementor's
			 * abilities are missing from every Toolset" into a rebuild.
			 */
			AcrossAI_Ability_Group::flush();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'AcrossAI integration [elementor] could not register the host\'s abilities: %s',
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Whether Elementor's own site-wide MCP exposure is switched on.
	 *
	 * Asks Elementor rather than reading `elementor_mcp_enabled` directly, so this answer follows
	 * whatever Elementor decides the option means — its own settings controller treats an absent
	 * value as off, despite a docblock there claiming the opposite.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function host_mcp_enabled(): bool {
		if ( ! is_callable( array( '\Elementor\Modules\Mcp\Module', 'is_site_mcp_exposure_enabled' ) ) ) {
			return false;
		}

		return (bool) \Elementor\Modules\Mcp\Module::is_site_mcp_exposure_enabled();
	}

	/**
	 * Elementor's MCP module, or null when it cannot be reached.
	 *
	 * Resolved through Elementor's modules manager rather than constructed: the module owns its
	 * registry, and building a second one would register ability objects Elementor never made.
	 *
	 * Every dereference is guarded. This walks another plugin's object graph, so the failure mode
	 * must be "Elementor's abilities are absent", never a fatal on a site that updated Elementor.
	 *
	 * @since  0.0.42
	 * @return object|null
	 */
	protected function host_module(): ?object {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( ! is_object( $plugin ) || ! isset( $plugin->modules_manager ) || ! is_object( $plugin->modules_manager ) ) {
			return null;
		}

		if ( ! is_callable( array( $plugin->modules_manager, 'get_modules' ) ) ) {
			return null;
		}

		$module = $plugin->modules_manager->get_modules( 'mcp' );

		return is_object( $module ) ? $module : null;
	}

	/**
	 * What switching this on unlocks.
	 *
	 * Display-only, and deliberately not the full 27: this names the groups rather than every slug,
	 * because Elementor's set moves between releases and a stale list here would describe abilities
	 * that no longer exist. The live set is always what `toolset/elementor` discover returns.
	 *
	 * @since  0.0.42
	 * @return array<int, array{slug: string, label: string, description?: string}>
	 */
	protected function abilities(): array {
		return array(
			array(
				'slug'        => 'elementor/build-composition',
				'label'       => __( 'Build and edit V4 element trees', 'acrossai-abilities-manager' ),
				'description' => __( 'Elementor\'s own authoring path for atomic (V4) elements, plus publishing and preview links.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'        => 'elementor/manage-global-variable',
				'label'       => __( 'V4 global design system', 'acrossai-abilities-manager' ),
				'description' => __( 'Global variables, global CSS classes and site-wide default styles on the active kit.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'        => 'elementor/list-widget-schemas',
				'label'       => __( 'Widget schemas and guidance', 'acrossai-abilities-manager' ),
				'description' => __( 'Per-widget JSON Schemas, dynamic tags, component and asset catalogues, and Elementor\'s own design guidance.', 'acrossai-abilities-manager' ),
			),
		);
	}
}
