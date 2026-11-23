<?php
/**
 * The All-in-One WP Migration Toolset.
 *
 * Archive state, inventory, exposure, and exporting or labelling an archive.
 *
 * Four declarations and no behaviour — everything else is
 * {@see Base_Toolset_Ability}. If this class ever needs more than these
 * methods, the shared class is missing something; add it there.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Toolset
 * @since      0.0.35
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Toolset;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher for the Cache group.
 */
final class All_In_One extends Base_Toolset_Ability {

	/**
	 * @return string
	 */
	protected function group(): string {
		return 'all-in-one-wp-migration';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/all-in-one-wp-migration';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'All-in-One WP Migration', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Whether this site can be recovered through All-in-One WP Migration: what archives exist and how recent they are, whether they are reachable over HTTP, and exporting, labelling or removing one. Restoring is part of their paid Unlimited Extension, and the ability says so rather than failing. action=discover lists this group; action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}

	/**
	 * Not part of the server type's default set.
	 *
	 * This dispatcher exists because All-in-One WP Migration is installed, so including it
	 * would make the default set differ per site and change on activation —
	 * and a connected MCP client caches `tools/list` with no way to be told it
	 * moved. Measured: with All-in-One WP Migration deactivated this Toolset was still being
	 * declared into the `acrossai` type and offered in the Tools picker, while
	 * Elementor's and Rank Math's correctly were not.
	 *
	 * Declared HERE rather than inherited: this class is hand-written and listed
	 * in the bootstrap's `$claimed` array precisely so `Integration_Toolset` does
	 * not also register the group, which means it never inherits that class's
	 * opt-out. An `instanceof Integration_Toolset` check elsewhere would miss it.
	 *
	 * Still a registered tool, still addable by hand, and still listed and
	 * runnable through `toolset/integrations`.
	 *
	 * @since  0.0.37
	 * @return bool
	 */
	protected function is_server_type_default(): bool {
		return false;
	}
}
