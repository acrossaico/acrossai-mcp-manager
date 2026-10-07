<?php
/**
 * Memoized registry for AbstractConnectorProfile subclasses (Feature 021).
 *
 * ONE public filter is the ONLY registration path:
 *   apply_filters( 'acrossai_mcp_manager_connector_profiles', [] )
 *
 * The base plugin ships zero profiles — every AI connector is a companion
 * plugin. FR-029, FR-030.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Connectors
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Connectors;

defined( 'ABSPATH' ) || exit;

final class ConnectorProfileRegistry {

	/** @var ConnectorProfileRegistry|null */
	private static $instance = null;

	/**
	 * Memoized profile list (null before first fetch).
	 *
	 * @var array<int, AbstractConnectorProfile>|null
	 */
	private $profiles = null;

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
	 * This ordering is the single source of truth for every surface that
	 * lists connectors — the AI Connectors tab strip, its Settings panel
	 * checkboxes, and (via the Discovery adapter) the free plugin's Quick
	 * Connect wizard.
	 *
	 * @return array<int, AbstractConnectorProfile>
	 */
	public function get_profiles(): array {
		if ( null !== $this->profiles ) {
			return $this->profiles;
		}

		/**
		 * Filters the connector profiles registered on this site.
		 *
		 * F095 dropped the companion's `acrossai_pro_profiles` alias. That was a
		 * BC bridge spanning two plugins, and there is no longer a boundary to
		 * span, so this is the single registration point. Slug-dedup below still
		 * collapses duplicate registrations (later wins).
		 *
		 * @since 0.3.9
		 * @param array<int, AbstractConnectorProfile> $profiles Start empty.
		 */
		$contributed = (array) apply_filters( 'acrossai_mcp_manager_connector_profiles', array() );

		$seen = array();
		foreach ( $contributed as $candidate ) {
			if ( ! ( $candidate instanceof AbstractConnectorProfile ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					_doing_it_wrong(
						'acrossai_mcp_manager_connector_profiles',
						esc_html__( 'Non-AbstractConnectorProfile entry discarded.', 'acrossai-mcp-manager' ),
						'0.1.0'
					);
				}
				continue;
			}

			$slug = $candidate->get_slug();
			if ( '' === $slug || ! preg_match( '/\A[a-z0-9-]{1,64}\z/', $slug ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					_doing_it_wrong(
						'acrossai_mcp_manager_connector_profiles',
						sprintf(
							/* translators: %s: rejected slug */
							esc_html__( 'Profile slug %s does not match /[a-z0-9-]{1,64}/ — discarded.', 'acrossai-mcp-manager' ),
							esc_html( $slug )
						),
						'0.1.0'
					);
				}
				continue;
			}

			if ( isset( $seen[ $slug ] ) && ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				_doing_it_wrong(
					'acrossai_mcp_manager_connector_profiles',
					sprintf(
						/* translators: %s: duplicate slug */
						esc_html__( 'Duplicate connector profile slug %s — later contribution wins.', 'acrossai-mcp-manager' ),
						esc_html( $slug )
					),
					'0.1.0'
				);
			}

			$seen[ $slug ] = $candidate;
		}

		// Priority ASC, slug ASC as the tiebreak — third-party profiles
		// inherit the default 100 and land after the built-ins unless they
		// deliberately override `get_priority()`.
		usort(
			$seen,
			static function ( AbstractConnectorProfile $a, AbstractConnectorProfile $b ): int {
				$cmp = $a->get_priority() <=> $b->get_priority();

				return 0 !== $cmp ? $cmp : $a->get_slug() <=> $b->get_slug();
			}
		);

		$this->profiles = array_values( $seen );

		return $this->profiles;
	}

	/**
	 * Look up a single profile by slug.
	 *
	 * @param string $slug Connector slug.
	 * @return AbstractConnectorProfile|null
	 */
	public function get_profile( string $slug ): ?AbstractConnectorProfile {
		if ( '' === $slug ) {
			return null;
		}
		foreach ( $this->get_profiles() as $profile ) {
			if ( $profile->get_slug() === $slug ) {
				return $profile;
			}
		}
		return null;
	}
}
