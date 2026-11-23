<?php
/**
 * Site Kit's toolset declaration.
 *
 * Site Kit registers no abilities of its own, so unlike Rank Math this claims a prefix that only
 * ever contains ours. It is declared anyway rather than left to the catch-all: `site-kit/*` is a
 * coherent group with its own tab, and letting it fall into Other would bury eleven abilities
 * behind a name that describes none of them.
 *
 * Not an {@see AcrossAI_Integration_Ability_Base}: there is no opt-in switch to own — only the
 * grouping.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.39
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit\Site_Kit_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's Site Kit abilities.
 */
final class Site_Kit implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key.
	 *
	 * Matches `Base_Site_Kit_Ability::TAB_GROUP` and `Toolset\Site_Kit::group()`. All three must
	 * agree or the abilities and their dispatcher land in different groups.
	 *
	 * @since 0.0.39
	 * @var   string
	 */
	public const TAB_GROUP = 'site-kit';

	/**
	 * @since  0.0.39
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.39
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'Site Kit by Google', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.39
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Site Kit by Google: connection and setup status, module activation and dashboard sharing, and reads of Search Console search analytics, Analytics 4 reports, PageSpeed Insights and AdSense earnings. Data is read live from Google using the connection of the WordPress user making the call, so a user who has not connected their own Google account sees no data even as an administrator. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Ability names in the Site Kit namespace.
	 *
	 * @since  0.0.39
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array( 'site-kit' );
	}

	/**
	 * Whether Site Kit is present.
	 *
	 * Same probe `Category_Registrar` uses, so the category and the toolset appear together.
	 *
	 * @since  0.0.39
	 * @return bool
	 */
	public function is_active(): bool {
		return Site_Kit_Context::available();
	}
}
