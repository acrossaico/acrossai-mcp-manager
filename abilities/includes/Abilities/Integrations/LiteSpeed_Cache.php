<?php
/**
 * LiteSpeed Cache's toolset declaration.
 *
 * The "we supply everything" shape: LiteSpeed Cache registers no abilities of its own — verified
 * across v7.9.1, no `wp_register_ability` and no abilities-api reference anywhere — so every ability
 * in this group is one of ours, declared under `includes/Abilities/LiteSpeed/` and already carrying
 * `meta.acrossai.tab_group`. Nothing needs tagging, which is why `ability_prefixes()` is empty:
 * claiming the `litespeed` prefix would capture any ability LiteSpeed might add later and file it
 * under our label without anyone deciding to.
 *
 * This declaration still owns the group key, the display name and the MCP description, and it is what
 * generates the dispatcher — so there is no `includes/Abilities/Toolset/` file for this suite.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\LiteSpeed_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's LiteSpeed Cache abilities.
 */
final class LiteSpeed_Cache implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key.
	 *
	 * Must equal `Base_LiteSpeed_Ability::TAB_GROUP`, which is what all 60 abilities declare. If the
	 * two drift, the abilities land in one group and the dispatcher serves another.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'litespeed-cache';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * Display name.
	 *
	 * Declared rather than derived: the label rule title-cases the group key, which would render
	 * `litespeed-cache` as "Litespeed Cache" and lose the internal capital in a product name.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'LiteSpeed Cache', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'LiteSpeed Cache: purge the page cache by target, URL, post or taxonomy; read and tune the cache settings, TTLs, exclusion lists and vary rules; control the CSS/JS/HTML optimisation pipeline and its exclusion lists; configure lazy loading; run and configure the crawler; report on database cleanup opportunities and autoloaded options; manage the object and browser cache; and apply or roll back configuration presets. Every ability requires administrator rights. Turning caching off, applying a preset and restoring a backup change the whole site at once and must be confirmed. Reads report settings as rows carrying each key, its value and whether this suite can write it. Database cleanup and all QUIC.cloud services — image optimisation, CCSS/UCSS, CDN — are deliberately absent. Narrow action=discover with sub_group: ls-purge, ls-cache, ls-optimize, ls-media, ls-crawler, ls-database, ls-object and ls-toolbox. action=execute runs one ability.',
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
	 * Whether LiteSpeed Cache is present.
	 *
	 * Delegated to the guard so every LiteSpeed symbol in the suite is named in exactly one directory.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return LiteSpeed_Guard::is_available();
	}
}
