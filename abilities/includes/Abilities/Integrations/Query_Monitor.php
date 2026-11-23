<?php
/**
 * Query Monitor's toolset declaration.
 *
 * The "we supply everything" shape: Query Monitor registers no abilities of its own — it is an admin
 * debug panel — so every ability in this group is one of ours, declared under
 * `includes/Abilities/Debugging/` and carrying `meta.acrossai.tab_group = 'query-monitor'`. They
 * share that folder with the conflict-testing abilities, which stay in the `diagnostics` group, so
 * `ability_prefixes()` is empty: claiming the `debugging` prefix would wrongly pull those four and
 * the conflict-testing set into one group.
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

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\QueryMonitor\Query_Monitor_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's Query Monitor abilities.
 */
final class Query_Monitor implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key.
	 *
	 * Must equal the `tab_group` the four Query Monitor abilities declare under
	 * `includes/Abilities/Debugging/`. If the two drift, the abilities land in one group and the
	 * dispatcher serves another.
	 *
	 * @since 0.0.43
	 * @var   string
	 */
	public const TAB_GROUP = 'query-monitor';

	/**
	 * @since  0.0.43
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.43
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'Query Monitor', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.43
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Query Monitor: profile one URL on this site and read what it actually did — its database queries with the slowest and duplicated ones, outbound HTTP calls, PHP errors, the template that rendered it, time and memory — plus the PHP/MySQL/WordPress environment, the object-cache backend and hit ratio, and turning Query Monitor\'s own output on or off. Profiling requires administrator rights and runs a single same-host loopback request. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Claims no prefixes — every ability in this group is declared by us, and the `debugging/` prefix
	 * is shared with the conflict-testing abilities that belong to a different group.
	 *
	 * @since  0.0.43
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array();
	}

	/**
	 * Whether Query Monitor is present.
	 *
	 * Detected through the suite's own guard (the `\QM_Collectors` registry), so the toolset and the
	 * abilities agree on what "active" means.
	 *
	 * @since  0.0.43
	 * @return bool
	 */
	public function is_active(): bool {
		return Query_Monitor_Guard::is_active();
	}
}
