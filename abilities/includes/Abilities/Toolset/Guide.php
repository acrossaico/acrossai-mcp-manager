<?php
/**
 * The AcrossAI server's guide — how to call a Toolset, and what is on this one.
 *
 * An assistant connecting here is handed fifteen dispatchers and no
 * instructions. It has to infer the calling convention, the parameters and
 * their phases, and where a given job lives — from tool descriptions alone.
 *
 * Those descriptions currently carry the burden: thirty of them end by
 * restating the same three actions, and every connection pays for all thirty
 * whether relevant or not. Said once here, the assistant gets a fuller answer
 * for less, including the parts no description mentions — the real limits, the
 * self-correctable error codes, and what `other` and `integrations` actually
 * hold.
 *
 * Deliberately NOT a Toolset. A Toolset dispatches to a group of abilities;
 * this dispatches to nothing and takes no input. It borrows the three
 * declarations a Toolset makes — tool-level, server-type default, protected
 * slug — because those are about being a tool, not about being a dispatcher.
 *
 * Read-only, idempotent, changes nothing.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Toolset
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Toolset;

use AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\AcrossAI_Toolset_Integrations;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Group;
use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * A one-call briefing for a client connected to an AcrossAI server.
 *
 * Not final since 0.0.42. A guide published onto a third-party MCP server needs the same four
 * sections — the calling convention, what this site holds, the two unusual Toolsets, the error
 * codes — and a different slug, label, description and connect message. Those five are the
 * overridable surface; everything else is shared, so the two guides cannot drift about how a
 * Toolset is called.
 */
class Guide {

	/**
	 * This ability's slug.
	 *
	 * Named to match the transport's guide for the other server type, which is
	 * `mcp-adapter/server-guide`. One shape — `<tool family>/server-guide` — so
	 * an assistant that has met one of these servers knows what to look for on
	 * the next, whatever type it is.
	 *
	 * In the `toolset/` namespace even though it dispatches to nothing, because
	 * that prefix carries meaning beyond dispatch:
	 * `AcrossAI_Ability_Override_Processor::ROUTER_PREFIXES` lists `toolset/`
	 * and `mcp-adapter/` as the slugs whose permission callback may never be
	 * replaced by an operator override. A guide is transport, so it belongs on
	 * that list — and under `acrossai/` it silently was not, while its opposite
	 * number already was.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const SLUG = 'toolset/server-guide';

	/**
	 * Wire registration. The same three a Toolset makes, and for once all three.
	 *
	 * The protected-slug hook is not optional. Without it
	 * `AcrossAI_Ability_Group_Tagger` finds no `meta.acrossai.tab_group` on this
	 * ability, falls through to `CATCH_ALL_GROUP`, and the guide turns up as a
	 * member INSIDE `toolset/other` — a dispatcher listing the thing that
	 * describes dispatchers.
	 *
	 * @since 0.0.34
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 20 );
		add_filter( 'acrossai_mcp_manager_tool_abilities', array( $this, 'declare_tool_level_ability' ) );
		add_filter( 'acrossai_mcp_server_types', array( $this, 'declare_server_type_tool' ) );
		add_filter( 'acrossai_abilities_manager_protected_slugs', array( $this, 'protect_own_slug' ) );
		add_filter( 'acrossai_mcp_server_instructions', array( $this, 'declare_server_instructions' ), 10, 2 );
	}

	/**
	 * What a client connecting to an AcrossAI-type server is told, before it
	 * calls anything.
	 *
	 * The transport passes a server's instructions through MCP `initialize`,
	 * which is the only thing an assistant reads without first choosing to call
	 * a tool. Everything else this plugin publishes — the guide, the Toolset
	 * descriptions — depends on it deciding to look.
	 *
	 * This plugin supplies the text because this plugin owns the vocabulary:
	 * Toolsets, the three actions, and `toolset/integrations` are ours to name.
	 * The transport names only its own tools and never ours, which is the same
	 * two-filter boundary the rest of this integration keeps.
	 *
	 * Appends; the operator's own description is already the first line.
	 *
	 * @since  0.0.34
	 * @param  mixed $instructions Guidance built so far.
	 * @param  mixed $server_type  The server row's type slug.
	 * @return mixed
	 */
	public function declare_server_instructions( $instructions, $server_type ) {
		if ( $this->is_host_only() || 'acrossai' !== $server_type ) {
			return $instructions;
		}

		return sprintf(
			/* translators: 1: the server guide's ability name, 2: the integrations toolset name. */
			__( 'This server exposes Toolsets. Each one takes action=discover to list what it holds, action=info to read an ability\'s parameters, and action=execute to run it — the same three everywhere, so learn them once. Call %1$s first: it names every Toolset on this site with what it covers and how to reach it. Note that your tool list was fixed when you connected and cannot be refreshed, so a capability may exist here without a tool of its own in your list — %2$s reaches those.', 'acrossai-abilities-manager' ),
			static::SLUG,
			'toolset/integrations'
		);
	}

	/**
	 * Whether this guide belongs to somebody else's MCP server rather than ours.
	 *
	 * Same question and same three exclusions as
	 * {@see Base_Toolset_Ability::is_host_only()}: a guide written for a third-party host's
	 * audience must not join the `acrossai` server type, must not appear in the transport's Tools
	 * picker, and must not supply the connect message for AcrossAI servers — which already have
	 * this guide's first-party sibling. Its slug stays protected either way.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function is_host_only(): bool {
		return false;
	}

	/**
	 * This guide's tool label.
	 *
	 * @since  0.0.42
	 * @return string
	 */
	protected function label(): string {
		return __( 'Toolset Guide', 'acrossai-abilities-manager' );
	}

	/**
	 * This guide's tool description.
	 *
	 * @since  0.0.42
	 * @return string
	 */
	protected function description(): string {
		return __( 'Read this first. Explains how every other tool on this server is called — the three actions, which parameters belong to which, and their real limits — then lists what this site holds, with an ability count per toolset. Also says what the two unusual toolsets are for: Other, which holds abilities that matched no group, and Integrations, which reaches plugin capability including plugins missing from your tool list. Takes no input and changes nothing.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since 0.0.34
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( static::SLUG ) ) {
			return;
		}

		wp_register_ability(
			static::SLUG,
			array(
				'label'               => $this->label(),
				'description'         => $this->description(),
				'category'            => Base_Toolset_Ability::CATEGORY,
				'meta'                => array(
					'mcp' => array(
						// Transport, not cargo: it reaches a client by being in
						// a server's tool list, never through discovery.
						'public' => false,
						'type'   => 'tool',
					),
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					// Load-bearing: core applies only the top-level default and
					// rejects null once a schema exists, so without this every
					// zero-argument call fails.
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'how_to_call' => array( 'type' => 'object' ),
						'toolsets'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object' ),
							'description' => 'Every toolset on this site. `call` says how to reach each one: directly if your tool list carries it, otherwise through toolset/integrations.',
						),
						'special'     => array( 'type' => 'object' ),
						'errors'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'   => array( 'how_to_call', 'toolsets', 'special', 'errors' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public function execute(): array {
		return array(
			'how_to_call' => $this->how_to_call(),
			'toolsets'    => $this->toolsets(),
			'special'     => $this->special(),
			'errors'      => $this->errors(),
		);
	}

	/**
	 * The calling convention, stated once.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	private function how_to_call(): array {
		return array(
			'actions' => array(
				'discover' => __( 'List what a toolset holds. Narrow it rather than paging.', 'acrossai-abilities-manager' ),
				'info'     => __( 'Return one ability\'s input and output schemas. Accepts a batch.', 'acrossai-abilities-manager' ),
				'execute'  => __( 'Run one ability, passing its own input under `parameters`.', 'acrossai-abilities-manager' ),
			),
			'params'  => array(
				'discover' => array( 'search', 'card', 'sub_group', 'plugin', 'limit', 'offset', 'include_fields' ),
				'info'     => array( 'ability', 'abilities', 'include_fields' ),
				'execute'  => array( 'ability', 'parameters' ),
			),
			'limits'  => array(
				'default_limit' => Base_Toolset_Ability::DEFAULT_LIMIT,
				'max_limit'     => Base_Toolset_Ability::MAX_LIMIT,
				'max_batch'     => Base_Toolset_Ability::MAX_BATCH,
			),
			'workflow' => array(
				__( 'List a toolset\'s abilities: call it with action=discover. Narrow with search, or with sub_group when its description names sub-groups.', 'acrossai-abilities-manager' ),
				__( 'Read one ability\'s parameters: action=info with ability=<name>, or abilities=[...] for several at once.', 'acrossai-abilities-manager' ),
				__( 'Run it: action=execute with ability=<name> and parameters={...} exactly as info described them.', 'acrossai-abilities-manager' ),
				__( 'Every toolset below answers those same three actions. Learn it once.', 'acrossai-abilities-manager' ),
			),
			'notes'   => array(
				__( 'Filters AND together, and all of them are discover-only.', 'acrossai-abilities-manager' ),
				__( '`sub_group` is a division WITHIN a group and never equals a group name — passing a group there returns an empty list. Use `plugin` on Integrations to narrow to one plugin.', 'acrossai-abilities-manager' ),
				__( 'Call info before execute. Guessed parameters fail at the ability\'s own validation, which tells you less than the schema would have.', 'acrossai-abilities-manager' ),
				__( 'Pass `parameters: {}` for an ability that takes no input.', 'acrossai-abilities-manager' ),
				__( 'discover never returns schemas. That is what info is for.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Every toolset on this site, with its size.
	 *
	 * Labels are read off each group's own registered dispatcher rather than
	 * restated here, so the guide and the tool it names cannot drift — the same
	 * technique `Integrations::discover_overview()` uses.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	private function toolsets(): array {
		// Which groups are NOT in this server type's default set — the same
		// source `toolset/integrations` spans, so the two can never disagree
		// about which half a toolset falls in.
		$indirect = apply_filters( 'acrossai_toolset_integration_groups', array() );
		$indirect = is_array( $indirect ) ? array_flip( array_map( 'strval', $indirect ) ) : array();

		$rows   = array();
		$counts = AcrossAI_Ability_Group::counts();

		foreach ( $counts as $group => $count ) {
			$name    = 'toolset/' . $group;
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;

			// A group with no registered dispatcher is not callable, so naming
			// it would send a model at a tool that does not exist.
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			$rows[] = array(
				'name'      => $name,
				'label'     => $ability->get_label(),
				'covers'    => self::summarise( $ability->get_description() ),
				'abilities' => (int) $count,
				// Naming a toolset without saying how to reach it is the same
				// mistake as naming one that does not exist. A non-default
				// toolset is a real, callable tool — but only if the client's
				// tool list happens to carry it, and a list fixed at connect
				// time usually will not. Say which route to take rather than
				// letting the caller discover the answer by failing.
				// Both routes, always, for the ones that have two. A plugin
				// toolset is a real tool AND reachable through Integrations —
				// which route works depends on whether this client's tool list
				// carries it, and only the client knows that. Naming one route
				// would be a guess; naming both lets it pick.
				'call'      => $this->call_route( $name, (string) $group, isset( $indirect[ (string) $group ] ), false ),
			);
		}

		// Counts is built by walking abilities, so a group holding none has no
		// key there at all. Default Toolsets register regardless, so the map is
		// no longer the whole list — and a tool the caller can see but this
		// guide never names is the precise fault the guide exists to prevent.
		foreach ( self::empty_groups( $counts ) as $group ) {
			$name    = 'toolset/' . $group;
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;

			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			$rows[] = array(
				'name'      => $name,
				'label'     => $ability->get_label(),
				'covers'    => self::summarise( $ability->get_description() ),
				'abilities' => 0,
				'call'      => $this->call_route( $name, $group, false, true ),
			);
		}

		return $rows;
	}

	/**
	 * How to reach one toolset from where the reader is sitting.
	 *
	 * Overridable because the honest answer depends on which server the client connected to, and
	 * getting it wrong is the one fault this guide exists to prevent. On an AcrossAI server the
	 * default set IS the client's tool list, so "call it directly" is true. On a third-party host
	 * that contributes only a gateway, it is false for every row — the client has two tools, and
	 * naming thirteen others as directly callable sends it at twelve that do not exist.
	 *
	 * @since  0.0.42
	 * @param  string $name     The toolset's ability name.
	 * @param  string $group    Its group slug.
	 * @param  bool   $indirect Whether it is outside the server type's default set.
	 * @param  bool   $empty    Whether its group currently holds nothing.
	 * @return string
	 */
	protected function call_route( string $name, string $group, bool $indirect, bool $empty ): string {
		if ( $indirect ) {
			return sprintf(
				/* translators: 1: toolset name, 2: plugin group slug. */
				__( 'Call %1$s directly if it is in your tool list; if it is not, call toolset/integrations with plugin=%2$s.', 'acrossai-abilities-manager' ),
				$name,
				$group
			);
		}

		if ( $empty ) {
			return __( 'Call it directly — it is in the default set, so your tool list has it. It holds nothing at the moment, and that changes when a plugin or theme is activated, so call discover rather than ruling it out for the rest of this session.', 'acrossai-abilities-manager' );
		}

		return __( 'Call it directly — it is in the default set, so your tool list has it.', 'acrossai-abilities-manager' );
	}

	/**
	 * Registered dispatchers whose group holds nothing right now.
	 *
	 * Only defaults can be here: a per-plugin Toolset with an empty group does
	 * not register at all, so it is not a tool and must not be named.
	 *
	 * **Membership is decided by the dispatcher's own marker, never by the slug
	 * prefix.** `toolset/` is a shared namespace: the transport registers
	 * `toolset/setup-required` there, a diagnostic it serves ONLY while this
	 * add-on is missing and removes from every healthy server. Matching on the
	 * prefix listed it here as an empty default and told a model to call a tool
	 * it will never be given — the precise fault this guide exists to prevent.
	 * `meta.acrossai.toolset` is set by `args()` on every dispatcher and by
	 * nothing else; the group tagger already relies on it for the same reason.
	 *
	 * `server-guide` carries no such marker, so it is excluded without being
	 * named. `integrations` does carry one and is described under `special()`,
	 * so it is skipped explicitly.
	 *
	 * @since  0.0.38
	 * @param  array<string, int> $counted Groups already named, keyed by group.
	 * @return array<int, string>
	 */
	private static function empty_groups( array $counted ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$skip   = array(
			'server-guide' => true,
			'integrations' => true,
		);
		$groups = array();

		foreach ( wp_get_abilities() as $ability ) {
			$name = (string) $ability->get_name();

			if ( 0 !== strpos( $name, 'toolset/' ) ) {
				continue;
			}

			$meta = $ability->get_meta_item( 'acrossai' );

			if ( ! is_array( $meta ) || empty( $meta['toolset'] ) ) {
				continue;
			}

			// A dispatcher built for a third-party MCP server. It is registered, and it carries the
			// Toolset marker, but no AcrossAI server advertises it — so a client reading this guide
			// will never have the tool. Naming it is the fault this guide exists to prevent, and it
			// reaches here because such a dispatcher spans every group and therefore has members
			// while owning no group of its own, which is exactly the shape `$counted` misses.
			if ( ! empty( $meta['host_only'] ) ) {
				continue;
			}

			$group = substr( $name, strlen( 'toolset/' ) );

			if ( isset( $counted[ $group ] ) || isset( $skip[ $group ] ) ) {
				continue;
			}

			$groups[] = $group;
		}

		sort( $groups );

		return $groups;
	}

	/**
	 * One line about what a toolset covers — enough to choose, not the essay.
	 *
	 * Derived from the toolset's own description rather than restated here, so a
	 * new toolset needs no edit in this file and the two can never disagree.
	 * Full descriptions run to 250 tokens each; fifteen of them would make this
	 * guide cost more than the tool list it is meant to explain.
	 *
	 * Takes the opening sentence, which every toolset description leads with,
	 * and trims it at a word boundary when it runs long. The caller already has
	 * the full text for any toolset in its own tool list — this is for choosing
	 * between them, and for the ones it cannot see.
	 *
	 * @since  0.0.34
	 * @param  string $description The toolset's full description.
	 * @return string
	 */
	private static function summarise( string $description ): string {
		$limit    = 160;
		// Split on a FULL STOP only. These descriptions are written as
		// "Subject: detail, detail, detail." — the colon introduces the content
		// rather than ending a sentence, and splitting on it yields a useless
		// stub ("Work with the database:") that tells a model nothing the label
		// did not already.
		$sentence = trim( (string) preg_split( '/(?<=\\.)\\s+/', $description, 2 )[0] );

		if ( '' === $sentence ) {
			return '';
		}

		if ( mb_strlen( $sentence ) <= $limit ) {
			return $sentence;
		}

		$cut  = mb_substr( $sentence, 0, $limit );
		$last = mb_strrpos( $cut, ' ' );

		return ( false === $last ? $cut : mb_substr( $cut, 0, $last ) ) . '…';
	}

	/**
	 * The two toolsets whose contents are not what their name suggests.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	protected function special(): array {
		$special = array();

		// The catch-all is a default, so it registers even on a site where every
		// ability found a group and it holds nothing. The guard remains for the
		// one case that still leaves it unregistered — another plugin claiming
		// the slug first — because describing a tool the caller cannot call is
		// the exact fault this guide exists to prevent.
		$catch_all = 'toolset/' . AcrossAI_Toolset_Integrations::CATCH_ALL_GROUP;

		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $catch_all ) ) {
			$special[ $catch_all ] = __( 'Abilities that matched no group. Membership is by fallthrough, never assignment, so its contents depend entirely on which plugins are installed — call discover before assuming it is irrelevant. It is often empty, and it fills and empties as plugins and themes are activated, so an earlier answer does not settle it. Anything here is a normal ability with its own permissions.', 'acrossai-abilities-manager' );
		}

		$special['toolset/integrations'] = __( 'Capability from installed plugins, and the route to plugins your tool list does not show. Your tool list was fixed when you connected and cannot be refreshed, so a plugin installed since then has no tool of its own from your point of view — it is reachable here. Its contents move with the site: activating or deactivating a plugin changes them, so call discover again rather than reusing an earlier answer. Call discover for the plugin list, then discover again with plugin=<name>, then execute.', 'acrossai-abilities-manager' );

		return $special;
	}

	/**
	 * The error codes worth recognising, and what to do about each.
	 *
	 * Every one of these is self-correctable, which is the point of returning a
	 * code rather than a sentence. `ability_not_in_group` even names the group
	 * the ability actually belongs to.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, string>>
	 */
	private function errors(): array {
		return array(
			array(
				'code' => 'ability_not_found',
				'then' => __( 'No ability of that name is registered. Re-run discover; do not retry the same name.', 'acrossai-abilities-manager' ),
			),
			array(
				'code' => 'ability_not_in_group',
				'then' => __( 'It exists but belongs elsewhere. The message names the correct group — call that toolset instead.', 'acrossai-abilities-manager' ),
			),
			array(
				'code' => 'ability_not_visible',
				'then' => __( 'It exists and is hidden from this server by the operator. Retrying will not help; report it rather than working around it.', 'acrossai-abilities-manager' ),
			),
			array(
				'code' => 'ability_threw',
				'then' => __( 'The ability itself failed. The message is its own — read it before retrying.', 'acrossai-abilities-manager' ),
			),
			array(
				'code' => 'invalid_action',
				'then' => __( 'Use discover, info or execute.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Advertise this as a tool-level entry.
	 *
	 * @since  0.0.34
	 * @param  mixed $slugs Slugs collected so far.
	 * @return mixed
	 */
	public function declare_tool_level_ability( $slugs ) {
		if ( $this->is_host_only() ) {
			return $slugs;
		}

		return $this->contribute_own_slug( $slugs );
	}

	/**
	 * Keep this out of the sitewide abilities surface AND out of every group.
	 *
	 * The second half is what matters here: `AcrossAI_Ability_Group::resolve()`
	 * drops protected slugs, which is what stops the guide being tagged into
	 * the catch-all and listed inside `toolset/other`.
	 *
	 * @since  0.0.34
	 * @param  mixed $slugs Slugs collected so far.
	 * @return mixed
	 */
	public function protect_own_slug( $slugs ) {
		return $this->contribute_own_slug( $slugs );
	}

	/**
	 * @since  0.0.34
	 * @param  mixed $slugs Slugs collected so far.
	 * @return array<int, string>
	 */
	private function contribute_own_slug( $slugs ): array {
		$slugs   = is_array( $slugs ) ? $slugs : array();
		$slugs[] = static::SLUG;

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Join the `acrossai` type's default tool set.
	 *
	 * Appends only. Unlike a Toolset this never CREATES the entry: if no
	 * dispatcher has run yet there is no label or description to supply, and a
	 * half-built entry would replace the transport's placeholder with something
	 * worse than it had.
	 *
	 * @since  0.0.34
	 * @param  mixed $types Types collected so far.
	 * @return mixed
	 */
	public function declare_server_type_tool( $types ) {
		if ( $this->is_host_only() ) {
			return $types;
		}

		if ( ! is_array( $types ) || ! isset( $types['acrossai'] ) || ! is_array( $types['acrossai'] ) ) {
			return $types;
		}

		$tools   = isset( $types['acrossai']['tools'] ) && is_array( $types['acrossai']['tools'] )
			? $types['acrossai']['tools']
			: array();
		$tools[] = static::SLUG;

		$types['acrossai']['tools'] = array_values( array_unique( $tools ) );

		return $types;
	}
}
