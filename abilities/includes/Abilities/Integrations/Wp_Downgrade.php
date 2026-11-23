<?php
/**
 * WP Downgrade's toolset declaration.
 *
 * The "we supply everything" shape. WP Downgrade is 230 lines of procedural PHP that defines no
 * classes and registers no abilities — three options and two filters over the core update transient.
 * So every ability in this group is one of ours, declared under `includes/Abilities/Versions/` and
 * carrying `meta.acrossai.tab_group = 'wp-downgrade'`.
 *
 * `ability_prefixes()` is empty for the same two reasons as the WP Rollback declaration: the host
 * ships nothing to claim, and the `versions/` namespace is shared with the rollback abilities, which
 * belong to that other group.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpDowngrade\Wp_Downgrade_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's core version-pinning abilities.
 *
 * @since 0.0.45
 */
final class Wp_Downgrade implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal the `tab_group` the core-pin abilities declare.
	 *
	 * @since 0.0.45
	 * @var   string
	 */
	public const TAB_GROUP = 'wp-downgrade';

	/**
	 * @since  0.0.45
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'WP Downgrade', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Hold WordPress core on a version you choose, instead of whatever is latest. Read the current pin, set one, or clear it. The important thing to understand: a pin does not move core — WP Downgrade only makes update checks offer the pinned version, and the move happens when a core update runs. Core only; it does not touch plugins or themes. This is the difference from core/rollback-wp-core, which moves core down once and is then undone by the next update check.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Nothing to claim: the host plugin ships no abilities and defines no classes.
	 *
	 * @since  0.0.45
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array();
	}

	/**
	 * @since  0.0.45
	 * @return bool
	 */
	public function is_active(): bool {
		return Wp_Downgrade_Guard::is_active();
	}
}
