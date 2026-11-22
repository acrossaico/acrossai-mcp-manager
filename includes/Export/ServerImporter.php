<?php
/**
 * Applies an export file to this site (Feature 105).
 *
 * ## Two rules decide everything
 *
 *  - A record's `blocks` list is the only thing touched. A block the record
 *    does not carry is left exactly as it is — NOT emptied. Without that
 *    distinction a server exported without its Tools block would be
 *    indistinguishable from one exported with no tools, and importing it
 *    would wipe the destination's curation.
 *  - A record's `origin` decides whether the server may be CREATED, never
 *    whether its settings may be applied. A plugin-registered server's
 *    settings travel like any other's; what cannot happen is writing a
 *    `database` row under its slug, which would shadow the real one the moment
 *    its plugin was installed.
 *
 * ## The ordering that is not a preference
 *
 * `PolicyTransition::apply()` clears every override row as a side effect of
 * changing the policy, and SKIPS that clear when the policy is unchanged.
 * Both halves are traps:
 *
 *  - Write overrides first, then the policy → the overrides are deleted.
 *  - Import a server whose policy already matches → the destination's stale
 *    overrides survive, which contradicts replace semantics.
 *
 * So the abilities block is always: set the policy, then clear overrides
 * unconditionally, then write the imported ones. Not "clear if changed".
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Export
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Export;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\PolicyTransition;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as ServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row as ServerRow;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerEnablement;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as AbilityQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as MetaQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as ToolQuery;
use AcrossAI_MCP_Manager\Includes\MCP\Controller as MCPController;
use AcrossAI_MCP_Manager\Includes\Utilities\ExportConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a validated payload onto this site.
 *
 * @since 0.4.9
 */
final class ServerImporter {

	/**
	 * Outcome codes, one per server in the report.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const RESULT_UPDATED       = 'updated';
	public const RESULT_CREATED       = 'created';
	public const RESULT_SKIPPED       = 'skipped-plugin-owned';
	public const RESULT_ROUTE_TAKEN   = 'skipped-route-conflict';
	public const RESULT_CREATE_FAILED = 'create-failed';

	/**
	 * Not instantiable.
	 *
	 * @since 0.4.9
	 */
	private function __construct() {}

	/**
	 * Apply a payload that has already passed {@see ImportValidator}.
	 *
	 * Takes the snapshot FIRST, before any write, and reports its key so the
	 * screen can offer Restore with the thing it just created.
	 *
	 * @since  0.4.9
	 * @param  array<string, mixed> $payload Validated payload.
	 * @param  bool                 $enable  Whether a `status` block may switch a server ON.
	 * @return array{snapshot:int, servers:array<int, array<string, mixed>>}
	 */
	public static function import( array $payload, bool $enable = false ): array {
		$snapshot = Snapshots::take( 'import' );

		$report = array();

		foreach ( (array) $payload['servers'] as $record ) {
			$report[] = self::one( (array) $record, $enable );
		}

		return array(
			'snapshot' => $snapshot,
			'servers'  => $report,
		);
	}

	/**
	 * Apply one server record.
	 *
	 * @since  0.4.9
	 * @param  array<string, mixed> $record One validated server record.
	 * @param  bool                 $enable Whether status may switch it on.
	 * @return array<string, mixed>
	 */
	private static function one( array $record, bool $enable ): array {
		$slug   = (string) $record['slug'];
		$blocks = isset( $record['blocks'] ) && is_array( $record['blocks'] ) ? $record['blocks'] : array();
		$row    = self::find( $slug );

		if ( null === $row ) {
			if ( ExportConstants::ORIGIN_PLUGIN === (string) $record['origin'] ) {
				return self::outcome( $slug, self::RESULT_SKIPPED, $blocks );
			}

			$conflict = self::route_owner( $record );

			if ( null !== $conflict ) {
				return self::outcome( $slug, self::RESULT_ROUTE_TAKEN, $blocks, $conflict );
			}

			$row = self::create( $record );

			if ( null === $row ) {
				return self::outcome( $slug, self::RESULT_CREATE_FAILED, $blocks );
			}

			self::apply_blocks( $row, $record, $blocks, $enable );

			return self::outcome( $slug, self::RESULT_CREATED, $blocks );
		}

		self::apply_blocks( $row, $record, $blocks, $enable );

		return self::outcome( $slug, self::RESULT_UPDATED, $blocks );
	}

	/**
	 * Find a server by slug.
	 *
	 * @since  0.4.9
	 * @param  string $slug Server slug.
	 * @return ServerRow|null
	 */
	private static function find( string $slug ): ?ServerRow {
		$rows = ServerQuery::instance()->query(
			array(
				'server_slug' => $slug,
				'number'      => 1,
			)
		);

		return isset( $rows[0] ) && $rows[0] instanceof ServerRow ? $rows[0] : null;
	}

	/**
	 * The slug of a different server already answering on this route, if any.
	 *
	 * Separate from a slug collision: two servers cannot share
	 * `namespace + route`, so a record whose slug is new but whose route is
	 * taken is refused with the conflict named rather than silently re-routed
	 * to something the file did not ask for.
	 *
	 * @since  0.4.9
	 * @param  array<string, mixed> $record Server record.
	 * @return string|null
	 */
	private static function route_owner( array $record ): ?string {
		$route = isset( $record['route'] ) && is_array( $record['route'] ) ? $record['route'] : array();

		$namespace = isset( $route['namespace'] ) && is_scalar( $route['namespace'] ) ? (string) $route['namespace'] : '';
		$path      = isset( $route['route'] ) && is_scalar( $route['route'] ) ? (string) $route['route'] : '';

		if ( '' === $namespace || '' === $path ) {
			return null;
		}

		foreach ( (array) ServerQuery::instance()->query( array( 'number' => 0 ) ) as $row ) {
			if ( ! $row instanceof ServerRow ) {
				continue;
			}

			if ( (string) $row->server_route_namespace === $namespace && (string) $row->server_route === $path ) {
				return (string) $row->server_slug;
			}
		}

		return null;
	}

	/**
	 * Create a server from a record.
	 *
	 * Always `registered_from = 'database'`, because that is what it now is —
	 * the origin in the file described where it came from on the SOURCE site,
	 * and only ever governed whether creating it here was allowed.
	 *
	 * Created disabled. A `status` block can switch it on, but only when the
	 * operator asked for that on the import screen: a file should not be able
	 * to publish an MCP endpoint on a site nobody has looked at yet.
	 *
	 * @since  0.4.9
	 * @param  array<string, mixed> $record Server record.
	 * @return ServerRow|null
	 */
	private static function create( array $record ): ?ServerRow {
		$slug  = (string) $record['slug'];
		$route = isset( $record['route'] ) && is_array( $record['route'] ) ? $record['route'] : array();

		$id = ServerQuery::instance()->add_item(
			array(
				'server_name'            => self::text( $record, 'name', $slug ),
				'server_slug'            => $slug,
				'description'            => self::text( $record, 'description', '' ),
				'is_enabled'             => 0,
				'registered_from'        => ExportConstants::ORIGIN_DATABASE,
				'server_route_namespace' => isset( $route['namespace'] ) && is_scalar( $route['namespace'] )
					? (string) $route['namespace']
					: 'acrossai-mcp',
				'server_route'           => isset( $route['route'] ) && is_scalar( $route['route'] )
					? (string) $route['route']
					: $slug,
				'server_version'         => 'v1.0.0',
				'server_type'            => self::text( $record, 'type', ServerTypes::default_slug() ),
			)
		);

		return is_numeric( $id ) && (int) $id > 0 ? self::find( $slug ) : null;
	}

	/**
	 * Write each block the record carries, in the order the comments demand.
	 *
	 * @since  0.4.9
	 * @param  ServerRow            $row    Destination server.
	 * @param  array<string, mixed> $record Server record.
	 * @param  array<int, string>   $blocks Blocks this record carries.
	 * @param  bool                 $enable Whether status may switch it on.
	 * @return void
	 */
	private static function apply_blocks( ServerRow $row, array $record, array $blocks, bool $enable ): void {
		$id = (int) $row->id;

		if ( in_array( ExportConstants::BLOCK_STATUS, $blocks, true ) ) {
			self::write_status( $id, (array) $record[ ExportConstants::BLOCK_STATUS ], $enable );
		}

		if ( in_array( ExportConstants::BLOCK_MESSAGE, $blocks, true ) ) {
			self::write_message( $id, (array) $record[ ExportConstants::BLOCK_MESSAGE ] );
		}

		if ( in_array( ExportConstants::BLOCK_CONNECTORS, $blocks, true ) ) {
			self::write_connectors( $id, (array) $record[ ExportConstants::BLOCK_CONNECTORS ] );
		}

		if ( in_array( ExportConstants::BLOCK_ACCESS, $blocks, true ) ) {
			self::write_access( (string) $row->server_slug, (array) $record[ ExportConstants::BLOCK_ACCESS ] );
		}

		// Abilities BEFORE tools is incidental; policy before overrides is not.
		if ( in_array( ExportConstants::BLOCK_ABILITIES, $blocks, true ) ) {
			self::write_abilities( $id, (array) $record[ ExportConstants::BLOCK_ABILITIES ] );
		}

		if ( in_array( ExportConstants::BLOCK_TOOLS, $blocks, true ) ) {
			self::write_tools( $row, (array) $record[ ExportConstants::BLOCK_TOOLS ] );
		}
	}

	/**
	 * Enabled state, gated on the operator having asked for it.
	 *
	 * @since  0.4.9
	 * @param  int                  $id     Server row id.
	 * @param  array<string, mixed> $status Status block.
	 * @param  bool                 $enable Whether switching ON is permitted.
	 * @return void
	 */
	private static function write_status( int $id, array $status, bool $enable ): void {
		$wanted = ! empty( $status['enabled'] );

		// Switching OFF always applies — that direction cannot expose
		// anything. Switching ON needs the operator's say-so, because a file
		// must not be able to publish an endpoint by itself.
		if ( $wanted && ! $enable ) {
			return;
		}

		// Through `ServerEnablement::set()`, never a direct `update_item()`.
		// It enforces the server-type requirement on off -> on, so a file that
		// says "enabled" for a type whose add-on is not installed here is
		// refused rather than publishing an endpoint that can only answer with
		// a setup diagnostic. The F090 governance gate fails the build on a
		// direct write, and caught this one.
		//
		// A refusal is deliberately silent: the server is simply left off, and
		// the Overview tab already explains why that type cannot be enabled.
		ServerEnablement::set( $id, $wanted );
	}

	/**
	 * The connect message.
	 *
	 * A `default` mode clears any custom text, which is the whole point of
	 * carrying the mode: it is how "this server uses the system default" is
	 * expressed, and leaving stale custom text behind would silently ignore it.
	 *
	 * @since  0.4.9
	 * @param  int                  $id      Server row id.
	 * @param  array<string, mixed> $message Message block.
	 * @return void
	 */
	private static function write_message( int $id, array $message ): void {
		$mode = isset( $message['mode'] ) && is_scalar( $message['mode'] ) ? (string) $message['mode'] : 'default';

		if ( 'custom' !== $mode ) {
			MetaQuery::update_meta( $id, MCPController::INSTRUCTIONS_META_KEY, '' );

			return;
		}

		$text = isset( $message['text'] ) && is_scalar( $message['text'] ) ? (string) $message['text'] : '';

		MetaQuery::update_meta( $id, MCPController::INSTRUCTIONS_META_KEY, $text );
	}

	/**
	 * Connector enablement.
	 *
	 * A record marked `stored: false` means the source server had nothing
	 * configured, so the destination is left alone rather than being written
	 * with the source's computed defaults.
	 *
	 * @since  0.4.9
	 * @param  int                  $id         Server row id.
	 * @param  array<string, mixed> $connectors Connectors block.
	 * @return void
	 */
	private static function write_connectors( int $id, array $connectors ): void {
		if ( ! isset( $connectors['stored'] ) || true !== $connectors['stored'] ) {
			return;
		}

		$slugs = isset( $connectors['enabled_slugs'] ) && is_array( $connectors['enabled_slugs'] )
			? array_values( array_map( 'sanitize_key', array_map( 'strval', $connectors['enabled_slugs'] ) ) )
			: array();

		ConnectorSettings::save_server_settings(
			$id,
			array(
				'enabled_slugs'          => $slugs,
				'allow_other'            => ! empty( $connectors['allow_other'] ),
				'require_admin_approval' => ! empty( $connectors['require_admin_approval'] ),
			)
		);
	}

	/**
	 * The access rule.
	 *
	 * A rule naming individual users never travels, so one cannot arrive here.
	 * Role and capability values travel as slugs and are written as given —
	 * a role the destination does not have simply matches nobody, which is the
	 * safe direction to fail in.
	 *
	 * @since  0.4.9
	 * @param  string               $slug   Server slug; the resource key.
	 * @param  array<string, mixed> $access Access block.
	 * @return void
	 */
	private static function write_access( string $slug, array $access ): void {
		$manager = AcrossAI_MCP_Access_Control::instance()->get_manager();

		if ( null === $manager ) {
			return;
		}

		$rule = isset( $access['rule'] ) && is_scalar( $access['rule'] ) ? sanitize_key( (string) $access['rule'] ) : '';

		if ( ExportConstants::ACCESS_RULE_USER === $rule ) {
			return;
		}

		$values = isset( $access['values'] ) && is_array( $access['values'] )
			? array_values( array_map( 'strval', $access['values'] ) )
			: array();

		$manager->get_query()->set_rule( 'acrossai-mcp-manager', $slug, $rule, $values );
	}

	/**
	 * The abilities policy, then the overrides.
	 *
	 * The clear is unconditional. `PolicyTransition::apply()` performs its own
	 * when the policy CHANGES and skips it when the value is unchanged — so
	 * relying on it would leave this site's stale overrides inside a server
	 * imported with a matching policy.
	 *
	 * The overrides are then written whatever the policy is. They are not a
	 * `per-ability` detail: an explicit row wins over the policy, so Enable
	 * All plus three hidden abilities is three rows that must survive a round
	 * trip.
	 *
	 * @since  0.4.9
	 * @param  int                  $id        Server row id.
	 * @param  array<string, mixed> $abilities Abilities block.
	 * @return void
	 */
	private static function write_abilities( int $id, array $abilities ): void {
		$policy = isset( $abilities['policy'] ) && is_scalar( $abilities['policy'] )
			? sanitize_key( (string) $abilities['policy'] )
			: 'per-ability';

		if ( ! in_array( $policy, array( 'expose', 'hide', 'per-ability' ), true ) ) {
			$policy = 'per-ability';
		}

		PolicyTransition::apply( $id, $policy );

		AbilityQuery::instance()->delete_items_for_server( $id );

		// No early return for `expose` / `hide`. An override row beats the
		// policy — `ExposureResolver::resolve_effective()` checks rows first
		// and only falls through to the policy when none exists — so a server
		// set to Enable All with three abilities toggled off needs those three
		// rows written back, or the import silently turns them all on.
		$overrides = isset( $abilities['overrides'] ) && is_array( $abilities['overrides'] )
			? $abilities['overrides']
			: array();

		foreach ( $overrides as $override ) {
			if ( ! is_array( $override ) || ! isset( $override['slug'] ) || ! is_scalar( $override['slug'] ) ) {
				continue;
			}

			$slug = trim( (string) $override['slug'] );

			if ( '' === $slug ) {
				continue;
			}

			AbilityQuery::instance()->upsert( $id, $slug, ! empty( $override['exposed'] ) );
		}
	}

	/**
	 * The tool state, then the delta, then the protocol columns.
	 *
	 * Resetting to the named state first is what makes the delta short and
	 * version-proof: `type-defaults` means "whatever this release says", so an
	 * imported server picks up a later release's defaults instead of being
	 * pinned to the source's.
	 *
	 * A slug whose plugin is absent here is written anyway. Curated rows
	 * deliberately outlive the plugin that registered the ability — the row
	 * waits, is filtered out at compose time, and returns intact the moment
	 * that plugin is active.
	 *
	 * @since  0.4.9
	 * @param  ServerRow            $row   Destination server.
	 * @param  array<string, mixed> $tools Tools block.
	 * @return void
	 */
	private static function write_tools( ServerRow $row, array $tools ): void {
		$id    = (int) $row->id;
		$type  = (string) $row->server_type;
		$state = isset( $tools['state'] ) && is_scalar( $tools['state'] ) ? sanitize_key( (string) $tools['state'] ) : '';

		switch ( $state ) {
			case ExportConstants::TOOLS_ALL:
				$base = array_values( ServerTypes::pool( $type ) );
				break;

			case ExportConstants::TOOLS_NONE:
				$base = array();
				break;

			default:
				// `type-defaults` and `custom` both measure their delta
				// against the type's declared set, so both reset to it first.
				$split = ToolPolicy::split_payload( ServerTypes::declared_tools( $type ) );
				$base  = array_values( $split['curated'] );
				break;
		}

		$added   = isset( $tools['added'] ) && is_array( $tools['added'] ) ? array_map( 'strval', $tools['added'] ) : array();
		$removed = isset( $tools['removed'] ) && is_array( $tools['removed'] ) ? array_map( 'strval', $tools['removed'] ) : array();

		$desired = array_values( array_diff( array_unique( array_merge( $base, $added ) ), $removed ) );

		ToolQuery::instance()->replace_set( $id, $desired );

		if ( ! isset( $tools['protocol'] ) || ! is_array( $tools['protocol'] ) ) {
			return;
		}

		$protocol = $tools['protocol'];

		ServerQuery::instance()->update_item(
			$id,
			array(
				'tool_discover_abilities' => ! empty( $protocol['discover'] ) ? 1 : 0,
				'tool_get_ability_info'   => ! empty( $protocol['get_info'] ) ? 1 : 0,
				'tool_execute_ability'    => ! empty( $protocol['execute'] ) ? 1 : 0,
			)
		);
	}

	/**
	 * One line of the report.
	 *
	 * @since  0.4.9
	 * @param  string             $slug   Server slug.
	 * @param  string             $result Outcome code.
	 * @param  array<int, string> $blocks Blocks the record carried.
	 * @param  string             $detail Extra context, e.g. a conflicting slug.
	 * @return array<string, mixed>
	 */
	private static function outcome( string $slug, string $result, array $blocks, string $detail = '' ): array {
		return array(
			'slug'   => $slug,
			'result' => $result,
			'blocks' => array_values( $blocks ),
			'detail' => $detail,
		);
	}

	/**
	 * One scalar field, with a fallback.
	 *
	 * @since  0.4.9
	 * @param  array<string, mixed> $record   Server record.
	 * @param  string               $key      Field name.
	 * @param  string               $fallback Value when absent.
	 * @return string
	 */
	private static function text( array $record, string $key, string $fallback ): string {
		return isset( $record[ $key ] ) && is_scalar( $record[ $key ] )
			? (string) $record[ $key ]
			: $fallback;
	}
}
