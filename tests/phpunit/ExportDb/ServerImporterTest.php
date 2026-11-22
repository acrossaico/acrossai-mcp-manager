<?php
/**
 * The import contract (Feature 105).
 *
 * The centrepiece is the round trip: export a distinctively configured server,
 * mutate the destination hard, import the export back, and require every
 * setting to return. That is the only test that exercises the two halves
 * against each other, which is where a format gets its meaning.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Tests\ExportDb;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\PolicyTransition;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as ServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row as ServerRow;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as AbilityQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta\Query as MetaQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as ToolQuery;
use AcrossAI_MCP_Manager\Includes\Export\Envelope;
use AcrossAI_MCP_Manager\Includes\Export\ImportValidator;
use AcrossAI_MCP_Manager\Includes\Export\ServerImporter;
use AcrossAI_MCP_Manager\Includes\Export\Snapshots;
use AcrossAI_MCP_Manager\Includes\MCP\Controller as MCPController;
use AcrossAI_MCP_Manager\Includes\Utilities\ExportConstants;

/**
 * @covers \AcrossAI_MCP_Manager\Includes\Export\ServerImporter
 * @covers \AcrossAI_MCP_Manager\Includes\Export\Snapshots
 */
class ServerImporterTest extends \WP_UnitTestCase {

	/** @var int[] */
	private $created = array();

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			ToolQuery::instance()->delete_items_for_server( $id );
			AbilityQuery::instance()->delete_items_for_server( $id );
			ServerQuery::instance()->delete_item( $id );
		}

		$this->created = array();

		delete_option( Snapshots::OPTION );

		parent::tear_down();
	}

	/**
	 * Create a server.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 */
	private function server( array $overrides = array() ): ServerRow {
		$slug = isset( $overrides['server_slug'] )
			? (string) $overrides['server_slug']
			: 'import-test-' . wp_generate_password( 6, false, false );

		$id = (int) ServerQuery::instance()->add_item(
			array_merge(
				array(
					'server_name'            => 'Import test',
					'server_slug'            => $slug,
					'description'            => '',
					'is_enabled'             => 1,
					'registered_from'        => 'database',
					'server_route_namespace' => 'acrossai-mcp',
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

	/** Export one server as a validated payload. */
	private function payload_for( int $id ): array {
		$payload = ImportValidator::check( Envelope::build( array( $id => ExportConstants::BLOCKS ) ) );

		$this->assertIsArray( $payload, 'A payload this plugin just produced must pass its own validator.' );

		return $payload;
	}

	// ---------------------------------------------------------------- //
	// The round trip                                                    //
	// ---------------------------------------------------------------- //

	/**
	 * Export, mutate the destination hard, import, and get everything back.
	 *
	 * The mutation is deliberately adversarial: it changes the policy (so
	 * `PolicyTransition` clears overrides), adds a stale override, replaces
	 * the message and the tool list, and disables the server.
	 */
	public function test_a_round_trip_restores_every_block(): void {
		$row = $this->server( array( 'abilities_default_policy' => 'per-ability' ) );
		$id  = (int) $row->id;

		MetaQuery::update_meta( $id, MCPController::INSTRUCTIONS_META_KEY, 'Call the guide first.' );
		AbilityQuery::instance()->upsert( $id, 'toolset/content', false );
		AbilityQuery::instance()->upsert( $id, 'toolset/users', true );
		ToolQuery::instance()->replace_set( $id, array( 'toolset/content', 'toolset/invented' ) );

		$payload = $this->payload_for( $id );

		// Destroy it.
		PolicyTransition::apply( $id, 'expose' );
		AbilityQuery::instance()->upsert( $id, 'toolset/stale', true );
		MetaQuery::update_meta( $id, MCPController::INSTRUCTIONS_META_KEY, 'WRONG TEXT' );
		ToolQuery::instance()->replace_set( $id, array( 'toolset/unrelated' ) );
		ServerQuery::instance()->update_item( $id, array( 'is_enabled' => 0 ) );

		$result = ServerImporter::import( $payload, true );

		$this->assertSame( ServerImporter::RESULT_UPDATED, $result['servers'][0]['result'] );

		$after = ServerQuery::instance()->get_item( $id );
		$this->assertInstanceOf( ServerRow::class, $after );

		$this->assertSame( 'per-ability', $after->abilities_default_policy );
		$this->assertSame( 1, (int) $after->is_enabled );
		$this->assertSame(
			'Call the guide first.',
			MetaQuery::get_meta( $id, MCPController::INSTRUCTIONS_META_KEY )
		);

		$tools = ToolQuery::instance()->get_added_slugs( $id );
		sort( $tools );

		$this->assertContains( 'toolset/invented', $tools, 'A slug whose plugin is absent must still be written — it waits.' );
		$this->assertNotContains( 'toolset/unrelated', $tools );
	}

	/**
	 * A stale override must not survive an import whose policy already matches.
	 *
	 * `PolicyTransition::apply()` SKIPS its own clear when the policy is
	 * unchanged, so an importer that relied on it would leave this site's
	 * leftovers inside a server the file said nothing about.
	 */
	public function test_overrides_are_cleared_even_when_the_policy_is_unchanged(): void {
		$row = $this->server( array( 'abilities_default_policy' => 'per-ability' ) );
		$id  = (int) $row->id;

		AbilityQuery::instance()->upsert( $id, 'toolset/content', false );

		$payload = $this->payload_for( $id );

		// Same policy, different overrides — the exact case the trap hides in.
		AbilityQuery::instance()->upsert( $id, 'toolset/stale', true );

		ServerImporter::import( $payload );

		$slugs = array_map(
			static function ( $override ) {
				return (string) $override->ability_slug;
			},
			(array) AbilityQuery::instance()->query(
				array(
					'server_id' => $id,
					'number'    => 0,
				)
			)
		);

		$this->assertSame( array( 'toolset/content' ), $slugs );
	}

	/**
	 * The bug a real round trip found: Enable All plus hidden abilities.
	 *
	 * A server on `expose` with three abilities toggled off holds three rows,
	 * and those rows beat the policy. The first version of this feature
	 * exported the policy alone, so importing the file turned every ability
	 * back on — reported from the screen, not caught here.
	 */
	public function test_hidden_abilities_survive_a_round_trip_under_expose(): void {
		$row = $this->server( array( 'abilities_default_policy' => 'expose' ) );
		$id  = (int) $row->id;

		AbilityQuery::instance()->upsert( $id, 'toolset/content', false );
		AbilityQuery::instance()->upsert( $id, 'toolset/database', false );

		$payload = $this->payload_for( $id );

		// Somebody turns them back on in the meantime.
		AbilityQuery::instance()->delete_items_for_server( $id );

		ServerImporter::import( $payload );

		$rows = (array) AbilityQuery::instance()->query(
			array(
				'server_id' => $id,
				'number'    => 0,
			)
		);

		$hidden = array();

		foreach ( $rows as $override ) {
			if ( empty( $override->is_exposed ) ) {
				$hidden[] = (string) $override->ability_slug;
			}
		}

		sort( $hidden );

		$this->assertSame(
			array( 'toolset/content', 'toolset/database' ),
			$hidden,
			'Hidden abilities must come back; the policy alone does not describe them.'
		);
	}

	// ---------------------------------------------------------------- //
	// Blocks absent vs empty                                            //
	// ---------------------------------------------------------------- //

	/** A block the record does not carry is left alone, not emptied. */
	public function test_an_absent_block_is_left_alone(): void {
		$row = $this->server();
		$id  = (int) $row->id;

		ToolQuery::instance()->replace_set( $id, array( 'toolset/keep-me' ) );
		MetaQuery::update_meta( $id, MCPController::INSTRUCTIONS_META_KEY, 'Keep this.' );

		// A record carrying only `status`.
		$payload = ImportValidator::check(
			Envelope::build( array( $id => array( ExportConstants::BLOCK_STATUS ) ) )
		);

		$this->assertIsArray( $payload );

		ServerImporter::import( $payload );

		$this->assertSame( array( 'toolset/keep-me' ), ToolQuery::instance()->get_added_slugs( $id ) );
		$this->assertSame( 'Keep this.', MetaQuery::get_meta( $id, MCPController::INSTRUCTIONS_META_KEY ) );
	}

	// ---------------------------------------------------------------- //
	// Origin, status gating, snapshots                                  //
	// ---------------------------------------------------------------- //

	/** A plugin-registered server that is absent here is reported, never created. */
	public function test_a_missing_plugin_registered_server_is_skipped(): void {
		$payload = array(
			'format'  => ExportConstants::FORMAT,
			'version' => ExportConstants::VERSION,
			'servers' => array(
				array(
					'slug'   => 'not-on-this-site',
					'origin' => ExportConstants::ORIGIN_PLUGIN,
					'blocks' => array( ExportConstants::BLOCK_STATUS ),
					'status' => array( 'enabled' => true ),
				),
			),
		);

		$checked = ImportValidator::check( $payload );
		$this->assertIsArray( $checked );

		$result = ServerImporter::import( $checked, true );

		$this->assertSame( ServerImporter::RESULT_SKIPPED, $result['servers'][0]['result'] );
		$this->assertNull(
			ServerQuery::instance()->query(
				array(
					'server_slug' => 'not-on-this-site',
					'number'      => 1,
				)
			)[0] ?? null,
			'Creating it would shadow the real one the moment its plugin arrived.'
		);
	}

	/** A file cannot switch a server on unless the operator asked. */
	public function test_status_cannot_enable_a_server_without_consent(): void {
		$row = $this->server( array( 'is_enabled' => 1 ) );
		$id  = (int) $row->id;

		$payload = $this->payload_for( $id );

		ServerQuery::instance()->update_item( $id, array( 'is_enabled' => 0 ) );

		ServerImporter::import( $payload, false );

		$after = ServerQuery::instance()->get_item( $id );
		$this->assertInstanceOf( ServerRow::class, $after );
		$this->assertSame( 0, (int) $after->is_enabled, 'An import must not publish an endpoint by itself.' );
	}

	/** Disabling always applies — that direction cannot expose anything. */
	public function test_status_can_always_disable(): void {
		$row = $this->server( array( 'is_enabled' => 0 ) );
		$id  = (int) $row->id;

		$payload = $this->payload_for( $id );

		ServerQuery::instance()->update_item( $id, array( 'is_enabled' => 1 ) );

		ServerImporter::import( $payload, false );

		$after = ServerQuery::instance()->get_item( $id );
		$this->assertInstanceOf( ServerRow::class, $after );
		$this->assertSame( 0, (int) $after->is_enabled );
	}

	/** Every import takes a restore point before it writes. */
	public function test_an_import_takes_a_snapshot_first(): void {
		$row = $this->server();

		$result = ServerImporter::import( $this->payload_for( (int) $row->id ) );

		$this->assertGreaterThan( 0, $result['snapshot'] );
		$this->assertIsArray( Snapshots::payload( $result['snapshot'] ) );
	}

	/** Snapshots are capped, so the option cannot grow without bound. */
	public function test_snapshots_are_capped(): void {
		for ( $i = 0; $i < Snapshots::KEEP + 3; $i++ ) {
			Snapshots::take( 'test' );
		}

		$this->assertCount( Snapshots::KEEP, Snapshots::all() );
	}

	/** The snapshot option is never autoloaded. */
	public function test_the_snapshot_option_is_not_autoloaded(): void {
		global $wpdb;

		Snapshots::take( 'test' );

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Snapshots::OPTION )
		);

		$this->assertNotSame( 'yes', $autoload, 'A growing blob on every page load is a performance problem.' );
	}
}
