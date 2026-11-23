<?php
/**
 * The Site Kit Toolset.
 *
 * Site Kit by Google — connection status, modules, and Google's own search, traffic
 * and speed data.
 *
 * Four declarations and no behaviour — everything else is {@see Base_Toolset_Ability}.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Toolset
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Toolset;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher for the Site Kit group.
 */
final class Site_Kit extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'site-kit';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/site-kit';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Site Kit by Google', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Work with Site Kit by Google: check whether it is set up and whether the current user has connected their own Google account, list and switch modules, read dashboard sharing, and read live Google data — Search Console search analytics, Analytics 4 reports, PageSpeed Insights and AdSense earnings. Start with site-kit/get-status: it reports what is connected and names the one thing to do next, which is what every other ability here depends on. Only present when Site Kit is active. Narrow action=discover with sub_group: site-kit-status (connection and setup), site-kit-modules (modules, settings, sharing, datapoint discovery) and site-kit-data (search, traffic, speed and earnings). action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}

	/**
	 * Not part of the server type's default set.
	 *
	 * This dispatcher exists because Site Kit is installed, so including it would make
	 * the default set differ per site and change on activation — and a connected MCP
	 * client caches `tools/list` with no way to be told it moved.
	 *
	 * Still a registered tool, still addable by hand, and still listed and runnable
	 * through `toolset/integrations`.
	 *
	 * @since  0.0.39
	 * @return bool
	 */
	protected function is_server_type_default(): bool {
		return false;
	}
}
