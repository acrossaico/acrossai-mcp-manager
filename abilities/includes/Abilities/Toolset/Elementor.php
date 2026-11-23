<?php
/**
 * The Elementor Toolset.
 *
 * Elementor page building, templates, forms and site settings.
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
 * Dispatcher for the Elementor group.
 */
final class Elementor extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'elementor';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/elementor';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Elementor', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Work with Elementor: build and edit pages, manage templates and global widgets, read form submissions, manage custom code and site settings, clear its cache and toggle maintenance mode. Elementor stores its pages as its own JSON document, not as post_content — use this tool rather than the Content or Blocks tools for anything on an Elementor page. Only present when Elementor is active. This group is large, so narrow action=discover with sub_group: elementor-elements (element tree and widget inserts), elementor-documents (whole-document read and write), elementor-templates (templates, import/export, theme builder conditions), elementor-kits (kits and global styles), elementor-guidance (widget catalog, style guide, render context), elementor-design-audit (read-only design analysis), elementor-design-fixes (subtree mutations that apply those findings), elementor-system (cache, URL replace, maintenance, experiments), elementor-custom-code and elementor-forms (both Elementor Pro). action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
	/**
	 * Not part of the server type's default set.
	 *
	 * This dispatcher exists because Elementor is installed, so including it
	 * would make the default set differ per site and change on activation —
	 * and a connected MCP client caches `tools/list` with no way to be told it
	 * moved.
	 *
	 * Declared HERE rather than inherited: this class is hand-written and listed
	 * in the bootstrap's `$claimed` array precisely so `Integration_Toolset` does
	 * not also register the group, which means it never inherits that class's
	 * opt-out. An `instanceof Integration_Toolset` check elsewhere would miss it.
	 *
	 * Still a registered tool, still addable by hand, and still listed and
	 * runnable through `toolset/integrations`.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function is_server_type_default(): bool {
		return false;
	}
}
