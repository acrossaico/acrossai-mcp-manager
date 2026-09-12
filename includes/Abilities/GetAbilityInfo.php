<?php
/**
 * Plugin-owned replacement callbacks for `mcp-adapter/get-ability-info`.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-owned callbacks for the `mcp-adapter/get-ability-info` tool.
 *
 * @since 0.1.0
 */
final class GetAbilityInfo {
	use AbilityHelpers;

	/**
	 * Bound as `permission_callback` on `mcp-adapter/get-ability-info`.
	 *
	 * @param array<string, mixed> $input Expects `[ 'ability_name' => string ]`.
	 * @return bool|\WP_Error
	 */
	public static function check_permission( $input = array() ) {
		$ability_name = isset( $input['ability_name'] ) ? (string) $input['ability_name'] : '';
		if ( '' === $ability_name ) {
			return new WP_Error( 'missing_ability_name', __( 'Ability name is required.', 'acrossai-mcp-manager' ) );
		}

		// Auth + capability gate (identical to vendor).
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'authentication_required',
				__( 'User must be authenticated to access this ability.', 'acrossai-mcp-manager' )
			);
		}
		$required_capability = apply_filters( 'mcp_adapter_get_ability_info_capability', 'read' );
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- capability resolved via filter
		if ( ! current_user_can( $required_capability ) ) {
			return new WP_Error(
				'insufficient_capability',
				sprintf(
					/* translators: %s: capability slug required by the tool */
					__( 'User lacks required capability: %s', 'acrossai-mcp-manager' ),
					$required_capability
				)
			);
		}

		// Check existence first: since WP 6.9, looking up an unregistered
		// ability makes the registry emit a _doing_it_wrong notice. "Not
		// found" is an expected outcome on this path — the caller supplies
		// the name — so it must not be reported as incorrect core usage.
		$ability = ( function_exists( 'wp_get_ability' ) && function_exists( 'wp_has_ability' ) && \wp_has_ability( $ability_name ) )
			? \wp_get_ability( $ability_name )
			: null;
		if ( ! $ability ) {
			return new WP_Error(
				'ability_not_found',
				sprintf(
					/* translators: %s: requested ability slug */
					__( 'Ability "%s" not found.', 'acrossai-mcp-manager' ),
					$ability_name
				)
			);
		}

		// Exposure filter — the whole point of this refactor.
		if ( ! self::apply_exposure_filter( $ability, 'get_info' ) ) {
			// Client-facing error code stays `acrossai_mcp_ability_not_exposed_for_server`
			// for BC with the prior post-hoc intercept module (see git history).
			// Do NOT rename this without a deprecation cycle.
			return new WP_Error(
				'acrossai_mcp_ability_not_exposed_for_server',
				__( 'This ability is not exposed on this MCP server.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Verbatim port of vendor's execute body — exposure was gated in
	 * `check_permission()` above, so this just serves the data.
	 *
	 * @param array<string, mixed> $input Expects `[ 'ability_name' => string ]`.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = array() ): array {
		$ability_name = isset( $input['ability_name'] ) ? (string) $input['ability_name'] : '';
		if ( '' === $ability_name ) {
			return array( 'error' => 'Ability name is required' );
		}

		// Check existence first: since WP 6.9, looking up an unregistered
		// ability makes the registry emit a _doing_it_wrong notice. "Not
		// found" is an expected outcome on this path — the caller supplies
		// the name — so it must not be reported as incorrect core usage.
		$ability = ( function_exists( 'wp_get_ability' ) && function_exists( 'wp_has_ability' ) && \wp_has_ability( $ability_name ) )
			? \wp_get_ability( $ability_name )
			: null;
		if ( ! $ability ) {
			return array( 'error' => sprintf( "Ability '%s' not found", $ability_name ) );
		}

		$info = array(
			'name'         => $ability->get_name(),
			'label'        => $ability->get_label(),
			'description'  => $ability->get_description(),
			'input_schema' => $ability->get_input_schema(),
		);

		$output_schema = $ability->get_output_schema();
		if ( ! empty( $output_schema ) ) {
			$info['output_schema'] = $output_schema;
		}
		$meta = $ability->get_meta();
		if ( ! empty( $meta ) ) {
			$info['meta'] = $meta;
		}

		return $info;
	}
}
