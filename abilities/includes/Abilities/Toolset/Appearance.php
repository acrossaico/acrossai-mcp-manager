<?php
/**
 * The Appearance Toolset.
 *
 * How the site looks — global styles, templates, navigation, fonts and branding.
 *
 * Four declarations and no behaviour — everything else is
 * {@see Base_Toolset_Ability}. If this class ever needs more than these
 * methods, the shared class is missing something; add it there.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Toolset
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Toolset;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher for the Appearance group.
 */
final class Appearance extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'appearance';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/appearance';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Appearance', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Change how the site looks: global styles and theme.json settings, block templates and template parts, the site editor, block style variations, navigation menus, fonts, widget areas, and site identity such as title, tagline, icon and logo. For installing or updating a theme, use the Updates tool instead. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
}
