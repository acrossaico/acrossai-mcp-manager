<?php
/**
 * One gateway to the whole site, for a client connected to an MCP server this plugin does not own.
 *
 * Every other Toolset covers one area. This one covers all of them, because of where it is used. A
 * client on somebody else's server already has their tools, and contributing fifteen dispatchers
 * beside them would bury what they shipped. So the areas become a menu this tool returns rather
 * than entries in the client's tool list.
 *
 * **Deliberately host-neutral, and there is exactly one of these.** It was
 * `toolset/elementor-site` until 0.0.42, when a second host would have made the naming a lie. It
 * only ever existed in development, so nothing shipped under it. Nothing about
 * dispatch was ever Elementor-specific — `groups()` spans every group on the site — so a per-host
 * copy would have duplicated the whole class to change a slug and three sentences. One ability slug
 * can be contributed to any number of servers: each host's filter appends it and the adapter builds
 * that server's tool list from slugs, so a new host is one
 * {@see Host_Mcp_Server} subclass and no new Toolset.
 *
 * Host-specific wording therefore lives in `Host_Mcp_Server::instructions()`, which is read at
 * `initialize` and is per host. That is the right split: this description says what the tool
 * reaches, the connect message says how it relates to the tools it is sitting among.
 *
 * The shape is `toolset/integrations`' — answer a bare `discover` with the map, not the contents —
 * applied to every group instead of the plugin ones. Measured on a 447-ability site: the menu is
 * ~751 tokens for thirteen rows against ~6,752 to list the same catalogue flat, and it is what lets
 * a model route to `database` or `files` instead of guessing.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Mcp
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Mcp;

use AcrossAI_Abilities_Manager\Includes\Abilities\Toolset\Base_Toolset_Ability;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Group;
use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher spanning every ability group on the site.
 */
final class Site_Toolset extends Base_Toolset_Ability {

	/**
	 * This Toolset's slug.
	 *
	 * Under `toolset/`, and never under a host's own namespace. Both halves matter.
	 *
	 * `toolset/` is not decoration: `AcrossAI_Ability_Override_Processor::ROUTER_PREFIXES` lists it
	 * as a prefix whose permission callback may never be replaced by an operator override, which a
	 * dispatcher routing to hundreds of abilities needs, and `protect_own_slug()` keys on it too.
	 * Under another prefix this would silently lose both.
	 *
	 * Registering under `elementor/` — or any host's prefix — would squat a namespace that is not
	 * ours, and would be wrong the moment the same tool appears on a second host's server, which is
	 * the whole point of it being one tool.
	 *
	 * `wp-site` rather than `site`: short enough to read well beside a host's own tools, and
	 * distinct from the first-party `toolset/server-guide` it would otherwise be confused with.
	 *
	 * @since 0.0.42
	 * @since 0.0.42 Renamed from `toolset/elementor-site`, which only ever existed in development —
	 *               nothing shipped under it. It was never Elementor-specific.
	 * @var   string
	 */
	public const SLUG = 'toolset/wp-site';

	/**
	 * Identity, not membership.
	 *
	 * No ability carries `wp-site` as its group — membership comes from `groups()`, which is
	 * every group on the site. This is what the Toolset reports as itself and what a slug collision
	 * would be logged against.
	 *
	 * Deliberately not a host's own group name — `elementor`, say, is the real Elementor ability
	 * suite fronted by `toolset/elementor`, and claiming it here would collide with a live
	 * dispatcher.
	 *
	 * @since  0.0.42
	 * @return string
	 */
	protected function group(): string {
		return 'wp-site';
	}

	/**
	 * @since  0.0.42
	 * @return string
	 */
	protected function slug(): string {
		return self::SLUG;
	}

	/**
	 * @since  0.0.42
	 * @return string
	 */
	protected function toolset_label(): string {
		return __( 'WordPress Site Tools', 'acrossai-abilities-manager' );
	}

	/**
	 * Written for a reader who came to this server for something else.
	 *
	 * Host-neutral on purpose. What a client here does not know is that the rest of WordPress is
	 * reachable at all, and that holds whichever server it connected to. What differs per host is
	 * which tools this sits beside, and naming those belongs to
	 * {@see Host_Mcp_Server::instructions()} — read at `initialize`, where host context lives,
	 * whereas this description is cached by the client the moment it connects.
	 *
	 * Until 0.0.42 this named Elementor and told the caller to prefer `elementor-*` for editor
	 * work. That was useful and is not lost: it moved to Elementor's own `instructions()`, the one
	 * place it can be said without being wrong on every other host.
	 *
	 * @since  0.0.42
	 * @return string
	 */
	protected function toolset_description(): string {
		return __( 'Everything this WordPress site can do, beyond whatever this server\'s own tools cover: posts and pages, media, blocks and templates, themes and appearance, settings, users, the database, files, cron, caching, updates and diagnostics — plus the capability of every other plugin installed here, from page builders and SEO to forms, ecommerce and email. Hundreds of abilities, so this does not list them: call action=discover with no arguments and it returns a short menu of areas with a count for each. Then call discover again with area=<area> for one area\'s abilities, or search to match by name across all of them. action=info returns one ability\'s parameters; action=execute runs it. Reach for this whenever the job is not covered by the other tools you were given.', 'acrossai-abilities-manager' );
	}

	/**
	 * Every ability group on the site.
	 *
	 * Read from the registry rather than listed here, so a new integration or a new first-party
	 * group is reachable through this gateway with no edit in this file.
	 *
	 * @since  0.0.42
	 * @return string[]
	 */
	protected function groups(): array {
		return array_values( array_map( 'strval', array_keys( AcrossAI_Ability_Group::counts() ) ) );
	}

	/**
	 * Never part of the AcrossAI server type's default set.
	 *
	 * AcrossAI's own servers already serve the Toolsets directly, each with its own description,
	 * which is a better answer than one gateway in front of all of them. This exists for servers
	 * that cannot do that — ones this plugin does not own.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function is_server_type_default(): bool {
		return false;
	}

	/**
	 * Built for a third-party host, so kept out of our own surfaces entirely.
	 *
	 * {@see Base_Toolset_Ability::is_host_only()} for what that excludes and why. Without it this
	 * would be offered in the transport's Tools picker and named by `toolset/server-guide` as an
	 * empty default — telling a client on an AcrossAI server to call a tool it does not have.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function is_host_only(): bool {
		return true;
	}

	/**
	 * Membership moves with the site, so a cached listing goes stale.
	 *
	 * True for the same reason as `toolset/integrations`, and more so: this spans every group, so
	 * any plugin activation changes the answer.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function is_volatile(): bool {
		return true;
	}

	/**
	 * Lean rows by default — the one change the measurements forced.
	 *
	 * The inherited default returns every field, and a drill into one area then costs **10,299**
	 * tokens for fifty rows, because each carries its full description. Names and labels are
	 * **1,093**. A model asking "what is in `database`?" wants to pick one, not read fifty essays,
	 * and will not think to ask for less — so the cheap answer is the default and detail is paid
	 * for deliberately, via `include_fields`.
	 *
	 * Only when the caller named no fields. An explicit `include_fields` is always honoured as
	 * given, including `include_fields=["description"]` to get the full rows back.
	 *
	 * **`discover` only.** This helper also feeds `action=info`, where the fields ARE the answer —
	 * defaulting there to name and label would strip the input and output schemas off every info
	 * call and leave a model guessing at parameters, which is the one thing info exists to prevent.
	 *
	 * @since  0.0.42
	 * @param  array<string, mixed> $input Caller input.
	 * @return string[]|null
	 */
	protected function requested_fields( array $input ): ?array {
		$fields = parent::requested_fields( $input );

		if ( null !== $fields ) {
			return $fields;
		}

		$action = isset( $input['action'] ) ? (string) $input['action'] : '';

		return 'discover' === $action ? array( 'name', 'label' ) : null;
	}

	/**
	 * Answer a bare `discover` with the areas, not their abilities.
	 *
	 * Modelled on `Toolset\Integrations::discover_overview()`, which solves the same problem for
	 * the plugin groups. The difference is only the vocabulary: the caller narrows with `area`,
	 * which is `plugin` under a name that is not a lie for `database` or `cron`.
	 *
	 * NOT `sub_group`, which was the first draft's mistake and is the one `Integrations` warns
	 * about in a comment: a sub-group is a division WITHIN a group, so it never equals a group
	 * name, and `sub_group=database` returned an empty list with nothing to explain why.
	 *
	 * @since  0.0.42
	 * @param  array<string, mixed> $input Caller input.
	 * @return array<string, mixed>|null
	 */
	protected function discover_overview( array $input ): ?array {
		$narrowed = false;

		foreach ( array( 'area', 'sub_group', 'plugin', 'search', 'card' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== (string) $input[ $key ] ) {
				$narrowed = true;
				break;
			}
		}

		if ( $narrowed ) {
			return null;
		}

		// `member_counts()`, not `AcrossAI_Ability_Group::counts()`: the latter includes abilities
		// this gateway cannot dispatch to. Measured, the `elementor` area advertised 106 while the
		// returned 99. A menu that overstates is worse than a smaller one — a caller cannot tell
		// which rows are real.
		$counts = $this->member_counts( 'discover' );
		$areas  = array();
		$total  = 0;

		foreach ( $counts as $group => $count ) {
			$group = (string) $group;
			$count = (int) $count;

			if ( 0 === $count || $this->group() === $group ) {
				continue;
			}

			$row = array(
				'area'      => $group,
				'abilities' => $count,
			);

			// Label and one-line summary read off the group's own dispatcher rather than restated
			// here — the same technique `Integrations::discover_overview()` and `Guide::toolsets()`
			// use, so a new group needs no edit in this file and the three cannot drift.
			$toolset = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'toolset/' . $group ) : null;

			if ( $toolset instanceof WP_Ability ) {
				$row['label']  = $toolset->get_label();
				$row['covers'] = self::summarise( (string) $toolset->get_description() );
			}

			$areas[] = $row;
			$total  += $count;
		}

		if ( array() === $areas ) {
			return array(
				'action'  => 'discover',
				'group'   => $this->group(),
				'success' => true,
				'areas'   => array(),
				'message' => __( 'No abilities are registered on this site right now.', 'acrossai-abilities-manager' ),
			);
		}

		return array(
			'action'          => 'discover',
			'group'           => $this->group(),
			'success'         => true,
			'areas'           => $areas,
			'total_areas'     => count( $areas ),
			'total_abilities' => $total,
			'hint'            => __( 'Pick an area and call discover again with area=<area>, or pass search=<term> to match by name across every area at once. Rows come back as name and label; add include_fields=["description"] for the full text. Then action=info with ability=<name> for its parameters, and action=execute with ability and parameters to run it.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * One line about what an area covers — enough to choose, not the essay.
	 *
	 * The same trim `Toolset\Guide` applies, and for the same reason: fourteen full descriptions
	 * would cost more than the menu is meant to save. Split on a full stop only — these
	 * descriptions are written as "Subject: detail, detail." and splitting on the colon yields a
	 * stub that tells a model nothing the label did not.
	 *
	 * @since  0.0.42
	 * @param  string $description The dispatcher's full description.
	 * @return string
	 */
	private static function summarise( string $description ): string {
		$limit    = 120;
		$sentence = trim( (string) preg_split( '/(?<=\.)\s+/', $description, 2 )[0] );

		if ( '' === $sentence || mb_strlen( $sentence ) <= $limit ) {
			return $sentence;
		}

		$cut  = mb_substr( $sentence, 0, $limit );
		$last = mb_strrpos( $cut, ' ' );

		return ( false === $last ? $cut : mb_substr( $cut, 0, $last ) ) . '…';
	}
}
