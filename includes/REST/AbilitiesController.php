<?php
/**
 * Feature 017 — Per-server Ability Selection REST controller.
 *
 * Registers two routes under the plugin's existing REST namespace:
 *
 *   GET  /acrossai-mcp-manager/v1/servers/{server_id}/abilities
 *   POST /acrossai-mcp-manager/v1/servers/{server_id}/abilities
 *
 * Both gate on `manage_options` (S2 / FR-012).
 *
 * READ path (post-@wordpress/abilities refactor):
 *   1. Look up server (404 if missing).
 *   2. Return `{ overrides: [ { slug, is_exposed }, ... ] }` — only the pairs
 *      that have a DB row. The client fetches ability metadata (name, label,
 *      category, meta) from the `@wordpress/abilities` data store and merges
 *      client-side. Abilities without an override inherit `meta[mcp][public]`.
 *
 * WRITE path:
 *   1. Look up server (404 if missing).
 *   2. Validate `abilities[]` — reject unknown slugs (400 / FR-011).
 *   3. Read effective value BEFORE upsert (for the FR-024 action's `$was`).
 *   4. Upsert each pair.
 *   5. If effective value changed, fire `acrossai_mcp_ability_exposure_changed`.
 *
 * Wired via `includes/Main.php::define_admin_hooks()` per A1.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\REST
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\REST;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\ExposureResolver;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerAbility\Query as MCPServerAbilityQuery;
use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the per-server abilities surface.
 *
 * @since 0.1.0
 */
final class AbilitiesController {

	/**
	 * REST namespace shared with the rest of the plugin.
	 *
	 * @var string
	 */
	private const NS = 'acrossai-mcp-manager/v1';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 0.1.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — enforces the singleton pattern (S6). No hook
	 * registration in ctors (A1) — wiring lives in Main::define_admin_hooks().
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Register both routes on `rest_api_init`.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register_routes(): void {
		$server_id_arg = array(
			'server_id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NS,
			'/servers/(?P<server_id>\d+)/abilities',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_abilities' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array_merge(
						$server_id_arg,
						array(
							'include_abilities' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_abilities' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array_merge(
						$server_id_arg,
						array(
							'abilities' => array(
								'type'     => 'array',
								'required' => true,
								'items'    => array( 'type' => 'object' ),
							),
						)
					),
				),
			)
		);
	}

	/**
	 * REST permission callback — gates both routes on `manage_options` (S2).
	 * Never `__return_true` (Constitution §III).
	 *
	 * @since 0.1.0
	 * @return bool
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET handler — return per-server override rows and (optionally) the
	 * full ability list.
	 *
	 * Preferred client path: use the `@wordpress/abilities` data store for
	 * the ability list and this endpoint for `overrides` only.
	 *
	 * Fallback client path (when the WP client-side abilities package isn't
	 * loaded): pass `?include_abilities=1` and receive the full ability list
	 * PHP-side alongside the overrides.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_abilities( WP_REST_Request $request ) {
		$server_id = (int) $request['server_id'];

		$server_check = $this->require_server( $server_id );
		if ( is_wp_error( $server_check ) ) {
			return $server_check;
		}

		$response = array(
			'overrides' => $this->fetch_overrides( $server_id ),
		);

		// Fallback path — client asks us to include the ability list too.
		$include_abilities = (bool) $request->get_param( 'include_abilities' );
		if ( $include_abilities && function_exists( 'wp_get_abilities' ) ) {
			$abilities = array();
			foreach ( \wp_get_abilities() as $ability ) {
				$meta        = $ability->get_meta();
				$abilities[] = array(
					// Field name matches the WP `@wordpress/abilities` store shape.
					'name'        => $ability->get_name(),
					'label'       => $ability->get_label(),
					'category'    => $ability->get_category(),
					'description' => $ability->get_description(),
					'meta'        => $meta,
				);
			}
			$response['abilities'] = $abilities;
		}

		// Per-server, per-request response — never cache. See DEC-OAUTH-DONOTCACHEPAGE-PATTERN (ai-connectors D7).
		return CacheHeaders::apply_to_rest_response( new WP_REST_Response( $response ) );
	}

	/**
	 * POST handler — upsert a batch of `{ slug, is_exposed }` pairs and
	 * return the refreshed merged list.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_abilities( WP_REST_Request $request ) {
		$server_id = (int) $request['server_id'];

		$server_check = $this->require_server( $server_id );
		if ( is_wp_error( $server_check ) ) {
			return $server_check;
		}

		$abilities_param = $request->get_param( 'abilities' );
		if ( ! is_array( $abilities_param ) || empty( $abilities_param ) ) {
			return new WP_Error(
				'acrossai_mcp_invalid_payload',
				__( 'The `abilities` field must be a non-empty array.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return new WP_Error(
				'acrossai_mcp_invalid_payload',
				__( 'The WordPress Abilities API is not available on this installation.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		// Build allowlist of currently-registered slugs (FR-011).
		$registered = array();
		foreach ( \wp_get_abilities() as $ability ) {
			$registered[ $ability->get_name() ] = $ability;
		}

		// Validate every entry BEFORE writing anything — the batch is all-or-nothing.
		$normalized = array();
		foreach ( $abilities_param as $index => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['slug'], $entry['is_exposed'] ) || ! is_string( $entry['slug'] ) ) {
				return new WP_Error(
					'acrossai_mcp_invalid_payload',
					sprintf(
						/* translators: %d: batch index */
						__( 'abilities[%d] must be an object with string `slug` and boolean `is_exposed`.', 'acrossai-mcp-manager' ),
						(int) $index
					),
					array( 'status' => 400 )
				);
			}
			$slug = sanitize_text_field( $entry['slug'] );
			if ( ! isset( $registered[ $slug ] ) ) {
				return new WP_Error(
					'acrossai_mcp_invalid_payload',
					sprintf(
						/* translators: %s: ability slug */
						__( 'Ability slug `%s` is not currently registered on this site.', 'acrossai-mcp-manager' ),
						$slug
					),
					array( 'status' => 400 )
				);
			}
			$normalized[] = array(
				'slug'       => $slug,
				'is_exposed' => (bool) $entry['is_exposed'],
			);
		}

		// Perform the writes + fire the effective-change action per pair.
		$user_id = get_current_user_id();
		foreach ( $normalized as $pair ) {
			$ability = $registered[ $pair['slug'] ];
			$meta    = $ability->get_meta();

			// Read effective value BEFORE the upsert.
			$was = ExposureResolver::resolve( $server_id, $pair['slug'], $meta );

			MCPServerAbilityQuery::instance()->upsert( $server_id, $pair['slug'], $pair['is_exposed'] );

			// Bust the resolver cache for this key so the AFTER read is fresh.
			ExposureResolver::_reset_cache_for_tests();
			$now = ExposureResolver::resolve( $server_id, $pair['slug'], $meta );

			if ( $was !== $now ) {
				/**
				 * Fires after a per-(server, ability) exposure value changes
				 * effectively (FR-024, D19 fail-open observability heir).
				 *
				 * Under concurrent writes to the same (server, ability) pair, the `$was`
				 * value reflects the value the resolver returned at the beginning of
				 * THIS request — it may not match the actual pre-write DB state if
				 * another writer commits between our resolver read and our upsert.
				 * Subscribers building strict audit trails should consult the DB's
				 * `updated_at` column for authoritative ordering (SEC-004 caveat).
				 *
				 * @since 0.1.0 @experimental May change without notice before 1.0.0
				 *
				 * @param int    $server_id    MCP server id.
				 * @param string $ability_slug Ability name.
				 * @param bool   $was          Effective exposure before the upsert.
				 * @param bool   $now          Effective exposure after the upsert.
				 * @param int    $user_id      User who initiated the write.
				 */
				do_action( 'acrossai_mcp_ability_exposure_changed', $server_id, $pair['slug'], $was, $now, $user_id );
			}
		}

		// Return the refreshed override rows (FR-010 — never require a follow-up GET).
		ExposureResolver::_reset_cache_for_tests();
		return CacheHeaders::apply_to_rest_response(
			new WP_REST_Response(
				array(
					'overrides' => $this->fetch_overrides( $server_id ),
				)
			)
		);
	}

	/**
	 * Resolve a server row or return a 404 WP_Error.
	 *
	 * @since 0.1.0
	 * @param int $server_id Server id.
	 * @return true|WP_Error True when the server exists.
	 */
	private function require_server( int $server_id ) {
		$rows = MCPServerQuery::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			return new WP_Error(
				'acrossai_mcp_server_not_found',
				__( 'MCP server not found.', 'acrossai-mcp-manager' ),
				array( 'status' => 404 )
			);
		}
		return true;
	}

	/**
	 * Load the per-server override rows as a slim array of
	 * `{ slug, is_exposed }` entries. Only pairs with an actual DB row are
	 * returned — abilities without an override inherit their default
	 * `meta[mcp][public]` on the client side.
	 *
	 * @since 0.1.0
	 *
	 * @param int $server_id Server id.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_overrides( int $server_id ): array {
		$rows = MCPServerAbilityQuery::instance()->query(
			array(
				'server_id' => $server_id,
				'number'    => 0, // no cap
			)
		);
		$out  = array();
		foreach ( $rows as $row ) {
			// $wpdb returns TINYINT as string (B18) — cast at the boundary.
			$out[] = array(
				'slug'       => (string) $row->ability_slug,
				'is_exposed' => (bool) $row->is_exposed,
			);
		}
		return $out;
	}
}
