<?php
/**
 * F089 — the input/output schema CallbackReplacer contributes to
 * `mcp-adapter/discover-abilities`.
 *
 * This schema is the only documentation an LLM ever reads for the tool: the
 * ability `description` becomes the MCP tool description, and the property
 * descriptions survive into `inputSchema` in `tools/list`. It is also what makes
 * the parameters reachable at all — WP core refuses input for an ability that
 * registers no input schema.
 *
 * @package AcrossAI_MCP_Manager\Tests\Abilities
 */

namespace AcrossAI_MCP_Manager\Tests\Abilities;

use AcrossAI_MCP_Manager\Includes\Abilities\CallbackReplacer;
use AcrossAI_MCP_Manager\Includes\Abilities\Discover;
use WP_UnitTestCase;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

class DiscoverSchemaTest extends WP_UnitTestCase {

	/**
	 * Run the filter exactly as `wp_register_ability_args` would.
	 *
	 * @param array<string, mixed> $args Incoming registration args.
	 * @return array<string, mixed>
	 */
	private function contribute( array $args = array() ): array {
		return CallbackReplacer::instance()->replace_callbacks( $args, CallbackReplacer::DISCOVER_ABILITY );
	}

	public function test_contributes_all_five_parameters(): void {
		$properties = $this->contribute()['input_schema']['properties'];

		foreach ( array( 'search', 'category', 'namespace', 'page', 'per_page' ) as $parameter ) {
			$this->assertArrayHasKey( $parameter, $properties );
			$this->assertNotEmpty(
				$properties[ $parameter ]['description'] ?? '',
				"{$parameter} needs a description — it is what the LLM reads in tools/list."
			);
		}
	}

	/**
	 * The load-bearing detail: `WP_Ability::normalize_input()` applies only the
	 * TOP-LEVEL default, and `validate_input()` rejects null once a schema
	 * exists. Without this, every existing zero-argument caller breaks the
	 * moment the schema is registered.
	 */
	public function test_root_default_keeps_zero_argument_calls_valid(): void {
		$schema = $this->contribute()['input_schema'];

		$this->assertSame( 'object', $schema['type'] );
		$this->assertSame( array(), $schema['default'] );
		$this->assertFalse( $schema['additionalProperties'], 'A typo\'d parameter must fail loudly.' );
	}

	public function test_advertised_page_size_matches_what_execute_enforces(): void {
		$per_page = $this->contribute()['input_schema']['properties']['per_page'];

		$this->assertSame( Discover::PER_PAGE_DEFAULT, $per_page['default'] );
		$this->assertSame( Discover::PER_PAGE_MAXIMUM, $per_page['maximum'] );
		$this->assertSame( 60, $per_page['default'], 'The documented default is 60.' );
	}

	public function test_output_schema_gains_pagination_and_keeps_vendor_keys(): void {
		$vendor = array(
			'type'       => 'object',
			'properties' => array(
				'abilities' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'label'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
						),
					),
				),
			),
			'required'   => array( 'abilities' ),
		);

		$schema = $this->contribute( array( 'output_schema' => $vendor ) )['output_schema'];

		$this->assertArrayHasKey( 'abilities', $schema['properties'], 'Vendor keys must survive the merge.' );
		$this->assertSame( array( 'abilities' ), $schema['required'] );
		$this->assertArrayHasKey(
			'category',
			$schema['properties']['abilities']['items']['properties'],
			'F089 adds category to each entry, so the item schema must declare it.'
		);

		foreach ( array( 'total', 'returned', 'page', 'per_page', 'has_more' ) as $key ) {
			$this->assertArrayHasKey( $key, $schema['properties'] );
		}
		$this->assertSame( 'boolean', $schema['properties']['has_more']['type'] );
	}

	public function test_description_states_the_paging_contract(): void {
		$description = $this->contribute()['description'];

		$this->assertStringContainsString( '60', $description );
		$this->assertStringContainsString( 'has_more', $description );
		$this->assertStringContainsString( 'get-ability-info', $description );
	}

	public function test_callbacks_are_still_rebound(): void {
		$args = $this->contribute();

		$this->assertSame( array( Discover::class, 'check_permission' ), $args['permission_callback'] );
		$this->assertSame( array( Discover::class, 'execute' ), $args['execute_callback'] );
	}

	/**
	 * The filter fires on EVERY registration of the slug, and third parties
	 * re-register this ability wholesale. A second pass must not re-derive or
	 * stack anything.
	 */
	public function test_contribution_is_idempotent(): void {
		$once  = $this->contribute();
		$twice = $this->contribute( $once );

		$this->assertSame( $once, $twice );
	}

	public function test_other_vendor_abilities_get_callbacks_only(): void {
		$args = CallbackReplacer::instance()->replace_callbacks( array(), 'mcp-adapter/get-ability-info' );

		$this->assertArrayNotHasKey( 'input_schema', $args, 'Only discover-abilities gains a schema.' );
		$this->assertArrayHasKey( 'execute_callback', $args );
	}

	public function test_unrelated_ability_is_untouched(): void {
		$args = array( 'label' => 'Mine' );

		$this->assertSame( $args, CallbackReplacer::instance()->replace_callbacks( $args, 'someone-else/thing' ) );
	}
}
