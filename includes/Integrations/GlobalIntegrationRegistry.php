<?php
/**
 * Memoized registry for site-wide integration toggles (Feature 024).
 *
 * ONE public filter is the ONLY registration path:
 *   apply_filters( 'acrossai_mcp_manager_global_integrations', [] )
 *
 * Deliberately the same shape as ConnectorProfileRegistry — singleton, fire the
 * filter once per request, dedupe by slug (later wins), sort by priority then
 * slug. Registry-plus-descriptor is this plugin's standard extension seam and
 * this is its second instance; see memory A17.
 *
 * Scope boundary (memory D22): members of THIS registry are enabled once for the
 * whole site. Per-server enablement belongs to ConnectorProfileRegistry members
 * and is answered by `ConnectorSettings::is_slug_enabled_on_server()`. The two
 * are not interchangeable — n8n's admin-issued bearer tokens never pass through
 * `/authorize`, which is the only place the per-server gate runs.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Integrations
 * @since 0.9.14
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Integrations;

defined( 'ABSPATH' ) || exit;

final class GlobalIntegrationRegistry {

	/** @var GlobalIntegrationRegistry|null */
	private static $instance = null;

	/**
	 * Memoized descriptor list (null before first fetch).
	 *
	 * @var array<int, GlobalIntegration>|null
	 */
	private $integrations = null;

	/**
	 * Private constructor enforces singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Fire the filter ONCE per request. Dedupe by slug (later-wins with
	 * `_doing_it_wrong` under WP_DEBUG). Sort by `get_priority()` ascending,
	 * with slug ascending as the tiebreak.
	 *
	 * This ordering is the single source of truth for the Pro settings tab's
	 * integration sections — adding a member here is the whole of what it takes
	 * to get a rendered toggle (FR-009).
	 *
	 * @return array<int, GlobalIntegration>
	 */
	public function get_integrations(): array {
		if ( null !== $this->integrations ) {
			return $this->integrations;
		}

		/**
		 * Filter: register site-wide integration toggles.
		 *
		 * @param array<int, GlobalIntegration> $integrations Start empty.
		 * @return array<int, GlobalIntegration>
		 */
		$contributed = (array) apply_filters( 'acrossai_mcp_manager_global_integrations', array() );

		$seen = array();
		foreach ( $contributed as $candidate ) {
			if ( ! ( $candidate instanceof GlobalIntegration ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					_doing_it_wrong(
						'acrossai_mcp_manager_global_integrations',
						esc_html__( 'Non-GlobalIntegration entry discarded.', 'acrossai-mcp-manager' ),
						'0.9.14'
					);
				}
				continue;
			}

			$slug = $candidate->get_slug();
			if ( '' === $slug || ! preg_match( '/\A[a-z0-9-]{1,64}\z/', $slug ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					_doing_it_wrong(
						'acrossai_mcp_manager_global_integrations',
						sprintf(
							/* translators: %s: rejected slug */
							esc_html__( 'Integration slug %s does not match /[a-z0-9-]{1,64}/ — discarded.', 'acrossai-mcp-manager' ),
							esc_html( $slug )
						),
						'0.9.14'
					);
				}
				continue;
			}

			// An option or filter name is not derivable from the slug (FR-010)
			// — a descriptor missing either cannot be resolved, so drop it
			// rather than fabricate a name that would orphan stored values.
			if ( '' === $candidate->get_option() || '' === $candidate->get_filter() ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					_doing_it_wrong(
						'acrossai_mcp_manager_global_integrations',
						sprintf(
							/* translators: %s: integration slug */
							esc_html__( 'Integration %s must declare both an option name and a filter name — discarded.', 'acrossai-mcp-manager' ),
							esc_html( $slug )
						),
						'0.9.14'
					);
				}
				continue;
			}

			if ( isset( $seen[ $slug ] ) && ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				_doing_it_wrong(
					'acrossai_mcp_manager_global_integrations',
					sprintf(
						/* translators: %s: duplicate slug */
						esc_html__( 'Duplicate global integration slug %s — later contribution wins.', 'acrossai-mcp-manager' ),
						esc_html( $slug )
					),
					'0.9.14'
				);
			}

			$seen[ $slug ] = $candidate;
		}

		// Priority ASC, slug ASC as the tiebreak — third-party integrations
		// inherit the default 100 and land after the built-ins unless they
		// deliberately set a lower priority.
		usort(
			$seen,
			static function ( GlobalIntegration $a, GlobalIntegration $b ): int {
				$cmp = $a->get_priority() <=> $b->get_priority();

				return 0 !== $cmp ? $cmp : $a->get_slug() <=> $b->get_slug();
			}
		);

		$this->integrations = array_values( $seen );

		return $this->integrations;
	}

	/**
	 * Look up a single descriptor by slug.
	 *
	 * @param string $slug Integration slug.
	 * @return GlobalIntegration|null
	 */
	public function get_integration( string $slug ): ?GlobalIntegration {
		if ( '' === $slug ) {
			return null;
		}
		foreach ( $this->get_integrations() as $integration ) {
			if ( $integration->get_slug() === $slug ) {
				return $integration;
			}
		}
		return null;
	}

	/**
	 * Whether a site-wide integration is switched on.
	 *
	 * Resolves the descriptor's own option through its own filter, preserving
	 * the D6 hybrid semantics each member declares for itself. An unregistered
	 * slug is disabled (FR-012) — fail closed, because the alternative is that
	 * a typo in a caller silently enables a surface.
	 *
	 * @param string $slug Integration slug.
	 * @return bool
	 */
	public static function is_enabled( string $slug ): bool {
		$integration = self::instance()->get_integration( $slug );
		if ( null === $integration ) {
			return false;
		}

		/**
		 * Per-integration override filter, named by the descriptor.
		 *
		 * @param bool $enabled Resolved from the descriptor's option.
		 * @return bool
		 */
		return (bool) apply_filters(
			$integration->get_filter(),
			(bool) get_option( $integration->get_option(), $integration->get_default() )
		);
	}

	/**
	 * Reset the memoized list. Test seam only — production fires the filter
	 * once per request by design.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->integrations = null;
	}
}
