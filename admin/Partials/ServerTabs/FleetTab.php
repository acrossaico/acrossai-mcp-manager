<?php
/**
 * The Fleet server's Overview — who may manage this site, and whether they can.
 *
 * Replaces {@see OverviewTab} on Fleet servers only. The standard Overview
 * answers "what is this server and where does it live", which is the wrong
 * question here: a site owner looking at this row wants to know who is
 * reaching their site, what they have done, and how to stop them.
 *
 * ## "Fleet access" is the server's own enabled flag
 *
 * Not a separate setting. A disabled server's route stops answering, which is
 * exactly "these hosts cannot reach this site" — so reusing `is_enabled`
 * means the toggle here and the one on the servers list can never disagree,
 * and there is no second piece of state to migrate, reconcile or leave stale.
 *
 * ## Hosts stay listed while access is off
 *
 * A switch that appears to delete your connections is a switch nobody dares
 * touch, and re-pairing means going back to the managing site for a new code.
 * So the list stays, dimmed, with the reason stated above it.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin\Partials\ServerTabs
 * @since      0.4.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;
use AcrossAI_MCP_Manager\Includes\Fleet\Hosts;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

defined( 'ABSPATH' ) || exit;

final class FleetTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * Deliberately `overview`, not `fleet`. This tab stands in the Overview
	 * slot, and sharing the slug means the tab strip's default landing tab,
	 * every `&tab=overview` link already in the wild, and the "first tab"
	 * fallback all arrive here without a redirect or a special case.
	 *
	 * @since 0.4.6
	 */
	public function slug(): string {
		return 'overview';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.4.6
	 */
	public function label(): string {
		return __( 'Overview', 'acrossai-mcp-manager' );
	}

	/**
	 * Leftmost tab, matching the Overview slot it replaces.
	 *
	 * @since 0.4.6
	 */
	public function priority(): int {
		return 10;
	}

	/**
	 * Fleet servers only.
	 *
	 * @since 0.4.6
	 *
	 * @param array<string, mixed> $server Server row data.
	 */
	public function visible_for( array $server ): bool {
		return ServerTypes::FLEET === ( $server['server_type'] ?? '' );
	}

	/**
	 * Renders the panel.
	 *
	 * @since 0.4.6
	 *
	 * @param array<string, mixed> $server Server row data.
	 */
	protected function render_body( array $server ): void {
		$server_id  = isset( $server['id'] ) ? (int) $server['id'] : 0;
		$is_enabled = ! empty( $server['is_enabled'] );
		$hosts      = Hosts::all( $server_id );

		echo '<div class="mcp-tab-panel">';

		if ( ! $is_enabled && ! empty( $hosts ) ) {
			// Two strings rather than `_n()`: the singular reads badly with a
			// number in it ("The 1 host below"), and `_n()` requires the
			// placeholder in both forms.
			$message = 1 === count( $hosts )
				? __( 'Fleet access is off. The host below cannot reach this site. Its connection is kept and resumes when you switch it back on.', 'acrossai-mcp-manager' )
				: sprintf(
					/* translators: %d: number of connected hosts. */
					__( 'Fleet access is off. The %d hosts below cannot reach this site. Their connections are kept and resume when you switch it back on.', 'acrossai-mcp-manager' ),
					count( $hosts )
				);

			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html( $message )
			);
		}

		$this->render_state( $server, $is_enabled );
		$this->render_hosts( $server_id, $hosts, $is_enabled );

		echo '</div>';
	}

	/**
	 * The on/off state, the endpoint, and what this server is for.
	 *
	 * @since 0.4.6
	 *
	 * @param array<string, mixed> $server     Server row data.
	 * @param bool                 $is_enabled Whether the server is enabled.
	 */
	private function render_state( array $server, bool $is_enabled ): void {
		$endpoint = rest_url(
			trailingslashit( (string) ( $server['server_route_namespace'] ?? '' ) )
			. (string) ( $server['server_route'] ?? '' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s<p class="description">%3$s</p></td></tr>',
			esc_html__( 'Fleet access', 'acrossai-mcp-manager' ),
			wp_kses_post( $this->render_toggle( $server, $is_enabled ) ),
			esc_html__( 'Lets WordPress sites you approve run abilities on this site.', 'acrossai-mcp-manager' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><code>%2$s</code><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Endpoint', 'acrossai-mcp-manager' ),
			esc_html( $endpoint ),
			esc_html__( 'Paired sites call this address. Your own AI clients keep using this site’s other MCP servers.', 'acrossai-mcp-manager' )
		);

		echo '</tbody></table>';
	}

	/**
	 * Status badge plus the enable/disable link.
	 *
	 * Reuses `action=toggle_status`, the same handler the servers list and the
	 * standard Overview use, so there is one code path that turns a server on
	 * and off however the operator got there.
	 *
	 * @since 0.4.6
	 *
	 * @param array<string, mixed> $server     Server row data.
	 * @param bool                 $is_enabled Whether the server is enabled.
	 * @return string Pre-escaped HTML.
	 */
	private function render_toggle( array $server, bool $is_enabled ): string {
		$badge = sprintf(
			'<span class="acrossai-status-badge %1$s">%2$s</span>',
			$is_enabled ? 'acrossai-status-active' : 'acrossai-status-inactive',
			esc_html( $is_enabled ? __( 'On', 'acrossai-mcp-manager' ) : __( 'Off', 'acrossai-mcp-manager' ) )
		);

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => AdminPageSlugs::PARENT,
					'action'      => 'toggle_status',
					'server'      => (int) $server['id'],
					'redirect_to' => 'edit',
				),
				admin_url( 'admin.php' )
			),
			'acrossai_mcp_toggle_' . (int) $server['id']
		);

		return $badge . sprintf(
			' &nbsp;<a href="%1$s" class="%2$s">%3$s</a>',
			esc_url( $url ),
			esc_attr( $is_enabled ? 'button button-small' : 'button button-primary button-small' ),
			esc_html( $is_enabled ? __( 'Turn off', 'acrossai-mcp-manager' ) : __( 'Turn on', 'acrossai-mcp-manager' ) )
		);
	}

	/**
	 * The connected-hosts list, or the empty state.
	 *
	 * @since 0.4.6
	 *
	 * @param int                              $server_id  Fleet server id.
	 * @param array<int, array<string, mixed>> $hosts      Connected hosts.
	 * @param bool                             $is_enabled Whether the server is enabled.
	 */
	private function render_hosts( int $server_id, array $hosts, bool $is_enabled ): void {
		printf( '<h2>%s</h2>', esc_html__( 'Connected hosts', 'acrossai-mcp-manager' ) );

		if ( empty( $hosts ) ) {
			printf(
				'<p>%s</p><p class="description">%s</p>',
				esc_html__( 'No hosts connected.', 'acrossai-mcp-manager' ),
				esc_html__( 'A host is another WordPress site allowed to run abilities here. You will need a pairing code from it.', 'acrossai-mcp-manager' )
			);
			$this->render_add_form( $server_id );
			return;
		}

		printf(
			'<p class="description">%s</p>',
			wp_kses_post(
				sprintf(
					/* translators: 1: Abilities tab link, 2: Logs tab link. */
					__( 'What hosts may do here is set on the %1$s tab and applies to all of them. Everything they have done is on the %2$s tab.', 'acrossai-mcp-manager' ),
					'<a href="' . esc_url( $this->tab_url( $server_id, 'abilities' ) ) . '">' . esc_html__( 'Abilities', 'acrossai-mcp-manager' ) . '</a>',
					'<a href="' . esc_url( $this->tab_url( $server_id, 'mcp-log' ) ) . '">' . esc_html__( 'Logs', 'acrossai-mcp-manager' ) . '</a>'
				)
			)
		);

		printf(
			'<table class="wp-list-table widefat striped"%s><thead><tr><th>%s</th><th>%s</th><th>%s</th><th></th></tr></thead><tbody>',
			$is_enabled ? '' : ' style="opacity:0.6"',
			esc_html__( 'Host', 'acrossai-mcp-manager' ),
			esc_html__( 'Connected', 'acrossai-mcp-manager' ),
			esc_html__( 'Last call', 'acrossai-mcp-manager' ),
		);

		foreach ( $hosts as $host ) {
			$this->render_host_row( $server_id, $host );
		}

		echo '</tbody></table>';
		$this->render_add_form( $server_id );
	}

	/**
	 * One host row.
	 *
	 * @since 0.4.6
	 *
	 * @param int                  $server_id Fleet server id.
	 * @param array<string, mixed> $host      Host record.
	 */
	private function render_host_row( int $server_id, array $host ): void {
		$added = (int) $host['added_at'];
		$last  = (int) $host['last_call_at'];

		$remove_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'fleet_remove_host',
					'server' => $server_id,
					'host'   => rawurlencode( (string) $host['key'] ),
				),
				admin_url( 'admin.php' )
			),
			'acrossai_mcp_fleet_remove_' . $server_id . '_' . $host['key']
		);

		printf(
			'<tr><td><strong>%1$s</strong></td><td>%2$s</td><td>%3$s</td><td style="text-align:right"><a href="%4$s" class="button button-small">%5$s</a></td></tr>',
			esc_html( (string) $host['host'] ),
			esc_html( $added > 0 ? (string) wp_date( (string) get_option( 'date_format' ), $added ) : __( 'unknown', 'acrossai-mcp-manager' ) ),
			esc_html(
				$last > 0
					/* translators: %s: human-readable time difference, e.g. "2 minutes". */
					? sprintf( __( '%s ago', 'acrossai-mcp-manager' ), human_time_diff( $last ) )
					: __( 'never', 'acrossai-mcp-manager' )
			),
			esc_url( $remove_url ),
			esc_html__( 'Remove', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * The add-a-host form.
	 *
	 * Inline rather than a modal: this is one field and a button, and a modal
	 * would add a JavaScript dependency to a screen that otherwise needs none.
	 *
	 * The consequence of submitting is stated above the button, not after it.
	 * This is the moment the site mints a credential and sends it to an
	 * address the operator pasted, and that is worth one plain sentence before
	 * they commit rather than a surprise afterwards.
	 *
	 * @since 0.4.6
	 *
	 * @param int $server_id Fleet server id.
	 */
	private function render_add_form( int $server_id ): void {
		$action = add_query_arg(
			array(
				'page'   => AdminPageSlugs::PARENT,
				'action' => 'fleet_add_host',
				'server' => $server_id,
			),
			admin_url( 'admin.php' )
		);

		echo '<form method="post" action="' . esc_url( $action ) . '" class="acrossai-fleet-add">';
		wp_nonce_field( 'acrossai_mcp_fleet_add_' . $server_id );

		printf(
			'<p><label for="acrossai-fleet-code"><strong>%s</strong></label></p>',
			esc_html__( 'Pairing code', 'acrossai-mcp-manager' )
		);

		printf(
			'<p><input type="text" id="acrossai-fleet-code" name="fleet_code" class="large-text code" autocomplete="off" spellcheck="false" placeholder="%s" /></p>',
			esc_attr__( 'fleet_…', 'acrossai-mcp-manager' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Get this from the site that will manage this one, under AcrossAI → Fleet.', 'acrossai-mcp-manager' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Connecting creates an Application Password dedicated to that site and sends it there. Removing the host withdraws it again.', 'acrossai-mcp-manager' )
		);

		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Connect', 'acrossai-mcp-manager' )
		);

		echo '</form>';
	}

	/**
	 * URL of another tab on this same server.
	 *
	 * @since 0.4.6
	 *
	 * @param int    $server_id Fleet server id.
	 * @param string $tab       Target tab slug.
	 */
	private function tab_url( int $server_id, string $tab ): string {
		return add_query_arg(
			array(
				'page'   => AdminPageSlugs::PARENT,
				'action' => 'edit',
				'server' => $server_id,
				'tab'    => $tab,
			),
			admin_url( 'admin.php' )
		);
	}
}
