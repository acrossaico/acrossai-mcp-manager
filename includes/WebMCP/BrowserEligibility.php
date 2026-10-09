<?php
/**
 * F093 — is this ability allowed in a browser at all?
 *
 * A DIFFERENT QUESTION FROM THE TOOLS TAB
 * ---------------------------------------
 * The Tools tab answers "which tools does this operator want exposed". This
 * class answers "which tools are safe in a page at all" — and they are not
 * the same question. An ability can be entirely reasonable over remote MCP,
 * where OAuth and RFC 8707 audience binding apply and the caller is a
 * deliberately-configured client, and wrong in a browser tab where any
 * in-page agent can invoke it with the administrator's own cookie.
 *
 * WHY THIS DEFAULTS OPEN, UNLIKE LARAVEL'S
 * ----------------------------------------
 * `SytxLabs/LaravelWebMCP` makes a tool invisible to the browser unless its
 * class carries `#[WebMcp]` — default hidden. That is right for Laravel,
 * where the application developer owns every tool class and annotating them
 * is a normal part of writing one.
 *
 * It would be wrong here. Our abilities come from many plugins we do not
 * author, and the operator has already curated them on the server's Tools
 * tab. Defaulting closed would mean the recommended server publishes
 * NOTHING — none of its fourteen `toolset/*` dispatchers declares anything
 * about WebMCP — so the feature would appear broken while behaving exactly
 * as designed.
 *
 * So the default is "inherit the admin's curation", and this class exists to
 * let an ability author VETO that for an ability they know is unsafe in a
 * page. The veto is one-directional on purpose: declaring `webmcp => false`
 * removes an ability from the browser, and declaring `true` does NOT add one
 * the admin did not curate. Nothing here can widen exposure.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\WebMCP
 * @since      0.4.2
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * All static. No hooks of its own.
 *
 * @since 0.4.2
 */
final class BrowserEligibility {

	/**
	 * May this ability be published to an in-browser agent?
	 *
	 * Reads `meta.webmcp` on the ability, then offers the decision to a
	 * filter. Both can only ever narrow: an ability the admin did not curate
	 * never reaches this method at all, because the caller composes from the
	 * Tools tab first.
	 *
	 * Recognised meta shapes:
	 *
	 *   'webmcp' => false              — hard veto, never in a browser
	 *   'webmcp' => array( 'enabled' => false )  — same, long form, so the
	 *                                   key can grow later (a `confirm`
	 *                                   option is the obvious next one)
	 *
	 * @since 0.4.2
	 *
	 * @param string               $slug Ability slug.
	 * @param array<string, mixed> $meta The ability's meta array.
	 * @return bool True when the ability may be published.
	 */
	public static function is_allowed( string $slug, array $meta ): bool {
		$allowed = true;

		if ( array_key_exists( 'webmcp', $meta ) ) {
			$declared = $meta['webmcp'];

			if ( is_bool( $declared ) ) {
				$allowed = $declared;
			} elseif ( is_array( $declared ) && array_key_exists( 'enabled', $declared ) ) {
				$allowed = (bool) $declared['enabled'];
			}
		}

		/**
		 * Filters whether one ability may be published to an in-browser agent.
		 *
		 * Narrowing only in practice: this runs after the server's curated
		 * tool list has already been composed, so returning true for an
		 * ability the operator did not curate cannot add it — the slug never
		 * reaches this filter.
		 *
		 * @since 0.4.2
		 *
		 * @param bool                 $allowed Current decision.
		 * @param string               $slug    Ability slug.
		 * @param array<string, mixed> $meta    The ability's meta array.
		 */
		return (bool) apply_filters( 'acrossai_mcp_webmcp_ability_allowed', $allowed, $slug, $meta );
	}
}
