<?php
/**
 * The Clients list — who has connected to this site's MCP servers (F104).
 *
 * `WP_List_Table`, not a React app, and that is the single biggest cost saving
 * in this feature. Logs is React because it filters a large, fast-moving table
 * live. This is one row per client per server — tens of rows on a busy site —
 * so the same screen in React would have cost a webpack entry, a REST
 * controller, a second `@wordpress/dataviews` app and the committed-bundle
 * staleness gate, for a list that fits on one page.
 *
 * Precedent: {@see CliAuthLogListTable}, which already renders inside a
 * per-server tab.
 *
 * ## Why "how it connects" is one column and not four
 *
 * The ask was connector, MCP client, npm and WP-CLI as separate columns. They
 * are four values of one axis — how the client got in — and as columns they
 * would be four mostly-empty cells per row. The live data says so: every
 * request row on this site has an empty `connector_slug`, because there is no
 * OAuth traffic yet. One column that always says something beats four that
 * mostly do not.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Clients\Revoker;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Query as ClientsQuery;
use AcrossAI_MCP_Manager\Includes\Database\Clients\Row;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Utilities\ClientsConstants;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Paginated registry of MCP clients.
 *
 * Per A10: `WP_List_Table` subclasses take a public constructor and are
 * instantiated per render, never Loader-wired.
 *
 * @since 0.4.9
 */
class ClientsListTable extends \WP_List_Table {

	/**
	 * Rows per page. Matches CliAuthLogListTable rather than the log's 50: this
	 * list is short, and a taller page would be mostly whitespace.
	 *
	 * @since 0.4.9
	 * @var   int
	 */
	private const PER_PAGE = 20;

	/**
	 * Scope to one server, or 0 for every server.
	 *
	 * @since 0.4.9
	 * @var   int
	 */
	private $server_id;

	/**
	 * Server id → name memo, for the Server column on the global screen.
	 *
	 * @since 0.4.9
	 * @var   array<int, string>
	 */
	private $server_names = array();

	/**
	 * @since 0.4.9
	 * @param int $server_id Scope to one server, or 0 for all of them.
	 */
	public function __construct( int $server_id = 0 ) {
		$this->server_id = absint( $server_id );

		parent::__construct(
			array(
				'singular' => 'mcp_client',
				'plural'   => 'mcp_clients',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * Server appears only on the global screen — inside a server's own tab it
	 * would repeat the page heading on every row. Same idea `ActivityLogPage`
	 * carries as `showServer`.
	 *
	 * @since  0.4.9
	 * @return array<string, string>
	 */
	public function get_columns() {
		$columns = array(
			'client' => __( 'Client', 'acrossai-mcp-manager' ),
			'route'  => __( 'How it connects', 'acrossai-mcp-manager' ),
			'user'   => __( 'User', 'acrossai-mcp-manager' ),
			'status' => __( 'Status', 'acrossai-mcp-manager' ),
		);

		if ( 0 === $this->server_id ) {
			$columns['server'] = __( 'Server', 'acrossai-mcp-manager' );
		}

		$columns['first_seen_at'] = __( 'First connected', 'acrossai-mcp-manager' );
		$columns['last_seen_at']  = __( 'Last used', 'acrossai-mcp-manager' );
		$columns['request_count'] = __( 'Requests', 'acrossai-mcp-manager' );

		return $columns;
	}

	/**
	 * Sortable columns.
	 *
	 * @since  0.4.9
	 * @return array<string, array<int, mixed>>
	 */
	public function get_sortable_columns() {
		return array(
			'client'        => array( 'client_name', false ),
			'first_seen_at' => array( 'first_seen_at', false ),
			'last_seen_at'  => array( 'last_seen_at', true ),
			'request_count' => array( 'request_count', false ),
		);
	}

	/**
	 * Empty-state message.
	 *
	 * Says WHEN rows appear, not just that there are none. "No clients" on a
	 * site that has answered hundreds of requests is the message the existing
	 * Connections panel shows, and it reads as a bug rather than a state.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No MCP client has connected yet. A client appears here the first time it calls, and stays after that.', 'acrossai-mcp-manager' );
	}

	/**
	 * Fetch rows and set up pagination.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function prepare_items() {
		$page = $this->get_pagenum();

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$query = ClientsQuery::instance();

		$args = array(
			'number'  => self::PER_PAGE,
			'offset'  => ( $page - 1 ) * self::PER_PAGE,
			'orderby' => $this->orderby(),
			'order'   => $this->order(),
		);

		if ( $this->server_id > 0 ) {
			$args['server_id'] = $this->server_id;
		}

		$this->items = array_values(
			array_filter(
				(array) $query->query( $args ),
				static function ( $row ) {
					return $row instanceof Row;
				}
			)
		);

		$count_args           = $args;
		$count_args['count']  = true;
		$count_args['number'] = 0;
		$count_args['offset'] = 0;

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->query( $count_args ),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Client name, with its version and credential hint beneath, plus the row
	 * actions.
	 *
	 * Falls back to `identity` when the name is empty — which is most rows on
	 * most sites, because `client_name` arrives only on MCP `initialize`. The
	 * identity is a user-agent product token in that case, so the cell reads
	 * `claude-code` rather than being blank.
	 *
	 * @since  0.4.9
	 * @param  Row $item Registry row.
	 * @return string
	 */
	public function column_client( $item ) {
		$name = '' !== $item->client_name ? $item->client_name : $item->identity;

		$cell = '<strong>' . esc_html( $name ) . '</strong>';

		if ( '' !== $item->client_version ) {
			$cell .= ' <span class="description">' . esc_html( $item->client_version ) . '</span>';
		}

		if ( '' !== $item->credential_hint ) {
			$cell .= '<br /><code>' . esc_html( $item->credential_hint ) . '</code>';
		}

		return $cell . $this->row_actions( $this->actions_for( $item ) );
	}

	/**
	 * How the client got in.
	 *
	 * A connector names itself; everything else uses the Connect tab's own
	 * vocabulary, so a route reads the same way on this screen as on the screen
	 * that set it up.
	 *
	 * @since  0.4.9
	 * @param  Row $item Registry row.
	 * @return string
	 */
	public function column_route( $item ) {
		if ( ClientsConstants::ROUTE_CONNECTOR === $item->route && '' !== $item->connector_slug ) {
			// The profile's own name, not `ucfirst()` on the slug — which
			// rendered the `chatgpt` profile as "Chatgpt". A connector has a
			// display name precisely so screens stop guessing at one.
			$profile = ConnectorProfileRegistry::instance()->get_profile( $item->connector_slug );
			$name    = null !== $profile ? $profile->get_name() : $item->connector_slug;

			return esc_html(
				sprintf(
					/* translators: %s: connector name, e.g. "ChatGPT". */
					__( '%s connector', 'acrossai-mcp-manager' ),
					$name
				)
			);
		}

		return esc_html( self::route_label( $item->route ) );
	}

	/**
	 * Which WordPress user the client acts as.
	 *
	 * @since  0.4.9
	 * @param  Row $item Registry row.
	 * @return string
	 */
	public function column_user( $item ) {
		if ( $item->user_id <= 0 ) {
			return '&mdash;';
		}

		$user = get_user_by( 'id', $item->user_id );

		return $user instanceof \WP_User ? esc_html( $user->user_login ) : '&mdash;';
	}

	/**
	 * Active, revoked, or registered-and-never-used.
	 *
	 * Three states, not a blank cell. Taken from easy-mcp-ai's self-service key
	 * list, which computes a status rather than leaving the reader to infer one
	 * from an empty column.
	 *
	 * @since  0.4.9
	 * @param  Row $item Registry row.
	 * @return string
	 */
	public function column_status( $item ) {
		switch ( $item->status() ) {
			case 'revoked':
				return '<span class="dashicons dashicons-dismiss" aria-hidden="true"></span> '
					. esc_html__( 'Revoked', 'acrossai-mcp-manager' );

			case 'never-used':
				return '<span class="dashicons dashicons-clock" aria-hidden="true"></span> '
					. esc_html__( 'Never used', 'acrossai-mcp-manager' );

			default:
				return '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> '
					. esc_html__( 'Active', 'acrossai-mcp-manager' );
		}
	}

	/**
	 * Which server the client connected to.
	 *
	 * @since  0.4.9
	 * @param  Row $item Registry row.
	 * @return string
	 */
	public function column_server( $item ) {
		$name = $this->server_name( $item->server_id );

		// A deleted server keeps its id on the row rather than losing the
		// client's history with it.
		return '' !== $name
			? esc_html( $name )
			: esc_html( sprintf( /* translators: %d: server row id. */ __( 'Server #%d (deleted)', 'acrossai-mcp-manager' ), $item->server_id ) );
	}

	/**
	 * First time we saw this client.
	 *
	 * @since  0.4.9
	 * @param  Row $item Registry row.
	 * @return string
	 */
	public function column_first_seen_at( $item ) {
		return self::format_datetime( $item->first_seen_at );
	}

	/**
	 * Last time this client called, or "Never".
	 *
	 * The word matters: a NULL here means registered and never used, which is
	 * a state an operator wants to see, not an absence to render as a dash.
	 *
	 * @since  0.4.9
	 * @param  Row $item Registry row.
	 * @return string
	 */
	public function column_last_seen_at( $item ) {
		if ( ! $item->has_been_used() ) {
			return '<em>' . esc_html__( 'Never', 'acrossai-mcp-manager' ) . '</em>';
		}

		return self::format_datetime( (string) $item->last_seen_at );
	}

	/**
	 * How many calls this client has made.
	 *
	 * @since  0.4.9
	 * @param  Row $item Registry row.
	 * @return string
	 */
	public function column_request_count( $item ) {
		return esc_html( number_format_i18n( $item->request_count ) );
	}

	/**
	 * Unknown columns render empty rather than warning.
	 *
	 * @since  0.4.9
	 * @param  Row    $item        Registry row.
	 * @param  string $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		unset( $item, $column_name );

		return '';
	}

	/**
	 * Row actions, offered only where they do something.
	 *
	 * Revoke is absent — not disabled — on a browser or direct-HTTP row,
	 * because there is no standing credential to withdraw and a control that
	 * reports success without changing anything is worse than no control.
	 *
	 * @since  0.4.9
	 * @param  Row $item Registry row.
	 * @return array<string, string>
	 */
	private function actions_for( Row $item ): array {
		$actions = array();

		if ( Revoker::can_revoke( $item ) ) {
			$actions['revoke'] = sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( $this->action_url( 'revoke', $item->id ) ),
				esc_html__( 'Revoke', 'acrossai-mcp-manager' )
			);
		}

		$actions['forget'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->action_url( 'forget', $item->id ) ),
			esc_html__( 'Forget', 'acrossai-mcp-manager' )
		);

		return $actions;
	}

	/**
	 * A nonced URL back to the current screen carrying one action.
	 *
	 * Built from the REQUEST_URI's own query rather than from `admin_url()`
	 * with a guessed page, so the same table works on the global screen and
	 * inside a server tab without knowing which it is on.
	 *
	 * @since  0.4.9
	 * @param  string $action Action name.
	 * @param  int    $id     Registry row id.
	 * @return string
	 */
	private function action_url( string $action, int $id ): string {
		$base = remove_query_arg( array( 'client_action', 'client', '_wpnonce', 'client_notice' ) );

		return wp_nonce_url(
			add_query_arg(
				array(
					'client_action' => $action,
					'client'        => $id,
				),
				$base
			),
			ClientsPage::nonce_action( $action, $id )
		);
	}

	/**
	 * Requested sort column, restricted to the sortable set.
	 *
	 * @since  0.4.9
	 * @return string
	 */
	private function orderby(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort parameter on an admin list screen.
		$requested = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : '';

		$allowed = array( 'client_name', 'first_seen_at', 'last_seen_at', 'request_count' );

		return in_array( $requested, $allowed, true ) ? $requested : 'last_seen_at';
	}

	/**
	 * Requested sort direction.
	 *
	 * @since  0.4.9
	 * @return string
	 */
	private function order(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort parameter on an admin list screen.
		$requested = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_GET['order'] ) ) ) : '';

		return 'ASC' === $requested ? 'ASC' : 'DESC';
	}

	/**
	 * A server's display name, memoised.
	 *
	 * @since  0.4.9
	 * @param  int $server_id Server row id.
	 * @return string Empty when the server no longer exists.
	 */
	private function server_name( int $server_id ): string {
		if ( isset( $this->server_names[ $server_id ] ) ) {
			return $this->server_names[ $server_id ];
		}

		$row  = $server_id > 0 ? MCPServerQuery::instance()->get_item( $server_id ) : null;
		$name = is_object( $row ) && isset( $row->server_name ) ? (string) $row->server_name : '';

		$this->server_names[ $server_id ] = $name;

		return $name;
	}

	/**
	 * A route rendered the way the Connect tab names it.
	 *
	 * @since  0.4.9
	 * @param  string $route A ClientsConstants ROUTE_* value.
	 * @return string
	 */
	public static function route_label( string $route ): string {
		$labels = array(
			ClientsConstants::ROUTE_CONNECTOR   => __( 'AI connector', 'acrossai-mcp-manager' ),
			ClientsConstants::ROUTE_CONFIG_FILE => __( 'Config file', 'acrossai-mcp-manager' ),
			ClientsConstants::ROUTE_NPM         => __( 'npm bridge', 'acrossai-mcp-manager' ),
			ClientsConstants::ROUTE_WP_CLI      => __( 'WP-CLI', 'acrossai-mcp-manager' ),
			ClientsConstants::ROUTE_FLEET       => __( 'Fleet', 'acrossai-mcp-manager' ),
			ClientsConstants::ROUTE_BROWSER     => __( 'Browser', 'acrossai-mcp-manager' ),
			ClientsConstants::ROUTE_DIRECT      => __( 'Direct HTTP', 'acrossai-mcp-manager' ),
		);

		return $labels[ $route ] ?? __( 'Unknown', 'acrossai-mcp-manager' );
	}

	/**
	 * A stored UTC datetime in the site's own format.
	 *
	 * @since  0.4.9
	 * @param  string $value `Y-m-d H:i:s` in UTC.
	 * @return string
	 */
	private static function format_datetime( string $value ): string {
		if ( '' === $value ) {
			return '&mdash;';
		}

		$timestamp = mysql2date( 'U', $value, false );

		if ( ! $timestamp ) {
			return esc_html( $value );
		}

		// `wp_date()` returns false on a bad timezone, in which case the stored
		// UTC value is still more use than an empty cell.
		$formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );

		return esc_html( is_string( $formatted ) ? $formatted : $value );
	}
}
