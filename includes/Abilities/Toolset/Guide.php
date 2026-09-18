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
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes/Abilities/Toolset
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities\Toolset;

use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\AbilityGroup;
use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * A one-call briefing for a client connected to an AcrossAI server.
 */
final class Guide {

	/**
	 * The group the add-on files ungrouped abilities under.
	 *
	 * Duplicated deliberately — see the note at its use site.
	 */
	private const CATCH_ALL_GROUP = 'other';

	/**
	 * Whether this copy ended up registering the guide.
	 *
	 * False both when another plugin already held the slug and when there was
	 * nothing to describe. Either way this copy must stop contributing its slug
	 * to the admin's tool pool, or the Tools tab would offer a tool that does
	 * not exist on this site.
	 *
	 * @var bool
	 */
	private bool $registered = false;

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
		if ( 'acrossai' !== $server_type ) {
			return $instructions;
		}

		return sprintf(
			/* translators: 1: the server guide's ability name, 2: the integrations toolset name. */
			__( 'This server exposes Toolsets. Each one takes action=discover to list what it holds, action=info to read an ability\'s parameters, and action=execute to run it — the same three everywhere, so learn them once. Call %1$s first: it names every Toolset on this site with what it covers and how to reach it. Note that your tool list was fixed when you connected and cannot be refreshed, so a capability may exist here without a tool of its own in your list — %2$s reaches those.', 'acrossai-mcp-manager' ),
			self::SLUG,
			'toolset/integrations'
		);
	}

	/**
	 * Register the guide, unless something already holds its slug.
	 *
	 * The slug check is what lets this plugin and the AcrossAI Abilities
	 * Manager add-on both carry a guide through the changeover: whichever gets
	 * there first keeps the slug and the other stands down rather than
	 * clobbering it. The collision fires the same observability action the
	 * dispatchers use, so a site carrying two copies says so in one place.
	 *
	 * @since 0.0.34
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		if ( wp_has_ability( self::SLUG ) ) {
			/** This action is documented in includes/Abilities/Toolset/Base_Toolset_Ability.php */
			do_action( 'acrossai_toolset_slug_collision', self::SLUG, 'server-guide' );
			return;
		}

		// Same rule the dispatchers apply to themselves: a Toolset for a group
		// with no abilities "would advertise a subject area that does not
		// exist, so it is not created at all". A guide is that case taken to
		// its limit — with no Toolsets registered it is a table of contents for
		// an empty book, and it would sit in the admin's tool list one line
		// under `mcp-adapter/server-guide`, which is the guide that DOES apply.
		//
		// This is what keeps a site with only this plugin unchanged. The guide
		// appears the moment any Toolset does, which is when the AcrossAI
		// Abilities Manager add-on arrives.
		if ( ! $this->describes_anything() ) {
			return;
		}

		wp_register_ability(
			self::SLUG,
			array(
				'label'               => __( 'Toolset Guide', 'acrossai-mcp-manager' ),
				'description'         => __( 'Read this first. Explains how every other tool on this server is called — the three actions, which parameters belong to which, and their real limits — then lists what this site holds, with an ability count per toolset. Also says what the two unusual toolsets are for: Other, which holds abilities that matched no group, and Integrations, which reaches plugin capability including plugins missing from your tool list. Takes no input and changes nothing.', 'acrossai-mcp-manager' ),
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

		$this->registered = true;
	}

	/**
	 * Whether any Toolset exists for this guide to describe.
	 *
	 * Checked at registration time, by which point every dispatcher has already
	 * had its turn — they attach to `wp_abilities_api_init` at the same
	 * priority and Registrar constructs this one last, deliberately, so that
	 * the others have declared themselves before it describes them.
	 *
	 * @since  0.3.6
	 * @return bool
	 */
	private function describes_anything(): bool {
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			if ( self::SLUG !== $slug && 0 === strpos( (string) $slug, 'toolset/' ) ) {
				return true;
			}
		}

		return false;
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
			'actions'  => array(
				'discover' => __( 'List what a toolset holds. Narrow it rather than paging.', 'acrossai-mcp-manager' ),
				'info'     => __( 'Return one ability\'s input and output schemas. Accepts a batch.', 'acrossai-mcp-manager' ),
				'execute'  => __( 'Run one ability, passing its own input under `parameters`.', 'acrossai-mcp-manager' ),
			),
			'params'   => array(
				'discover' => array( 'search', 'card', 'sub_group', 'plugin', 'limit', 'offset', 'include_fields' ),
				'info'     => array( 'ability', 'abilities', 'include_fields' ),
				'execute'  => array( 'ability', 'parameters' ),
			),
			'limits'   => array(
				'default_limit' => Base_Toolset_Ability::DEFAULT_LIMIT,
				'max_limit'     => Base_Toolset_Ability::MAX_LIMIT,
				'max_batch'     => Base_Toolset_Ability::MAX_BATCH,
			),
			'workflow' => array(
				__( 'List a toolset\'s abilities: call it with action=discover. Narrow with search, or with sub_group when its description names sub-groups.', 'acrossai-mcp-manager' ),
				__( 'Read one ability\'s parameters: action=info with ability=<name>, or abilities=[...] for several at once.', 'acrossai-mcp-manager' ),
				__( 'Run it: action=execute with ability=<name> and parameters={...} exactly as info described them.', 'acrossai-mcp-manager' ),
				__( 'Every toolset below answers those same three actions. Learn it once.', 'acrossai-mcp-manager' ),
			),
			'notes'    => array(
				__( 'Filters AND together, and all of them are discover-only.', 'acrossai-mcp-manager' ),
				__( '`sub_group` is a division WITHIN a group and never equals a group name — passing a group there returns an empty list. Use `plugin` on Integrations to narrow to one plugin.', 'acrossai-mcp-manager' ),
				__( 'Call info before execute. Guessed parameters fail at the ability\'s own validation, which tells you less than the schema would have.', 'acrossai-mcp-manager' ),
				__( 'Pass `parameters: {}` for an ability that takes no input.', 'acrossai-mcp-manager' ),
				__( 'discover never returns schemas. That is what info is for.', 'acrossai-mcp-manager' ),
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

		$rows = array();

		foreach ( AbilityGroup::counts() as $group => $count ) {
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
				'call'      => isset( $indirect[ (string) $group ] )
					? sprintf(
						/* translators: 1: toolset name, 2: plugin group slug. */
						__( 'Call %1$s directly if it is in your tool list; if it is not, call toolset/integrations with plugin=%2$s.', 'acrossai-mcp-manager' ),
						$name,
						$group
					)
					: __( 'Call it directly — it is in the default set, so your tool list has it.', 'acrossai-mcp-manager' ),
			);
		}

		return $rows;
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
		$limit = 160;
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
	private function special(): array {
		$special = array();

		// Only describe the catch-all when it actually exists. It has no
		// dispatcher on a site where every ability found a group — which is the
		// normal, healthy state — and describing a tool the caller cannot call
		// is the exact fault this guide exists to prevent.
		// Mirrors the integration registry's CATCH_ALL_GROUP, which stays in the
		// add-on with the rest of the integration contract. Held as a literal
		// rather than imported: this plugin must not depend on the sibling, and
		// the value is one word that has never changed. `Test_*` locks it.
		$catch_all = 'toolset/' . self::CATCH_ALL_GROUP;

		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $catch_all ) ) {
			$special[ $catch_all ] = __( 'Abilities that matched no group. Membership is by fallthrough, never assignment, so its contents depend entirely on which plugins are installed — call discover before assuming it is irrelevant. Anything here is a normal ability with its own permissions.', 'acrossai-mcp-manager' );
		}

		$special['toolset/integrations'] = __( 'Capability from installed plugins, and the route to plugins your tool list does not show. Your tool list was fixed when you connected and cannot be refreshed, so a plugin installed since then has no tool of its own from your point of view — it is reachable here. Call discover for the plugin list, then discover again with plugin=<name>, then execute.', 'acrossai-mcp-manager' );

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
				'then' => __( 'No ability of that name is registered. Re-run discover; do not retry the same name.', 'acrossai-mcp-manager' ),
			),
			array(
				'code' => 'ability_not_in_group',
				'then' => __( 'It exists but belongs elsewhere. The message names the correct group — call that toolset instead.', 'acrossai-mcp-manager' ),
			),
			array(
				'code' => 'ability_not_visible',
				'then' => __( 'It exists and is hidden from this server by the operator. Retrying will not help; report it rather than working around it.', 'acrossai-mcp-manager' ),
			),
			array(
				'code' => 'ability_threw',
				'then' => __( 'The ability itself failed. The message is its own — read it before retrying.', 'acrossai-mcp-manager' ),
			),
			array(
				'code' => 'invalid_action',
				'then' => __( 'Use discover, info or execute.', 'acrossai-mcp-manager' ),
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
		return $this->contribute_own_slug( $slugs );
	}

	/**
	 * Keep this out of the sitewide abilities surface AND out of every group.
	 *
	 * The second half is what matters here: `AbilityGroup::resolve()`
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
		$slugs = is_array( $slugs ) ? $slugs : array();

		if ( ! $this->registered ) {
			return array_values( $slugs );
		}

		$slugs[] = self::SLUG;

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
		if ( ! is_array( $types ) || ! isset( $types['acrossai'] ) || ! is_array( $types['acrossai'] ) ) {
			return $types;
		}

		/** This guard is explained in includes/Abilities/Toolset/Base_Toolset_Ability.php */
		if ( ! $this->registered ) {
			return $types;
		}

		$tools   = isset( $types['acrossai']['tools'] ) && is_array( $types['acrossai']['tools'] )
			? $types['acrossai']['tools']
			: array();
		$tools[] = self::SLUG;

		$types['acrossai']['tools'] = array_values( array_unique( $tools ) );

		return $types;
	}
}
