<?php
/**
 * The MCP Adapter server's guide — how to use this server, and what is on it.
 *
 * An assistant connecting to an `mcp-adapter` server is handed three meta-tools
 * over several hundred abilities and no instructions. It has to infer the
 * calling convention and the available filters from tool descriptions, and it
 * has no way at all to learn what the library CONTAINS without paging through
 * every ability sixty at a time.
 *
 * This answers both in one call. The inventory is the part that cannot be got
 * any other way: which categories, namespaces and toolset groups exist here,
 * and how many abilities are in each. With it a model can narrow on the first
 * attempt instead of guessing a filter value and getting an empty list.
 *
 * Read-only, takes no input, changes nothing.
 *
 * Scoped to the `mcp-adapter` server type. The `acrossai` type has a different
 * surface — dispatchers rather than meta-tools — and its own guide, in the
 * plugin that owns that vocabulary.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * A one-call briefing for a client connected to an MCP Adapter server.
 */
final class ServerGuide {

	/**
	 * This ability's slug.
	 *
	 * @since 0.1.0
	 * @var   string
	 */
	public const SLUG = 'acrossai/server-guide';

	/**
	 * Registered under this plugin's own category, never the vendor's.
	 *
	 * Same reason as `SetupRequired::CATEGORY`: WP 6.9+ `_doing_it_wrong()`s on
	 * an unregistered category, and borrowing the adapter's would couple this to
	 * its boot order.
	 *
	 * @since 0.1.0
	 * @var   string
	 */
	public const CATEGORY = 'acrossai-mcp';

	/**
	 * Register the ability. Loader-wired from Main.php (A1).
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( self::SLUG ) ) {
			return;
		}

		wp_register_ability(
			self::SLUG,
			array(
				'label'               => __( 'Server guide', 'acrossai-mcp-manager' ),
				'description'         => __( 'Read this first. Explains how to use this server — the three tools, every filter they accept and their real limits — and reports what this site actually contains: which categories, namespaces and toolset groups exist, with an ability count for each. That inventory is not obtainable any other way without paging through every ability, and it is what lets you narrow on the first attempt rather than guessing a filter value. Takes no input and changes nothing.', 'acrossai-mcp-manager' ),
				'category'            => self::CATEGORY,
				'meta'                => array(
					'mcp' => array(
						// Not public: the guide is transport, not cargo. It
						// reaches a client by being in a server's tool list,
						// never by appearing in its own discover results.
						'public' => false,
						'type'   => 'tool',
					),
				),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					// Load-bearing: core applies only the TOP-LEVEL default, and
					// validate_input() rejects null once a schema exists. Without
					// this every zero-argument call fails (B59).
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'tools'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'how_to_find' => array( 'type' => 'object' ),
						'inventory'   => array( 'type' => 'object' ),
					),
					'required'   => array( 'tools', 'how_to_find', 'inventory' ),
				),
				'execute_callback'    => array( self::class, 'execute' ),
				// Same bar as listing the server's tools: this reports what is
				// visible to the caller and carries no privilege of its own.
				'permission_callback' => static function (): bool {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * @since  0.1.0
	 * @return array<string, mixed>
	 */
	public static function execute(): array {
		return array(
			'tools'       => self::tools(),
			'how_to_find' => self::how_to_find(),
			'inventory'   => self::inventory(),
		);
	}

	/**
	 * What each of the three tools is for.
	 *
	 * Named from `ToolPolicy::PROTOCOL_TOOLS` rather than restated, so this can
	 * never describe a tool the server does not actually carry.
	 *
	 * @since  0.1.0
	 * @return array<int, array<string, string>>
	 */
	private static function tools(): array {
		$use_for = array(
			'mcp-adapter/discover-abilities' => __( 'Find abilities. Start here, narrowed by a filter — never page through everything.', 'acrossai-mcp-manager' ),
			'mcp-adapter/get-ability-info'   => __( 'Read one ability\'s input and output schema. Call this before executing rather than guessing parameters.', 'acrossai-mcp-manager' ),
			'mcp-adapter/execute-ability'    => __( 'Run one ability with the parameters its schema declares.', 'acrossai-mcp-manager' ),
		);

		$rows = array();

		foreach ( ToolPolicy::PROTOCOL_TOOLS as $slug ) {
			$rows[] = array(
				'name'    => $slug,
				'use_for' => $use_for[ $slug ] ?? '',
			);
		}

		return $rows;
	}

	/**
	 * The filters and limits that actually exist.
	 *
	 * Limits are read from `Discover`'s own constants, so an advertised number
	 * can never drift from the enforced one — the same discipline
	 * `CallbackReplacer::discover_input_schema()` follows.
	 *
	 * @since  0.1.0
	 * @return array<string, mixed>
	 */
	private static function how_to_find(): array {
		return array(
			'filters'  => array(
				'search'    => __( 'Case-insensitive substring across name, label, description and category.', 'acrossai-mcp-manager' ),
				'category'  => __( 'Exact match on an ability\'s category.', 'acrossai-mcp-manager' ),
				'namespace' => __( 'Everything before the first slash in an ability name — scopes to one plugin.', 'acrossai-mcp-manager' ),
				'tab_group' => __( 'Exact match on the toolset group. Usually the sharpest cut, because it is the axis the library is organised on.', 'acrossai-mcp-manager' ),
			),
			'per_page' => array(
				'default' => Discover::PER_PAGE_DEFAULT,
				'maximum' => Discover::PER_PAGE_MAXIMUM,
			),
			'notes'    => array(
				__( 'Filters AND together. Combine them rather than raising per_page.', 'acrossai-mcp-manager' ),
				__( 'When has_more is true you did not receive everything — request the next page.', 'acrossai-mcp-manager' ),
				__( 'Call get-ability-info before execute-ability. Guessed parameters fail at the ability\'s own validation, which reports less than the schema would have told you.', 'acrossai-mcp-manager' ),
			),
		);
	}

	/**
	 * What this site actually holds, by each axis a filter accepts.
	 *
	 * Built from `Discover::collect_visible_abilities()` — the same list the
	 * discover tool paginates, already past the exposure gate. Counting the
	 * registry directly would report abilities the caller cannot reach, which
	 * is worse than no inventory: it sends a model looking for something that
	 * will be refused.
	 *
	 * An axis with nothing on it is omitted entirely rather than returned
	 * empty. A guide that advertises `tab_group` on a site where no ability
	 * declares one would be describing a filter that can only ever return
	 * nothing.
	 *
	 * @since  0.1.0
	 * @return array<string, mixed>
	 */
	private static function inventory(): array {
		$categories = array();
		$namespaces = array();
		$tab_groups = array();

		foreach ( Discover::collect_visible_abilities() as $entry ) {
			self::tally( $categories, (string) ( $entry['category'] ?? '' ) );
			self::tally( $tab_groups, (string) ( $entry['tab_group'] ?? '' ) );

			$name      = (string) ( $entry['name'] ?? '' );
			$separator = strpos( $name, '/' );

			self::tally( $namespaces, false === $separator ? '' : substr( $name, 0, $separator ) );
		}

		$inventory = array(
			'total_abilities' => count( Discover::collect_visible_abilities() ),
			'categories'      => self::rows( $categories, 'category' ),
			'namespaces'      => self::rows( $namespaces, 'namespace' ),
		);

		if ( array() !== $tab_groups ) {
			$inventory['tab_groups'] = self::rows( $tab_groups, 'tab_group' );
		}

		return $inventory;
	}

	/**
	 * Count one value, ignoring the empty string.
	 *
	 * @since  0.1.0
	 * @param  array<string, int> $tally Running tally, by reference.
	 * @param  string             $value Value to count.
	 * @return void
	 */
	private static function tally( array &$tally, string $value ): void {
		if ( '' === $value ) {
			return;
		}

		$tally[ $value ] = isset( $tally[ $value ] ) ? $tally[ $value ] + 1 : 1;
	}

	/**
	 * Turn a tally into rows, largest first.
	 *
	 * Ordered by count because a model scanning for "where would this live?"
	 * is best served by the biggest groups first; alphabetical would bury them.
	 *
	 * @since  0.1.0
	 * @param  array<string, int> $tally Value => count.
	 * @param  string             $key   Row key for the value.
	 * @return array<int, array<string, mixed>>
	 */
	private static function rows( array $tally, string $key ): array {
		arsort( $tally );

		$rows = array();

		foreach ( $tally as $value => $count ) {
			$rows[] = array(
				$key        => (string) $value,
				'abilities' => (int) $count,
			);
		}

		return $rows;
	}
}
