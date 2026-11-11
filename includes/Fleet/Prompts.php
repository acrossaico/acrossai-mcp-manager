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
	 * The hub-side ability that pairs a site.
	 *
	 * Named as a constant because it appears in the prompt, in the
	 * wrong-chat fallback and in the tests, and a typo in any one of them is
	 * an instruction a model cannot follow.
	 *
	 * @since 0.4.8
	 * @var   string
	 */
	public const ADD_TOOL = 'fleet/add-site';

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
				sprintf(
					/* translators: 1: tool name, 2: the same name with a hyphen. */
					__( 'Call your "%1$s" tool with site_url and security_id set to the two values above. Some clients list it as "%2$s".', 'acrossai-mcp-manager' ),
					self::ADD_TOOL,
					self::hyphenated( self::ADD_TOOL )
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
			__( 'Call it first without confirming. It will change nothing and reply with %s — show me that and wait for my yes. Then call it again with confirmed set to true.', 'acrossai-mcp-manager' ),
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
		return __( 'If you have no such tool, stop and tell me. It means this conversation is connected to the wrong WordPress site: the tool lives on the hub that manages this site, not on this site itself.', 'acrossai-mcp-manager' );
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
