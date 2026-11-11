<?php
/**
 * The paste-able prompts on the Fleet tab.
 *
 * These are instructions a model follows against someone else's live site, so
 * what is pinned here is the parts that make them followable rather than their
 * prose. Four things matter and none of them are visible from reading the
 * strings once:
 *
 *  - the values are the CURRENT ones, because a prompt carrying a stale
 *    security ID fails in a way that looks like the feature is broken;
 *  - the two-call protocol is stated in words, because a weaker model will not
 *    infer it from a schema field it may never be shown;
 *  - both spellings of each tool name are present, because clients normalise
 *    `/` differently and a model matching on the wrong one finds nothing;
 *  - no vendor is named, because these go wherever the operator's hub is
 *    connected and Feature 099's Claude-specific prompt is the thing they are
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
	 * Server id the prompts are built for.
	 *
	 * @var int
	 */
	private const SERVER_ID = 4242;

	/**
	 * Both prompts, for the properties they share.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function both_prompts(): array {
		return array(
			'add'    => array( 'add' ),
			'remove' => array( 'remove' ),
		);
	}

	/**
	 * Build one by name, so a provider can name them as strings.
	 *
	 * @param  string $which 'add' or 'remove'.
	 * @return string
	 */
	private function prompt( string $which ): string {
		return 'add' === $which
			? Prompts::add_site( self::SERVER_ID )
			: Prompts::remove_site();
	}

	// ─────────────────────────────────────────────────────────────────
	// The values have to be the live ones
	// ─────────────────────────────────────────────────────────────────

	/**
	 * The add prompt carries the security ID this server holds right now.
	 *
	 * The whole flow is "paste this and it works". A prompt built from a
	 * remembered or defaulted ID would be refused by the registration route
	 * and read to the operator as a broken feature rather than a stale value.
	 */
	public function test_the_add_prompt_carries_the_current_security_id(): void {
		$id = SecurityId::get( self::SERVER_ID );

		$this->assertNotSame( '', $id, 'The fixture produced no security ID.' );
		$this->assertStringContainsString( $id, Prompts::add_site( self::SERVER_ID ) );
	}

	/**
	 * Regenerating the ID changes the prompt.
	 *
	 * The ID is spent on every successful registration, so a cached prompt
	 * would hand the next hub a code that has already been used.
	 */
	public function test_regenerating_the_id_changes_the_add_prompt(): void {
		$before = Prompts::add_site( self::SERVER_ID );

		SecurityId::regenerate( self::SERVER_ID );

		$after = Prompts::add_site( self::SERVER_ID );

		$this->assertNotSame( $before, $after );
		$this->assertStringContainsString( SecurityId::get( self::SERVER_ID ), $after );
	}

	/**
	 * Both carry this site's address, which is how the hub finds it.
	 *
	 * @param string $which Which prompt.
	 *
	 * @dataProvider both_prompts
	 */
	public function test_it_carries_this_sites_address( string $which ): void {
		$this->assertStringContainsString( home_url(), $this->prompt( $which ) );
	}

	/** The remove prompt does not carry the security ID — it has no use for one. */
	public function test_the_remove_prompt_carries_no_security_id(): void {
		$this->assertStringNotContainsString(
			SecurityId::get( self::SERVER_ID ),
			Prompts::remove_site(),
			'Unpairing needs no credential, so the prompt must not spend one.'
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// Followable by any model
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Each names its tool in both spellings.
	 *
	 * @param string $which Which prompt.
	 *
	 * @dataProvider both_prompts
	 */
	public function test_it_names_the_tool_both_ways( string $which ): void {
		$slug   = 'add' === $which ? Prompts::ADD_TOOL : Prompts::REMOVE_TOOL;
		$prompt = $this->prompt( $which );

		$this->assertStringContainsString( $slug, $prompt );
		$this->assertStringContainsString( str_replace( '/', '-', $slug ), $prompt );
	}

	/**
	 * The two-call protocol is spelled out, not left to the schema.
	 *
	 * This is the safety property: the first call changes nothing and names
	 * what it would touch. A model that does not know to make it goes straight
	 * to the one that acts.
	 *
	 * @param string $which Which prompt.
	 *
	 * @dataProvider both_prompts
	 */
	public function test_it_states_the_confirm_step( string $which ): void {
		$prompt = $this->prompt( $which );

		$this->assertStringContainsString( 'without confirming', $prompt );
		$this->assertStringContainsString( 'confirmed set to true', $prompt );
		$this->assertStringContainsString( 'change nothing', $prompt );
	}

	/**
	 * Each names the wrong-chat failure.
	 *
	 * This site's own MCP server has no fleet tools, so pasting into a chat
	 * connected here fails in a way that reads like a bug. Telling the model
	 * to stop and say so converts it into a sentence the operator can act on.
	 *
	 * @param string $which Which prompt.
	 *
	 * @dataProvider both_prompts
	 */
	public function test_it_names_the_wrong_chat_failure( string $which ): void {
		$this->assertStringContainsString( 'wrong WordPress site', $this->prompt( $which ) );
	}

	/**
	 * No vendor, app or file is named.
	 *
	 * Feature 099's prompt could lean on one client's config file and restart
	 * behaviour. These are pasted into whatever the hub is connected to, and
	 * naming a vendor is how they would start reading as wrong to everyone
	 * using a different one.
	 *
	 * @param string $which Which prompt.
	 *
	 * @dataProvider both_prompts
	 */
	public function test_it_names_no_vendor( string $which ): void {
		$prompt = strtolower( $this->prompt( $which ) );

		foreach ( array( 'claude', 'chatgpt', 'openai', 'gemini', 'cursor', 'anthropic', '.json' ) as $vendor ) {
			$this->assertStringNotContainsString(
				$vendor,
				$prompt,
				sprintf( 'The prompt names "%s", which makes it wrong everywhere else.', $vendor )
			);
		}
	}

	// ─────────────────────────────────────────────────────────────────
	// Unpairing takes two halves
	// ─────────────────────────────────────────────────────────────────

	/**
	 * The remove prompt says what it does NOT do.
	 *
	 * Telling a hub to forget this site revokes nothing here — that is the
	 * Remove button beside the host. An operator who does only one of the two
	 * is left with a host that can still reach this site, believing they have
	 * disconnected it.
	 */
	public function test_the_remove_prompt_says_it_revokes_nothing_here(): void {
		$prompt = Prompts::remove_site();

		$this->assertStringContainsString( 'does not revoke', $prompt );
		$this->assertStringContainsString( 'Remove button', $prompt );
	}

	/**
	 * The two prompts are not interchangeable.
	 *
	 * A guard against the kind of copy-paste slip that would have the Fleet
	 * tab offer the same block twice under two headings.
	 */
	public function test_the_two_prompts_differ(): void {
		$this->assertNotSame( Prompts::add_site( self::SERVER_ID ), Prompts::remove_site() );
		$this->assertStringNotContainsString( Prompts::REMOVE_TOOL, Prompts::add_site( self::SERVER_ID ) );
		$this->assertStringNotContainsString( Prompts::ADD_TOOL, Prompts::remove_site() );
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
