<?php
/**
 * SetupRequired — the one thing a server advertises when its type cannot work.
 *
 * Feature 090 (US3). When a server's type has an unmet requirement — the
 * AcrossAI type with `acrossai-abilities-manager` deactivated — the server keeps
 * answering and advertises exactly this ability, whose description and return
 * value both name the plugin that must be installed.
 *
 * **Why an ability rather than an error.** The MCP Adapter resolves a tool
 * BEFORE `mcp_adapter_pre_tool_call` fires: `ToolsHandler::call_tool()` returns
 * `tool_not_found` at the lookup and never reaches the filter. A vanished
 * `toolset/*` therefore cannot be intercepted or rewritten by any hook this
 * plugin owns. What we DO control is the tool list composed at registration, so
 * an unmet requirement swaps that list for one honest entry.
 *
 * Known residual gap, accepted: a client that listed the toolsets BEFORE the
 * sibling was deactivated and calls one immediately still gets the vendor's
 * generic `tool_not_found`. Most agents re-list after an error and then find
 * this. Closing it precisely would mean registering stand-in abilities in the
 * sibling's namespace, which this plugin will not do.
 *
 * **Never unregister the server instead.** Skipping `create_server()` would 404
 * the route and kill a live client session rather than explain itself.
 *
 * SEC-002 — `wp_register_ability()` is site-global, so this ability is
 * explicitly kept out of the general abilities surface: excluded from
 * `ToolAbilities`, hidden from `discover-abilities`, and admitted only to the
 * effective tool list of a server whose own requirement is unmet
 * (`ToolPolicy::compose_effective_tools_for_row()`).
 *
 * Carries no privilege and performs no work — it cannot become a path that
 * skips another ability's `permission_callback` (D24: exposure != authorization).
 *
 * Stateless pure service per the A11 exemption.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * The diagnostic ability shown when a server type's requirement is unmet.
 *
 * @since 0.1.0
 */
final class SetupRequired {

	/**
	 * Ability slug. Plugin-owned namespace — never the sibling's.
	 *
	 * @var string
	 */
	public const SLUG = 'acrossai/setup-required';

	/**
	 * Ability category this plugin owns and registers.
	 *
	 * WP 6.9+ requires a category to be REGISTERED before an ability may be
	 * assigned to it — `WP_Abilities_Registry::register()` emits
	 * `_doing_it_wrong` otherwise. That notice is not a fatal, so it does not
	 * show up in manual testing; PHPUnit's incorrect-usage tracking is what
	 * catches it.
	 *
	 * Deliberately NOT the vendor's `mcp-adapter` category. The adapter
	 * registers that one inside `McpAdapter`, so borrowing it would make this
	 * ability depend on the adapter having booted — and this ability exists
	 * precisely to describe a broken configuration, which is the worst moment
	 * to inherit someone else's boot order.
	 *
	 * @var string
	 */
	public const CATEGORY = 'acrossai-mcp';

	/**
	 * Register the ability. Loader-wired from `Main::define_public_hooks()`
	 * on `wp_abilities_api_init` per A1 — never from a constructor.
	 *
	 * Registered unconditionally: it is admitted to a server's tool list only
	 * by `ToolPolicy`, so registering it costs nothing on a healthy install and
	 * avoids a presence probe whose timing would be load-order dependent.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( self::SLUG ) ) {
			return;
		}

		wp_register_ability(
			self::SLUG,
			array(
				'label'               => __( 'Setup required', 'acrossai-mcp-manager' ),
				'description'         => self::message(),
				'category'            => 'acrossai-mcp',
				'meta'                => array(
					'mcp' => array(
						// NOT public: this must never surface through
						// discover-abilities. It reaches a client only by being
						// placed in a specific server's tool list (SEC-002).
						'public' => false,
						'type'   => 'tool',
					),
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'message' => array(
							'type'        => 'string',
							'description' => __( 'What the site administrator must do before this server can offer any tools.', 'acrossai-mcp-manager' ),
						),
					),
				),
				'execute_callback'    => static function (): array {
					return array( 'message' => self::message() );
				},
				// Same bar as listing the server's tools. This discloses only a
				// public plugin name, and carries no privilege of its own.
				'permission_callback' => static function (): bool {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * The operator-facing sentence, used as BOTH the tool description and its
	 * return value so the message lands whether a client reads the list or
	 * calls the tool.
	 *
	 * Translatable like every other string in the plugin (clarification Q3). An
	 * AI client on a localised site receives it in that site's language;
	 * hardcoding English would leave the only untranslated string in the
	 * plugin, which a future contributor would "fix" without knowing why.
	 *
	 * Discloses the add-on's public name only — no paths, versions, or site
	 * configuration.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function message(): string {
		return __(
			'This MCP server requires the AcrossAI Abilities Manager plugin to be installed and activated. No tools are available until then. Ask the site administrator to install it from the AcrossAI Add-ons page, or to change this server\'s type.',
			'acrossai-mcp-manager'
		);
	}

	/**
	 * Register the ability category this plugin owns.
	 *
	 * Loader-wired to `wp_abilities_api_categories_init` per A1. Must run before
	 * `register()` assigns an ability to it; WP fires the categories hook ahead
	 * of `wp_abilities_api_init`, so the ordering is the platform's, not ours.
	 *
	 * Guarded on the function existing so a site without the Abilities API
	 * degrades silently rather than fataling (§V: optional integrations).
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'AcrossAI MCP', 'acrossai-mcp-manager' ),
				'description' => __( 'Diagnostic entries owned by the AcrossAI MCP Manager plugin.', 'acrossai-mcp-manager' ),
			)
		);
	}
}
