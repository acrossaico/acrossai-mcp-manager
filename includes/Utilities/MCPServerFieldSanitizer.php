<?php
/**
 * MCPServerFieldSanitizer — shared sanitizer helper for server-create payloads.
 *
 * Feature 069 — Extracted from the sanitizer sequence currently inlined at
 * `admin/Partials/Settings.php::handle_create_server()` (lines 264-268 pre-F069)
 * so the wizard's Step 1b REST handler + the existing admin new-server form
 * apply identical validation — no drift possible.
 *
 * TASK-SEC-001 + REF-001 (memory pattern B7 defence): the sanitizer MUST
 * filter its input array against a hard-coded 6-key whitelist BEFORE
 * per-field sanitization runs. Forged keys like `is_enabled`, `id`,
 * `created_at` are silently dropped. This is the primary defence against
 * mass-assignment via forged POST keys to $wpdb->insert().
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes/Utilities
 * @since      0.2.11
 */

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shared sanitizer for MCP server create-form payloads.
 *
 * Stateless final class — no instance state, no ctor args, no hook
 * registration. Matches the A11 pure-service-class exemption from the
 * plugin's singleton-only rule.
 *
 * @since 0.2.11
 */
final class MCPServerFieldSanitizer {

	/**
	 * Hard-coded whitelist of accepted server-create payload keys.
	 *
	 * ANY additional key present in the input array is silently dropped
	 * BEFORE sanitization runs. This prevents mass-assignment via forged
	 * POST/JSON keys like `is_enabled`, `id`, `created_at` reaching the
	 * downstream `MCPServerQuery::add_item()` call and slipping into
	 * $wpdb->insert() as writable columns.
	 *
	 * Order preserved for stable test fixture output.
	 *
	 * @since 0.2.11
	 * @var array<int,string>
	 */
	public const ALLOWED_KEYS = array(
		'server_name',
		'server_slug',
		'description',
		'server_route_namespace',
		'server_route',
		'server_version',
	);

	/**
	 * Sanitize an already-unslashed server-create payload.
	 *
	 * The caller MUST have unslashed the values first (`wp_unslash()` for
	 * $_POST-source; WordPress REST layer already unslashes for JSON bodies).
	 * This helper handles filtering + per-field sanitization only.
	 *
	 * Field mapping (matches existing admin form at Settings.php:264-268):
	 *
	 *   server_name            → sanitize_text_field()
	 *   server_slug            → sanitize_title() when non-empty, else derived
	 *                            from server_name via sanitize_title() (matches
	 *                            Settings.php:275 fallback).
	 *   description            → sanitize_textarea_field()
	 *   server_route_namespace → sanitize_text_field() (default 'mcp')
	 *   server_route           → sanitize_text_field() (default: same as slug)
	 *   server_version         → sanitize_text_field() (default 'v1.0.0')
	 *
	 * @since 0.2.11
	 *
	 * @param array<string,mixed> $raw Already-unslashed input array. Extra keys silently dropped.
	 * @return array<string,string> Sanitized values, always contains all 6 keys.
	 */
	public static function sanitize( array $raw ): array {
		// Whitelist filter — drop every key not in ALLOWED_KEYS. This is the
		// primary B7 mass-assignment defence; must run BEFORE any per-field
		// sanitization to keep the downstream logic straightforward.
		$filtered = array_intersect_key( $raw, array_flip( self::ALLOWED_KEYS ) );

		// Strip NUL bytes. sanitize_text_field()/sanitize_textarea_field() do not
		// remove them — NUL is valid UTF-8 — so "foo\0bar" survived intact into
		// server names and routes, where it can truncate C-string consumers and
		// confuse log/UI output. Cheap to drop at the single entry point every
		// caller already funnels through.
		foreach ( $filtered as $key => $value ) {
			if ( is_string( $value ) ) {
				$filtered[ $key ] = str_replace( "\0", '', $value );
			}
		}

		$name = isset( $filtered['server_name'] )
			? sanitize_text_field( (string) $filtered['server_name'] )
			: '';

		$slug = isset( $filtered['server_slug'] ) && '' !== (string) $filtered['server_slug']
			? sanitize_title( (string) $filtered['server_slug'] )
			: sanitize_title( $name );

		$description = isset( $filtered['description'] )
			? sanitize_textarea_field( (string) $filtered['description'] )
			: '';

		$namespace = isset( $filtered['server_route_namespace'] ) && '' !== (string) $filtered['server_route_namespace']
			? sanitize_text_field( (string) $filtered['server_route_namespace'] )
			: 'mcp';

		$route = isset( $filtered['server_route'] ) && '' !== (string) $filtered['server_route']
			? sanitize_text_field( (string) $filtered['server_route'] )
			: $slug;

		$version = isset( $filtered['server_version'] ) && '' !== (string) $filtered['server_version']
			? sanitize_text_field( (string) $filtered['server_version'] )
			: 'v1.0.0';

		return array(
			'server_name'            => $name,
			'server_slug'            => $slug,
			'description'            => $description,
			'server_route_namespace' => $namespace,
			'server_route'           => $route,
			'server_version'         => $version,
		);
	}

	/**
	 * Convenience wrapper for $_POST-sourced input — unslashes each raw
	 * value before delegating to `sanitize()`. Prefer this from admin
	 * form handlers that read from $_POST directly.
	 *
	 * @since 0.2.11
	 *
	 * @param array<string,mixed> $post_data Typically `$_POST` (raw + slashed).
	 * @return array<string,string>
	 */
	public static function sanitize_from_post( array $post_data ): array {
		$filtered  = array_intersect_key( $post_data, array_flip( self::ALLOWED_KEYS ) );
		$unslashed = array();
		foreach ( $filtered as $key => $value ) {
			$unslashed[ $key ] = is_string( $value ) ? wp_unslash( $value ) : '';
		}
		return self::sanitize( $unslashed );
	}
}
