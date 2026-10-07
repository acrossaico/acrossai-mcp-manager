<?php
/**
 * Bridges this plugin's ConnectorProfileRegistry into mcp-manager's
 * Discovery API via the `acrossai_mcp_manager_discovery_ai_connectors`
 * filter. Since pro 0.8.1 (and mcp-manager's paired release), mcp-manager
 * no longer probes for our namespace directly — it just fires the filter
 * and we contribute DTOs when present.
 *
 * @package AcrossAI_MCP_Manager
 */

namespace AcrossAI_MCP_Manager\Includes\Discovery;

use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;

defined( 'ABSPATH' ) || exit;

final class DiscoveryConnectorAdapter {

	/**
	 * Filter callback for `acrossai_mcp_manager_discovery_ai_connectors`.
	 *
	 * Reads every registered ConnectorProfile from the local registry and
	 * appends a matching DTO. There is no licence lane: the companion gated
	 * the registration filter on Freemius, so an unlicensed install produced
	 * zero profiles → zero DTOs → no `ai_connector` category. F095 made the
	 * connectors free and registration unconditional, so the category is now
	 * always present. An empty result means a third party filtered the
	 * profiles out, not that the site is unlicensed.
	 *
	 * NOT filtered by per-server enablement (`ConnectorSettings::is_slug_enabled_on_server`),
	 * unlike the AI Connectors tab and the OAuth gates. Two reasons, in order:
	 *
	 *  1. There is no server in scope. The `acrossai_mcp_manager_discovery_ai_connectors`
	 *     filter passes only the DTO accumulator, and the signature belongs to
	 *     mcp-manager — this callback cannot know which server, if any, the
	 *     caller means.
	 *  2. Even given one, it would be the wrong question. This list answers
	 *     "which connectors does this plugin support?" — a site-level catalogue
	 *     used to render the Quick Connect wizard's options. Per-server
	 *     enablement answers "may this connector be used against server N",
	 *     which is decided at /authorize.
	 *
	 * The gap this leaves is real but narrow: the wizard can offer a connector
	 * that a given server has disabled, and the operator finds out at the
	 * consent step. Closing it needs a server-aware filter in mcp-manager
	 * first. Tracked as a follow-up to Feature 024, not fixed here.
	 *
	 * @param mixed $dtos Incoming DTOs from earlier callbacks. Expected array; anything else is normalized to [].
	 * @return array<int, array<string, mixed>>
	 */
	public static function provide_ai_connectors( $dtos ): array {
		if ( ! is_array( $dtos ) ) {
			$dtos = array();
		}

		if ( ! class_exists( '\\AcrossAI_MCP_Manager\\Includes\\Connectors\\ConnectorProfileRegistry' ) ) {
			return $dtos;
		}

		$profiles = ConnectorProfileRegistry::instance()->get_profiles();
		foreach ( $profiles as $profile ) {
			$icon_url  = $profile->get_icon_url();
			$whitelist = $profile->get_redirect_uri_whitelist();
			$dtos[]    = array(
				'category'    => 'ai_connector',
				'slug'        => $profile->get_slug(),
				'name'        => $profile->get_name(),
				'description' => '',
				'icon'        => $icon_url,
				'meta'        => array(
					'icon_url'               => $icon_url,
					'has_redirect_whitelist' => ! empty( $whitelist ),
					'class'                  => get_class( $profile ),
				),
			);
		}

		return $dtos;
	}

	/**
	 * Filter callback for `acrossai_mcp_manager_discovery_ai_connector_instructions`.
	 *
	 * Sibling of {@see self::provide_ai_connectors()} — same iteration pass over
	 * ConnectorProfileRegistry, but returns the per-profile walkthrough HTML
	 * produced by `get_mcp_url_setup_html()` on each profile instead of a lean
	 * identity DTO. This is the "How to connect" step-by-step guide (Claude:
	 * Web / Team / CLI sub-sections; ChatGPT / Cursor / Gemini / Grok: their
	 * own provider-specific flows).
	 *
	 * Because the free plugin's discovery filter fires once per pageload with
	 * no server context, we pass the sentinel `__ACROSSAI_MCP_URL__` where the
	 * real MCP endpoint URL would go. The free plugin's Quick Connect Step 10
	 * substitutes the sentinel with the wizard's currently-selected server URL
	 * client-side just before `dangerouslySetInnerHTML`. This avoids either
	 * changing the existing filter contract or shipping N copies of the HTML
	 * (one per server) on every wizard bootstrap.
	 *
	 * The HTML is run through `wp_kses_post` here so the free plugin can treat
	 * the value as render-safe (its trust boundary), matching the guarantee
	 * documented in the profiles' `get_mcp_url_setup_html()` docblocks. The
	 * sentinel token has no HTML-special characters, so it survives kses
	 * unchanged.
	 *
	 * @param mixed $map Incoming map from earlier callbacks. Expected array; anything else is normalized to [].
	 * @return array<string, string> Map of connector slug → walkthrough HTML.
	 */
	public static function provide_ai_connector_instructions( $map ): array {
		if ( ! is_array( $map ) ) {
			$map = array();
		}

		if ( ! class_exists( '\\AcrossAI_MCP_Manager\\Includes\\Connectors\\ConnectorProfileRegistry' ) ) {
			return $map;
		}

		foreach ( ConnectorProfileRegistry::instance()->get_profiles() as $profile ) {
			if ( ! method_exists( $profile, 'get_mcp_url_setup_html' ) ) {
				continue;
			}
			$map[ $profile->get_slug() ] = wp_kses_post(
				$profile->get_mcp_url_setup_html( '__ACROSSAI_MCP_URL__' )
			);
		}

		return $map;
	}
}
