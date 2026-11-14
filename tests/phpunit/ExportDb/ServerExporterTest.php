<?php
/**
 * The export contract, against a real database (Feature 105).
 *
 * Needs WordPress and MySQL because an export reads six storage layers —
 * a table row, three columns, two more tables and two meta keys — and the
 * question each test asks is what came OUT, not what a pure function would
 * have returned.
 *
 * The first test is the merge-blocker. Everything else can be fixed in a
 * follow-up; a leaked export cannot, because the file is already in somebody's
 * inbox by the time anyone notices.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ExportDb;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as ServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row as ServerRow;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Table as ServerTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as AbilityQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Table as AbilityTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as MetaQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Table as MetaTable;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as ToolQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Table as ToolTable;
use AcrossAI_MCP_Manager\Includes\Export\Envelope;
use AcrossAI_MCP_Manager\Includes\Export\ServerExporter;
use AcrossAI_MCP_Manager\Includes\MCP\Controller as MCPController;
use AcrossAI_MCP_Manager\Includes\Utilities\ExportConstants;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Export\ServerExporter
 * @covers \AcrossAI_MCP_Manager\Includes\Export\Envelope
 */
class ServerExporterTest extends \WP_UnitTestCase {

	/**
	 * Server ids created by a test, removed in tear_down.
	 *
	 * @var int[]
	 */
	private $created = array();

	public function set_up(): void {
		parent::set_up();

		ServerTable::instance()->maybe_upgrade();
		ToolTable::instance()->maybe_upgrade();
		AbilityTable::instance()->maybe_upgrade();
		MetaTable::instance()->maybe_upgrade();
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			ToolQuery::instance()->delete_items_for_server( $id );
			AbilityQuery::instance()->delete_items_for_server( $id );
			ServerQuery::instance()->delete_item( $id );
		}

		$this->created = array();

		parent::tear_down();
	}

	/**
	 * Create a server and return its row.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 */
	private function server( array $overrides = array() ): ServerRow {
		$slug = isset( $overrides['server_slug'] )
			? (string) $overrides['server_slug']
			: 'export-test-' . wp_generate_password( 6, false, false );

		$id = (int) ServerQuery::instance()->add_item(
			array_merge(
				array(
					'server_name'            => 'Export test',
					'server_slug'            => $slug,
					'description'            => 'Seeded by ServerExporterTest',
					'is_enabled'             => 1,
					'registered_from'        => 'database',
					'server_route_namespace' => 'mcp',
					'server_route'           => $slug,
					'server_version'         => 'v1.0.0',
					'server_type'            => 'mcp-adapter',
				),
				$overrides
			)
		);

		$this->created[] = $id;

		$row = ServerQuery::instance()->get_item( $id );

		$this->assertInstanceOf( ServerRow::class, $row );

		return $row;
	}

	// ---------------------------------------------------------------- //
	// The one that must never regress                                   //
	// ---------------------------------------------------------------- //

	/**
	 * A fully configured server exports no secret and no site-bound value.
	 *
	 * The server is given everything that must NOT travel — a fleet pairing
	 * credential, a host list carrying another site's public key, pending and
	 * approved user ids — and then exported with every block requested.
	 *
	 * This is the regression the allowlist exists to prevent. If it ever fails,
	 * the fix is to stop adding the field, never to filter it out afterwards:
	 * a denylist only removes what somebody remembered to name.
	 */
	public function test_a_fully_configured_export_leaks_no_secret(): void {
		$row = $this->server();
		$id  = (int) $row->id;

		MetaQuery::update_meta( $id, '_fleet_security_id', 'jxdq6m3qqvvj' );
		MetaQuery::update_meta(
			$id,
			'_fleet_hosts',
			(string) wp_json_encode(
				array(
					array(
						'key'    => 'agency-example-com-718b6c16',
						'host'   => 'agency.example.com',
						'pubkey' => 'SSHRSAPUBLICKEYMATERIALc3RyaW5n',
					),
				)
			)
		);
		MetaQuery::update_meta( $id, '_acrossai_mcp_pending_users', (string) wp_json_encode( array( 4242 ) ) );
		MetaQuery::update_meta(
			$id,
			'_acrossai_mcp_server_settings',
			(string) wp_json_encode(
				array(
					'enabled_slugs'          => array( 'claude' ),
					'allow_other'            => false,
					'require_admin_approval' => true,
					'approved_users'         => array( 4242 ),
				)
			)
		);

		$json = (string) wp_json_encode( ServerExporter::export( $row ) );

		$forbidden = array(
			'jxdq6m3qqvvj',
			'_fleet_security_id',
			'_fleet_hosts',
			'pubkey',
			'SSHRSAPUBLICKEYMATERIAL',
			'agency-example-com-718b6c16',
			'pending_users',
			'approved_users',
			'4242',
		);

		$leaked = array();

		foreach ( $forbidden as $needle ) {
			if ( false !== stripos( $json, $needle ) ) {
				$leaked[] = $needle;
			}
		}

		$this->assertSame(
			array(),
			$leaked,
			"An export must never carry a credential or a site-bound value. Leaked: " . implode( ', ', $leaked )
				. "\nFix by not ADDING the field — filtering it out afterwards only removes what somebody remembered to name."
		);
	}

	/**
	 * Exporting reads. It does not write.
	 *
	 * Pinned because it did not: `ConnectorSettings::get_server_settings()`
	 * persists a computed seed on first read, so a single export run created
	 * settings rows on two servers that had never been configured. An operator
	 * pressing "Show JSON" must not change their site.
	 */
	public function test_export_does_not_write_to_the_database(): void {
		global $wpdb;

		$row = $this->server();

		$table  = $wpdb->prefix . 'acrossai_mcp_servers_meta';
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB

		ServerExporter::export( $row );
		ServerExporter::export( $row );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB

		$this->assertSame( $before, $after, 'Exporting a server must not create meta rows.' );
	}

	// ---------------------------------------------------------------- //
	// Blocks                                                            //
	// ---------------------------------------------------------------- //

	/** Only the blocks asked for appear, and `blocks` names exactly those. */
	public function test_only_requested_blocks_appear(): void {
		$row = $this->server();

		$record = ServerExporter::export(
			$row,
			array( ExportConstants::BLOCK_STATUS, ExportConstants::BLOCK_ABILITIES )
		);

		$this->assertSame(
			array( ExportConstants::BLOCK_STATUS, ExportConstants::BLOCK_ABILITIES ),
			$record['blocks']
		);
		$this->assertArrayHasKey( ExportConstants::BLOCK_STATUS, $record );
		$this->assertArrayHasKey( ExportConstants::BLOCK_ABILITIES, $record );
		$this->assertArrayNotHasKey( ExportConstants::BLOCK_TOOLS, $record );
		$this->assertArrayNotHasKey( ExportConstants::BLOCK_CONNECTORS, $record );
	}

	/**
	 * `blocks` is the importer's whole safety net, so it must match reality.
	 *
	 * Without it a server exported without its Tools block is indistinguishable
	 * from one exported with no tools at all, and the import empties it.
	 */
	public function test_blocks_lists_exactly_the_keys_present(): void {
		$row    = $this->server();
		$record = ServerExporter::export( $row );

		foreach ( $record['blocks'] as $block ) {
			$this->assertArrayHasKey( $block, $record, "blocks names {$block} but the record has no such key." );
		}

		foreach ( ExportConstants::BLOCKS as $block ) {
			if ( ! in_array( $block, $record['blocks'], true ) ) {
				$this->assertArrayNotHasKey( $block, $record, "{$block} is present but not named in blocks." );
			}
		}
	}

	/** An unknown block name is ignored rather than producing a null key. */
	public function test_an_unknown_block_is_ignored(): void {
		$row    = $this->server();
		$record = ServerExporter::export( $row, array( 'status', 'not-a-real-block' ) );

		$this->assertSame( array( ExportConstants::BLOCK_STATUS ), $record['blocks'] );
		$this->assertArrayNotHasKey( 'not-a-real-block', $record );
	}

	// ---------------------------------------------------------------- //
	// Tools: state + delta                                              //
	// ---------------------------------------------------------------- //

	/** Curation matching the type's defaults records no delta. */
	public function test_tools_report_type_defaults_when_curation_matches(): void {
		$row = $this->server();

		\AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::apply_type_defaults( (int) $row->id, 'mcp-adapter' );

		$fresh = ServerQuery::instance()->get_item( (int) $row->id );
		$this->assertInstanceOf( ServerRow::class, $fresh );

		$tools = ServerExporter::export( $fresh )[ ExportConstants::BLOCK_TOOLS ];

		$this->assertSame( ExportConstants::TOOLS_TYPE_DEFAULTS, $tools['state'] );
		$this->assertSame( array(), $tools['added'] );
		$this->assertSame( array(), $tools['removed'] );
	}

	/** A hand-added slug shows up as a delta, not as a 15-item absolute list. */
	public function test_tools_record_an_added_slug_as_a_delta(): void {
		$row = $this->server();
		$id  = (int) $row->id;

		\AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy::apply_type_defaults( $id, 'mcp-adapter' );

		$curated   = ToolQuery::instance()->get_added_slugs( $id );
		$curated[] = 'toolset/invented-by-hand';

		ToolQuery::instance()->replace_set( $id, $curated );

		$fresh = ServerQuery::instance()->get_item( $id );
		$this->assertInstanceOf( ServerRow::class, $fresh );

		$tools = ServerExporter::export( $fresh )[ ExportConstants::BLOCK_TOOLS ];

		$this->assertSame( ExportConstants::TOOLS_CUSTOM, $tools['state'] );
		$this->assertSame( array( 'toolset/invented-by-hand' ), $tools['added'] );
		$this->assertSame( array(), $tools['removed'] );
	}

	/** The three protocol columns travel inside the tools block. */
	public function test_tools_carry_the_protocol_columns(): void {
		$row = $this->server(
			array(
				'tool_discover_abilities' => 1,
				'tool_get_ability_info'   => 1,
				'tool_execute_ability'    => 0,
			)
		);

		$protocol = ServerExporter::export( $row )[ ExportConstants::BLOCK_TOOLS ]['protocol'];

		$this->assertTrue( $protocol['discover'] );
		$this->assertTrue( $protocol['get_info'] );
		$this->assertFalse( $protocol['execute'] );
	}

	// ---------------------------------------------------------------- //
	// Abilities                                                         //
	// ---------------------------------------------------------------- //

	/** Under Enable All the override list cannot affect anything, so it is absent. */
	public function test_abilities_omit_overrides_under_expose(): void {
		$row = $this->server( array( 'abilities_default_policy' => 'expose' ) );

		AbilityQuery::instance()->upsert( (int) $row->id, 'toolset/content', false );

		$fresh = ServerQuery::instance()->get_item( (int) $row->id );
		$this->assertInstanceOf( ServerRow::class, $fresh );

		$abilities = ServerExporter::export( $fresh )[ ExportConstants::BLOCK_ABILITIES ];

		$this->assertSame( 'expose', $abilities['policy'] );
		$this->assertArrayNotHasKey( 'overrides', $abilities );
	}

	/** Under per-ability they are the configuration, so they travel. */
	public function test_abilities_include_overrides_under_per_ability(): void {
		$row = $this->server( array( 'abilities_default_policy' => 'per-ability' ) );

		AbilityQuery::instance()->upsert( (int) $row->id, 'toolset/content', false );
		AbilityQuery::instance()->upsert( (int) $row->id, 'toolset/users', true );

		$fresh = ServerQuery::instance()->get_item( (int) $row->id );
		$this->assertInstanceOf( ServerRow::class, $fresh );

		$abilities = ServerExporter::export( $fresh )[ ExportConstants::BLOCK_ABILITIES ];

		$this->assertSame( 'per-ability', $abilities['policy'] );
		$this->assertSame(
			array(
				array(
					'slug'    => 'toolset/content',
					'exposed' => false,
				),
				array(
					'slug'    => 'toolset/users',
					'exposed' => true,
				),
			),
			$abilities['overrides'],
			'Overrides travel sorted by slug so two exports of one server diff cleanly.'
		);
	}

	// ---------------------------------------------------------------- //
	// Origin, message, connectors                                       //
	// ---------------------------------------------------------------- //

	/** A plugin-registered server exports; only its creation is restricted. */
	public function test_a_plugin_registered_server_exports_with_origin_plugin(): void {
		$row = $this->server( array( 'registered_from' => 'plugin' ) );

		$record = ServerExporter::export( $row );

		$this->assertSame( ExportConstants::ORIGIN_PLUGIN, $record['origin'] );
		$this->assertCount( count( ExportConstants::BLOCKS ), $record['blocks'] );
		$this->assertArrayHasKey( ExportConstants::BLOCK_TOOLS, $record );
	}

	/** The system-default message travels as a mode, never as frozen text. */
	public function test_the_default_message_travels_without_text(): void {
		$row = $this->server();

		$message = ServerExporter::export( $row )[ ExportConstants::BLOCK_MESSAGE ];

		$this->assertSame( 'default', $message['mode'] );
		$this->assertArrayNotHasKey( 'text', $message );
	}

	/** A custom message is the operator's own words, so it travels. */
	public function test_a_custom_message_travels_with_its_text(): void {
		$row = $this->server();

		MetaQuery::update_meta( (int) $row->id, MCPController::INSTRUCTIONS_META_KEY, 'Call the server guide first.' );

		$message = ServerExporter::export( $row )[ ExportConstants::BLOCK_MESSAGE ];

		$this->assertSame( 'custom', $message['mode'] );
		$this->assertSame( 'Call the server guide first.', $message['text'] );
	}

	/** An unconfigured server says so, rather than exporting today's defaults. */
	public function test_connectors_report_not_stored_when_unset(): void {
		$row = $this->server();

		$connectors = ServerExporter::export( $row )[ ExportConstants::BLOCK_CONNECTORS ];

		$this->assertFalse( $connectors['stored'] );
		$this->assertArrayNotHasKey( 'enabled_slugs', $connectors );
	}

	/** A configured one travels with its slugs sorted. */
	public function test_connectors_travel_when_stored(): void {
		$row = $this->server();

		MetaQuery::update_meta(
			(int) $row->id,
			'_acrossai_mcp_server_settings',
			(string) wp_json_encode(
				array(
					'enabled_slugs'          => array( 'claude', 'chatgpt' ),
					'allow_other'            => true,
					'require_admin_approval' => false,
				)
			)
		);

		$connectors = ServerExporter::export( $row )[ ExportConstants::BLOCK_CONNECTORS ];

		$this->assertTrue( $connectors['stored'] );
		$this->assertSame( array( 'chatgpt', 'claude' ), $connectors['enabled_slugs'] );
		$this->assertTrue( $connectors['allow_other'] );
		$this->assertFalse( $connectors['require_admin_approval'] );
	}

	// ---------------------------------------------------------------- //
	// Envelope                                                          //
	// ---------------------------------------------------------------- //

	/** The envelope identifies itself so a foreign JSON file can be refused. */
	public function test_the_envelope_identifies_the_format(): void {
		$row = $this->server();

		$payload = Envelope::build( array( (int) $row->id => ExportConstants::BLOCKS ) );

		$this->assertSame( ExportConstants::FORMAT, $payload['format'] );
		$this->assertSame( ExportConstants::VERSION, $payload['version'] );
		$this->assertCount( 1, $payload['servers'] );
		$this->assertNotEmpty( $payload['generated_at'] );
	}

	/** A server deleted between rendering and submitting is skipped, not fatal. */
	public function test_a_missing_server_is_skipped(): void {
		$row = $this->server();

		$payload = Envelope::build(
			array(
				(int) $row->id => ExportConstants::BLOCKS,
				9999999        => ExportConstants::BLOCKS,
			)
		);

		$this->assertCount( 1, $payload['servers'] );
	}

	/** The JSON is readable, because people diff these files. */
	public function test_the_json_is_pretty_and_unescaped(): void {
		$row  = $this->server();
		$json = Envelope::to_json( Envelope::build( array( (int) $row->id => ExportConstants::BLOCKS ) ) );

		$this->assertStringContainsString( "\n", $json, 'Pretty-printed.' );
		$this->assertStringNotContainsString( '\\/', $json, 'Slashes unescaped.' );
		$this->assertIsArray( json_decode( $json, true ) );
	}
}
