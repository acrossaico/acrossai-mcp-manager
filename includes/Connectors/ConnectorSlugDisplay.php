<?php
/**
 * Map a connector slug to its user-facing display name.
 *
 * Central helper so the "Connector" column in the consolidated Connections
 * table + the checkbox labels in the server-wide Settings panel + any future
 * caller all agree on how to name each slug (including the sentinel used for
 * unrecognized OAuth clients).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Connectors
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Connectors;

defined( 'ABSPATH' ) || exit;

final class ConnectorSlugDisplay {

	/**
	 * Sentinel slug for OAuth clients that don't match any registered
	 * connector profile (e.g. a custom DCR client). Surfaced in the Settings
	 * UI as "Any other OAuth-compliant MCP client".
	 *
	 * NOT a member of server-settings' `enabled_slugs` list, despite what this
	 * docblock claimed before Feature 024. It is backed by the separate
	 * `allow_other` boolean: `ConnectorSettings::is_slug_enabled_on_server()`
	 * branches on this sentinel and reads `allow_other`, and the save handler
	 * whitelists `enabled_slugs` against registered profile slugs, so `'other'`
	 * can never enter the list in the first place. The distinction matters —
	 * code that went looking for `'other'` in `enabled_slugs` would find it
	 * absent and conclude the category was disabled regardless of the toggle.
	 */
	public const OTHER_SLUG = 'other';

	/**
	 * Return the display name for a slug.
	 *
	 * Empty string and the OTHER_SLUG sentinel both render as
	 * "Other (OAuth)" — an empty slug in the DB always means "no matching
	 * profile", which is semantically identical to the "other" bucket the
	 * admin toggles from the Settings panel.
	 *
	 * @param string $slug Connector slug (registered profile slug, '', or OTHER_SLUG).
	 * @return string User-facing name.
	 */
	public static function display_name( string $slug ): string {
		if ( '' === $slug || self::OTHER_SLUG === $slug ) {
			return __( 'Other (OAuth)', 'acrossai-mcp-manager' );
		}
		$profile = ConnectorProfileRegistry::instance()->get_profile( $slug );
		if ( null !== $profile ) {
			return $profile->get_name();
		}
		return __( 'Other (OAuth)', 'acrossai-mcp-manager' );
	}

	/**
	 * Normalize a slug into the bucket used by the server-wide settings gate.
	 *
	 * An empty slug ('' — DCR client with no matching profile) collapses to
	 * OTHER_SLUG so `is_slug_enabled_on_server()` can enforce a single
	 * `allow_other` toggle instead of a special-case branch at every call site.
	 *
	 * @param string $slug Raw slug from a client row.
	 * @return string OTHER_SLUG for empty input, otherwise the input unchanged.
	 */
	public static function bucket( string $slug ): string {
		return '' === $slug ? self::OTHER_SLUG : $slug;
	}
}
