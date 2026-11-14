<?php
/**
 * Turns one server into the portable record of itself (Feature 105).
 *
 * ## Allowlist, not denylist
 *
 * ACF's exporter strips three fields and keeps the rest, which it can afford
 * because a field group holds no secrets. A server row sits beside a live
 * fleet pairing credential, OAuth client secrets, and user ids that name a
 * different person on another site. So this builds the record field by field
 * from a list of what MAY travel, and everything else is absent because it was
 * never added — not because something removed it afterwards.
 *
 * A leaked export cannot be recalled the way a bad database row can, which is
 * why the test that no secret appears in a fully-configured export is a
 * merge-blocker rather than a nice-to-have.
 *
 * ## Why `origin` is not `registered_from`
 *
 * `registered_from = 'plugin'` means another plugin creates the row. It does
 * NOT mean the row has no settings worth carrying: on this codebase's own dev
 * site the adapter's default server is the only one with all three protocol
 * tools switched on, and its `is_enabled` is a real off-switch. So the field
 * travels under a name that says what the importer should do with it — update
 * where present, never create.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Export
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Export;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row as ServerRow;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as AbilityQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as MetaQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as ToolQuery;
use AcrossAI_MCP_Manager\Includes\MCP\Controller as MCPController;
use AcrossAI_MCP_Manager\Includes\Utilities\ExportConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one server's export record.
 *
 * A11 pure service: static methods, no singleton, never Loader-wired.
 *
 * @since 0.4.9
 */
final class ServerExporter {

	/**
	 * Not instantiable.
	 *
	 * @since 0.4.9
	 */
	private function __construct() {}

	/**
	 * Export one server, limited to the blocks asked for.
	 *
	 * The record always names which blocks it carries. That is what lets an
	 * importer tell "not exported, leave it alone" from "exported as empty,
	 * set it to none" — without it, a server exported without its Tools block
	 * would be indistinguishable from one with no tools at all, and the import
	 * would empty it.
	 *
	 * @since  0.4.9
	 * @param  ServerRow         $row    Server to export.
	 * @param  array<int,string> $blocks Block keys to include; defaults to all.
	 * @return array<string, mixed>
	 */
	public static function export( ServerRow $row, array $blocks = ExportConstants::BLOCKS ): array {
		$server_id = (int) $row->id;
		$include   = array_values( array_intersect( ExportConstants::BLOCKS, $blocks ) );

		$record = array(
			'slug'        => (string) $row->server_slug,
			'name'        => (string) $row->server_name,
			'description' => (string) $row->description,
			'type'        => (string) $row->server_type,
			'origin'      => self::origin( $row ),
			'route'       => array(
				'namespace' => (string) $row->server_route_namespace,
				'route'     => (string) $row->server_route,
			),
			'blocks'      => $include,
		);

		foreach ( $include as $block ) {
			$value = self::block( $block, $row, $server_id );

			if ( null !== $value ) {
				$record[ $block ] = $value;
			}
		}

		/**
		 * Filters one server's export record.
		 *
		 * The seam a companion plugin uses to add its own block — acrossai-pro
		 * contributing its embeds settings, say — without this plugin knowing
		 * they exist. A listener MUST also append its key to `blocks`, or the
		 * importer will correctly ignore what it added.
		 *
		 * @since 0.4.9
		 *
		 * `|mixed` on the record because a filter return is third-party code
		 * and can be any type; narrowing it would make the is_array() guard
		 * below look redundant when it is the only thing preventing a fatal.
		 *
		 * @param array<string, mixed>|mixed $record  The record so far.
		 * @param ServerRow                  $row     The server being exported.
		 * @param array<int, string>         $include Blocks the operator asked for.
		 */
		$filtered = apply_filters( 'acrossai_mcp_export_server', $record, $row, $include );

		return is_array( $filtered ) ? $filtered : $record;
	}

	/**
	 * Build one block, or null when it has nothing to say.
	 *
	 * @since  0.4.9
	 * @param  string    $block     Block key.
	 * @param  ServerRow $row       Server row.
	 * @param  int       $server_id Server row id.
	 * @return array<string, mixed>|null
	 */
	private static function block( string $block, ServerRow $row, int $server_id ): ?array {
		switch ( $block ) {
			case ExportConstants::BLOCK_STATUS:
				return array( 'enabled' => ! empty( $row->is_enabled ) );

			case ExportConstants::BLOCK_MESSAGE:
				return self::message( $server_id );

			case ExportConstants::BLOCK_TOOLS:
				return self::tools( $row, $server_id );

			case ExportConstants::BLOCK_ABILITIES:
				return self::abilities( $row, $server_id );

			case ExportConstants::BLOCK_ACCESS:
				return self::access( (string) $row->server_slug );

			case ExportConstants::BLOCK_CONNECTORS:
				return self::connectors( $server_id );
		}

		return null;
	}

	/**
	 * Whether an import may create this server.
	 *
	 * @since  0.4.9
	 * @param  ServerRow $row Server row.
	 * @return string
	 */
	private static function origin( ServerRow $row ): string {
		return ExportConstants::ORIGIN_DATABASE === (string) $row->registered_from
			? ExportConstants::ORIGIN_DATABASE
			: ExportConstants::ORIGIN_PLUGIN;
	}

	/**
	 * The connect message, and only the text when it is the operator's own.
	 *
	 * The System default is composed by the plugin and kept current with it.
	 * Writing that text into a file would freeze one release's wording and
	 * reinstate it on a site running a newer one — so the default mode travels
	 * as a mode, with no text.
	 *
	 * @since  0.4.9
	 * @param  int $server_id Server row id.
	 * @return array<string, mixed>
	 */
	private static function message( int $server_id ): array {
		$stored = MetaQuery::get_meta( $server_id, MCPController::INSTRUCTIONS_META_KEY );
		$custom = null !== $stored && '' !== $stored;

		return $custom
			? array(
				'mode' => 'custom',
				'text' => (string) $stored,
			)
			: array( 'mode' => 'default' );
	}

	/**
	 * The tool configuration, as a named state plus a delta.
	 *
	 * Measured on this codebase's dev site, every server's curated set is
	 * byte-for-byte what Reset to Type Defaults writes — zero hand-made
	 * decisions across four servers. Recording the absolute slug list would
	 * therefore carry no information AND pin an imported server to one
	 * release's defaults, silently undoing a later release's changes.
	 *
	 * The three protocol columns are part of this block because they are part
	 * of the same screen and the same `split_payload()` contract, even though
	 * they live in columns rather than rows.
	 *
	 * @since  0.4.9
	 * @param  ServerRow $row       Server row.
	 * @param  int       $server_id Server row id.
	 * @return array<string, mixed>
	 */
	private static function tools( ServerRow $row, int $server_id ): array {
		$curated = array_values( ToolQuery::instance()->get_added_slugs( $server_id ) );
		sort( $curated );

		// `declared_tools()`, not `tools_for()`: the former is what
		// `apply_type_defaults()` actually writes. The latter narrows to
		// abilities registered right now, so comparing against it reports a
		// delta on a site where a contributing plugin is merely inactive.
		$split    = ToolPolicy::split_payload( ServerTypes::declared_tools( (string) $row->server_type ) );
		$defaults = array_values( $split['curated'] );
		sort( $defaults );

		$pool = array_values( ServerTypes::pool( (string) $row->server_type ) );
		sort( $pool );

		$state = ExportConstants::TOOLS_CUSTOM;

		if ( $curated === $defaults ) {
			$state = ExportConstants::TOOLS_TYPE_DEFAULTS;
		} elseif ( array() === $curated ) {
			$state = ExportConstants::TOOLS_NONE;
		} elseif ( $curated === $pool ) {
			$state = ExportConstants::TOOLS_ALL;
		}

		$baseline = self::baseline_for( $state, $defaults, $pool );

		return array(
			'state'    => $state,
			'added'    => array_values( array_diff( $curated, $baseline ) ),
			'removed'  => array_values( array_diff( $baseline, $curated ) ),
			'protocol' => array(
				'discover' => ! empty( $row->tool_discover_abilities ),
				'get_info' => ! empty( $row->tool_get_ability_info ),
				'execute'  => ! empty( $row->tool_execute_ability ),
			),
		);
	}

	/**
	 * The slug set a delta is measured against.
	 *
	 * `custom` measures against type defaults deliberately: it is the state an
	 * import resets to first, so the delta applied afterwards is the shortest
	 * description of the difference.
	 *
	 * @since  0.4.9
	 * @param  string             $state    Tool state.
	 * @param  array<int, string> $defaults Type defaults.
	 * @param  array<int, string> $pool     Everything offerable.
	 * @return array<int, string>
	 */
	private static function baseline_for( string $state, array $defaults, array $pool ): array {
		if ( ExportConstants::TOOLS_ALL === $state ) {
			return $pool;
		}

		if ( ExportConstants::TOOLS_NONE === $state ) {
			return array();
		}

		return $defaults;
	}

	/**
	 * The abilities policy, and overrides only where they mean anything.
	 *
	 * The Abilities tab's three buttons ARE the three stored values — Enable
	 * All is `expose`, Disable All is `hide`, Reset to Ability Defaults is
	 * `per-ability`. Under the first two the override table is not consulted
	 * at read time, so exporting a list there would carry rows that cannot
	 * affect anything.
	 *
	 * @since  0.4.9
	 * @param  ServerRow $row       Server row.
	 * @param  int       $server_id Server row id.
	 * @return array<string, mixed>
	 */
	private static function abilities( ServerRow $row, int $server_id ): array {
		$policy = (string) $row->abilities_default_policy;
		$policy = '' !== $policy ? $policy : 'per-ability';

		$block = array( 'policy' => $policy );

		if ( 'per-ability' !== $policy ) {
			return $block;
		}

		$overrides = array();

		foreach ( (array) AbilityQuery::instance()->query(
			array(
				'server_id' => $server_id,
				'number'    => 0,
			)
		) as $override ) {
			if ( ! is_object( $override ) || ! isset( $override->ability_slug ) ) {
				continue;
			}

			$overrides[] = array(
				'slug'    => (string) $override->ability_slug,
				'exposed' => ! empty( $override->is_exposed ),
			);
		}

		usort(
			$overrides,
			static function ( array $a, array $b ): int {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);

		$block['overrides'] = $overrides;

		return $block;
	}

	/**
	 * The access rule, with any user list refused.
	 *
	 * `everyone` and `authenticated` travel cleanly. Roles and capabilities
	 * travel as slugs, and the importer reports any the destination does not
	 * have rather than dropping them silently. A rule naming individual USERS
	 * is the one that cannot travel at all: a user id is a different person on
	 * another site, so carrying it would grant access to whoever holds that id.
	 *
	 * The refusal is recorded rather than hidden — an operator seeing
	 * `users_omitted` knows the destination needs the people picking again.
	 *
	 * @since  0.4.9
	 * @param  string $server_slug Server slug; the access-control resource key.
	 * @return array<string, mixed>
	 */
	private static function access( string $server_slug ): array {
		// Null when the vendor package has not booted — on an install where
		// access control is unavailable the honest export is "no rule", not a
		// fatal in the middle of a download.
		$manager = AcrossAI_MCP_Access_Control::instance()->get_manager();

		if ( null === $manager ) {
			return array( 'rule' => '' );
		}

		// `get_rule()` always returns array{key: string, value: array<string>},
		// filling in '' and [] when no row exists — so no presence guards here.
		$rule = $manager->get_query()->get_rule( 'acrossai-mcp-manager', $server_slug );

		$key    = (string) $rule['key'];
		$values = array_map( 'strval', $rule['value'] );

		if ( ExportConstants::ACCESS_RULE_USER === $key ) {
			return array(
				'rule'          => $key,
				'values'        => array(),
				'users_omitted' => count( $values ),
			);
		}

		return array(
			'rule'   => $key,
			'values' => $values,
		);
	}

	/**
	 * Which connectors may reach this server.
	 *
	 * The OAuth clients themselves are credentials and never travel, so an
	 * imported server arrives with this list intact and no live grants — every
	 * client re-authorises. `approved_user_ids` is deliberately absent for the
	 * same reason the access rule refuses users.
	 *
	 * `peek_server_settings()`, NOT `get_server_settings()`. The latter
	 * persists a computed seed on first read, so exporting a site would have
	 * written a settings row to every server that had never been configured —
	 * an export that mutates the site it is reading. Caught on this codebase's
	 * own dev site: a single export run created rows on two servers.
	 *
	 * An unset server reports `stored: false` rather than today's computed
	 * defaults. Those are the same values only until a release changes them or
	 * a connector profile is absent on the destination, and an import should
	 * leave an unconfigured server unconfigured — the same reasoning that made
	 * the tools block a delta rather than an absolute list.
	 *
	 * @since  0.4.9
	 * @param  int $server_id Server row id.
	 * @return array<string, mixed>
	 */
	private static function connectors( int $server_id ): array {
		$settings = ConnectorSettings::peek_server_settings( $server_id );

		if ( null === $settings ) {
			return array( 'stored' => false );
		}

		$slugs = isset( $settings['enabled_slugs'] ) && is_array( $settings['enabled_slugs'] )
			? array_values( array_map( 'strval', $settings['enabled_slugs'] ) )
			: array();

		sort( $slugs );

		return array(
			'stored'                 => true,
			'enabled_slugs'          => $slugs,
			'allow_other'            => ! empty( $settings['allow_other'] ),
			'require_admin_approval' => ! empty( $settings['require_admin_approval'] ),
		);
	}
}
