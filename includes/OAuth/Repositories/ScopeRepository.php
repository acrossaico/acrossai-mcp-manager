<?php
/**
 * ScopeRepository — single-scope validator (Feature 021 ships `mcp` only).
 *
 * Multi-scope support is a follow-up feature per spec.md §Assumptions.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth\Repositories
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth\Repositories;

defined( 'ABSPATH' ) || exit;

final class ScopeRepository {

	private const DEFAULT_SCOPE = 'mcp';

	/**
	 * True iff the requested scope is exactly the single supported scope.
	 *
	 * @param string $scope Requested scope (may be empty — falls back to default).
	 * @return bool
	 */
	public static function is_valid( string $scope ): bool {
		return '' === $scope || self::DEFAULT_SCOPE === $scope;
	}

	/**
	 * @return string Default scope value ('mcp').
	 */
	public static function default_scope(): string {
		return self::DEFAULT_SCOPE;
	}

	/**
	 * Normalize a caller-supplied scope: empty → 'mcp'; invalid → 'mcp'.
	 *
	 * @param string $scope
	 * @return string
	 */
	public static function normalize( string $scope ): string {
		return self::is_valid( $scope ) && '' !== $scope ? $scope : self::DEFAULT_SCOPE;
	}
}
