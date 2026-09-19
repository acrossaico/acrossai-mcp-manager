<?php
/**
 * Feature 020 — Per-server Tool Selection REST controller.
 *
 * Registers two routes under the plugin's REST namespace:
 *
 *   GET  /acrossai-mcp-manager/v1/servers/{server_id}/tools
 *   POST /acrossai-mcp-manager/v1/servers/{server_id}/tools
 *
 * Both gate on `manage_options` (S2 / FR-021).
 *
 * READ path:
 *   1. Look up server (404 if missing).
 *   2. Return `{ tools: [ ability_slug, ... ] }` — the current curated set.
 *   3. When `?include_abilities=1`, also return the full ability catalog
 *      (excluding the three protocol tools) as `{ abilities: [ ... ] }`.
 *
 * WRITE path (replace-all semantics):
 *   1. Look up server (404 if missing).
 *   2. Sanitize + validate every slug (400 on unknown or excluded).
 *   3. Call `Query::replace_set()` inside a try/catch — TX rollback
 *      surfaces as generic 500 with `acrossai_mcp_tools_save_failed`.
 *   4. Flush the ToolExposureGate per-request cache.
 *   5. Fire `acrossai_mcp_tools_changed` per applied add/remove — each
 *      `do_action` individually wrapped in try/catch so observer errors
 *      never bubble to the REST response (FR-031).
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
use AcrossAI_MCP_Manager\Includes\Abilities\Toolset\Registrar as ToolsetRegistrar;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as MCPServerToolQuery;
use AcrossAI_MCP_Manager\Includes\MCP\ToolExposureGate;
use AcrossAI_MCP_Manager\Includes\Utilities\CacheHeaders;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the per-server tools surface.
 *
 * @since 0.1.0
 */
final class ToolsController {

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
				'validate_callback' => static function ( $value ) {
					if ( (int) $value > 0 ) {
						return true;
					}
					return new WP_Error(
						'rest_invalid_id',
						esc_html__( 'server_id must be a positive integer.', 'acrossai-mcp-manager' ),
						array( 'status' => 400 )
					);
				},
			),
		);

		register_rest_route(
			self::NS,
			'/servers/(?P<server_id>\d+)/tools',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tools' ),
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
					'callback'            => array( $this, 'post_tools' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array_merge(
						$server_id_arg,
						array(
							// F090 — declared so core rejects a non-string at the
							// boundary; the handler still validates it against the
							// registry, because "is a string" is not "is a type".
							'server_type' => array(
								'type'     => 'string',
								'required' => false,
							),
							'tools'       => array(
								'type'              => 'array',
								'items'             => array( 'type' => 'string' ),
								'required'          => true,
								'sanitize_callback' => static function ( $value ) {
									return array_values(
										array_map(
											'sanitize_text_field',
											(array) $value
										)
									);
								},
								'validate_callback' => static function ( $value ) {
									if ( is_array( $value ) ) {
										return true;
									}
									return new WP_Error(
										'rest_invalid_type',
										esc_html__( 'The `tools` field must be an array of ability slug strings.', 'acrossai-mcp-manager' ),
										array( 'status' => 400 )
									);
								},
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
	 * GET handler — return current curated slug set + optional ability catalog.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_tools( WP_REST_Request $request ) {
		$server_id = (int) $request['server_id'];

		$server_row = $this->fetch_server_row( $server_id );
		if ( is_wp_error( $server_row ) ) {
			return $server_row;
		}

		// F025: 'tools' is the CONFIGURED set — the composed union of enabled
		// protocol columns and curated rows (ToolPolicy::compose_for_row).
		//
		// F090 adds 'effective_tools': what the server ACTUALLY serves, after the
		// standing policy and the unmet-requirement swap. The two are no longer
		// the same question, and returning only one of them is what would let the
		// Tools tab show a list the server is not serving (ARCH-2).
		$server_type = (string) $server_row->server_type;

		$response = array(
			'tools'           => ToolPolicy::compose_for_row( $server_row ),
			'effective_tools' => ToolPolicy::compose_effective_tools_for_row( $server_row ),
			'server_type'     => $server_type,
			// Whether the operator has switched this server ON. The tab uses it
			// to decide whether an unmet requirement is worth mentioning: a
			// disabled server serves nobody, so warning that it would serve
			// only a setup notice is noise stacked on top of the "Server is
			// disabled" banner already above it.
			'server_enabled'  => ! empty( $server_row->is_enabled ),
			'type_available'  => ServerTypes::is_available( $server_type ),
			// Falls back to the raw slug so an unrecognised type renders as
			// itself marked unavailable, rather than blank (FR-010).
			'type_label'      => self::type_label( $server_type ),
			'server_types'    => self::types_payload(),
			// F090 — the pool this server may offer, computed server-side so the
			// picker and "Enable All" cannot disagree about what "every
			// available tool" means. Scoped to the server's TYPE since 0.3.6,
			// so an AcrossAI server stops offering mcp-adapter's vocabulary.
			'type_pool'       => ServerTypes::pool( (string) $server_row->server_type ),
		);

		$include_abilities = (bool) $request->get_param( 'include_abilities' );
		if ( $include_abilities ) {
			$abilities  = array();
			$seen_names = array();
			if ( function_exists( 'wp_get_abilities' ) ) {
				foreach ( \wp_get_abilities() as $ability ) {
					$name                = (string) $ability->get_name();
					$meta                = $ability->get_meta();
					$abilities[]         = array(
						'name'        => $name,
						'label'       => (string) $ability->get_label(),
						'description' => (string) $ability->get_description(),
						'type'        => is_array( $meta ) && isset( $meta['mcp']['type'] ) ? (string) $meta['mcp']['type'] : '',
						'category'    => (string) $ability->get_category(),
					);
					$seen_names[ $name ] = true;
				}
			}
			// F025 runtime-timing fallback: guarantee the three protocol slugs
			// appear in the catalog so the UI's left "All abilities" pane can
			// re-add one after the operator removes it via the ConfirmDialog.
			// The vendor mcp-adapter's `wp_register_ability` for these three
			// slugs fires on `wp_abilities_api_init`, but its listener attaches
			// inside Controller::initialize_adapter() (rest_api_init) — too
			// late for wp_abilities_api_init on REST requests. ToolPolicy is
			// the canonical source for the metadata; dedup guards against a
			// future timing fix that DOES land them in wp_get_abilities().
			foreach ( ToolPolicy::PROTOCOL_TOOL_METADATA as $stub ) {
				if ( isset( $seen_names[ $stub['name'] ] ) ) {
					continue;
				}
				$seen_names[ $stub['name'] ] = true;
				$abilities[]                 = $stub;
			}

			// Same idea, different reason. A Toolset's ability is registered by
			// the AcrossAI Abilities Manager add-on, so on a site without it
			// every `toolset/*` slug an AcrossAI server carries is absent from
			// the registry — and the tab rendered each as a bare slug printed
			// twice with no description, which reads as breakage rather than as
			// a tool waiting on a plugin.
			//
			// This plugin owns the dispatcher classes, so it knows their real
			// label and description without anything being registered. Dedup
			// leaves the live registration authoritative whenever there is one.
			foreach ( ToolsetRegistrar::tool_metadata() as $stub ) {
				if ( isset( $seen_names[ $stub['name'] ] ) ) {
					continue;
				}
				$seen_names[ $stub['name'] ] = true;
				$abilities[]                 = array_merge(
					$stub,
					array(
						'type'     => 'tool',
						'category' => '',
					)
				);
			}

			$response['abilities'] = $abilities;
		}

		// Per-server, per-request response — never cache. See DEC-OAUTH-DONOTCACHEPAGE-PATTERN (ai-connectors D7).
		return CacheHeaders::apply_to_rest_response( new WP_REST_Response( $response ) );
	}

	/**
	 * POST handler — replace the tool set with the submitted array.
	 *
	 * @since 0.1.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_tools( WP_REST_Request $request ) {
		$server_id = (int) $request['server_id'];

		$server_row = $this->fetch_server_row( $server_id );
		if ( is_wp_error( $server_row ) ) {
			return $server_row;
		}

		$tools_param = $request->get_param( 'tools' );
		if ( ! is_array( $tools_param ) ) {
			// Belt-and-braces — the args validate_callback should have caught this.
			return new WP_Error(
				'rest_invalid_type',
				esc_html__( 'The `tools` field must be an array of ability slug strings.', 'acrossai-mcp-manager' ),
				array( 'status' => 400 )
			);
		}

		// Validate against the currently-registered ability catalog (all-or-nothing).
		// F025: protocol slugs are canonical plugin constants (ToolPolicy::PROTOCOL_TOOLS)
		// and MUST bypass wp_get_abilities() validation. The vendor mcp-adapter
		// registers them on wp_abilities_api_init, but its listener attaches inside
		// Controller::initialize_adapter() (rest_api_init) — which fires AFTER
		// wp_abilities_api_init on any REST request whose Abilities-API bootstrap
		// already ran on `init`. That leaves wp_get_abilities() blind to the three
		// protocol slugs at POST-time. Since ToolPolicy::PROTOCOL_TOOLS is the
		// authoritative source, catalog resolution is not needed for these slugs.
		// (SEC-025-v2-2 correction — the v2 review claimed the hook order was safe;
		// runtime evidence 2026-07-14 disproved that. See F025 plan-review-v3 if
		// authored.)
		// The type this write leaves the server on — the submitted one when the
		// request also switches type, since the two are one atomic write below.
		// Resolved BEFORE validation because it decides which slugs are
		// canonical for this server.
		$effective_type = (string) $server_row->server_type;
		$submitted_type = $request->get_param( 'server_type' );

		if ( null !== $submitted_type && '' !== $submitted_type ) {
			$candidate = sanitize_key( (string) $submitted_type );

			if ( null !== ServerTypes::get( $candidate ) ) {
				$effective_type = $candidate;
			}
		}

		if ( function_exists( 'wp_get_abilities' ) ) {
			$registered = array();
			foreach ( \wp_get_abilities() as $ability ) {
				$registered[ (string) $ability->get_name() ] = true;
			}

			// A type's OWN declared tools are canonical for a server of that
			// type, registered or not. Without this, an AcrossAI server on a
			// site lacking the add-on could not be saved at all: every
			// `toolset/*` slug it legitimately carries is unregistered, so
			// pressing Reset — or saving the tab at all — answered 400
			// `acrossai_mcp_invalid_tool_slug` and the operator was locked out
			// of their own tool list.
			//
			// This is a narrow exemption, not a hole. It admits exactly the
			// vocabulary a registered server type declares for itself, which is
			// the same list the seeder writes at INSERT; an arbitrary slug is
			// still rejected.
			$canonical = array_merge(
				ToolPolicy::PROTOCOL_TOOLS,
				ServerTypes::declared_tools( $effective_type )
			);

			$invalid_slugs = array();
			foreach ( $tools_param as $slug ) {
				$slug_str = (string) $slug;
				if ( '' === $slug_str ) {
					continue;
				}
				if ( in_array( $slug_str, $canonical, true ) ) {
					continue;
				}
				if ( ! isset( $registered[ $slug_str ] ) ) {
					$invalid_slugs[] = $slug_str;
				}
			}
			if ( ! empty( $invalid_slugs ) ) {
				return new WP_Error(
					'acrossai_mcp_invalid_tool_slug',
					esc_html__( 'One or more submitted ability slugs are not registered on this site.', 'acrossai-mcp-manager' ),
					array(
						'status'        => 400,
						'invalid_slugs' => array_values( array_unique( $invalid_slugs ) ),
					)
				);
			}
		}

		// F090 (T022/T023) — an optional server_type makes a type switch and its
		// resulting tool set ONE atomic write, so the two can never disagree.
		$type_columns = array();

		if ( null !== $submitted_type && '' !== $submitted_type ) {
			$type_param = sanitize_key( (string) $submitted_type );

			// Rejected here rather than above: an unregistered type must fail
			// the request, but the validation block only needed to know which
			// type's vocabulary to trust, and falls back to the stored one.
			if ( null === ServerTypes::get( $type_param ) ) {
				return new WP_Error(
					'acrossai_mcp_invalid_server_type',
					esc_html__( 'That server type is not registered on this site.', 'acrossai-mcp-manager' ),
					array( 'status' => 400 )
				);
			}

			if ( $type_param !== (string) $server_row->server_type ) {
				$type_columns['server_type'] = $type_param;
			}
		}

		// F025: split the unified payload across the two storage layers.
		$split         = ToolPolicy::split_payload( $tools_param );
		$prior_columns = array(
			'tool_discover_abilities' => (int) $server_row->tool_discover_abilities,
			'tool_get_ability_info'   => (int) $server_row->tool_get_ability_info,
			'tool_execute_ability'    => (int) $server_row->tool_execute_ability,
		);

		try {
			// Layer 1 — flip the three protocol columns in one UPDATE.
			MCPServerQuery::instance()->update_item( $server_id, array_merge( $split['columns'], $type_columns ) );

			// SEC-025-INFO-2: accepted race window between column update and curated
			// replace_set — see security-review v1 § two-write POST path. Two concurrent
			// saves on the same server may leave columns from writer A and curated rows
			// from writer B. Tools tab is single-operator in practice; window is
			// milliseconds; reset is one click away.

			// Layer 2 — transactional replace_set for the curated remainder.
			$curated_applied = MCPServerToolQuery::instance()->replace_set( $server_id, $split['curated'] );
		} catch ( \Throwable $e ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- SEC-020-010: log-specific / respond-generic. Log has no user-controlled content.
				sprintf(
					'[acrossai_mcp_tools_save_failed] server_id=%d, desired_count=%d: %s',
					$server_id,
					count( $tools_param ),
					$e->getMessage()
				)
			);
			return new WP_Error(
				'acrossai_mcp_tools_save_failed',
				esc_html__( 'Could not save the tools list. Please try again.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}

		// Invalidate the enforcement gate's per-request cache so a same-request
		// tool call following this save sees fresh state.
		ToolExposureGate::flush_cache( $server_id );

		// F025 FR-016 — fire acrossai_mcp_tools_changed per flipped protocol column
		// (one bullet per column whose new value differs from pre-save). Reuses the
		// existing F020 event stream so audit subscribers observe a unified diff.
		$columns_added   = array();
		$columns_removed = array();
		foreach ( ToolPolicy::COLUMN_MAP as $column => $slug ) {
			$new_value = (int) $split['columns'][ $column ];
			$old_value = (int) $prior_columns[ $column ];
			if ( $new_value === $old_value ) {
				continue;
			}
			if ( 1 === $new_value ) {
				$this->fire_change_action( $server_id, $slug, 'added' );
				$columns_added[] = $slug;
			} else {
				$this->fire_change_action( $server_id, $slug, 'removed' );
				$columns_removed[] = $slug;
			}
		}

		// Curated-side flips continue firing per F020's existing per-slug loop.
		foreach ( $curated_applied['added'] as $slug ) {
			$this->fire_change_action( $server_id, (string) $slug, 'added' );
		}
		foreach ( $curated_applied['removed'] as $slug ) {
			$this->fire_change_action( $server_id, (string) $slug, 'removed' );
		}

		// Re-fetch the row so the response's composed 'tools' reflects the write.
		$refreshed = $this->fetch_server_row( $server_id );
		if ( is_wp_error( $refreshed ) ) {
			// Extremely unlikely — the server existed above the write.
			return $refreshed;
		}

		// BerlinDB's singleton Query can serve a memoized row within the same
		// request, so the re-fetch above may predate this handler's own column
		// write. Re-apply what we know we wrote; without this a type switch
		// reports the PREVIOUS type and its tool list.
		foreach ( $type_columns as $column => $value ) {
			$refreshed->{$column} = $value;
		}

		return CacheHeaders::apply_to_rest_response(
			new WP_REST_Response(
				array(
					'tools'           => ToolPolicy::compose_for_row( $refreshed ),
					'added'           => array_values( array_merge( $columns_added, $curated_applied['added'] ) ),
					'removed'         => array_values( array_merge( $columns_removed, $curated_applied['removed'] ) ),
					// F090 — the POST response mirrors the GET's F090 fields so
					// the Tools tab reconciles against server truth after a
					// write, exactly as it already does for `tools`. Omitting
					// them would leave a type switch's UI state optimistic and
					// unverified.
					'effective_tools' => ToolPolicy::compose_effective_tools_for_row( $refreshed ),
					'server_type'     => (string) $refreshed->server_type,
					'server_enabled'  => ! empty( $refreshed->is_enabled ),
					'type_available'  => ServerTypes::is_available( (string) $refreshed->server_type ),
					'type_label'      => self::type_label( (string) $refreshed->server_type ),
					// The pool is TYPE-DEPENDENT, so a switch must return the new
					// one. Without it the picker keeps offering the previous
					// type's tools until the operator reloads.
					'type_pool'       => ServerTypes::pool( (string) $refreshed->server_type ),
					'server_types'    => self::types_payload(),
				)
			)
		);
	}

	/**
	 * Resolve a server Row or return a 404 WP_Error.
	 *
	 * F025 refactor: previously returned bool — now returns the Row so callers
	 * can read the tool_* column state without a second DB round-trip.
	 *
	 * @since 0.1.0
	 * @param int $server_id Server id.
	 * @return \AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row|WP_Error
	 */
	private function fetch_server_row( int $server_id ) {
		$rows = MCPServerQuery::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			return new WP_Error(
				'acrossai_mcp_server_not_found',
				esc_html__( 'MCP server not found.', 'acrossai-mcp-manager' ),
				array( 'status' => 404 )
			);
		}
		return $rows[0];
	}

	/**
	 * Fire the `acrossai_mcp_tools_changed` action wrapped in try/catch.
	 *
	 * A throwing observer is caught + `error_log`'d; it does NOT bubble to
	 * the REST response. Later observers continue firing. FR-031.
	 *
	 * @since 0.1.0
	 * @param int    $server_id    Server id.
	 * @param string $ability_slug Ability slug.
	 * @param string $operation    'added' | 'removed'.
	 * @return void
	 */
	private function fire_change_action( int $server_id, string $ability_slug, string $operation ): void {
		try {
			/**
			 * Fires once per applied add + once per applied remove during a
			 * successful POST /servers/{id}/tools.
			 *
			 * Payload is a positional array — callbacks receive it via the
			 * first parameter. Never contains user IDs, IP addresses, or
			 * session identifiers.
			 *
			 * @since 0.1.0
			 *
			 * @param array{server_id:int, ability_slug:string, operation:string} $payload
			 */
			do_action(
				'acrossai_mcp_tools_changed',
				array(
					'server_id'    => $server_id,
					'ability_slug' => $ability_slug,
					'operation'    => $operation,
				)
			);
		} catch ( \Throwable $e ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- FR-031 SEC-020-004 observer isolation. Slug is validated by wp_get_abilities catalog membership above; server_id is an int.
				sprintf(
					'[acrossai_mcp_tools_changed] observer error for %s on %d: %s',
					$ability_slug,
					$server_id,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * The registered server types, shaped for the Tools tab selector.
	 *
	 * Availability is resolved here rather than in JS so the UI cannot drift
	 * from `ServerTypes::is_available()` — the single resolver the enablement
	 * gate and the runtime composer also use (B32).
	 *
	 * @since 0.1.0 (Feature 090)
	 * @return array<int, array{slug: string, label: string, description: string, available: bool, requires: ?string, tools: string[]}>
	 */
	private static function types_payload(): array {
		$payload = array();

		foreach ( ServerTypes::all() as $slug => $type ) {
			$payload[] = array(
				'slug'        => (string) $slug,
				'label'       => (string) $type['label'],
				'description' => (string) $type['description'],
				'available'   => ServerTypes::is_available( (string) $slug ),
				'requires'    => $type['requires'],
				// The actual slugs, not just a count — the Tools tab resolves
				// Reset and a type switch from this, so the UI cannot drift from
				// what the server would store.
				//
				// DECLARED, not `tools_for()`. This is what Reset WRITES, and
				// the narrowed answer is wrong for a write: on a site without
				// the add-on every `toolset/*` slug narrows away, so Reset on an
				// AcrossAI server replaced its fifteen dormant Toolsets with
				// mcp-adapter's four — data loss dressed as a restore.
				//
				// The write path accepts these: `post_tools()` treats a type's
				// own declared tools as canonical for a server of that type, so
				// the picker can no longer offer something the save would
				// reject.
				'tools'       => ServerTypes::declared_tools( (string) $slug ),
			);
		}

		return $payload;
	}

	/**
	 * A type's display label, falling back to the raw slug.
	 *
	 * Extracted at its second use (Constitution VI) — the GET and POST
	 * responses both need it, and a copy is how the two drift.
	 *
	 * @since 0.1.0 (Feature 090)
	 * @param string $slug Stored type slug.
	 * @return string
	 */
	private static function type_label( string $slug ): string {
		$type = ServerTypes::get( $slug );

		return null !== $type ? (string) $type['label'] : $slug;
	}
}
