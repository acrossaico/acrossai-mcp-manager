<?php
/**
 * Classic Editor's toolset declaration.
 *
 * The "we supply everything" shape. Classic Editor registers no abilities of its own — verified
 * across the whole plugin, including its JavaScript — so every ability in this group is one of ours
 * and already carries `meta.acrossai.tab_group`. `ability_prefixes()` is therefore empty: claiming
 * `editor` would capture any future ability in that namespace and file it here without anyone
 * deciding to.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's Classic Editor abilities.
 */
final class Classic_Editor implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal Base_Classic_Editor_Ability::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'classic-editor';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'Classic Editor', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Classic Editor: read the effective editor configuration and which layer decided it, change the site-wide default and whether users may choose for themselves, find out which editor a given post will actually open in and why, and check which editors each post type allows. The stored values are not the whole answer — a site that has never saved these settings still behaves as classic, and a per-user or per-post choice can override the site default. Only present when Classic Editor is active. Requires administrator rights. To read or write the raw values directly use the Configuration and Content tools: the settings are ordinary options, the per-user choice is user meta, and the per-post choice is post meta. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Claims no prefixes — every ability in this group is declared by us.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return defined( 'CLASSIC_EDITOR_VERSION' ) && class_exists( 'Classic_Editor' );
	}
}
