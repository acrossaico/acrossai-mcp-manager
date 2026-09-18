<?php
/**
 * Callback-swap layer for vendor's three built-in MCP abilities.
 *
 * Hooks the WP core `wp_register_ability_args` filter (fires inside
 * `WP_Abilities_Registry::register()` — see `wp-includes/abilities-api/
 * class-wp-abilities-registry.php:129`) and, when the ability being
 * registered is one of vendor's three defaults, rebinds
 * `execute_callback` + `permission_callback` to plugin-owned classes.
 *
 * Labels, categories and annotations remain vendor-supplied. F089 adds one
 * exception: `mcp-adapter/discover-abilities` also gets its `input_schema`,
 * `output_schema` and `description` from here, because the vendor registers it
 * with NO input schema at all and WP core then refuses any input
 * (`WP_Ability::validate_input()` → `ability_missing_input_schema`) and never
 * passes `$input` to the callback (`invoke_callback()`). Contributing the
 * schema through this same filter is what makes search + pagination reachable
 * without forking the adapter.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide singleton per A11. Public methods are hook callbacks;
 * wire in `Includes\Main::define_admin_hooks()`.
 *
 * @since 0.1.0
 */
final class CallbackReplacer {

	/**
	 * The discovery ability slug. Named because F089 contributes a schema to
	 * this one specifically, not just a callback swap.
	 */
	public const DISCOVER_ABILITY = 'mcp-adapter/discover-abilities';

	/**
	 * Map of vendor ability slug → [ callback_class, permission_method, execute_method ].
	 */
	private const VENDOR_ABILITIES = array(
		self::DISCOVER_ABILITY         => array( Discover::class, 'check_permission', 'execute' ),
		'mcp-adapter/get-ability-info' => array( GetAbilityInfo::class, 'check_permission', 'execute' ),
		'mcp-adapter/execute-ability'  => array( Execute::class, 'check_permission', 'execute' ),
	);

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance(). Hook wiring lives in Main.php per A1.
	 */
	private function __construct() {}

	/**
	 * Callback for `wp_register_ability_args`.
	 *
	 * @param array<string, mixed> $args The ability registration args.
	 * @param string               $name The ability slug being registered.
	 * @return array<string, mixed>
	 */
	public function replace_callbacks( array $args, string $name ): array {
		if ( ! isset( self::VENDOR_ABILITIES[ $name ] ) ) {
			return $args;
		}

		[ $class, $permission_method, $execute_method ] = self::VENDOR_ABILITIES[ $name ];

		$args['permission_callback'] = array( $class, $permission_method );
		$args['execute_callback']    = array( $class, $execute_method );

		if ( self::DISCOVER_ABILITY !== $name ) {
			return $args;
		}

		// Idempotency: this filter fires on EVERY registration of the slug, and
		// a third party may re-register it (the competitor plugin Novamira, for
		// one, unregisters and re-registers this ability wholesale). Bail if our
		// schema is already in place rather than re-deriving it.
		if ( isset( $args['input_schema']['properties']['per_page'] ) ) {
			return $args;
		}

		$args['input_schema']  = $this->discover_input_schema();
		$args['output_schema'] = $this->discover_output_schema(
			isset( $args['output_schema'] ) && is_array( $args['output_schema'] ) ? $args['output_schema'] : array()
		);
		$args['description']   = $this->discover_description();

		return $args;
	}

	/**
	 * Input schema for `mcp-adapter/discover-abilities` (F089).
	 *
	 * The root `default` is load-bearing: `WP_Ability::normalize_input()` applies
	 * only the TOP-LEVEL default, and `validate_input()` rejects `null` once a
	 * schema exists. Without it, every existing zero-argument caller would start
	 * failing the moment this schema is registered.
	 *
	 * Per-property defaults are NOT applied by core — `Discover::execute()`
	 * applies its own. They are declared here purely so the LLM reading
	 * `tools/list` can see them, and they read from Discover's constants so the
	 * advertised numbers cannot drift from the ones actually enforced.
	 *
	 * @return array<string, mixed>
	 */
	private function discover_input_schema(): array {
		return array(
			'type'                 => 'object',
			'default'              => array(),
			'additionalProperties' => false,
			'properties'           => array(
				'search'    => array(
					'type'        => 'string',
					'description' => __(
						'Free-text filter. Case-insensitive substring match against each ability\'s name, label, description and category. Example: "post" finds acrossai/list-posts and acrossai/update-post.',
						'acrossai-mcp-manager'
					),
				),
				'category'  => array(
					'type'        => 'string',
					'description' => __(
						'Return only abilities in this exact category. Category values appear on every returned ability, so call once without filters to see which exist.',
						'acrossai-mcp-manager'
					),
				),
				'namespace' => array(
					'type'        => 'string',
					'description' => __(
						'Return only abilities whose name starts with this namespace, e.g. "acrossai" matches acrossai/list-posts. Use this to scope to one plugin.',
						'acrossai-mcp-manager'
					),
				),
				'tab_group' => array(
					'type'        => 'string',
					'description' => __(
						'Return only abilities in this toolset group, e.g. "content" or "elementor". This is the axis the ability library is organised on, so it narrows further than category usually does. Every returned ability carries its tab_group, so call once without filters to see which exist. Empty on sites where no plugin groups its abilities.',
						'acrossai-mcp-manager'
					),
				),
				'page'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __(
						'1-based page number. Only needed when a previous response returned has_more = true.',
						'acrossai-mcp-manager'
					),
				),
				'per_page'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => Discover::PER_PAGE_MAXIMUM,
					'default'     => Discover::PER_PAGE_DEFAULT,
					'description' => __(
						'How many abilities to return per page. Defaults to 60, maximum 200. Prefer narrowing with search/category/namespace/tab_group over raising this.',
						'acrossai-mcp-manager'
					),
				),
			),
		);
	}

	/**
	 * Merge F089's pagination fields into the vendor output schema.
	 *
	 * Merged rather than replaced so vendor keys (and anything another filter
	 * already contributed) survive.
	 *
	 * @param array<string, mixed> $existing Output schema as registered so far.
	 * @return array<string, mixed>
	 */
	private function discover_output_schema( array $existing ): array {
		$existing['type'] = 'object';

		$properties = isset( $existing['properties'] ) && is_array( $existing['properties'] )
			? $existing['properties']
			: array();

		// F089 also adds `category` to each ability entry, so the item schema
		// the vendor declared needs it too.
		if ( isset( $properties['abilities']['items']['properties'] ) && is_array( $properties['abilities']['items']['properties'] ) ) {
			$properties['abilities']['items']['properties']['category']  = array( 'type' => 'string' );
			$properties['abilities']['items']['properties']['tab_group'] = array( 'type' => 'string' );
		}

		$properties['total']    = array(
			'type'        => 'integer',
			'description' => __( 'Total abilities matching the filters, before pagination.', 'acrossai-mcp-manager' ),
		);
		$properties['returned'] = array(
			'type'        => 'integer',
			'description' => __( 'How many abilities this response contains.', 'acrossai-mcp-manager' ),
		);
		$properties['page']     = array(
			'type'        => 'integer',
			'description' => __( 'The 1-based page this response represents.', 'acrossai-mcp-manager' ),
		);
		$properties['per_page'] = array(
			'type'        => 'integer',
			'description' => __( 'The page size actually applied, after clamping.', 'acrossai-mcp-manager' ),
		);
		$properties['has_more'] = array(
			'type'        => 'boolean',
			'description' => __( 'True when more abilities match than this page returned. Request page + 1 to continue.', 'acrossai-mcp-manager' ),
		);

		$existing['properties'] = $properties;

		return $existing;
	}

	/**
	 * The tool description an MCP client shows the model.
	 *
	 * This string is the ability's `description`, which
	 * `RegisterAbilityAsMcpTool::build_tool_data()` copies verbatim into the
	 * tool's `description` in `tools/list` — so it IS the documentation the LLM
	 * reads. State the default page size and the paging contract explicitly;
	 * a model that cannot see `has_more` assumes it received everything.
	 */
	private function discover_description(): string {
		return __(
			'Discover the WordPress abilities available on this site. Returns up to 60 abilities per call, each with its name, label, description, category and tab_group. Narrow the list with search, category, namespace or tab_group rather than paging through everything — tab_group is the axis the library is organised on and usually the sharpest cut. When has_more is true, request the next page with page. Once you have a name, call mcp-adapter/get-ability-info for that ability\'s full input schema. If you are unsure how to search this site, or what it contains, call mcp-adapter/server-guide first — it reports every category, namespace and tab_group present with a count for each, which is what lets you narrow on the first attempt instead of guessing a filter value.',
			'acrossai-mcp-manager'
		);
	}
}
