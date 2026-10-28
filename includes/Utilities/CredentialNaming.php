<?php
/**
 * Naming rules for the Application Passwords this plugin issues.
 *
 * Extracted from `Admin\Partials\ApplicationPasswords` when Feature 099 gave
 * `Public\Renderers\MCPClientsBlock` the same need: the renderer must count a
 * server's existing credentials, and counting means knowing how they are
 * named. A9 puts a constant read by two or more modules here rather than on a
 * sibling feature class, and a `public/` class reaching into `admin/` would
 * invert the layering besides.
 *
 * The NAME IS THE DURABLE PROVENANCE RECORD (B76). The activity-log entry
 * written alongside each issuance ages out on that log's retention schedule;
 * the name lives exactly as long as the credential it describes, and is what
 * the operator reads when deciding which one to revoke.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Utilities
 */

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Credential naming constants and matching.
 */
final class CredentialNaming {

	/**
	 * Prefix every credential this plugin issues must carry.
	 *
	 * Load-bearing, not decorative: the Tokens listing and
	 * `GET /list-app-passwords` both filter on this substring, so a credential
	 * issued without it is invisible to the operator who created it. That was a
	 * real defect — wizard-generated credentials were named
	 * "Quick Edit - Claude Desktop" and appeared in neither place.
	 *
	 * @var string
	 */
	public const PREFIX = 'AcrossAI MCP Manager';

	/**
	 * Separator between the parts of a credential name.
	 *
	 * @var string
	 */
	public const SEPARATOR = ' — ';

	/**
	 * Does this credential belong to this plugin?
	 *
	 * @param string $name Credential display name.
	 * @return bool
	 */
	public static function is_ours( string $name ): bool {
		return '' !== $name && false !== strpos( $name, self::PREFIX );
	}

	/**
	 * Does this credential belong to this plugin AND this server?
	 *
	 * Matches on the server name the issuing code wrote into the name. A
	 * server renamed after a credential was issued stops matching, which is
	 * correct: the name records what was true at issue time, and silently
	 * re-attributing an old credential to a new name would be worse than
	 * under-counting.
	 *
	 * @param string $name        Credential display name.
	 * @param string $server_name Server display name.
	 * @return bool
	 */
	public static function is_for_server( string $name, string $server_name ): bool {
		if ( ! self::is_ours( $name ) ) {
			return false;
		}

		return '' !== $server_name && false !== strpos( $name, $server_name );
	}

	/**
	 * Count the current user's credentials issued for one server.
	 *
	 * @param int    $user_id     User whose credentials to count.
	 * @param string $server_name Server display name.
	 * @return int
	 */
	public static function count_for_server( int $user_id, string $server_name ): int {
		if ( ! class_exists( 'WP_Application_Passwords' ) || $user_id <= 0 ) {
			return 0;
		}

		// Core types this as a list of fully-populated credential arrays, so a
		// defensive is_array()/isset() pair here is provably dead code —
		// PHPStan says so at level 8 — and dead guards make a reader wonder
		// which case they were protecting against.
		$all = \WP_Application_Passwords::get_user_application_passwords( $user_id );

		$count = 0;

		foreach ( $all as $password ) {
			if ( self::is_for_server( (string) $password['name'], $server_name ) ) {
				++$count;
			}
		}

		return $count;
	}
}
