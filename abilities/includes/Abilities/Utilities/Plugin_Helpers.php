<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers for fuzzy plugin resolution, activation, deactivation, and listing.
 */
class Plugin_Helpers {

	/**
	 * Get all installed plugins with status info.
	 *
	 * @param string $status_filter 'all', 'active', or 'inactive'.
	 * @return array { plugins, total, active, actions }
	 */
	public static function get_all_plugins( string $status_filter = 'all' ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$plugins        = array();
		$active_count   = 0;

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$is_active = in_array( $plugin_file, $active_plugins, true );

			if ( 'active' === $status_filter && ! $is_active ) {
				continue;
			}
			if ( 'inactive' === $status_filter && $is_active ) {
				continue;
			}

			if ( $is_active ) {
				++$active_count;
			}

			$plugins[] = array(
				'name'    => $plugin_data['Name'],
				'slug'    => $plugin_file,
				'version' => $plugin_data['Version'],
				'author'  => $plugin_data['Author'],
				'active'  => $is_active,
			);
		}

		$actions = array();
		foreach ( $plugins as $plugin ) {
			if ( $plugin['active'] ) {
				$actions[] = array(
					'label'        => $plugin['name'],
					'button_label' => __( 'Deactivate', 'acrossai-abilities-manager' ),
					'action'       => 'plugins/deactivate-plugin',
					'args'         => array( 'plugin' => $plugin['slug'] ),
				);
			} else {
				$actions[] = array(
					'label'        => $plugin['name'],
					'button_label' => __( 'Activate', 'acrossai-abilities-manager' ),
					'action'       => 'plugins/activate-plugin',
					'args'         => array( 'plugin' => $plugin['slug'] ),
				);
			}
		}

		return array(
			'plugins' => $plugins,
			'total'   => count( $plugins ),
			'active'  => $active_count,
			'actions' => $actions,
		);
	}

	/**
	 * Resolve a plugin identifier (name, slug, or partial match) to a plugin file path.
	 *
	 * Tiered fuzzy match with certainty scoring:
	 * - 10.0: Exact slug match
	 * - 9.5:  Exact name match (case-insensitive)
	 * - 9.0:  Slug directory prefix
	 * - 8.0:  Name starts with input
	 * - 6.0:  Input is substring of name
	 * - 5.0:  Name is substring of input
	 *
	 * @param string $identifier Plugin name, slug, or partial string.
	 * @return array { plugin_file, plugin_name, certainty, candidates }
	 */
	public static function resolve_plugin( string $identifier ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$input_lower = strtolower( trim( $identifier ) );
		$input_lower = preg_replace( '/\s+plugin$/', '', $input_lower );

		if ( '' === $input_lower ) {
			return array(
				'plugin_file' => null,
				'plugin_name' => null,
				'certainty'   => 0.0,
				'candidates'  => array(),
			);
		}

		$matches = array();

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$name_lower = strtolower( $plugin_data['Name'] );
			$slug_dir   = strtolower( dirname( $plugin_file ) );

			if ( strtolower( $plugin_file ) === $input_lower ) {
				$matches[] = array(
					'plugin_file' => $plugin_file,
					'plugin_name' => $plugin_data['Name'],
					'tier'        => 1,
					'certainty'   => 10.0,
				);
				continue;
			}

			if ( $name_lower === $input_lower ) {
				$matches[] = array(
					'plugin_file' => $plugin_file,
					'plugin_name' => $plugin_data['Name'],
					'tier'        => 2,
					'certainty'   => 9.5,
				);
				continue;
			}

			if ( '.' !== $slug_dir && $slug_dir === $input_lower ) {
				$matches[] = array(
					'plugin_file' => $plugin_file,
					'plugin_name' => $plugin_data['Name'],
					'tier'        => 3,
					'certainty'   => 9.0,
				);
				continue;
			}

			if ( str_starts_with( $name_lower, $input_lower ) ) {
				$matches[] = array(
					'plugin_file' => $plugin_file,
					'plugin_name' => $plugin_data['Name'],
					'tier'        => 4,
					'certainty'   => 8.0,
				);
				continue;
			}

			if ( false !== strpos( $name_lower, $input_lower ) ) {
				$matches[] = array(
					'plugin_file' => $plugin_file,
					'plugin_name' => $plugin_data['Name'],
					'tier'        => 5,
					'certainty'   => 6.0,
				);
				continue;
			}

			if ( false !== strpos( $input_lower, $name_lower ) ) {
				$matches[] = array(
					'plugin_file' => $plugin_file,
					'plugin_name' => $plugin_data['Name'],
					'tier'        => 6,
					'certainty'   => 5.0,
				);
				continue;
			}
		}

		if ( empty( $matches ) ) {
			return array(
				'plugin_file' => null,
				'plugin_name' => null,
				'certainty'   => 0.0,
				'candidates'  => array(),
			);
		}

		usort(
			$matches,
			static function ( array $a, array $b ): int {
				if ( $a['tier'] !== $b['tier'] ) {
					return $a['tier'] - $b['tier'];
				}
				return strlen( $a['plugin_name'] ) - strlen( $b['plugin_name'] );
			}
		);

		$best            = $matches[0];
		$best_tier       = $best['tier'];
		$same_tier_count = 0;

		foreach ( $matches as $match ) {
			if ( $match['tier'] === $best_tier ) {
				++$same_tier_count;
			}
		}

		if ( $same_tier_count > 1 ) {
			$best['certainty'] = max( 1.0, $best['certainty'] - 1.0 );
		}

		return array(
			'plugin_file' => $best['plugin_file'],
			'plugin_name' => $best['plugin_name'],
			'certainty'   => $best['certainty'],
			'candidates'  => array_slice( $matches, 0, 5 ),
		);
	}

	/**
	 * Get plugin data by its file path slug.
	 *
	 * @param string $plugin_file Plugin file path (e.g. "akismet/akismet.php").
	 * @return array|null Plugin data or null if not found.
	 */
	public static function get_plugin_by_slug( string $plugin_file ): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();

		if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
			return null;
		}

		$is_active = is_plugin_active( $plugin_file );

		return array(
			'name'    => $all_plugins[ $plugin_file ]['Name'],
			'slug'    => $plugin_file,
			'version' => $all_plugins[ $plugin_file ]['Version'],
			'author'  => $all_plugins[ $plugin_file ]['Author'],
			'active'  => $is_active,
		);
	}

	/**
	 * Activate a plugin by its slug or display name (fuzzy-resolved).
	 *
	 * Returns candidate action buttons when certainty is below 8.0.
	 *
	 * @param string $plugin_identifier Plugin file path, name, or partial match.
	 * @return array { success, message, certainty, matched_plugin?, actions? }
	 */
	public static function activate_plugin_by_slug( string $plugin_identifier ): array {
		$plugin_identifier = sanitize_text_field( $plugin_identifier );
		$resolved          = self::resolve_plugin( $plugin_identifier );

		if ( null === $resolved['plugin_file'] ) {
			return array(
				'success'   => false,
				/* translators: %s: plugin identifier */
				'message'   => sprintf( __( 'No plugin found matching "%s".', 'acrossai-abilities-manager' ), $plugin_identifier ),
				'certainty' => 0.0,
			);
		}

		if ( $resolved['certainty'] < 8.0 ) {
			return self::build_candidate_response(
				$resolved,
				$plugin_identifier,
				'plugins/activate-plugin',
				__( 'Activate', 'acrossai-abilities-manager' )
			);
		}

		$plugin_file = $resolved['plugin_file'];
		$plugin_name = $resolved['plugin_name'];
		$certainty   = $resolved['certainty'];

		if ( is_plugin_active( $plugin_file ) ) {
			return array(
				'success'        => true,
				/* translators: %s: plugin name */
				'message'        => sprintf( __( 'Plugin "%s" is already active.', 'acrossai-abilities-manager' ), $plugin_name ),
				'matched_plugin' => $plugin_name,
				'certainty'      => $certainty,
			);
		}

		$result = activate_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'        => false,
				/* translators: 1: plugin name, 2: error message */
				'message'        => sprintf( __( 'Failed to activate plugin "%1$s": %2$s', 'acrossai-abilities-manager' ), $plugin_name, $result->get_error_message() ),
				'matched_plugin' => $plugin_name,
				'certainty'      => $certainty,
			);
		}

		if ( ! is_plugin_active( $plugin_file ) ) {
			return array(
				'success'        => false,
				/* translators: %s: plugin name */
				'message'        => sprintf( __( 'Failed to activate plugin "%s".', 'acrossai-abilities-manager' ), $plugin_name ),
				'matched_plugin' => $plugin_name,
				'certainty'      => $certainty,
			);
		}

		return array(
			'success'        => true,
			/* translators: %s: plugin name */
			'message'        => sprintf( __( 'Plugin "%s" has been activated successfully.', 'acrossai-abilities-manager' ), $plugin_name ),
			'matched_plugin' => $plugin_name,
			'certainty'      => $certainty,
		);
	}

	/**
	 * Deactivate a plugin by its slug or display name (fuzzy-resolved).
	 *
	 * Returns candidate action buttons when certainty is below 8.0.
	 *
	 * @param string $plugin_identifier Plugin file path, name, or partial match.
	 * @return array { success, message, certainty, matched_plugin?, actions? }
	 */
	public static function deactivate_plugin_by_slug( string $plugin_identifier ): array {
		$plugin_identifier = sanitize_text_field( $plugin_identifier );
		$resolved          = self::resolve_plugin( $plugin_identifier );

		if ( null === $resolved['plugin_file'] ) {
			return array(
				'success'   => false,
				/* translators: %s: plugin identifier */
				'message'   => sprintf( __( 'No plugin found matching "%s".', 'acrossai-abilities-manager' ), $plugin_identifier ),
				'certainty' => 0.0,
			);
		}

		if ( $resolved['certainty'] < 8.0 ) {
			return self::build_candidate_response(
				$resolved,
				$plugin_identifier,
				'plugins/deactivate-plugin',
				__( 'Deactivate', 'acrossai-abilities-manager' )
			);
		}

		$plugin_file = $resolved['plugin_file'];
		$plugin_name = $resolved['plugin_name'];
		$certainty   = $resolved['certainty'];

		if ( ! is_plugin_active( $plugin_file ) ) {
			return array(
				'success'        => true,
				/* translators: %s: plugin name */
				'message'        => sprintf( __( 'Plugin "%s" is already inactive.', 'acrossai-abilities-manager' ), $plugin_name ),
				'matched_plugin' => $plugin_name,
				'certainty'      => $certainty,
			);
		}

		deactivate_plugins( $plugin_file );

		if ( is_plugin_active( $plugin_file ) ) {
			return array(
				'success'        => false,
				/* translators: %s: plugin name */
				'message'        => sprintf( __( 'Failed to deactivate plugin "%s".', 'acrossai-abilities-manager' ), $plugin_name ),
				'matched_plugin' => $plugin_name,
				'certainty'      => $certainty,
			);
		}

		return array(
			'success'        => true,
			/* translators: %s: plugin name */
			'message'        => sprintf( __( 'Plugin "%s" has been deactivated.', 'acrossai-abilities-manager' ), $plugin_name ),
			'matched_plugin' => $plugin_name,
			'certainty'      => $certainty,
		);
	}

	/**
	 * Build a response with candidate action buttons when certainty is too low to act.
	 *
	 * @param array  $resolved       Result from resolve_plugin().
	 * @param string $original_input The user's original input string.
	 * @param string $action_id      Ability action ID (e.g. "plugins/activate-plugin").
	 * @param string $button_label   Button label (e.g. "Activate").
	 * @return array
	 */
	public static function build_candidate_response( array $resolved, string $original_input, string $action_id, string $button_label ): array {
		$actions = array();

		foreach ( $resolved['candidates'] as $candidate ) {
			$actions[] = array(
				'label'        => sprintf( '%s (%.1f/10)', $candidate['plugin_name'], $candidate['certainty'] ),
				'button_label' => $button_label,
				'action'       => $action_id,
				'args'         => array( 'plugin' => $candidate['plugin_file'] ),
			);
		}

		return array(
			'success'   => false,
			/* translators: %s: user input */
			'message'   => sprintf( __( 'Multiple plugins match "%s". Please select the correct one:', 'acrossai-abilities-manager' ), $original_input ),
			'certainty' => $resolved['certainty'],
			'actions'   => $actions,
		);
	}
}
