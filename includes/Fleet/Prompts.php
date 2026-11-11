<?php
/**
 * The paste-able pairing prompt on the Fleet tab.
 *
 * Pairing has always meant carrying a twelve-character code between two
 * browser tabs: read it here, switch to the managing site's admin, type it in
 * with this site's address. This is the alternative — a block of prose an
 * operator pastes into whatever AI their managing site is already connected
 * to, which calls one ability and is done.
 *
 * ## Written for any model, not one
 *
 * Feature 099's prompt could lean on Claude Desktop's config file and restart
 * behaviour. This goes into whatever the operator's managing site is connected
 * to, so it names no vendor, no app and no file, gives both spellings of the
 * tool name because clients normalise `/` differently, and spells the confirm
 * handshake out in prose — a weaker model will not infer a two-call protocol
 * from a schema field it may never see.
 *
 * ## Built here rather than in the view
 *
 * It is a string assembled from this site's address and its security ID.
 * Keeping that out of the renderer is what lets it be tested without
 * asserting against HTML.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Fleet
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Fleet;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt text for pairing.
 *
 * @since 0.4.8
 */
final class Prompts {

	/**
	 * The hub-side TOOL a client can actually see.
	 *
	 * Fleet is one tool over seven abilities, the same shape every toolset on
	 * this site uses. The distinction is the whole reason this constant was
	 * split in two: the ability is what the hub runs, the tool is what the
	 * client can call, and only the second belongs in an instruction.
	 *
	 * Naming the ability alone is how this prompt broke. It told a model to
	 * call a tool that is not in its list, and the fallback below then
	 * diagnosed a working hub as the wrong chat — a correct setup, reported
	 * as a connection fault, by our own instructions.
	 *
	 * This is a CROSS-PLUGIN contract: the hub owns these names and this
	 * plugin repeats them. Nothing can check that from here, which is why
	 * both are constants rather than inline strings, and why the test asserts
	 * the shape rather than the prose.
	 *
	 * @since 0.4.8
	 * @var   string
	 */
	public const DISPATCH_TOOL = 'toolset/fleet';

	/**
	 * The hub-side ability that pairs a site, run through the tool above.
	 *
	 * @since 0.4.8
	 * @var   string
	 */
	public const ADD_ABILITY = 'fleet/add-site';

	/**
	 * Ask a hub to adopt this site.
	 *
	 * @since  0.4.8
	 * @param  int $server_id Fleet server id.
	 * @return string Plain text, ready to paste.
	 */
	public static function add_site( int $server_id ): string {
		return self::join(
			array(
				__( 'Add this WordPress site to the fleet this hub manages.', 'acrossai-mcp-manager' ),
				self::values(
					array(
						__( 'Site URL', 'acrossai-mcp-manager' )    => home_url(),
						__( 'Security ID', 'acrossai-mcp-manager' ) => SecurityId::get( $server_id ),
					)
				),
				self::what_it_grants(),
				sprintf(
					/* translators: 1: tool name, 2: the same name with a hyphen, 3: ability name. */
					__( 'Use your "%1$s" tool — some clients list it as "%2$s". Call it with action set to "execute", ability set to "%3$s", and parameters containing site_url and security_id set to the two values above.', 'acrossai-mcp-manager' ),
					self::DISPATCH_TOOL,
					self::hyphenated( self::DISPATCH_TOOL ),
					self::ADD_ABILITY
				),
				self::confirm_step( __( 'the name of the site it is about to add', 'acrossai-mcp-manager' ) ),
				__( 'When it succeeds, tell me the site name and site key it returns.', 'acrossai-mcp-manager' ),
				self::wrong_chat(),
			)
		);
	}

	/**
	 * The two-call protocol, in prose.
	 *
	 * Stated rather than left to the schema because the schema is the thing a
	 * weaker model is least likely to read carefully, and the first call
	 * doing nothing is the entire safety property.
	 *
	 * @since  0.4.8
	 * @param  string $what What the first call reports back.
	 * @return string
	 */
	private static function confirm_step( string $what ): string {
		return sprintf(
			/* translators: %s: what the first call reports, e.g. "the name of the site it is about to add". */
			__( 'Call it first without confirming. It will change nothing and reply with %s — show me that and wait for my yes. Then call it again with the same values plus confirmed set to true INSIDE parameters, next to site_url and security_id — not beside action and ability, where the tool will refuse it.', 'acrossai-mcp-manager' ),
			$what
		);
	}

	/**
	 * What to do when the tool is not there.
	 *
	 * The one real footgun: this site's own MCP server has no fleet tools at
	 * all, so pasting into the wrong chat fails in a way that reads like a
	 * broken feature. Naming the failure turns it into a sentence the
	 * operator can act on.
	 *
	 * @since  0.4.8
	 * @return string
	 */
	private static function wrong_chat(): string {
		return sprintf(
			/* translators: %s: tool name. */
			__( 'If you have no "%s" tool, stop and tell me. It means this conversation is connected to the wrong WordPress site: the tool lives on the hub that manages this site, not on this site itself.', 'acrossai-mcp-manager' ),
			self::DISPATCH_TOOL
		);
	}

	/**
	 * What the operator is actually handing over.
	 *
	 * Pasting a code into someone else's AI chat is a grant of access, and
	 * the rest of this prompt reads like a connection step. Two facts, both
	 * of which the operator can check against this screen: the code is not a
	 * password, and the pairing is theirs to end.
	 *
	 * @since  0.4.8
	 * @return string
	 */
	private static function what_it_grants(): string {
		return __( 'The security ID is not a password. It is single-use and only valid for this pairing. Adding the site lets that hub run tools on it; this site can remove the hub at any time from its own Fleet screen.', 'acrossai-mcp-manager' );
	}

	/**
	 * The labelled values, indented so they read as a block.
	 *
	 * @since  0.4.8
	 * @param  array<string, string> $pairs Label => value.
	 * @return string
	 */
	private static function values( array $pairs ): string {
		$width = 0;

		foreach ( array_keys( $pairs ) as $label ) {
			$width = max( $width, strlen( $label ) );
		}

		$lines = array();

		foreach ( $pairs as $label => $value ) {
			$lines[] = '    ' . str_pad( $label . ':', $width + 2 ) . $value;
		}

		return implode( "\n", $lines );
	}

	/**
	 * A slug as clients that cannot show `/` spell it.
	 *
	 * @since  0.4.8
	 * @param  string $slug Ability slug.
	 * @return string
	 */
	private static function hyphenated( string $slug ): string {
		return str_replace( '/', '-', $slug );
	}

	/**
	 * Paragraphs into one block.
	 *
	 * @since  0.4.8
	 * @param  array<int, string> $parts Paragraphs.
	 * @return string
	 */
	private static function join( array $parts ): string {
		return implode( "\n\n", array_filter( $parts ) );
	}
}
