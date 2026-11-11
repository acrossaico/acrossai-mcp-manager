<?php
/**
 * The paste-able pairing prompt on the Fleet tab.
 *
 * This is an instruction a model follows against someone else's live site, so
 * what is pinned here is the parts that make it followable rather than its
 * prose. Four things matter and none of them are visible from reading the
 * string once:
 *
 *  - the values are the CURRENT ones, because a prompt carrying a stale
 *    security ID fails in a way that looks like the feature is broken;
 *  - the two-call protocol is stated in words, because a weaker model will not
 *    infer it from a schema field it may never be shown;
 *  - both spellings of the tool name are present, because clients normalise
 *    `/` differently and a model matching on the wrong one finds nothing;
 *  - no vendor is named, because it goes wherever the operator's managing site
 *    is connected and Feature 099's Claude-specific prompt is the thing it is
 *    deliberately not.
 *
 * @package AcrossAI_MCP_Manager\Tests
 */

declare(strict_types=1);

namespace AcrossAI_MCP_Manager\Tests\Admin\ServerTabs;

use AcrossAI_MCP_Manager\Includes\Fleet\Prompts;
use AcrossAI_MCP_Manager\Includes\Fleet\SecurityId;
use WP_UnitTestCase;

final class FleetPromptsTest extends WP_UnitTestCase {

	/**
	 * Server id the prompt is built for.
	 *
	 * @var int
	 */
	private const SERVER_ID = 4242;

	// ─────────────────────────────────────────────────────────────────
	// The values have to be the live ones
	// ─────────────────────────────────────────────────────────────────

	/**
	 * It carries the security ID this server holds right now.
	 *
	 * The whole flow is "paste this and it works". A prompt built from a
	 * remembered or defaulted ID would be refused by the registration route
	 * and read to the operator as a broken feature rather than a stale value.
	 */
	public function test_it_carries_the_current_security_id(): void {
		$id = SecurityId::get( self::SERVER_ID );

		$this->assertNotSame( '', $id, 'The fixture produced no security ID.' );
		$this->assertStringContainsString( $id, Prompts::add_site( self::SERVER_ID ) );
	}

	/**
	 * Regenerating the ID changes the prompt.
	 *
	 * The ID is spent on every successful registration, so a cached prompt
	 * would hand the next managing site a code that has already been used.
	 */
	public function test_regenerating_the_id_changes_the_prompt(): void {
		$before = Prompts::add_site( self::SERVER_ID );

		SecurityId::regenerate( self::SERVER_ID );

		$after = Prompts::add_site( self::SERVER_ID );

		$this->assertNotSame( $before, $after );
		$this->assertStringContainsString( SecurityId::get( self::SERVER_ID ), $after );
	}

	/** It carries this site's address, which is how the managing site finds it. */
	public function test_it_carries_this_sites_address(): void {
		$this->assertStringContainsString( home_url(), Prompts::add_site( self::SERVER_ID ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// Followable by any model
	// ─────────────────────────────────────────────────────────────────

	/** It names the tool in both spellings. */
	public function test_it_names_the_tool_both_ways(): void {
		$prompt = Prompts::add_site( self::SERVER_ID );

		$this->assertStringContainsString( Prompts::ADD_TOOL, $prompt );
		$this->assertStringContainsString( str_replace( '/', '-', Prompts::ADD_TOOL ), $prompt );
	}

	/**
	 * The two-call protocol is spelled out, not left to the schema.
	 *
	 * This is the safety property: the first call changes nothing and names
	 * what it would touch. A model that does not know to make it goes straight
	 * to the one that acts.
	 */
	public function test_it_states_the_confirm_step(): void {
		$prompt = Prompts::add_site( self::SERVER_ID );

		$this->assertStringContainsString( 'without confirming', $prompt );
		$this->assertStringContainsString( 'confirmed set to true', $prompt );
		$this->assertStringContainsString( 'change nothing', $prompt );
	}

	/**
	 * It names the wrong-chat failure.
	 *
	 * This site's own MCP server has no fleet tools, so pasting into a chat
	 * connected here fails in a way that reads like a bug. Telling the model
	 * to stop and say so converts it into a sentence the operator can act on.
	 */
	public function test_it_names_the_wrong_chat_failure(): void {
		$this->assertStringContainsString( 'wrong WordPress site', Prompts::add_site( self::SERVER_ID ) );
	}

	/**
	 * No vendor, app or file is named.
	 *
	 * Feature 099's prompt could lean on one client's config file and restart
	 * behaviour. This one is pasted into whatever the managing site is
	 * connected to, and naming a vendor is how it would start reading as wrong
	 * to everyone using a different one.
	 */
	public function test_it_names_no_vendor(): void {
		$prompt = strtolower( Prompts::add_site( self::SERVER_ID ) );

		foreach ( array( 'claude', 'chatgpt', 'openai', 'gemini', 'cursor', 'anthropic', '.json' ) as $vendor ) {
			$this->assertStringNotContainsString(
				$vendor,
				$prompt,
				sprintf( 'The prompt names "%s", which makes it wrong everywhere else.', $vendor )
			);
		}
	}

	/**
	 * The labelled values line up under each other.
	 *
	 * Cosmetic on its own, but the block is read by a person checking the URL
	 * and the ID before they paste, and a ragged two-line block is where a
	 * wrong value hides.
	 *
	 * The VALUES are what must align, not the colons — the labels are padded
	 * to a common width, so "Site URL:" and "Security ID:" end at different
	 * columns by design.
	 */
	public function test_the_values_are_aligned(): void {
		$id     = SecurityId::get( self::SERVER_ID );
		$prompt = Prompts::add_site( self::SERVER_ID );

		$lines = array_values(
			array_filter(
				explode( "\n", $prompt ),
				static function ( string $line ): bool {
					return 0 === strpos( $line, '    ' );
				}
			)
		);

		$this->assertCount( 2, $lines, 'Expected exactly the URL and the ID to be indented.' );

		$url_at = strpos( $lines[0], home_url() );
		$id_at  = strpos( $lines[1], $id );

		$this->assertNotFalse( $url_at );
		$this->assertNotFalse( $id_at );
		$this->assertSame( $url_at, $id_at, 'The two values do not start in the same column.' );
	}
}
