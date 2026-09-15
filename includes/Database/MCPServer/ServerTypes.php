<?php
/**
 * ServerTypes — the registry of MCP server types.
 *
 * Feature 090. A server *type* is a starting point plus a label. It decides what
 * **Switch** and **Reset** WRITE into the existing tool storage, and nothing
 * more: it is never consulted at registration time, so what a server serves
 * stays exactly what the Tools tab says.
 *
 * Two types ship. `mcp-adapter` is the legacy baseline — the three protocol
 * tools — and is the value every pre-090 row is backfilled to. `acrossai` is the
 * AcrossAI-branded type whose tools come from the sibling plugin
 * `acrossai-abilities-manager`; this plugin ships it with an EMPTY tool list and
 * a `requires` key, and the sibling replaces it through the filter below.
 *
 * **This plugin never names the sibling's vocabulary.** `ToolAbilities` states
 * the rule — "Companion plugins declare their own via the filter; nothing here
 * hardcodes another plugin's vocabulary" — and a server type is no exception.
 *
 * Shape deliberately mirrors `Includes\Abilities\ToolAbilities`: a constant
 * seed, one filter, a small normalizer. Per D55, mirroring a sibling's SHAPE
 * does NOT license reusing its accessor NAMES for different semantics — every
 * accessor here means what its name says at this level.
 *
 * NOT `Utilities\RegistryEntryNormalizer`: that helper drops any non-built-in
 * entry lacking a callable `render_callback`, and a server type is pure data
 * with nothing to render.
 *
 * Stateless pure service per the A11 exemption — no singleton, no ctor, no DB
 * access. Resolved on every admin request, so callbacks MUST stay cheap.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServer
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServer;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless registry resolving the available MCP server types.
 *
 * @since 0.1.0
 */
final class ServerTypes {

	/**
	 * The legacy type — every pre-090 server row is backfilled to this, and it
	 * is the always-registered floor that can never be unavailable.
	 *
	 * @var string
	 */
	public const LEGACY = 'mcp-adapter';

	/**
	 * The AcrossAI type. Ships with no tools of its own; the sibling plugin
	 * supplies them by re-registering this same slug.
	 *
	 * @var string
	 */
	public const ACROSSAI = 'acrossai';

	/**
	 * Filter name — the public extension point. Documented in
	 * `docs/extending-server-types.md`.
	 *
	 * @var string
	 */
	public const FILTER = 'acrossai_mcp_server_types';

	/**
	 * Plugin folder slug of the sibling that supplies the AcrossAI type's tools.
	 *
	 * @var string
	 */
	private const ACROSSAI_REQUIRES = 'acrossai-abilities-manager';

	/**
	 * Resolve the full type registry.
	 *
	 * Deliberately NOT memoized — a companion plugin may register its callback
	 * later than the first call, and this runs a handful of times per request.
	 * Matches `ToolAbilities::get_slugs()`'s reasoning exactly.
	 *
	 * @since 0.1.0
	 * @return array<string, array{label: string, description: string, tools: string[], requires: ?string, is_default: bool}>
	 */
	public static function all(): array {
		/**
		 * Filter the registered MCP server types.
		 *
		 * Dedup is slug-keyed LAST-WINS (D41): a later registration REPLACES an
		 * earlier one with the same slug, including this plugin's built-ins.
		 * That is precisely how the sibling overrides the `acrossai`
		 * placeholder below with its real toolsets.
		 *
		 * @since 0.1.0 (Feature 090)
		 *
		 * @param array<string, array<string, mixed>> $types Types keyed by slug.
		 */
		$types = apply_filters( self::FILTER, self::seed() );

		return self::normalize( is_array( $types ) ? $types : array() );
	}

	/**
	 * One type by slug, or null when it is not registered.
	 *
	 * Returning null rather than falling back is deliberate: callers that need
	 * a usable tool list call `tools_for()`, which degrades; callers that need
	 * to know whether the slug is real check this.
	 *
	 * @since 0.1.0
	 * @param string $slug Type slug.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();

		return $all[ $slug ] ?? null;
	}

	/**
	 * The tool slugs a type starts with.
	 *
	 * An unrecognised slug degrades to the legacy type's tools rather than
	 * returning an empty set — a server whose stored type was removed with its
	 * plugin must stay usable, not silently lose every tool.
	 *
	 * @since 0.1.0
	 * @param string $slug Type slug.
	 * @return string[]
	 */
	public static function tools_for( string $slug ): array {
		$all  = self::all();
		$type = $all[ $slug ] ?? null;

		// Unrecognised slug, OR a registered type that contributes no tools of
		// its own. Both fall back to the legacy set.
		//
		// The empty case is load-bearing, not theoretical. This plugin ships
		// `acrossai` as a PLACEHOLDER with `tools => []`, expecting the sibling
		// to replace it (D41 last-wins). Between the sibling being ACTIVE (so
		// `is_available()` is true) and the sibling actually registering its
		// type, the placeholder is what resolves — and a template of `[]` would
		// make Reset WIPE every tool on that server. That is strictly worse than
		// the hardcoded-defaults bug this feature exists to fix.
		//
		// A server type that offers nothing is never a useful template, so
		// returning the legacy set is right regardless of how the empty arose.
		if ( null === $type || empty( $type['tools'] ) ) {
			return $all[ self::LEGACY ]['tools'] ?? array();
		}

		return $type['tools'];
	}

	/**
	 * Whether a type's requirement is satisfied.
	 *
	 * THE single resolver for "requirement met". Selection, the enablement gate
	 * and the runtime tool composition all call this; none re-derives the rule.
	 * B32: a filter default that gates a decision must be the canonical
	 * resolver's output, never a partial derivation.
	 *
	 * An unregistered slug is NOT available — it cannot be shown to work.
	 *
	 * @since 0.1.0
	 * @param string $slug Type slug.
	 * @return bool
	 */
	public static function is_available( string $slug ): bool {
		$type = self::get( $slug );

		if ( null === $type ) {
			return false;
		}

		if ( null === $type['requires'] || '' === $type['requires'] ) {
			return true;
		}

		return self::plugin_is_active( $type['requires'] );
	}

	/**
	 * The type preselected for newly created servers.
	 *
	 * Resolves to the LAST entry flagged `is_default` **whose requirement is
	 * satisfied**, so a site without the sibling never defaults to a type it
	 * cannot use. `mcp-adapter` is the floor and is always registered.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function default_slug(): string {
		$resolved = self::LEGACY;

		foreach ( self::all() as $slug => $type ) {
			if ( $type['is_default'] && self::is_available( $slug ) ) {
				$resolved = $slug;
			}
		}

		return $resolved;
	}

	/**
	 * Why a server of this type may not be ENABLED, or null when it may.
	 *
	 * Returns a `WP_Error` the caller renders in its own surface. Consumed
	 * exclusively by `ServerEnablement::set()` — see that class for why
	 * enforcement lives behind one facade rather than at each call site.
	 *
	 * @since 0.1.0
	 * @param string $slug Type slug.
	 * @return WP_Error|null
	 */
	public static function enablement_error( string $slug ): ?WP_Error {
		if ( self::is_available( $slug ) ) {
			return null;
		}

		$type  = self::get( $slug );
		$label = null !== $type ? $type['label'] : $slug;

		if ( null === $type ) {
			return new WP_Error(
				'acrossai_mcp_unknown_server_type',
				sprintf(
					/* translators: %s: the server type slug stored on the row. */
					esc_html__( 'This server has an unrecognised type (%s). Change its type before enabling it.', 'acrossai-mcp-manager' ),
					$label
				),
				array( 'status' => 400 )
			);
		}

		return new WP_Error(
			'acrossai_mcp_server_type_unavailable',
			sprintf(
				/* translators: 1: server type label, 2: required plugin name. */
				esc_html__( 'The %1$s server type requires the %2$s plugin to be installed and activated. Install it, or change this server\'s type.', 'acrossai-mcp-manager' ),
				$label,
				esc_html__( 'AcrossAI Abilities Manager', 'acrossai-mcp-manager' )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * The built-in types this plugin ships.
	 *
	 * @since 0.1.0
	 * @return array<string, array<string, mixed>>
	 */
	private static function seed(): array {
		return array(
			self::LEGACY   => array(
				'label'       => __( 'MCP Adapter', 'acrossai-mcp-manager' ),
				'description' => __( 'The three built-in MCP tools. AI clients discover and run abilities through them.', 'acrossai-mcp-manager' ),
				'tools'       => ToolPolicy::PROTOCOL_TOOLS,
			),
			self::ACROSSAI => array(
				'label'       => __( 'AcrossAI', 'acrossai-mcp-manager' ),
				'description' => __( 'Abilities grouped into toolsets, supplied by the AcrossAI Abilities Manager add-on.', 'acrossai-mcp-manager' ),
				// Intentionally EMPTY. The sibling plugin re-registers this slug
				// with its own toolsets; this entry is the placeholder it
				// replaces (D41 last-wins).
				'tools'       => array(),
				'requires'    => self::ACROSSAI_REQUIRES,
				'is_default'  => true,
			),
		);
	}

	/**
	 * Coerce filter output into the documented entry shape.
	 *
	 * A malformed contribution degrades to a dropped entry, never a fatal —
	 * the same defensive posture `Controller::register_database_servers()`
	 * applies to its own filters.
	 *
	 * `mcp-adapter` is re-asserted as the floor: a callback that removes it
	 * cannot leave the site with no usable type.
	 *
	 * @since 0.1.0
	 * @param array<string, mixed> $raw Filter output.
	 * @return array<string, array{label: string, description: string, tools: string[], requires: ?string, is_default: bool}>
	 */
	private static function normalize( array $raw ): array {
		$normalized = array();

		foreach ( $raw as $slug => $entry ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' === $slug || ! is_array( $entry ) ) {
				continue;
			}

			$label = isset( $entry['label'] ) ? (string) $entry['label'] : '';

			if ( '' === $label ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					_doing_it_wrong(
						__METHOD__,
						sprintf(
							/* translators: 1: filter name, 2: type slug. */
							esc_html__( '%1$s entry "%2$s" is missing a label and was dropped.', 'acrossai-mcp-manager' ),
							esc_html( self::FILTER ),
							esc_html( $slug )
						),
						'0.1.0'
					);
				}
				continue;
			}

			$tools = isset( $entry['tools'] ) ? (array) $entry['tools'] : array();
			$tools = array_map( 'strval', $tools );
			$tools = array_filter(
				$tools,
				static function ( string $tool ): bool {
					return '' !== $tool;
				}
			);

			$requires = isset( $entry['requires'] ) && '' !== $entry['requires']
				? (string) $entry['requires']
				: null;

			$normalized[ $slug ] = array(
				'label'       => $label,
				'description' => isset( $entry['description'] ) ? (string) $entry['description'] : '',
				'tools'       => array_values( array_unique( $tools ) ),
				'requires'    => $requires,
				'is_default'  => ! empty( $entry['is_default'] ),
			);
		}

		// The floor. A callback that dropped it gets it back.
		if ( ! isset( $normalized[ self::LEGACY ] ) ) {
			$seed                       = self::seed();
			$normalized[ self::LEGACY ] = array(
				'label'       => (string) $seed[ self::LEGACY ]['label'],
				'description' => (string) $seed[ self::LEGACY ]['description'],
				'tools'       => array_values( (array) $seed[ self::LEGACY ]['tools'] ),
				'requires'    => null,
				'is_default'  => false,
			);
		}

		return $normalized;
	}

	/**
	 * Whether a plugin folder slug is installed AND active.
	 *
	 * Both "not installed" and "installed but deactivated" count as unmet; they
	 * differ only in the wording of the remedy the admin surfaces offer.
	 *
	 * @since 0.1.0
	 * @param string $plugin_slug Plugin folder slug.
	 * @return bool
	 */
	private static function plugin_is_active( string $plugin_slug ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_slug . '/' . $plugin_slug . '.php' );
	}
}
