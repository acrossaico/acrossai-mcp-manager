<?php
/**
 * The Content Toolset.
 *
 * Posts, pages, custom post types, comments, media, taxonomies and content search.
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
 * Dispatcher for the Content group.
 */
final class Content extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'content';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/content';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Content', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Create, read, update and delete site content — posts, pages, custom post types and their meta and revisions — plus comments and moderation, the media library, categories and tags, and semantic content search and internal linking. Start here for anything a visitor would read. Before changing the content of an existing post, page or custom post type, run content/inspect-post-builder: a page builder such as Elementor keeps its layout outside post_content, so an update here can report success, change nothing a visitor sees, and be reverted the next time the page is saved in the builder. action=discover lists this group (narrow with search, card or sub_group); action=info returns one ability\'s schemas; action=execute runs one.', 'acrossai-abilities-manager' );
	}
}
