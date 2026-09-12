<?php
/**
 * Plugin-owned replacement callbacks for `mcp-adapter/execute-ability`.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Abilities;

use WP\MCP\Domain\Utils\AbilityArgumentNormalizer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-owned callbacks for the `mcp-adapter/execute-ability` tool.
 *
 * @since 0.1.0
 */
final class Execute {
	use AbilityHelpers;

	/**
	 * Bound as `permission_callback` on `mcp-adapter/execute-ability`.
	 *
	 * Ordering matters for the "exposure ≠ authorization" invariant:
	 * auth check → exposure filter → target ability permission_callback.
	 *
	 * @param array<string, mixed> $input Expects `[ 'ability_name' => string, 'parameters' => mixed ]`.
	 * @return bool|\WP_Error
	 */
	public static function check_permission( $input = array() ) {
		$ability_name = isset( $input['ability_name'] ) ? (string) $input['ability_name'] : '';
		if ( '' === $ability_name ) {
			return new WP_Error( 'missing_ability_name', __( 'Ability name is required.', 'acrossai-mcp-manager' ) );
		}

		// 1. Auth + tool-level capability.
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'authentication_required', __( 'User must be authenticated to access this ability.', 'acrossai-mcp-manager' ) );
		}
		$required_capability = apply_filters( 'mcp_adapter_execute_ability_capability', 'read' );
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

		// 2. Exposure filter — per-server visibility gate.
		if ( ! self::apply_exposure_filter( $ability, 'execute' ) ) {
			return new WP_Error(
				'acrossai_mcp_ability_not_exposed_for_server',
				__( 'This ability is not exposed on this MCP server.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		/*
		 * 3. Target ability's own permission_callback — SEPARATE from exposure.
		 * An ability can be "exposed" (visible on this server) but still
		 * forbidden to a given principal at invocation time.
		 */
		$parameters        = $input['parameters'] ?? null;
		// An MCP client's `{}` arrives as stdClass whenever the payload was
		// decoded without associative mode. The vendor normalizer only
		// recognises null and [] as "no arguments", so an object falls
		// straight through and WP_Ability then does array access on it
		// ("Cannot use object of type stdClass as array"). Flatten first.
		if ( is_object( $parameters ) ) {
			$parameters = (array) $parameters;
		}
		$parameters        = AbilityArgumentNormalizer::normalize( $ability, $parameters );
		$permission_result = $ability->check_permissions( $parameters );

		if ( is_wp_error( $permission_result ) ) {
			return $permission_result;
		}
		return (bool) $permission_result;
	}

	/**
	 * Bound as `execute_callback` on `mcp-adapter/execute-ability`.
	 *
	 * @param array<string, mixed> $input Expects `[ 'ability_name' => string, 'parameters' => mixed ]`.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = array() ): array {
		$ability_name = isset( $input['ability_name'] ) ? (string) $input['ability_name'] : '';
		$parameters   = $input['parameters'] ?? null;

		if ( '' === $ability_name ) {
			return array(
				'success' => false,
				'error'   => 'Ability name is required',
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
			return array(
				'success' => false,
				'error'   => sprintf( "Ability '%s' not found", $ability_name ),
			);
		}

		// An MCP client's `{}` arrives as stdClass whenever the payload was
		// decoded without associative mode. The vendor normalizer only
		// recognises null and [] as "no arguments", so an object falls
		// straight through and WP_Ability then does array access on it
		// ("Cannot use object of type stdClass as array"). Flatten first.
		if ( is_object( $parameters ) ) {
			$parameters = (array) $parameters;
		}
		$parameters = AbilityArgumentNormalizer::normalize( $ability, $parameters );

		try {
			$result = $ability->execute( $parameters );
			if ( is_wp_error( $result ) ) {
				return array(
					'success' => false,
					'error'   => $result->get_error_message(),
				);
			}
			return array(
				'success' => true,
				'data'    => $result,
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}
}
