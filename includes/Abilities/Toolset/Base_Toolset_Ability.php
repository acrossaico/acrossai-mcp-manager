<?php
/**
 * The one abstract class every Toolset extends.
 *
 * A Toolset is a dispatcher: a single ability covering one ability group,
 * answering three questions about that group and nothing else — what is in
 * here (`discover`), what does this one need (`info`), and run this one
 * (`execute`). Thirteen of them replace a generic entry point that returns
 * the whole ~450-ability catalogue in one unfiltered response.
 *
 * **Everything shared lives here.** Schemas, dispatch, member resolution,
 * search, card and sub-group filtering, pagination and all three permission
 * layers. A subclass declares four things — its group, its slug, its label
 * and its description — and carries no behaviour. If a subclass needs
 * anything more, this class is missing something; add it here (Constitution
 * §VI).
 *
 * **Toolsets register directly rather than through the Library pipeline.**
 * `Ability_Definition::push_definition()` is what makes an ability register,
 * but the same definitions build the Integrations cards — so a Toolset that
 * went through it would appear as a row inside a card, which FR-043 forbids.
 * Registering here excludes them at the source instead of relying on every
 * consumer of that catalogue to filter them out.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage AcrossAI_MCP_Manager/includes/Abilities/Toolset
 * @since      0.0.34
 */

namespace AcrossAI_MCP_Manager\Includes\Abilities\Toolset;

use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\AbilityGroup;
use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\InputNormalizer;
use WP_Ability;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour for every Toolset.
 */
abstract class Base_Toolset_Ability {

	/**
	 * The ability category every Toolset declares.
	 *
	 * Deliberately its own, holding no real abilities. That is what decouples
	 * a Toolset from the operator settings applying to the abilities it
	 * dispatches to — see DEC-ABILITY-GROUP-IDENTIFIER-LOAD-BEARING.
	 *
	 * @var string
	 */
	public const CATEGORY = 'acrossai-toolset';

	/**
	 * Default page size for `discover`.
	 *
	 * @var int
	 */
	// PUBLIC so `Guide` can report the limits this class actually enforces
	// rather than restating them. An advertised number that has drifted from
	// the enforced one is worse than none: a caller plans around it and is
	// silently clamped.
	public const DEFAULT_LIMIT = 50;

	/**
	 * Largest page a caller may request.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 200;

	/**
	 * Most abilities describable in one `info` call.
	 *
	 * @var int
	 */
	public const MAX_BATCH = 20;

	/**
	 * Per-request member memo, keyed by group and context.
	 *
	 * @var array<string, array<int, WP_Ability>>
	 */
	private array $memo = array();

	/**
	 * Whether another plugin was found holding this Toolset's slug.
	 *
	 * Deliberately the negative. Keying the list on "did I register?" looks
	 * safer and does not work: the transport builds it during admin page setup,
	 * which runs BEFORE `wp_abilities_api_init`, so every Toolset would still
	 * be reporting "not yet registered" and none would be declared. Verified
	 * against the live Abilities tab.
	 *
	 * So the slug is contributed by default and withdrawn only once
	 * `register()` has positively found someone else holding it — hiding
	 * another plugin's ability being the one outcome actually worth avoiding.
	 *
	 * @var bool
	 */
	private bool $slug_taken_by_other = false;

	/**
	 * Wire registration. Mirrors Ability_Definition, which also hooks here.
	 *
	 * Priority 20 — after the Library processor (5) and DB abilities (10), so
	 * that anything a Toolset might dispatch to already exists. Membership is
	 * still resolved per call, so this ordering is convenience rather than a
	 * dependency.
	 *
	 * @since 0.0.34
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 20 );
		add_filter( 'acrossai_mcp_manager_tool_abilities', array( $this, 'declare_tool_level_ability' ) );
		add_filter( 'acrossai_abilities_manager_protected_slugs', array( $this, 'protect_own_slug' ) );
		add_filter( 'acrossai_mcp_server_types', array( $this, 'declare_server_type_tool' ) );
		add_filter( 'acrossai_toolset_integration_groups', array( $this, 'declare_integration_group' ) );
	}

	/**
	 * Name this Toolset's group as one `toolset/integrations` should span.
	 *
	 * Only non-default Toolsets answer, which makes the two halves of this
	 * feature exactly complementary: whatever step one removed from the server
	 * type's default set, step two makes reachable through the directory. A new
	 * per-plugin Toolset joins both by declaring neither — it simply inherits
	 * the opt-out and lands here automatically.
	 *
	 * Same shape and same reason as the three declarations beside it: a Toolset
	 * is the only thing that knows its own group, so it says so itself rather
	 * than having the list repeated somewhere that can drift.
	 *
	 * @since  0.0.34
	 * @param  mixed $groups Groups collected so far.
	 * @return mixed
	 */
	public function declare_integration_group( $groups ) {
		if ( ! is_array( $groups ) ) {
			return $groups;
		}

		if ( $this->slug_taken_by_other || $this->is_server_type_default() ) {
			return $groups;
		}

		$groups[] = $this->group();

		return array_values( array_unique( $groups ) );
	}

	/**
	 * Declare this Toolset as a tool-level ability to the connected transport.
	 *
	 * A Toolset is one of the entries an MCP server advertises in `tools/list`;
	 * the abilities behind it travel through it rather than beside it. The
	 * transport's list drives two opposite presentations, and both are what we
	 * want: its Abilities tab hides these (a per-ability Exposed toggle on a
	 * dispatcher would no-op or break it), while its Tools tab offers ONLY
	 * these for curation, which is precisely the choice an operator should be
	 * making.
	 *
	 * The hook belongs to acrossai-mcp-manager. Filtering a hook that plugin
	 * may never fire costs nothing, so this is registered unconditionally
	 * rather than guarded on its presence.
	 *
	 * Each Toolset contributes its own slug. The alternative — one callback
	 * walking wp_get_abilities() for meta.acrossai.toolset — reads better but
	 * cannot work here: the transport builds this list during admin page setup,
	 * before `wp_abilities_api_init` has fired, so the walk would find an empty
	 * registry and declare nothing. Contributing a known string needs no
	 * registry at all, which is the whole point.
	 *
	 * A slug is withheld only when `register()` has found another plugin
	 * holding it. An unregistered Toolset still contributes: naming a slug
	 * nothing has claimed is inert, whereas withholding on "not registered yet"
	 * breaks the common case above.
	 *
	 * @since  0.0.34
	 * @param  mixed $slugs Slugs collected so far.
	 * @return string[]
	 */
	public function declare_tool_level_ability( $slugs ): array {
		return $this->contribute_own_slug( $slugs );
	}

	/**
	 * Contribute this Toolset to the transport's `acrossai` server type.
	 *
	 * The transport ships that type as a PLACEHOLDER — a label and a `requires`
	 * pointing at this plugin, with an empty tool list — precisely so it never
	 * hardcodes our vocabulary. Without this callback the type resolves to the
	 * transport's own fallback, and "Reset to Type Defaults" on an AcrossAI
	 * server restores the three mcp-adapter protocol tools instead of the
	 * Toolsets. Correct, but not what the label promises.
	 *
	 * Same shape and same reason as the two declarations either side of it: a
	 * Toolset is the only thing that knows its own slug, so it says so itself
	 * rather than having the list repeated somewhere that can drift.
	 *
	 * Contributed UNCONDITIONALLY, like its siblings. An earlier attempt gated
	 * this on whether the Toolset had actually registered an ability, to keep
	 * groups with no members out of the list. That made the answer depend on
	 * whether the transport resolved its type registry before or after
	 * `wp_abilities_api_init` — on a REST request it resolved first and every
	 * Toolset silently dropped out. Filtering a declared slug that never became
	 * an ability is the transport's job: it is the side that knows when
	 * abilities are registered.
	 *
	 * The FIRST Toolset to run also supplies the entry's label and description,
	 * because the transport's dedup is slug-keyed LAST-WINS — our entry replaces
	 * the placeholder wholesale, so it must carry everything the placeholder
	 * did. `requires` is deliberately dropped: the requirement is THIS plugin,
	 * and by the time this callback runs it is satisfied by definition.
	 *
	 * `is_default` is deliberately NOT carried over. The placeholder's own
	 * flag, whatever it says, is the transport's to decide — see the inline
	 * note at the assignment below.
	 *
	 * @since  0.0.34
	 * @param  mixed $types Types collected so far, keyed by slug.
	 * @return mixed The list with this Toolset's slug appended to `acrossai`.
	 */
	public function declare_server_type_tool( $types ) {
		if ( ! is_array( $types ) ) {
			return $types;
		}

		if ( $this->slug_taken_by_other ) {
			return $types;
		}

		if ( ! isset( $types['acrossai'] ) || ! is_array( $types['acrossai'] ) ) {
			$types['acrossai'] = array();
		}

		if ( empty( $types['acrossai']['label'] ) ) {
			$types['acrossai']['label'] = __( 'AcrossAI', 'acrossai-mcp-manager' );
		}

		if ( empty( $types['acrossai']['description'] ) ) {
			$types['acrossai']['description'] = __(
				'Abilities grouped into Toolsets — one dispatcher per area, each routing to the abilities behind it.',
				'acrossai-mcp-manager'
			);
		}

		// The requirement is this plugin. If this code is running, it is met.
		$types['acrossai']['requires'] = null;

		// NOTE `is_default` is deliberately NOT set here.
		//
		// This callback used to force it true, which meant every new server on
		// a site with this add-on installed was created as an AcrossAI server —
		// and the transport could not decide otherwise, because this filter
		// runs after its own registry and won.
		//
		// Which type a new server STARTS as is a server question, and servers
		// are the transport's half of the boundary. This plugin contributes the
		// type's label, description, tools and requirement — its own vocabulary
		// — and stops there. The transport declares its preferred type in
		// `ServerTypes::seed()`, and an operator who wants AcrossAI picks it in
		// the form or flips that one key.

		$tools = isset( $types['acrossai']['tools'] ) && is_array( $types['acrossai']['tools'] )
			? $types['acrossai']['tools']
			: array();

		// Only DEFAULT Toolsets go in the type's tool set. This one line decides
		// what the transport's "Reset to Type Defaults" restores, so a Toolset
		// that is not a default is still registered, still a tool, and still
		// addable by hand from the picker — it is simply not handed out.
		//
		// Per-plugin Toolsets opt out (see Integration_Toolset), which is what
		// makes the default set STABLE: installing a plugin, or shipping a new
		// integration in this add-on, no longer changes what an existing server
		// serves. An MCP client caches `tools/list` when it connects and there
		// is no way to refresh it, so a default set that moves is a default set
		// half the connected clients are wrong about.
		if ( $this->is_server_type_default() ) {
			$tools[] = $this->slug();
		}

		$types['acrossai']['tools'] = array_values( array_unique( $tools ) );

		return $types;
	}

	/**
	 * Whether this Toolset belongs in the `acrossai` type's DEFAULT tool set.
	 *
	 * True for the stable Toolsets — the ones that describe WordPress itself and
	 * are present on every site regardless of what is installed. False for
	 * per-plugin Toolsets, which come and go.
	 *
	 * Deliberately NOT called `is_default`: the entry already carries an
	 * `is_default` key meaning "preselect this server TYPE for new servers",
	 * which the transport reads when resolving its default type. Same word, a
	 * different question.
	 *
	 * **Answered at declaration time, never from the ability registry.** The
	 * note on `declare_server_type_tool()` records why: an earlier gate consulted
	 * registered abilities and the answer then depended on whether the transport
	 * resolved its type registry before or after `wp_abilities_api_init` — on a
	 * REST request it resolved first and every Toolset dropped out. A per-class
	 * constant answer has no such ordering.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function is_server_type_default(): bool {
		/**
		 * Filter whether a Toolset is part of the server type's default set.
		 *
		 * Published in the shape of `acrossai_toolset_member_visible`: a `true`
		 * default a site or companion plugin can flip, keyed on the group.
		 *
		 * Callbacks MUST answer from the group name alone. Anything consulting
		 * `wp_get_abilities()` reintroduces the registration-ordering bug
		 * described on `declare_server_type_tool()`.
		 *
		 * Changing this does not add or remove a tool — every Toolset stays
		 * registered and stays addable from the picker. It changes only what
		 * **Reset to Type Defaults** restores.
		 *
		 * @since 0.0.34
		 * @param bool   $is_default Whether this Toolset is a default.
		 * @param string $group      The Toolset's ability group.
		 */
		return (bool) apply_filters( 'acrossai_toolset_is_server_type_default', true, $this->group() );
	}

	/**
	 * Keep this Toolset out of the sitewide abilities surface.
	 *
	 * A Toolset is not an ability anyone edits. Listed as an ordinary one it
	 * invites an operator to override or disable it, and that takes the whole
	 * group behind it offline — seven abilities in the smallest group, sixty-
	 * four in the largest — with nothing on screen saying why. Protection is
	 * what makes `AcrossAI_Abilities_Write_Controller` refuse the write.
	 *
	 * Same shape as the tool-level declaration above and for the same reason:
	 * a Toolset is the only thing that knows its own slug, so it says so
	 * itself rather than having the list repeated somewhere that can drift.
	 *
	 * @since  0.0.34
	 * @param  mixed $slugs Slugs collected so far.
	 * @return string[]
	 */
	public function protect_own_slug( $slugs ): array {
		return $this->contribute_own_slug( $slugs );
	}

	/**
	 * Add this Toolset's slug to a list it belongs on.
	 *
	 * Both published lists want the same answer to the same question — "which
	 * slugs are Toolsets?" — so they share one implementation. The collision
	 * guard matters equally to both: protecting or tool-listing a slug another
	 * plugin holds would act on THEIR ability.
	 *
	 * @since  0.0.34
	 * @param  mixed $slugs Slugs collected so far.
	 * @return string[]
	 */
	private function contribute_own_slug( $slugs ): array {
		// A prior callback returning junk would otherwise take the Toolsets
		// down with it. Both hooks' owners normalise the same way.
		$slugs = is_array( $slugs ) ? $slugs : array();

		if ( $this->slug_taken_by_other ) {
			return $slugs;
		}

		$slugs[] = $this->slug();

		return $slugs;
	}

	/*
	 * The four declarations a subclass supplies.
	 * ------------------------------------------------------------------
	 */

	/**
	 * The ability group this Toolset covers, e.g. `content`.
	 *
	 * @return string
	 */
	abstract protected function group(): string;

	/**
	 * Every ability group this Toolset dispatches to.
	 *
	 * Almost always the one group it is named after, which is why this is not
	 * abstract. `toolset/integrations` is the exception: it spans every group
	 * that is NOT a server-type default, so that a client whose cached
	 * `tools/list` predates a plugin can still reach that plugin's abilities.
	 *
	 * `group()` remains the Toolset's IDENTITY — what it reports, what its
	 * filters are keyed on, what a collision is logged against. This is only
	 * the membership question: which abilities may travel through it.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	protected function groups(): array {
		return array( $this->group() );
	}

	/**
	 * An alternative answer to a bare `discover`, or null for the normal one.
	 *
	 * A Toolset covering ONE group can list its abilities directly — a dozen or
	 * two is a reasonable first response. One covering every plugin on the site
	 * cannot: the honest first answer there is "here is what this site has",
	 * not two hundred abilities the caller did not ask for.
	 *
	 * Returning null keeps the normal listing, which is what every Toolset but
	 * `integrations` does.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Caller input.
	 * @return array<string, mixed>|null
	 */
	protected function discover_overview( array $input ): ?array {
		unset( $input );

		return null;
	}

	/**
	 * This Toolset's ability slug, e.g. `toolset/content`.
	 *
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	abstract protected function toolset_label(): string;

	/**
	 * Description an assistant reads when choosing between Toolsets.
	 *
	 * The single most important string a subclass declares: it is what a model
	 * uses to pick this tool over the other twelve. Written by hand for that
	 * reason.
	 *
	 * @return string
	 */
	abstract protected function toolset_description(): string;

	/*
	 * Registration.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Register this Toolset, unless its group is empty or its slug is taken.
	 *
	 * A Toolset for a group with no registered abilities would advertise a
	 * subject area that does not exist, so it is not created at all.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$slug = $this->slug();

		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $slug ) ) {
			/**
			 * Fires when a Toolset slug is already claimed.
			 *
			 * Registration is skipped rather than clobbering whatever holds the
			 * slug. Observability only.
			 *
			 * @since 0.0.34
			 * @param string $slug  The contested ability slug.
			 * @param string $group The group whose Toolset was skipped.
			 */
			do_action( 'acrossai_toolset_slug_collision', $slug, $this->group() );
			$this->slug_taken_by_other = true;
			return;
		}

		if ( ! $this->has_any_member() ) {
			return;
		}

		wp_register_ability( $slug, $this->args() );
	}

	/**
	 * Registration arguments.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	private function args(): array {
		return array(
			'label'               => $this->toolset_label(),
			'description'         => $this->toolset_description(),
			'category'            => self::CATEGORY,
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'input_schema'        => self::input_schema(),
			'output_schema'       => self::output_schema(),
			'meta'                => array(
				'acrossai'    => array( 'toolset' => true ),
				// Never advertised on the vendor default server. A Toolset reaches
				// a client because an operator adds it on the Tools screen, which
				// reads the registry and ignores this flag.
				'mcp'         => array(
					'public' => false,
					'type'   => 'tool',
				),
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		);
	}

	/*
	 * Schemas — identical for every Toolset.
	 * ------------------------------------------------------------------
	 */

	/**
	 * The request shape.
	 *
	 * `action` is required and never defaulted: a caller sending
	 * `{ability, parameters}` intending to execute would otherwise receive a
	 * listing, with nothing signalling that it did the wrong thing.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'         => array(
					'type'        => 'string',
					'enum'        => array( 'discover', 'info', 'execute' ),
					'description' => 'discover: list this group\'s abilities. info: return input/output schemas. execute: run one ability.',
				),
				'search'         => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'discover only. Case-insensitive substring match against name, label and description.',
				),
				'card'           => array(
					'type'        => 'string',
					'maxLength'   => 100,
					'description' => 'discover only. Restrict to one contributing card, as returned by a previous discover call.',
				),
				'sub_group'      => array(
					'type'        => 'string',
					'maxLength'   => 64,
					'description' => 'discover only. Restrict to one sub-group.',
				),
				'plugin'         => array(
					'type'        => 'string',
					'maxLength'   => 64,
					'description' => 'discover only, and only useful on a Toolset that spans several plugins. Restrict to one, as returned by a previous discover call.',
				),
				'limit'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_LIMIT,
					'description' => 'discover only. Default ' . self::DEFAULT_LIMIT . '.',
				),
				'offset'         => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'discover only.',
				),
				'include_fields' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'discover or info. Trim the response to these fields; the ability name is always returned.',
				),
				'ability'        => array(
					'type'        => 'string',
					'maxLength'   => 255,
					'description' => 'info or execute. The ability name exactly as returned by discover. Required for execute.',
				),
				'abilities'      => array(
					'type'        => 'array',
					'items'       => array(
						'type'      => 'string',
						'maxLength' => 255,
					),
					'maxItems'    => self::MAX_BATCH,
					'description' => 'info only. Batch form; takes precedence over "ability".',
				),
				'parameters'     => array(
					'type'        => 'object',
					'description' => 'execute only. The target ability\'s own input, exactly as given by info. Pass {} for abilities that take no input.',
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * The response shape.
	 *
	 * One permissive object keyed by `action` rather than `oneOf`, which
	 * renders badly in clients and gives a model no real help. `data` carries
	 * the same union the vendor execute tool uses, because it has to absorb
	 * ~450 different output shapes and WordPress validates against it.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'          => array(
					'type' => 'string',
					'enum' => array( 'discover', 'info', 'execute' ),
				),
				'group'           => array( 'type' => 'string' ),
				'success'         => array( 'type' => 'boolean' ),

				/*
				 * Deliberately NOT named `error`. The MCP adapter treats any
				 * result matching `{ success: false, error: <string> }` as a
				 * protocol-level tool failure and replaces the whole payload
				 * with that one string (ToolsHandler, "Backward compatibility"),
				 * discarding error_code. A caller that cannot read the code
				 * cannot self-correct, which is the entire point of returning
				 * a soft failure instead of raising one. Renaming this key is
				 * what keeps the structured response intact end to end.
				 */
				'error_message'   => array( 'type' => 'string' ),
				'error_code'      => array( 'type' => 'string' ),
				'message'         => array( 'type' => 'string' ),
				'abilities'       => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'                => array( 'type' => 'string' ),
							'label'               => array( 'type' => 'string' ),
							'description'         => array( 'type' => 'string' ),
							'card'                => array( 'type' => 'string' ),
							'sub_group'           => array( 'type' => 'string' ),
							'input_schema'        => array( 'type' => 'object' ),
							'output_schema'       => array( 'type' => 'object' ),
							'annotations'         => array( 'type' => 'object' ),

							/*
							 * info only, and only when the ability declares any. Rows of
							 * { slug, reason, saves? } — a list, never a name-keyed map, or it
							 * encodes as a JSON object and fails this very schema
							 * (BUG-ARRAY-TYPED-OUTPUT-IS-A-JSON-OBJECT).
							 */
							'suggested_abilities' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
						'required'             => array( 'name' ),
						'additionalProperties' => false,
					),
				),

				/*
				 * `discover` on a Toolset that spans several groups answers with
				 * the GROUPS rather than their abilities — two hundred rows is
				 * not a useful reply to "what is here?". Declared on the shared
				 * schema rather than overridden per subclass because the schema
				 * is `additionalProperties: false`: an undeclared key is not a
				 * soft mismatch, it fails the whole response.
				 *
				 * Each row carries BOTH routes deliberately. `toolset` is the
				 * direct call for a client whose tool list has it; `sub_group`
				 * is the way back in here for one whose cached list predates it.
				 */
				'plugins'         => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'toolset'     => array( 'type' => 'string' ),
							'plugin'      => array( 'type' => 'string' ),
							'label'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'abilities'   => array( 'type' => 'integer' ),
						),
						'required'             => array( 'toolset', 'plugin' ),
						'additionalProperties' => false,
					),
				),
				'total_plugins'   => array( 'type' => 'integer' ),
				'total_abilities' => array( 'type' => 'integer' ),
				'hint'            => array( 'type' => 'string' ),
				'total'           => array( 'type' => 'integer' ),
				'returned'        => array( 'type' => 'integer' ),
				'offset'          => array( 'type' => 'integer' ),
				'has_more'        => array( 'type' => 'boolean' ),
				'not_found'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'data'            => array(
					'type'        => array( 'object', 'array', 'string', 'number', 'integer', 'boolean', 'null' ),
					'description' => 'Result of the executed ability.',
				),
			),
			'required'             => array( 'action', 'success' ),
			'additionalProperties' => false,
		);
	}

	/*
	 * Permissions — three layers, none skippable.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Gate the Toolset, and for `execute` gate the target as well.
	 *
	 * The Toolset's own capability is deliberately low: it gates a *listing*,
	 * and requiring `manage_options` here would lock out a legitimately-scoped
	 * editor while protecting nothing — every run still passes the target's own
	 * check.
	 *
	 * For `execute` the target's `check_permissions()` runs here too, so a
	 * denial is a real authorisation failure raised before `execute()` is
	 * reached, indistinguishable from calling the ability directly.
	 *
	 * @since  0.0.34
	 * @param  mixed $input Caller input.
	 * @return bool|WP_Error
	 */
	public function check_permission( $input = null ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'acrossai_toolset_forbidden',
				__( 'You must be logged in to use this tool.', 'acrossai-mcp-manager' ),
				array( 'status' => 401 )
			);
		}

		/**
		 * Filters the capability required to reach a Toolset.
		 *
		 * Gates the listing only. Executing an ability through a Toolset always
		 * runs that ability's own permission callback as well, so raising this
		 * restricts discovery without ever loosening execution.
		 *
		 * @since 0.0.34
		 * @param string $capability Default 'read'.
		 * @param string $group      The group this Toolset covers.
		 */
		$capability = (string) apply_filters( 'acrossai_toolset_capability', 'read', $this->group() );

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'acrossai_toolset_forbidden',
				__( 'You are not allowed to use this tool.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		$input = is_array( $input ) ? $input : array();

		if ( 'execute' !== ( $input['action'] ?? '' ) ) {
			return true;
		}

		$name   = (string) ( $input['ability'] ?? '' );
		$target = $this->find_member( $name, 'execute' );

		if ( ! $target instanceof WP_Ability ) {
			/*
			 * Hidden is a policy decision, not a mistake. The sibling
			 * transport's own execute tool denies here with a 403 rather than
			 * reporting a miss, and it is right to: retrying or rephrasing will
			 * never help, and an operator switching an ability off for one
			 * server should look the same however the caller reached it.
			 */
			if ( $this->is_hidden_member( $name ) ) {
				return new WP_Error(
					'acrossai_toolset_ability_not_exposed',
					__( 'This ability is not exposed on this MCP server.', 'acrossai-mcp-manager' ),
					array( 'status' => 403 )
				);
			}

			/*
			 * Everything else — a typo, or an ability that lives in another
			 * group — IS self-correctable, so execute() reports it with a
			 * machine-readable code instead of raising an authorisation
			 * failure the caller cannot learn from.
			 */
			return true;
		}

		$parameters = InputNormalizer::normalize(
			$target,
			$input['parameters'] ?? null
		);

		$allowed = $target->check_permissions( $parameters );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( true !== $allowed ) {
			return new WP_Error(
				'acrossai_toolset_target_forbidden',
				sprintf(
					/* translators: %s: ability name. */
					__( 'You are not allowed to run "%s".', 'acrossai-mcp-manager' ),
					$target->get_name()
				),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/*
	 * Dispatch.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Route a call to one of the three actions.
	 *
	 * @since  0.0.34
	 * @param  mixed $input Caller input.
	 * @return array<string, mixed>
	 */
	public function execute( $input = null ): array {
		$input  = is_array( $input ) ? $input : array();
		$action = (string) ( $input['action'] ?? '' );

		switch ( $action ) {
			case 'discover':
				return $this->do_discover( $input );
			case 'info':
				return $this->do_info( $input );
			case 'execute':
				return $this->do_execute( $input );
			default:
				return $this->failure(
					$action,
					'invalid_action',
					__( 'Unknown action. Use discover, info or execute.', 'acrossai-mcp-manager' )
				);
		}
	}

	/**
	 * List the group's abilities.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Caller input.
	 * @return array<string, mixed>
	 */
	private function do_discover( array $input ): array {
		$overview = $this->discover_overview( $input );

		if ( null !== $overview ) {
			return $overview;
		}

		$members = $this->members( 'discover' );

		if ( array() === $members ) {
			return array(
				'action'    => 'discover',
				'group'     => $this->group(),
				'success'   => true,
				'abilities' => array(),
				'total'     => 0,
				'returned'  => 0,
				'offset'    => 0,
				'has_more'  => false,
				'message'   => __( 'No abilities in this group are currently available to you.', 'acrossai-mcp-manager' ),
			);
		}

		$members = $this->apply_filters_to( $members, $input );
		$total   = count( $members );

		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
		$limit  = max( 1, min( self::MAX_LIMIT, $limit ) );
		$offset = isset( $input['offset'] ) ? max( 0, (int) $input['offset'] ) : 0;

		$page   = array_slice( $members, $offset, $limit );
		$fields = $this->requested_fields( $input );

		$rows = array();
		foreach ( $page as $ability ) {
			$rows[] = $this->summarise( $ability, $fields );
		}

		return array(
			'action'    => 'discover',
			'group'     => $this->group(),
			'success'   => true,
			'abilities' => $rows,
			'total'     => $total,
			'returned'  => count( $rows ),
			'offset'    => $offset,
			'has_more'  => ( $offset + count( $rows ) ) < $total,
		);
	}

	/**
	 * Describe one or more abilities.
	 *
	 * Gated identically to execute: an input schema names parameters and
	 * constraints, so describing an ability a caller may not run would be a
	 * read-side disclosure.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Caller input.
	 * @return array<string, mixed>
	 */
	private function do_info( array $input ): array {
		$names = array();

		if ( isset( $input['abilities'] ) && is_array( $input['abilities'] ) ) {
			$names = array_slice( array_map( 'strval', $input['abilities'] ), 0, self::MAX_BATCH );
		} elseif ( isset( $input['ability'] ) ) {
			$names = array( (string) $input['ability'] );
		}

		if ( array() === $names ) {
			return $this->failure(
				'info',
				'missing_ability',
				__( 'Name an ability with "ability", or several with "abilities".', 'acrossai-mcp-manager' )
			);
		}

		$fields    = $this->requested_fields( $input );
		$found     = array();
		$not_found = array();

		foreach ( $names as $name ) {
			$member = $this->find_member( $name, 'info' );

			if ( $member instanceof WP_Ability ) {
				$found[] = $this->describe( $member, $fields );
				continue;
			}

			$not_found[] = $name;
		}

		return array(
			'action'    => 'info',
			'group'     => $this->group(),
			'success'   => array() !== $found,
			'abilities' => $found,
			'not_found' => $not_found,
		);
	}

	/**
	 * Run one ability in this group.
	 *
	 * Invokes through `WP_Ability::execute()` and never the raw callback, so
	 * validation, the target's own permission check, output validation and
	 * every lifecycle event fire exactly as they would on a direct call. The
	 * permission check therefore runs twice — once in check_permission() and
	 * once inside execute(). That is intentional and costs nothing; do not
	 * remove either.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Caller input.
	 * @return array<string, mixed>
	 */
	private function do_execute( array $input ): array {
		$name = (string) ( $input['ability'] ?? '' );

		if ( '' === $name ) {
			return $this->failure(
				'execute',
				'missing_ability',
				__( 'Name the ability to run with "ability".', 'acrossai-mcp-manager' )
			);
		}

		$target = $this->find_member( $name, 'execute' );

		if ( ! $target instanceof WP_Ability ) {
			return $this->miss( 'execute', $name );
		}

		$parameters = InputNormalizer::normalize(
			$target,
			$input['parameters'] ?? null
		);

		try {
			$result = $target->execute( $parameters );
		} catch ( \Throwable $e ) {
			/*
			 * An ability that throws would otherwise reach the transport as an
			 * unhandled exception and come back as a generic "Failed to execute
			 * tool", losing both the message and which ability threw.
			 */
			return $this->failure( 'execute', 'ability_threw', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return array(
				'action'        => 'execute',
				'group'         => $this->group(),
				'success'       => false,
				'error_message' => $result->get_error_message(),
				'error_code'    => (string) $result->get_error_code(),
			);
		}

		return array(
			'action'  => 'execute',
			'group'   => $this->group(),
			'success' => true,
			'data'    => $result,
		);
	}

	/*
	 * Membership.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Whether any group this Toolset covers has a registered member.
	 *
	 * Deliberately does NOT go through `members()`: this runs during
	 * registration, and memoising a membership list at that moment would freeze
	 * an answer taken before later-priority callbacks have registered theirs.
	 * It also skips the visibility filter — an empty-because-hidden group should
	 * still register its Toolset, or a per-role policy could unregister a tool
	 * for everyone.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	private function has_any_member(): bool {
		foreach ( $this->groups() as $group ) {
			if ( array() !== AbilityGroup::members( (string) $group ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The abilities this Toolset may act on, for one action.
	 *
	 * Membership comes from registration; visibility is applied here, per
	 * request, because it varies with the context of the call and must never
	 * be carried between them.
	 *
	 * @since  0.0.34
	 * @param  string $context One of discover, info, execute.
	 * @return array<int, WP_Ability>
	 */
	private function members( string $context ): array {
		if ( isset( $this->memo[ $context ] ) ) {
			return $this->memo[ $context ];
		}

		$visible = array();
		$seen    = array();

		foreach ( $this->groups() as $group ) {
			foreach ( AbilityGroup::members( (string) $group ) as $ability ) {
				if ( ! $this->is_dispatchable( $ability ) ) {
					continue;
				}

				$name = $ability->get_name();

				// A Toolset spanning several groups must not list an ability
				// twice if two groups ever claim it.
				if ( isset( $seen[ $name ] ) ) {
					continue;
				}

				/**
				 * Filters whether one ability is visible through one Toolset.
				 *
				 * Shaped to mirror the connected transport's own exposure filter so
				 * the two read as siblings. Nothing in this plugin hooks it; it is
				 * published so a per-connection or per-role policy can narrow
				 * membership without changing this feature.
				 *
				 * **Visibility is not authorisation.** Returning true does not
				 * bypass the ability's own permission callback.
				 *
				 * @since 0.0.34
				 * @param bool       $visible Default true for a registered member.
				 * @param WP_Ability $ability The ability being considered.
				 * @param string     $group   The group being listed.
				 * @param string     $context One of 'discover' | 'info' | 'execute'.
				 */
				// The ability's OWN group, not this Toolset's identity — a
				// policy narrowing `elementor` must still work when the ability
				// is reached through `integrations`.
				if ( ! apply_filters( 'acrossai_toolset_member_visible', true, $ability, (string) $group, $context ) ) {
					continue;
				}

				$seen[ $name ] = true;
				$visible[]     = $ability;
			}
		}

		$this->memo[ $context ] = $visible;

		return $visible;
	}

	/**
	 * Whether an ability may be dispatched to at all.
	 *
	 * Excludes other Toolsets — a Toolset never dispatches to a Toolset, so no
	 * recursion and no cross-group hop — and anything not published as a
	 * callable tool.
	 *
	 * @since  0.0.34
	 * @param  WP_Ability $ability Candidate.
	 * @return bool
	 */
	private function is_dispatchable( WP_Ability $ability ): bool {
		$acrossai = $ability->get_meta_item( 'acrossai' );

		if ( is_array( $acrossai ) && ! empty( $acrossai['toolset'] ) ) {
			return false;
		}

		$mcp  = $ability->get_meta_item( 'mcp' );
		$type = is_array( $mcp ) && isset( $mcp['type'] ) ? (string) $mcp['type'] : 'tool';

		return 'tool' === $type;
	}

	/**
	 * Find a named member of this group.
	 *
	 * @since  0.0.34
	 * @param  string $name    Ability name.
	 * @param  string $context One of discover, info, execute.
	 * @return WP_Ability|null
	 */
	private function find_member( string $name, string $context ): ?WP_Ability {
		if ( '' === $name ) {
			return null;
		}

		foreach ( $this->members( $context ) as $ability ) {
			if ( $ability->get_name() === $name ) {
				return $ability;
			}
		}

		return null;
	}

	/*
	 * Shaping.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Apply the discover filters — search, card, sub-group.
	 *
	 * @since  0.0.34
	 * @param  array<int, WP_Ability> $members Candidates.
	 * @param  array<string, mixed>   $input   Caller input.
	 * @return array<int, WP_Ability>
	 */
	private function apply_filters_to( array $members, array $input ): array {
		$search    = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$card      = isset( $input['card'] ) ? (string) $input['card'] : '';
		$sub_group = isset( $input['sub_group'] ) ? (string) $input['sub_group'] : '';
		// Narrows by GROUP, which `sub_group` cannot: a sub-group is a division
		// WITHIN a group (`elementor-elements`), so it never equals a group name.
		// Only meaningful on a Toolset spanning several groups; on any other it
		// either matches everything or nothing, which is the honest answer.
		$plugin = isset( $input['plugin'] ) ? (string) $input['plugin'] : '';

		if ( '' === $search && '' === $card && '' === $sub_group && '' === $plugin ) {
			return $members;
		}

		return array_values(
			array_filter(
				$members,
				function ( WP_Ability $ability ) use ( $search, $card, $sub_group, $plugin ): bool {
					if ( '' !== $plugin && AbilityGroup::of( $ability ) !== $plugin ) {
						return false;
					}

					if ( '' !== $card && ! $this->card_matches( $ability, $card ) ) {
						return false;
					}

					if ( '' !== $sub_group && $this->sub_group_of( $ability ) !== $sub_group ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						$ability->get_name() . ' ' . $ability->get_label() . ' ' . $ability->get_description()
					);

					// `strpos()`, not `str_contains()`: this plugin's PHPCS
					// enforces a 7.4 floor even though its header says 8.1.
					return false !== strpos( $haystack, $search );
				}
			)
		);
	}

	/**
	 * Whether an ability belongs to a named card.
	 *
	 * Accepts the full category slug or its shorthand, so a caller can pass
	 * back either what discover returned or the obvious short form.
	 *
	 * @since  0.0.34
	 * @param  WP_Ability $ability Candidate.
	 * @param  string     $card    Requested card.
	 * @return bool
	 */
	private function card_matches( WP_Ability $ability, string $card ): bool {
		$actual = $ability->get_category();

		return $actual === $card || $this->shorten_card( $actual ) === $this->shorten_card( $card );
	}

	/**
	 * Trim the plugin prefix from a category slug for display.
	 *
	 * @since  0.0.34
	 * @param  string $category Category slug.
	 * @return string
	 */
	private function shorten_card( string $category ): string {
		return '' === $category ? '' : (string) preg_replace( '/^acrossai-/', '', $category );
	}

	/**
	 * The sub-group an ability declares.
	 *
	 * @since  0.0.34
	 * @param  WP_Ability $ability Candidate.
	 * @return string
	 */
	private function sub_group_of( WP_Ability $ability ): string {
		$acrossai = $ability->get_meta_item( 'acrossai' );

		return is_array( $acrossai ) && isset( $acrossai['sub_group'] )
			? (string) $acrossai['sub_group']
			: '';
	}

	/**
	 * A discover row.
	 *
	 * Never carries schemas or annotations — those are the info surface, and
	 * including them here would restore the payload problem this feature
	 * exists to solve.
	 *
	 * @since  0.0.34
	 * @param  WP_Ability    $ability Member.
	 * @param  string[]|null $fields  Requested fields, or null for all.
	 * @return array<string, mixed>
	 */
	private function summarise( WP_Ability $ability, ?array $fields ): array {
		$row = array(
			'name'        => $ability->get_name(),
			'label'       => $ability->get_label(),
			'description' => $ability->get_description(),
			'card'        => $this->shorten_card( $ability->get_category() ),
			'sub_group'   => $this->sub_group_of( $ability ),
		);

		return $this->trim_to( $row, $fields );
	}

	/**
	 * An info row.
	 *
	 * @since  0.0.34
	 * @param  WP_Ability    $ability Member.
	 * @param  string[]|null $fields  Requested fields, or null for all.
	 * @return array<string, mixed>
	 */
	private function describe( WP_Ability $ability, ?array $fields ): array {
		$annotations = $ability->get_meta_item( 'annotations' );

		$row = array(
			'name'          => $ability->get_name(),
			'label'         => $ability->get_label(),
			'description'   => $ability->get_description(),
			'card'          => $this->shorten_card( $ability->get_category() ),
			'sub_group'     => $this->sub_group_of( $ability ),
			'input_schema'  => $ability->get_input_schema(),
			'output_schema' => $ability->get_output_schema(),
			'annotations'   => is_array( $annotations ) ? $annotations : array(),
		);

		$suggested = $this->suggested_abilities_of( $ability );

		if ( array() !== $suggested ) {
			$row['suggested_abilities'] = $suggested;
		}

		return $this->trim_to( $row, $fields );
	}

	/**
	 * An ability's declared suggestions, or none.
	 *
	 * Feature 108. `describe()` built its row from scratch and read only `annotations` out of meta,
	 * so `meta.acrossai.suggested_abilities` — the whole Feature 095 framework — never reached a
	 * client on the toolset path. Since the toolsets exist specifically to replace the flat
	 * catalogue as the way an assistant finds abilities, that meant the suggestions were invisible
	 * on the only surface most callers use.
	 *
	 * They stay out of `summarise()` deliberately: `DEC-ABILITY-SUGGESTED-ABILITIES-CONTRACT` keeps
	 * them out of discovery so listing payloads stay small, and `action=info` is the per-ability
	 * call that already carries both schemas. This adds them where the contract always intended
	 * them and nowhere else.
	 *
	 * Honours `acrossai_disable_ability_suggestions`, the same kill-switch
	 * `AcrossAI_Ability_Library_Registry::apply_suggested_abilities_decoration()` applies on the
	 * Library path. Without this check the admin toggle would silently stop working for MCP.
	 *
	 * @since  0.0.34
	 * @param  WP_Ability $ability Member.
	 * @return array<int, mixed>
	 */
	private function suggested_abilities_of( WP_Ability $ability ): array {
		if ( (bool) get_option( 'acrossai_disable_ability_suggestions', 0 ) ) {
			return array();
		}

		$acrossai = $ability->get_meta_item( 'acrossai' );

		if ( ! is_array( $acrossai ) || empty( $acrossai['suggested_abilities'] ) ) {
			return array();
		}

		$suggested = $acrossai['suggested_abilities'];

		return is_array( $suggested ) ? array_values( $suggested ) : array();
	}

	/**
	 * The fields a caller asked for, or null when they asked for everything.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Caller input.
	 * @return string[]|null
	 */
	protected function requested_fields( array $input ): ?array {
		if ( ! isset( $input['include_fields'] ) || ! is_array( $input['include_fields'] ) ) {
			return null;
		}

		$fields = array_map( 'strval', $input['include_fields'] );

		return array() === $fields ? null : $fields;
	}

	/**
	 * Reduce a row to the requested fields.
	 *
	 * Unrecognised names are ignored rather than rejected, and the `$always`
	 * key(s) survive trimming — a row a caller cannot identify, or one missing a
	 * field its own output schema requires, is worse than a verbose one.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $row    Full row.
	 * @param  string[]|null        $fields Requested fields.
	 * @param  string|string[]      $always Key(s) that survive trimming.
	 * @return array<string, mixed>
	 */
	protected function trim_to( array $row, ?array $fields, $always = 'name' ): array {
		if ( null === $fields ) {
			return $row;
		}

		// Some keys are never trimmed away — a trimmed row the caller cannot act
		// on is worse than a verbose one, and the output schema REQUIRES them,
		// so dropping one fails the whole response rather than returning less.
		//
		// Which keys those are depends on what the row describes: an ability is
		// identified by `name`; a plugin row needs BOTH `plugin` and `toolset`,
		// because they are the two routes to it and a row naming one route is
		// half an answer. `include_fields=["plugin"]` returning a row without
		// its toolset was exactly that failure.
		$always = array_values( array_filter( (array) $always ) );
		$keep   = array();

		foreach ( $always as $key ) {
			if ( array_key_exists( $key, $row ) ) {
				$keep[ $key ] = $row[ $key ];
			}
		}

		foreach ( $fields as $field ) {
			if ( ! in_array( $field, $always, true ) && array_key_exists( $field, $row ) ) {
				$keep[ $field ] = $row[ $field ];
			}
		}

		return $keep;
	}

	/*
	 * Failure shapes.
	 * ------------------------------------------------------------------
	 */

	/**
	 * A recoverable failure.
	 *
	 * Returned as an unsuccessful result rather than a WP_Error so a model can
	 * read the reason and correct itself. Authorisation failures are the
	 * deliberate exception and are raised from check_permission().
	 *
	 * @since  0.0.34
	 * @param  string $action  Action attempted.
	 * @param  string $code    Machine-readable reason.
	 * @param  string $message Human-readable reason.
	 * @return array<string, mixed>
	 */
	private function failure( string $action, string $code, string $message ): array {
		return array(
			'action'        => '' === $action ? 'discover' : $action,
			'group'         => $this->group(),
			'success'       => false,
			'error_message' => $message,
			'error_code'    => $code,
		);
	}

	/**
	 * Is this a real member of this group that a policy has hidden?
	 *
	 * `members()` drops an ability for exactly two reasons: it is not
	 * dispatchable, or `acrossai_toolset_member_visible` returned false. So an
	 * ability that is registered, dispatchable and in this group, yet absent
	 * from `members()`, was hidden by a policy — per-server exposure on the
	 * connected transport, or a site's own callback.
	 *
	 * Kept separate from `miss()` because the two answer different questions at
	 * different times: this one runs in the permission callback and decides
	 * whether to deny, `miss()` runs afterwards and explains a miss.
	 *
	 * @since  0.0.34
	 * @param  string $name Ability name.
	 * @return bool
	 */
	private function is_hidden_member( string $name ): bool {
		if ( '' === $name || ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $name ) ) {
			return false;
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;

		if ( ! $ability instanceof WP_Ability || ! $this->is_dispatchable( $ability ) ) {
			return false;
		}

		return in_array( AbilityGroup::of( $ability ), $this->groups(), true );
	}

	/**
	 * Distinguish the three ways a named ability can be unusable here.
	 *
	 * A caller that cannot tell "does not exist" from "exists elsewhere" from
	 * "hidden from you" cannot correct itself.
	 *
	 * @since  0.0.34
	 * @param  string $action Action attempted.
	 * @param  string $name   Ability name.
	 * @return array<string, mixed>
	 */
	private function miss( string $action, string $name ): array {
		$exists = function_exists( 'wp_has_ability' ) && wp_has_ability( $name );

		if ( ! $exists ) {
			return $this->failure(
				$action,
				'ability_not_found',
				sprintf(
					/* translators: %s: ability name. */
					__( 'No ability named "%s" is registered.', 'acrossai-mcp-manager' ),
					$name
				)
			);
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		$group   = $ability instanceof WP_Ability ? AbilityGroup::of( $ability ) : '';

		if ( '' !== $group && ! in_array( $group, $this->groups(), true ) ) {
			return $this->failure(
				$action,
				'ability_not_in_group',
				sprintf(
					/* translators: 1: ability name, 2: the group it belongs to. */
					__( '"%1$s" belongs to the "%2$s" group. Use that tool instead.', 'acrossai-mcp-manager' ),
					$name,
					$group
				)
			);
		}

		return $this->failure(
			$action,
			'ability_not_visible',
			sprintf(
				/* translators: %s: ability name. */
				__( '"%s" is not available through this tool.', 'acrossai-mcp-manager' ),
				$name
			)
		);
	}
}
