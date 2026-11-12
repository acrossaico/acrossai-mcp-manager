<?php
/**
 * Feature 104 — the Clients screen.
 *
 * Answers "who has been connecting to this site", which until now nothing
 * could: the activity log is pruned at 30 days, `oauth_clients` has no
 * last-seen, and the AI Connectors "connections" panel lists live grants whose
 * last-activity figure is really the newest token's creation time — the last
 * refresh rotation, not the last call.
 *
 * ## Why "Clients" and not "Connections"
 *
 * The per-server wizard tab is already called "Connect", and the AI Connectors
 * method inside it already has a `?panel=connections`. A third thing called
 * connections would have been the third different meaning of the word on one
 * screen. "Clients" names the thing the rows ARE.
 *
 * ## Relationship to that existing panel
 *
 * They are deliberately both alive. `?panel=connections` answers "what is
 * authorised right now" and can revoke a live grant; this answers "who has
 * connected", including clients whose grants are long gone. Merging them is a
 * decision for after this has proved itself, not a prerequisite for shipping
 * it.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Clients\Revoker;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Query as ClientsQuery;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Recorder as ClientsRecorder;
use AcrossAI_Main_Menu\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page for the clients registry. Hook wiring lives in Main.php per A1.
 *
 * @since 0.4.9
 */
final class ClientsPage {

	/**
	 * `?page=` slug for this screen.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const PAGE_SLUG = 'acrossai_mcp_clients';

	/**
	 * Days after which an unused client counts as idle.
	 *
	 * Lifted from easy-mcp-ai's stale-grant sweep, which uses the same
	 * `COALESCE(last_used, created)` expression over 90 days — the figure is
	 * theirs and it independently validates treating a null last-seen as
	 * "idle since it was registered".
	 *
	 * @since 0.4.9
	 * @var   int
	 */
	public const IDLE_DAYS = 90;

	/**
	 * Singleton instance.
	 *
	 * @since 0.4.9
	 * @var   self|null
	 */
	private static ?self $instance = null;

	/**
	 * Shared instance.
	 *
	 * @since  0.4.9
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private — wiring lives in Main::define_admin_hooks() per A1.
	 *
	 * @since 0.4.9
	 */
	private function __construct() {}

	/**
	 * Register the submenu.
	 *
	 * Position 9, directly after Logs at 8. The two answer adjacent questions —
	 * what happened, and who has been here — and an operator who opens one
	 * usually wants the other next.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			SettingsPage::PARENT_SLUG,
			__( 'MCP Clients', 'acrossai-mcp-manager' ),
			__( 'Clients', 'acrossai-mcp-manager' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			9
		);
	}

	/**
	 * The nonce action for one row action.
	 *
	 * Scoped to the action AND the row, so a Revoke link cannot be replayed
	 * against a different client.
	 *
	 * @since  0.4.9
	 * @param  string $action Action name.
	 * @param  int    $id     Registry row id.
	 * @return string
	 */
	public static function nonce_action( string $action, int $id ): string {
		return 'acrossai_mcp_client_' . $action . '_' . $id;
	}

	/**
	 * Run a Revoke or Forget if one was requested, and report the outcome.
	 *
	 * Public and static so the per-server tab can call it too — the row actions
	 * post back to whichever screen rendered them, and the handler has to work
	 * on both.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public static function maybe_handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is verified below, once we know which action and row to scope it to.
		$action = isset( $_GET['client_action'] ) ? sanitize_key( wp_unslash( (string) $_GET['client_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$id = isset( $_GET['client'] ) ? absint( wp_unslash( (string) $_GET['client'] ) ) : 0;

		if ( ! in_array( $action, array( 'revoke', 'forget' ), true ) || $id <= 0 ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change MCP client access.', 'acrossai-mcp-manager' ) );
		}

		check_admin_referer( self::nonce_action( $action, $id ) );

		$result = 'revoke' === $action ? Revoker::revoke( $id ) : Revoker::forget( $id );

		if ( is_wp_error( $result ) ) {
			self::notice( 'error', $result->get_error_message() );

			return;
		}

		self::notice(
			'success',
			'forget' === $action
				? __( 'Client record removed.', 'acrossai-mcp-manager' )
				: (string) $result
		);
	}

	/**
	 * Render the global screen.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view MCP clients.', 'acrossai-mcp-manager' ) );
		}

		self::maybe_handle_action();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'MCP Clients', 'acrossai-mcp-manager' ) . '</h1>';
		echo '<p class="description">'
			. esc_html__( 'Every AI client, bridge and paired site that has connected to your MCP servers. Rows are kept after a client stops calling, so this outlives the activity log.', 'acrossai-mcp-manager' )
			. '</p>';

		self::render_table( 0 );

		echo '</div>';
	}

	/**
	 * Render the stat line, any pending notice, and the table.
	 *
	 * Shared between this screen and the per-server tab so the two cannot
	 * drift — the only difference is the scope.
	 *
	 * @since  0.4.9
	 * @param  int $server_id Scope to one server, or 0 for every server.
	 * @return void
	 */
	public static function render_table( int $server_id ): void {
		self::render_notice();
		self::render_failure_warning();
		self::render_summary( $server_id );

		$table = new ClientsListTable( $server_id );
		$table->prepare_items();
		$table->display();
	}

	/**
	 * The counts above the table.
	 *
	 * @since  0.4.9
	 * @param  int $server_id Scope, or 0 for all.
	 * @return void
	 */
	private static function render_summary( int $server_id ): void {
		$summary = ClientsQuery::instance()->summary( $server_id, self::IDLE_DAYS );

		if ( 0 === $summary['total'] ) {
			return;
		}

		$parts = array(
			sprintf(
				/* translators: %s: number of clients. */
				_n( '%s client', '%s clients', $summary['total'], 'acrossai-mcp-manager' ),
				number_format_i18n( $summary['total'] )
			),
		);

		if ( $summary['never_used'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: number of clients. */
				__( '%s never used', 'acrossai-mcp-manager' ),
				number_format_i18n( $summary['never_used'] )
			);
		}

		if ( $summary['idle'] > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: number of clients, 2: number of days. */
				__( '%1$s not seen in %2$s days', 'acrossai-mcp-manager' ),
				number_format_i18n( $summary['idle'] ),
				number_format_i18n( self::IDLE_DAYS )
			);
		}

		if ( $summary['revoked'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: number of clients. */
				__( '%s revoked', 'acrossai-mcp-manager' ),
				number_format_i18n( $summary['revoked'] )
			);
		}

		echo '<p class="description">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
	}

	/**
	 * Say so when the registry is failing to write.
	 *
	 * Without this, a broken write path and a genuinely quiet site look
	 * identical — an empty list. They are opposite facts, and only one of them
	 * needs an operator's attention.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	private static function render_failure_warning(): void {
		$failures = ClientsRecorder::failure_count();

		if ( $failures <= 0 ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of failed writes. */
					_n(
						'%s client record could not be written. This list may be incomplete.',
						'%s client records could not be written. This list may be incomplete.',
						$failures,
						'acrossai-mcp-manager'
					),
					number_format_i18n( $failures )
				)
			)
		);
	}

	/**
	 * Stash a notice for the current render.
	 *
	 * A transient keyed to the user, not a redirect: the row actions are GET
	 * links handled during the same render that then draws the table, so there
	 * is no redirect to carry a query argument across. Keyed per user so two
	 * administrators acting at once cannot read each other's result.
	 *
	 * @since  0.4.9
	 * @param  string $type    `success` or `error`.
	 * @param  string $message Human-readable text.
	 * @return void
	 */
	private static function notice( string $type, string $message ): void {
		set_transient( self::notice_key(), array( $type, $message ), 60 );
	}

	/**
	 * Draw and clear any pending notice.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	private static function render_notice(): void {
		$notice = get_transient( self::notice_key() );

		if ( ! is_array( $notice ) || 2 !== count( $notice ) ) {
			return;
		}

		delete_transient( self::notice_key() );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $notice[0] ? 'error' : 'success',
			esc_html( (string) $notice[1] )
		);
	}

	/**
	 * Transient key for the current user's pending notice.
	 *
	 * @since  0.4.9
	 * @return string
	 */
	private static function notice_key(): string {
		return 'acrossai_mcp_clients_notice_' . get_current_user_id();
	}
}
