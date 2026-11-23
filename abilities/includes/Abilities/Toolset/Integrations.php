<?php
/**
 * The Integrations Toolset — a directory of plugin capability, and a way to run it.
 *
 * Every other Toolset covers one area of WordPress. This one covers every OTHER
 * Toolset that is not part of the server type's default set: the per-plugin
 * dispatchers that come and go as plugins are installed.
 *
 * It exists because of a limitation on the other side of the wire. An MCP client
 * caches `tools/list` when it connects and there is no way to tell it the list
 * changed — the adapter advertises `tools.listChanged: false` and its
 * server-to-client channel is unimplemented. Measured, with controls: a client
 * given both corrections ignored the notification anyway.
 *
 * So a client that connected before WooCommerce was installed will never see
 * `toolset/woocommerce`, however long it stays connected. This Toolset is always
 * in the default set, so that client always has it — and through it, everything
 * it cannot see. It **runs** abilities as well as listing them; a directory that
 * only describes leaves the caller exactly where it started.
 *
 * The two halves are complementary by construction. `Base_Toolset_Ability`'s
 * `is_server_type_default()` decides what leaves the menu, and the same answer
 * decides what appears here, via `acrossai_toolset_integration_groups`. A new
 * per-plugin Toolset joins both by declaring neither.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Toolset
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Toolset;

use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Group;
use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher spanning every non-default Toolset.
 */
final class Integrations extends Base_Toolset_Ability {

	/**
	 * Identity, not membership.
	 *
	 * No ability carries `integrations` as its group — membership comes from
	 * `groups()`. This is what the Toolset reports as itself, what its filters
	 * are keyed on, and what a slug collision would be logged against.
	 *
	 * @return string
	 */
	protected function group(): string {
		return 'integrations';
	}

	/**
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/integrations';
	}

	/**
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'Integrations', 'acrossai-abilities-manager' );
	}

	/**
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Capabilities that come from the plugins installed on this site — page builders, SEO, ecommerce, forms, email delivery, code snippets and whatever else is here. The contents depend entirely on which plugins this site runs, so call action=discover first: it returns the list of plugins, not a wall of abilities. That list also moves while you are connected — activating or deactivating a plugin adds or removes entries — so call discover again rather than reusing an earlier answer. Then call action=discover again with plugin=<name> to see one plugin\'s abilities, or pass search to look across all of them at once. If a plugin has its own toolset in your tool list, prefer calling that directly — it carries the fuller description. If it does not, run the ability from here: everything listed is executable through this tool. action=info returns schemas; action=execute runs one ability.', 'acrossai-abilities-manager' );
	}

	/**
	 * Every group whose Toolset opted out of the default set.
	 *
	 * Collected from the Toolsets themselves rather than from a list kept here,
	 * so adding an integration needs no edit in this file.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	protected function groups(): array {
		/**
		 * Filter the groups reachable through `toolset/integrations`.
		 *
		 * Every non-default Toolset appends its own group
		 * ({@see Base_Toolset_Ability::declare_integration_group()}). Hook this
		 * to add a group whose abilities are registered without a Toolset, or
		 * to withhold one.
		 *
		 * @since 0.0.34
		 * @param string[] $groups Groups collected so far.
		 */
		$groups = apply_filters( 'acrossai_toolset_integration_groups', array() );

		return is_array( $groups ) ? array_values( array_unique( array_map( 'strval', $groups ) ) ) : array();
	}

	/**
	 * Every group here belongs to a plugin, so the contents move with the site.
	 *
	 * This is the Toolset most likely to be read once and remembered, because it
	 * is the one a caller reaches for when its cached tool list is already out of
	 * date — exactly the caller least able to notice the answer has moved again.
	 *
	 * @since  0.0.38
	 * @return bool
	 */
	protected function is_volatile(): bool {
		return true;
	}

	/**
	 * Answer a bare `discover` with the plugins, not their abilities.
	 *
	 * Listing every ability across every plugin would be hundreds of rows for a
	 * caller who asked "what is here?". The useful first answer is the map.
	 *
	 * Once the caller narrows — `plugin` for one of them, or `search` across all
	 * of them — the normal listing takes over, and returns exactly what that
	 * plugin's own Toolset would have returned.
	 *
	 * `plugin` rather than `sub_group` deliberately: a sub-group is a division
	 * WITHIN a group (`elementor-elements`), so it can never equal a group name.
	 * Telling a caller to pass `sub_group: elementor` would send it to an empty
	 * result with nothing to explain why.
	 *
	 * Each row carries BOTH routes. A client holding a current tool list should
	 * call the named toolset directly; one holding a stale list cannot, and uses
	 * `plugin` here instead. Saying so explicitly costs one field and saves the
	 * model a guess.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Caller input.
	 * @return array<string, mixed>|null
	 */
	protected function discover_overview( array $input ): ?array {
		$narrowed = ( isset( $input['plugin'] ) && '' !== (string) $input['plugin'] )
			|| ( isset( $input['sub_group'] ) && '' !== (string) $input['sub_group'] )
			|| ( isset( $input['search'] ) && '' !== (string) $input['search'] )
			|| ( isset( $input['card'] ) && '' !== (string) $input['card'] );

		if ( $narrowed ) {
			return null;
		}

		$counts = AcrossAI_Ability_Group::counts();

		// Lean by default. Each row's `description` is that plugin's full Toolset
		// description — around 250 tokens for Elementor alone — and a caller
		// asking "what is here?" needs the names and sizes, not three essays. On
		// this site the default answer costs ~157 tokens against ~885 untrimmed.
		//
		// Descriptions are one `include_fields` away, and the hint says so. The
		// default is chosen because a model will not think to ask: an unprompted
		// caller should get the cheap answer, and pay for detail deliberately.
		$fields = $this->requested_fields( $input );

		if ( null === $fields ) {
			$fields = array( 'toolset', 'label', 'abilities' );
		}
		$plugins = array();
		$total   = 0;

		foreach ( $this->groups() as $group ) {
			$count = isset( $counts[ $group ] ) ? (int) $counts[ $group ] : 0;

			if ( 0 === $count ) {
				continue;
			}

			$row = array(
				'toolset'   => 'toolset/' . $group,
				'plugin'    => $group,
				'abilities' => $count,
			);

			// Read the label and description off the group's own registered
			// Toolset rather than restating them here. It is the same string a
			// client with a current tool list would see, so the two can never
			// drift, and it works for hand-written Toolsets as well as
			// generated ones.
			$toolset = function_exists( 'wp_get_ability' ) ? wp_get_ability( $row['toolset'] ) : null;

			if ( $toolset instanceof WP_Ability ) {
				$row['label']       = $toolset->get_label();
				$row['description'] = $toolset->get_description();
			}

			// `include_fields` is documented as trimming a discover response, and
			// this IS a discover response. Honoured through the shared helper so
			// the two paths cannot drift. It matters more here than in a normal
			// listing: these rows carry a full Toolset description each, and a
			// caller that only wants the names should not pay for three of them.
			$plugins[] = $this->trim_to( $row, $fields, array( 'plugin', 'toolset' ) );

			$total += $count;
		}

		if ( array() === $plugins ) {
			return array(
				'action'  => 'discover',
				'group'   => $this->group(),
				'success' => true,
				'plugins' => array(),
				'message' => __( 'No plugins on this site contribute abilities beyond the built-in toolsets.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'action'          => 'discover',
			'group'           => $this->group(),
			'success'         => true,
			'plugins'         => $plugins,
			'total_plugins'   => count( $plugins ),
			'total_abilities' => $total,
			'hint'            => __( 'Call discover again with plugin=<name> for one plugin\'s abilities, or search to match across all of them. Add include_fields=["description"] here for what each plugin covers. Prefer the named toolset if it is in your tool list; if it is not, run the ability from here with action=execute.', 'acrossai-abilities-manager' ),
		);
	}
}
