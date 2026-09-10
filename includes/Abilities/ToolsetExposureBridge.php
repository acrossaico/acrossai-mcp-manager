<?php
/**
 * Applies per-server exposure to abilities reached through a Toolset.
 *
 * The Abilities tab is per-server; the sibling Integrations screen is global.
 * Global-off already stops an ability registering at all, so a Toolset cannot
 * see it. Per-server-off is different: the ability is registered and healthy,
 * and only this server is supposed to be blind to it.
 *
 * That distinction was enforced only in the three plugin-owned meta tools
 * (`discover-abilities`, `get-ability-info`, `execute-ability`), which call
 * `AbilityHelpers::apply_exposure_filter()`. A Toolset is a fourth way into the
 * same catalogue and answered to none of it: an ability hidden for this server
 * still appeared in `toolset-users` discover and still ran through its execute.
 *
 * The sibling publishes `acrossai_toolset_member_visible` for exactly this, so
 * the policy stays here — this plugin owns per-server exposure, the sibling
 * owns the Toolsets — and both paths resolve through the same trait method, so
 * they cannot drift.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.1
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide singleton per A11. Public methods are hook callbacks;
 * wire in `Includes\Main::define_admin_hooks()`.
 *
 * @since 0.1.1
 */
final class ToolsetExposureBridge {

	use AbilityHelpers;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Toolset action → the context vocabulary this plugin's filter uses.
	 *
	 * The two contracts agree except that a Toolset calls it `info` where the
	 * meta tool is `get_ability_info`. Mapping here means one policy callback
	 * on `acrossai_mcp_is_ability_exposed` covers both surfaces.
	 *
	 * @var array<string, string>
	 */
	private const CONTEXT_MAP = array(
		'discover' => 'discover',
		'info'     => 'get_info',
		'execute'  => 'execute',
	);

	/**
	 * Singleton accessor.
	 *
	 * @since 0.1.1
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * `acrossai_toolset_member_visible` callback.
	 *
	 * Narrows only. An ability the sibling has already decided to hide stays
	 * hidden — this is a second gate on the same catalogue, not a chance to
	 * re-admit something, which keeps deny-precedence the same here as in
	 * `ToolExposureGate`.
	 *
	 * Outside an MCP request there is no server whose policy could apply, so
	 * the decision is left alone: WP-CLI, cron and a direct
	 * `wp_get_ability( 'toolset/users' )->execute()` behave as they did.
	 *
	 * **Exposure is not authorization.** Returning true does not bypass the
	 * target ability's own `permission_callback`; the Toolset still runs it
	 * before dispatch and again inside `WP_Ability::execute()`.
	 *
	 * @since 0.1.1
	 *
	 * @param bool        $visible Sibling's decision so far.
	 * @param \WP_Ability $ability The member being considered.
	 * @param string      $group   Toolset group being listed (unused).
	 * @param string      $context One of 'discover' | 'info' | 'execute'.
	 * @return bool
	 */
	public function filter_member_visible( $visible, $ability, $group, $context ): bool {
		unset( $group );

		if ( ! $visible ) {
			return false;
		}

		if ( ! $ability instanceof WP_Ability ) {
			return (bool) $visible;
		}

		if ( null === CurrentServerHolder::instance()->get_server_id() ) {
			return (bool) $visible;
		}

		return self::apply_exposure_filter(
			$ability,
			self::CONTEXT_MAP[ $context ] ?? 'discover'
		);
	}
}
