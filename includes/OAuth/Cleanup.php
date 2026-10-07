<?php
/**
 * Daily cron cleanup for F021 tables (Feature 021 / SEC-021-T01).
 *
 * Fires `acrossai_mcp_manager_oauth_cleanup` at the start of each run so
 * integrators can piggy-back on the daily schedule. Then deletes expired
 * auth codes and expired-plus-revoked tokens.
 *
 * SEC-021-T01: this class MUST ship in Phase 2 alongside the cron schedule
 * to avoid a fatal-error window between activation and Phase 7.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Query as AuthCodesQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as TokensQuery;

defined( 'ABSPATH' ) || exit;

final class Cleanup {

	/** @var Cleanup|null */
	private static $instance = null;

	/** @var bool Re-entry guard (WP-Cron sometimes double-fires under load). */
	private static $running = false;

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
	 * Cron handler.
	 *
	 * Fires the observability action first — subscribers can capture the
	 * "cleanup started" timestamp before rows disappear.
	 */
	public function run(): void {
		if ( self::$running ) {
			return;
		}
		self::$running = true;

		try {
			/**
			 * Action: acrossai_mcp_manager_oauth_cleanup
			 * Fired once at start of each daily cleanup run. No args.
			 */
			do_action( 'acrossai_mcp_manager_oauth_cleanup' );

			$now = gmdate( 'Y-m-d H:i:s' );

			AuthCodesQuery::instance()->delete_expired( $now );
			TokensQuery::instance()->delete_expired( $now );
		} finally {
			self::$running = false;
		}
	}
}
