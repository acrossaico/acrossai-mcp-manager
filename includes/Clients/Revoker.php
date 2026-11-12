<?php
/**
 * Withdraws a client's access, by whichever route it holds (Feature 104).
 *
 * ## Revoke means four different things
 *
 * The Clients screen shows one list, but the credential behind a row differs
 * per route, and so does the act of taking it away:
 *
 * | Route               | What is revoked                              |
 * | ------------------- | -------------------------------------------- |
 * | connector / oauth   | every live token for that client id          |
 * | application password| that one WordPress application password      |
 * | fleet               | the host-list row that authorises the peer   |
 * | browser / direct    | nothing — there is no standing credential    |
 *
 * The last line is why this returns a reason rather than a bool: a screen that
 * offers Revoke on a row with nothing to revoke, and then reports success, has
 * told the operator their site is safer than it is. The list asks
 * {@see self::can_revoke()} first and renders no action at all where the answer
 * is no — absent, not disabled-and-unexplained.
 *
 * ## Why this is not in the Database namespace
 *
 * It reaches into OAuth and Fleet to end a grant. The registry row is a record
 * of that having happened, written afterwards by `Clients\Recorder::revoke()`;
 * the two are deliberately separate so a failed credential revoke does not
 * leave a row claiming the access is gone.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Clients
 * @since      0.4.8
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Clients;

use AcrossAI_MCP_Manager\Includes\Database\Clients\Query as ClientsQuery;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Recorder as ClientsRecorder;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Row;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as OAuthTokensQuery;
use AcrossAI_MCP_Manager\Includes\Fleet\Hosts;
use AcrossAI_MCP_Manager\Includes\Utilities\ClientsConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Routes a revoke to the credential that actually backs the row.
 *
 * @since 0.4.8
 */
final class Revoker {

	/**
	 * Not instantiable — stateless, never Loader-wired.
	 *
	 * @since 0.4.8
	 */
	private function __construct() {}

	/**
	 * Is there a credential behind this row that we can take away?
	 *
	 * @since  0.4.8
	 * @param  Row $row Registry row.
	 * @return bool
	 */
	public static function can_revoke( Row $row ): bool {
		if ( $row->is_revoked() ) {
			return false;
		}

		switch ( $row->route ) {
			case ClientsConstants::ROUTE_CONNECTOR:
				return '' !== $row->client_id;

			case ClientsConstants::ROUTE_FLEET:
				// The key has to be IN the host list, not merely present on the
				// row. Rows seeded by the backfill carry the peer LABEL rather
				// than the host key, because the activity log never stored one
				// — so without this check every backfilled fleet row would
				// offer a Revoke that can only ever fail.
				return '' !== $row->identity && self::is_fleet_host( $row->server_id, $row->identity );

			default:
				// Every remaining route reaches us by application password when
				// it carries a credential at all — the npm bridge, WP-CLI and a
				// config-file client alike. The uuid is the test, not the route.
				return '' !== $row->credential_id;
		}
	}

	/**
	 * Revoke one client's access and record that it happened.
	 *
	 * @since  0.4.8
	 * @param  int $id Registry row id.
	 * @return \WP_Error|string A human-readable summary, or the reason it failed.
	 */
	public static function revoke( int $id ) {
		$row = self::row( $id );

		if ( $row instanceof \WP_Error ) {
			return $row;
		}

		if ( ! self::can_revoke( $row ) ) {
			return new \WP_Error(
				'acrossai_mcp_clients_nothing_to_revoke',
				__( 'This client holds no credential that can be withdrawn from here.', 'acrossai-mcp-manager' )
			);
		}

		$done = self::withdraw( $row );

		if ( $done instanceof \WP_Error ) {
			return $done;
		}

		// Only now. A registry row marked revoked while the credential still
		// works is worse than no record at all: it is a record that says the
		// opposite of the truth.
		ClientsRecorder::revoke( $id );

		return $done;
	}

	/**
	 * Drop a row, keeping the credential alone.
	 *
	 * The answer to clutter rather than to risk — test clients, a renamed
	 * install, the rows a backfill turned up from traffic nobody remembers.
	 * Always available, because a row we cannot revoke is exactly the row most
	 * likely to be junk.
	 *
	 * @since  0.4.8
	 * @param  int $id Registry row id.
	 * @return \WP_Error|true
	 */
	public static function forget( int $id ) {
		$row = self::row( $id );

		if ( $row instanceof \WP_Error ) {
			return $row;
		}

		$deleted = ClientsQuery::instance()->delete_item( $id );

		if ( ! $deleted ) {
			return new \WP_Error(
				'acrossai_mcp_clients_forget_failed',
				__( 'The client record could not be removed.', 'acrossai-mcp-manager' )
			);
		}

		ClientsQuery::instance()->flush_cache();

		return true;
	}

	/**
	 * Take the credential away, per route.
	 *
	 * @since  0.4.8
	 * @param  Row $row Registry row.
	 * @return \WP_Error|string
	 */
	private static function withdraw( Row $row ) {
		if ( ClientsConstants::ROUTE_CONNECTOR === $row->route ) {
			// Per-server, deliberately: the same client id may hold a separate
			// grant on another server, and this row speaks only for this one.
			$revoked = OAuthTokensQuery::instance()->revoke_by_client_id( $row->client_id, $row->server_id );

			return sprintf(
				/* translators: %d: number of access tokens. */
				_n( '%d access token revoked.', '%d access tokens revoked.', count( $revoked ), 'acrossai-mcp-manager' ),
				count( $revoked )
			);
		}

		if ( ClientsConstants::ROUTE_FLEET === $row->route ) {
			// `identity` IS the host key for a fleet row — see Clients\Identity.
			if ( ! Hosts::remove( $row->server_id, $row->identity ) ) {
				return new \WP_Error(
					'acrossai_mcp_clients_fleet_remove_failed',
					__( 'That host is no longer in this server\'s host list.', 'acrossai-mcp-manager' )
				);
			}

			return __( 'Host removed from the fleet.', 'acrossai-mcp-manager' );
		}

		if ( ! class_exists( '\\WP_Application_Passwords' ) ) {
			return new \WP_Error(
				'acrossai_mcp_clients_no_app_passwords',
				__( 'Application passwords are not available on this site.', 'acrossai-mcp-manager' )
			);
		}

		$deleted = \WP_Application_Passwords::delete_application_password( $row->user_id, $row->credential_id );

		if ( true !== $deleted ) {
			return new \WP_Error(
				'acrossai_mcp_clients_app_password_failed',
				__( 'That application password has already been removed.', 'acrossai-mcp-manager' )
			);
		}

		return __( 'Application password deleted.', 'acrossai-mcp-manager' );
	}

	/**
	 * Is this key one of the server's registered fleet hosts?
	 *
	 * @since  0.4.8
	 * @param  int    $server_id Fleet server row id.
	 * @param  string $key       Host key.
	 * @return bool
	 */
	private static function is_fleet_host( int $server_id, string $key ): bool {
		// `Hosts::all()` normalises every row, so `key` is always present.
		foreach ( Hosts::all( $server_id ) as $host ) {
			if ( $host['key'] === $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load a row, or say why not.
	 *
	 * @since  0.4.8
	 * @param  int $id Registry row id.
	 * @return \WP_Error|Row
	 */
	private static function row( int $id ) {
		$row = $id > 0 ? ClientsQuery::instance()->get_item( $id ) : null;

		if ( ! $row instanceof Row ) {
			return new \WP_Error(
				'acrossai_mcp_clients_not_found',
				__( 'That client record no longer exists.', 'acrossai-mcp-manager' )
			);
		}

		return $row;
	}
}
