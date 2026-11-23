<?php
/**
 * The Blocks Toolset.
 *
 * Block editor authoring — patterns, generation, analysis and block-tree mutation.
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
 * Dispatcher for the Blocks group.
 */
final class Blocks extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'blocks';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/blocks';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Blocks', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Work with the block editor: read and mutate a post\'s block tree, insert and move and remove blocks, create and apply block patterns and reusable blocks, generate sections and landing pages, and analyse or validate existing content. These abilities all rewrite post_content, so run content/inspect-post-builder first on any existing post: a page built with Elementor or another page builder has no block tree to edit, and the write will not show up. For theme-level design such as global styles and templates, use the Appearance tool instead. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}
}
