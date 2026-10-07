<?php
/**
 * FR-042 / Q4 — WordPress user deletion cascade (Feature 021).
 *
 * Hooks `deleted_user @ 10`. Bulk-revokes every token for the deleted user
 * + bulk-deletes their pending auth codes + fires
 * `acrossai_mcp_manager_oauth_token_revoked` per revoked row with reason
 * `'user_deleted'`.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\OAuth
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\OAuth;

use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Query as ConnectorApprovedUsersQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Query as AuthCodesQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as TokensQuery;

defined( 'ABSPATH' ) || exit;

final class UserLifecycle {

	/** @var UserLifecycle|null */
	private static $instance = null;

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
	 * `deleted_user @ 10` callback.
	 *
	 * Order: revoke tokens first (so any concurrent request presenting a
	 * bearer fails immediately), then delete pending auth codes.
	 *
	 * @param int $user_id Deleted user id.
	 * @return void
	 */
	public function on_user_deleted( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$revoked_ids = TokensQuery::instance()->revoke_by_user_id( $user_id );
		foreach ( $revoked_ids as $token_id ) {
			/**
			 * Action: acrossai_mcp_manager_oauth_token_revoked
			 * Fires once per row transitioned to `revoked=1`. Reason
			 * `user_deleted` is a stable enum per contracts/php-hooks.md.
			 */
			do_action( 'acrossai_mcp_manager_oauth_token_revoked', (int) $token_id, 'user_deleted' );
		}

		AuthCodesQuery::instance()->delete_by_user_id( $user_id );

		// F032 FR-042 cascade extension — remove every connector approval row
		// for this user across every (server, connector) pair. Site-wide by
		// design; mirrors the tokens + auth codes cascade above.
		ConnectorApprovedUsersQuery::instance()->delete_by_user_id( $user_id );
	}
}
