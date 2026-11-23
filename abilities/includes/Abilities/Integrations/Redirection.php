<?php
/**
 * Redirection's toolset declaration.
 *
 * The "we supply everything" shape: the Redirection plugin registers no abilities of its own — it is
 * a REST-API-driven admin screen — so every ability in this group is one of ours, declared under
 * `includes/Abilities/Redirection/` and already carrying `meta.acrossai.tab_group`. Nothing needs
 * tagging, which is why `ability_prefixes()` is empty: claiming the `redirects` prefix would capture
 * any ability Redirection might add later and file it under our label without anyone deciding to.
 *
 * This declaration owns the group key, the display name and the MCP description, and it is what
 * generates the dispatcher — so there is no hand-written `includes/Abilities/Toolset/` file for this
 * suite; the generic `Integration_Toolset` serves it.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.43
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's Redirection abilities.
 */
final class Redirection implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key.
	 *
	 * Must equal `Base_Redirection_Ability::TAB_GROUP`, which all eight abilities declare. If the two
	 * drift, the abilities land in one group and the dispatcher serves another.
	 *
	 * @since 0.0.43
	 * @var   string
	 */
	public const TAB_GROUP = 'redirection';

	/**
	 * @since  0.0.43
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * Display name.
	 *
	 * @since  0.0.43
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'Redirection', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.43
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Redirection: create, list, update and delete URL redirects, read the 404 error log to find the URLs a site needs redirects for, read the redirect hit log, list redirect groups, and read Redirection\'s settings. Redirects affect the live site and every ability requires administrator rights; deleting a redirect is irreversible and gated behind a confirmation. Narrow action=discover with sub_group: redirects-manage (the redirect lifecycle), redirects-logs (404s and hits) and redirects-settings (groups and options). action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Claims no prefixes — every ability in this group is declared by us.
	 *
	 * @since  0.0.43
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array();
	}

	/**
	 * Whether Redirection is present.
	 *
	 * `Red_Item` rather than a version constant: it is the model every ability reaches through, so its
	 * presence also proves Redirection's includes are live.
	 *
	 * @since  0.0.43
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( '\Red_Item' );
	}
}
