<?php
/**
 * AI Connectors tab — built-in per-server tab (priority 35).
 *
 * Renders one card per registered AbstractConnectorProfile with a
 * Generate/Regenerate button. Contribution of profiles happens ONLY via
 * the `acrossai_mcp_manager_connector_profiles` filter — the base plugin
 * ships zero profiles.
 *
 * **This is a BUILT-IN tab wired directly in Registry::all_tabs().**
 * Third-party tabs use the `acrossai_mcp_manager_server_tabs` filter — see
 * docs/extending-per-server-tabs.md. Do not convert this tab to
 * filter-registered without a major version bump
 * (DEC-OAUTH-BUILTIN-TAB-NOT-FILTER).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.1.0
 */

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs;

use AcrossAI_MCP_Manager\Includes\Connectors\AbstractConnectorProfile;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorProfileRegistry;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSettings;
use AcrossAI_MCP_Manager\Includes\Connectors\ConnectorSlugDisplay;
use AcrossAI_MCP_Manager\Includes\Database\ConnectorApprovedUsers\Query as ConnectorApprovedUsersQuery;
use AcrossAI_MCP_Manager\Includes\OAuth\Repositories\AccessTokenRepository;

// Feature 003: cross-plugin base class — AbstractServerTab stays owned by
// mcp-manager's per-server tab framework. Imported by FQN because mcp-manager
// is a hard `Requires Plugins:` dependency of this plugin.
use AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\AbstractServerTab;

defined( 'ABSPATH' ) || exit;

/**
 * The AI Connectors tab.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs
 * @since      0.1.0
 */
final class AIConnectorsTab extends AbstractServerTab {

	/**
	 * Returns the tab slug.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function slug(): string {
		return 'ai-connectors';
	}

	/**
	 * Returns the tab label. Matches mcp-manager's
	 * the F040 promo placeholder's label so the tab keeps the same name
	 * before and after this plugin is activated.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public function label(): string {
		return __( 'Connectors/Integrations/Plugins', 'acrossai-mcp-manager' );
	}

	/**
	 * Priority slot 35 — between ClientsTab (30) and WpCliTab (40).
	 *
	 * @since 0.1.0
	 * @return int
	 */
	public function priority(): int {
		// 10 on Connect\MethodRegistry's level-2 scale (ai-connectors 10,
		// clients 20, npm 30, n8n 40, wp-cli 50). The companion returned 35
		// because it contributed through the TOP-LEVEL
		// `acrossai_mcp_manager_server_tabs` filter, which is a different
		// scale entirely. Seeding directly into MethodRegistry means the
		// level-2 numbering applies, and 10 is the slot the promo tab held.
		return 10;
	}

	/**
	 * Cross-connector server-wide panel identifiers. Per-connector panels
	 * are computed dynamically from ConnectorProfileRegistry so that new
	 * profiles show up as tabs automatically. Values double as the
	 * `?panel=` query arg.
	 */
	private const CROSS_PANELS = array( 'connections', 'approved-users', 'settings' );

	/**
	 * Render the AI Connectors tab body. Empty-state notice when no
	 * connector profiles are registered; otherwise the level-2 tab layout:
	 *
	 *   [ Claude | ChatGPT | Gemini | Grok | Cursor | ... | Connections | Approved Users | Settings ]
	 *
	 * Connector order comes from `ConnectorProfileRegistry::get_profiles()`
	 * (priority ASC, slug ASC tiebreak); the three cross panels always close
	 * the strip in the fixed order above.
	 *
	 * The three cross panels are server-wide (span all connectors). Each
	 * per-connector tab renders that profile's own credentials
	 * / setup card via `AbstractConnectorProfile::render_tab_section()`.
	 *
	 * @since 0.1.0
	 * @param array<string, mixed> $server Server row data.
	 * @return void
	 */
	protected function render_body( array $server ): void {
		$profiles = ConnectorProfileRegistry::instance()->get_profiles();

		if ( empty( $profiles ) ) {
			$this->render_empty_state();
			return;
		}

		$server_id = (int) ( $server['id'] ?? 0 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$requested_panel = isset( $_GET['panel'] ) ? sanitize_key( wp_unslash( (string) $_GET['panel'] ) ) : '';
		// phpcs:enable

		// No panel requested, or an unknown / legacy name: land on the FIRST
		// connector in display order (Claude today) rather than Connections.
		// Setting a connector up is what operators come here to do; the
		// server-wide panels are follow-up management. `$profiles` is
		// guaranteed non-empty (the empty case returned above) and already
		// sorted by ConnectorProfileRegistry, so index 0 is the leftmost tab.
		//
		// Valid panel = one of the cross panels OR a registered connector slug.
		$valid_panels = array_merge( self::CROSS_PANELS, $this->connector_panel_slugs( $profiles ) );
		if ( ! in_array( $requested_panel, $valid_panels, true ) ) {
			$requested_panel = $profiles[0]->get_slug();
		}

		echo '<div class="acrossai-mcp-ai-connectors" data-server-id="' . esc_attr( (string) $server_id ) . '" data-wp-rest-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">';

		$this->render_top_tabs( $server, $requested_panel, $profiles );

		echo '<div class="acrossai-mcp-ai-connectors__panel acrossai-mcp-ai-connectors__panel--' . esc_attr( $requested_panel ) . '">';

		$this->render_panel_dispatch( $server, $requested_panel, $profiles );

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Extract the list of connector-slug panels from the registry — one
	 * per registered profile.
	 *
	 * @param array<int, AbstractConnectorProfile> $profiles
	 * @return array<int, string>
	 */
	private function connector_panel_slugs( array $profiles ): array {
		$slugs = array();
		foreach ( $profiles as $profile ) {
			$slugs[] = $profile->get_slug();
		}
		return $slugs;
	}

	/**
	 * Render the level-2 tab bar — the three cross-connector server-wide
	 * panels followed by one tab per registered connector profile.
	 *
	 * @param array<string, mixed>                 $server         Server row.
	 * @param string                               $selected_panel Active panel id.
	 * @param array<int, AbstractConnectorProfile> $profiles       Registered profiles from the registry.
	 * @return void
	 */
	private function render_top_tabs( array $server, string $selected_panel, array $profiles ): void {
		echo '<nav class="nav-tab-wrapper acrossai-mcp-ai-connectors__tabs">';

		// Per-connector tabs first — one per registered profile
		// (Claude, ChatGPT, Grok, Gemini, …).
		foreach ( $profiles as $profile ) {
			$slug   = $profile->get_slug();
			$active = $slug === $selected_panel ? ' nav-tab-active' : '';
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $this->panel_url( $server, $slug ) ),
				esc_attr( $active ),
				esc_html( $profile->get_name() )
			);
		}

		// Cross-connector server-wide panels last.
		$cross_labels = array(
			'connections'    => __( 'Connections', 'acrossai-mcp-manager' ),
			'approved-users' => __( 'Approved Users', 'acrossai-mcp-manager' ),
			'settings'       => __( 'Settings', 'acrossai-mcp-manager' ),
		);
		foreach ( $cross_labels as $panel_id => $label ) {
			$active = $panel_id === $selected_panel ? ' nav-tab-active' : '';
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $this->panel_url( $server, $panel_id ) ),
				esc_attr( $active ),
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Dispatch to the correct renderer — either a cross-connector panel
	 * or a per-connector tab that delegates to the profile itself.
	 *
	 * @param array<string, mixed>                 $server   Server row.
	 * @param string                               $panel    One of self::CROSS_PANELS or a connector slug.
	 * @param array<int, AbstractConnectorProfile> $profiles Registered profiles from the registry.
	 * @return void
	 */
	private function render_panel_dispatch( array $server, string $panel, array $profiles ): void {
		switch ( $panel ) {
			case 'connections':
				$this->render_connections_all( $server );
				return;
			case 'approved-users':
				$this->render_approved_users_all( $server );
				return;
			case 'settings':
				$this->render_server_settings( $server );
				return;
		}

		// Connector-slug panel — delegate to the profile's own render.
		foreach ( $profiles as $profile ) {
			if ( $profile->get_slug() === $panel ) {
				$this->maybe_render_disabled_notice( $server, $profile );
				$profile->render_tab_section( $server );
				return;
			}
		}
	}

	/**
	 * Warn, on a connector's own tab, when that connector is disabled on this
	 * server (FR-001).
	 *
	 * The tab itself stays visible and keeps its position — that is settled by
	 * DEC-CONNECTOR-TAB-VISIBILITY (docs/memory/DECISIONS.md): "rethink WHAT the
	 * per-entity page renders, not whether the tab should exist. Keep the tab."
	 * The setup instructions below this notice stay readable for the same
	 * reason: an operator reads a connector's instructions, decides to use it,
	 * and only then enables it (FR-003).
	 *
	 * History — this is a restoration, not a new idea. Feature 008 shipped the
	 * same notice for the "Any other OAuth-compliant MCP client" bucket in
	 * `render_others_panel()`. Feature 010 (`624108f`) dropped the per-connector
	 * tabs and deleted that method wholesale, taking the only disabled-state
	 * notice in the plugin with it; the same-day restore (`4dd0bbc`) rebuilt the
	 * tab strip but not the check, leaving the `ConnectorSettings` import above
	 * unused until now. Named connectors never had this notice at all. See
	 * memory B21.
	 *
	 * Rendered by this class rather than by each profile so there is one
	 * implementation for all five, and so a profile that overrides
	 * `render_tab_section()` cannot accidentally omit it.
	 *
	 * @param array<string, mixed>     $server  Server row.
	 * @param AbstractConnectorProfile $profile The connector whose panel is open.
	 * @return void
	 * @since 0.9.14
	 */
	private function maybe_render_disabled_notice( array $server, AbstractConnectorProfile $profile ): void {
		$server_id = (int) ( $server['id'] ?? 0 );
		if ( $server_id <= 0 ) {
			return;
		}

		if ( ConnectorSettings::is_slug_enabled_on_server( $server_id, $profile->get_slug() ) ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: connector display name, e.g. "Grok" */
				__( '<strong>%s</strong> is disabled on this server. New connections are refused and any tokens it held were revoked when it was disabled. The setup steps below are for reference until you switch it back on.', 'acrossai-mcp-manager' ),
				esc_html( $profile->get_name() )
			)
		);
		printf(
			' <a href="%1$s">%2$s</a>',
			esc_url( $this->panel_url( $server, 'settings' ) ),
			esc_html__( 'Enable it in Settings', 'acrossai-mcp-manager' )
		);
		echo '</p></div>';
	}

	/**
	 * Build the query-arg URL for a top-level tab.
	 *
	 * @param array<string, mixed> $server Server row.
	 * @param string               $panel  Tab identifier (connector slug or cross-panel id).
	 * @return string
	 */
	private function panel_url( array $server, string $panel ): string {
		// Feature 020 chained `&panel=` onto whichever address shape the
		// installed host used, asking the cross-plugin `HostCapabilities` shim
		// which one that was. F095 removed the shim with the rest of the
		// companion-only wiring: this plugin IS the host, so the Connect-tab
		// shape is the only shape and ConnectTab owns it. `method_url()`
		// returns a RAW url by contract precisely so this chaining works; it is
		// esc_url()'d at the output sites above.
		return add_query_arg(
			'panel',
			sanitize_key( $panel ),
			ConnectTab::method_url( $server, 'ai-connectors' )
		);
	}

	/**
	 * Connections panel — one row per authorization GRANT (token_family_id)
	 * on this server, sorted by most-recent activity DESC. A user who
	 * authorized twice from two claude.ai accounts shows up as two "Claude"
	 * rows (they share one DCR client_id but never a token family), each
	 * independently revocable. Legacy pre-family tokens fall back to one
	 * (user × connector) row with the old client-scoped actions.
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return void
	 */
	private function render_connections_all( array $server ): void {
		$server_id = (int) ( $server['id'] ?? 0 );
		$groups    = AccessTokenRepository::group_by_grant_for_server( $server_id );

		// Feature 013: n8n admin-issued bearer tokens live in the same
		// oauth_tokens table and are grouped by the shared helper, but
		// they belong on the dedicated n8n tab's Connections sub-panel
		// (?tab=n8n&panel=connections) — not here. Strip them out so this
		// panel only shows the OAuth-based AI connectors (Claude / ChatGPT /
		// Cursor / Gemini / Grok / DCR-inferred / Others).
		$groups = array_values(
			array_filter(
				$groups,
				static function ( $g ) {
					return ! isset( $g['connector_slug'] ) || 'n8n' !== $g['connector_slug'];
				}
			)
		);

		echo '<div class="acrossai-mcp-connector-panel">';
		printf(
			'<h3 class="acrossai-mcp-connector-panel__title">%s</h3>',
			esc_html(
				sprintf(
					/* translators: %d: total number of active authorization grants */
					_n( '%d active connection', '%d active connections', count( $groups ), 'acrossai-mcp-manager' ),
					count( $groups )
				)
			)
		);

		if ( empty( $groups ) ) {
			printf(
				'<p class="acrossai-mcp-connector-panel__empty description">%s</p>',
				esc_html__( 'No AI clients have connected to this server yet.', 'acrossai-mcp-manager' )
			);
			echo '</div>';
			return;
		}

		echo '<div class="acrossai-mcp-connector-panel__actions-legend notice notice-info inline"><p><strong>' . esc_html__( 'What each action button does', 'acrossai-mcp-manager' ) . ':</strong></p><ul style="margin-left:1.5em;list-style:disc;">';
		printf(
			'<li><strong>%s</strong> — %s</li>',
			esc_html__( 'Revoke', 'acrossai-mcp-manager' ),
			esc_html__( 'Marks every token issued by THIS authorization grant on THIS server as revoked. Other connections are unaffected — including other connections by the same user from the same AI host (e.g. a second claude.ai account). The underlying OAuth client rows stay intact; the user can re-authorize from their AI host without re-registering.', 'acrossai-mcp-manager' )
		);
		printf(
			'<li><strong>%s</strong> — %s</li>',
			esc_html__( 'Delete client (legacy rows only)', 'acrossai-mcp-manager' ),
			esc_html__( 'Shown only on rows marked (legacy) — tokens issued before per-grant tracking. Revokes this user\'s tokens AND deletes the underlying OAuth client row(s) if no other user still has active tokens on them. Because a client can be shared by several grants and users, deleting it may disconnect others; per-grant rows therefore only offer Revoke.', 'acrossai-mcp-manager' )
		);
		echo '</ul></div>';

		echo '<table class="widefat striped acrossai-mcp-connector-panel__table">';
		echo '<thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'User', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Connector', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Active tokens', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Connected', 'acrossai-mcp-manager' ) );
		printf( '<th>%s</th>', esc_html__( 'Actions', 'acrossai-mcp-manager' ) );
		echo '</tr></thead><tbody>';

		$now = time(); // GMT — matches stored created_at.
		foreach ( $groups as $group ) {
			$uid  = (int) $group['user_id'];
			$user = get_userdata( $uid );
			if ( $user instanceof \WP_User ) {
				$user_label = sprintf( '%s (#%d)', $user->user_login, $uid );
			} else {
				// Deleted / unresolvable user — still show the id so operators
				// can trace it back rather than silently hiding the row.
				$user_label = sprintf(
					/* translators: %d: WordPress user id whose row has been deleted */
					__( '(deleted user #%d)', 'acrossai-mcp-manager' ),
					$uid
				);
			}

			$connector_label = ConnectorSlugDisplay::display_name( (string) $group['connector_slug'] );

			$family    = (string) ( $group['token_family_id'] ?? '' );
			$is_legacy = ! empty( $group['is_legacy'] );

			// "Connected" cell — absolute first-authorization date/time in the
			// site timezone (stable across refresh rotation, so it doubles as
			// the differentiator between two grants from the same AI host),
			// with last-activity time-ago alongside.
			$first_iso  = (string) ( $group['first_created_at'] ?? '' );
			$latest_iso = (string) $group['latest_created_at'];
			$connected  = '' !== $first_iso
				? get_date_from_gmt( $first_iso, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
				: '—';
			$latest_ts  = '' !== $latest_iso ? (int) strtotime( $latest_iso . ' UTC' ) : 0;
			if ( $latest_ts > 0 ) {
				$last_activity = sprintf(
					/* translators: %s: human-readable time difference like "5 minutes" */
					__( 'last activity %s ago', 'acrossai-mcp-manager' ),
					human_time_diff( $latest_ts, $now )
				);
			} else {
				$last_activity = '';
			}

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $user_label ) );
			if ( '' !== $family ) {
				printf(
					'<td>%s <span class="description" title="%s">%s</span></td>',
					esc_html( $connector_label ),
					esc_attr( $family ),
					esc_html(
						sprintf(
							/* translators: %s: last 8 characters of the authorization grant id */
							__( 'grant …%s', 'acrossai-mcp-manager' ),
							substr( $family, -8 )
						)
					)
				);
			} else {
				printf(
					'<td>%s <span class="description">%s</span></td>',
					esc_html( $connector_label ),
					esc_html__( '(legacy)', 'acrossai-mcp-manager' )
				);
			}
			printf(
				'<td>%d <span class="description">(%s)</span></td>',
				(int) $group['token_count'],
				esc_html(
					sprintf(
						/* translators: 1: access token count, 2: refresh token count */
						__( '%1$d access · %2$d refresh', 'acrossai-mcp-manager' ),
						(int) $group['access_count'],
						(int) $group['refresh_count']
					)
				)
			);
			printf(
				'<td><span title="%s">%s</span>%s</td>',
				esc_attr( $first_iso ),
				esc_html( $connected ),
				'' !== $last_activity
					? sprintf( ' <span class="description" title="%s">(%s)</span>', esc_attr( $latest_iso ), esc_html( $last_activity ) )
					: ''
			);

			if ( ! $is_legacy ) {
				printf(
					'<td>' .
						'<button type="button" class="button-link acrossai-mcp-connector-panel__revoke-grant-btn" ' .
							'data-acrossai-family-id="%1$s" ' .
							'data-acrossai-server-id="%2$d">%3$s</button>' .
					'</td>',
					esc_attr( $family ),
					(int) $server_id,
					esc_html__( 'Revoke', 'acrossai-mcp-manager' )
				);
			} else {
				$client_ids_json = wp_json_encode( array_values( $group['client_ids'] ) );
				printf(
					'<td>' .
						'<button type="button" class="button-link acrossai-mcp-connector-panel__revoke-user-btn" ' .
							'data-acrossai-user-id="%1$d" ' .
							'data-acrossai-connector-slug="%2$s" ' .
							'data-acrossai-server-id="%3$d" ' .
							'data-acrossai-client-ids="%4$s">%5$s</button>' .
						' | <button type="button" class="button-link-delete acrossai-mcp-connector-panel__delete-user-btn" ' .
							'data-acrossai-user-id="%1$d" ' .
							'data-acrossai-connector-slug="%2$s" ' .
							'data-acrossai-server-id="%3$d" ' .
							'data-acrossai-client-ids="%4$s">%6$s</button>' .
					'</td>',
					(int) $uid,
					esc_attr( (string) $group['connector_slug'] ),
					(int) $server_id,
					esc_attr( (string) $client_ids_json ),
					esc_html__( 'Revoke', 'acrossai-mcp-manager' ),
					esc_html__( 'Delete client', 'acrossai-mcp-manager' )
				);
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Consolidated server-wide Approved Users panel. Always visible in the
	 * top tab bar; when admin-approval is off, both sections just show
	 * their empty state and a note that gating isn't enforced.
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return void
	 */
	private function render_approved_users_all( array $server ): void {
		$server_id = (int) ( $server['id'] ?? 0 );
		$settings  = ConnectorSettings::get_server_settings( $server_id );
		$pending   = ConnectorSettings::server_pending_user_ids( $server_id );
		$approved  = ConnectorApprovedUsersQuery::instance()->find_all_by_server( $server_id );

		echo '<div class="acrossai-mcp-connector-panel">';
		printf( '<h3 class="acrossai-mcp-connector-panel__title">%s</h3>', esc_html__( 'Approved Users', 'acrossai-mcp-manager' ) );

		if ( ! $settings['require_admin_approval'] ) {
			echo '<div class="notice notice-info inline"><p>';
			esc_html_e( 'Admin approval is not currently required for this server. Any signed-in WordPress user who passes the Access Control gate can complete the OAuth consent flow. Turn on "Require admin approval for new connections" in the Settings tab if you want to gate connections here.', 'acrossai-mcp-manager' );
			echo '</p></div>';
		}

		echo '<div class="acrossai-mcp-connector-panel__actions-legend notice notice-info inline"><p><strong>' . esc_html__( 'What each action button does', 'acrossai-mcp-manager' ) . ':</strong></p><ul style="margin-left:1.5em;list-style:disc;">';
		printf(
			'<li><strong>%s</strong> — %s</li>',
			esc_html__( 'Approve', 'acrossai-mcp-manager' ),
			esc_html__( 'Grants this user permission to complete the OAuth consent flow for any connector on this server.', 'acrossai-mcp-manager' )
		);
		printf(
			'<li><strong>%s</strong> — %s</li>',
			esc_html__( 'Deny', 'acrossai-mcp-manager' ),
			esc_html__( 'Removes this user from the pending list without approving. The user must re-attempt the connect flow from their AI host if they want to try again.', 'acrossai-mcp-manager' )
		);
		printf(
			'<li><strong>%s</strong> — %s</li>',
			esc_html__( 'Revoke approval', 'acrossai-mcp-manager' ),
			esc_html__( 'Removes this user from the approved list AND revokes every active OAuth token they hold on this server (any connector). The user is disconnected immediately and their next connect attempt re-enters the pending flow.', 'acrossai-mcp-manager' )
		);
		echo '</ul></div>';

		printf( '<h4 class="acrossai-mcp-connector-panel__section-title">%s</h4>', esc_html__( 'Pending approvals', 'acrossai-mcp-manager' ) );

		if ( empty( $pending ) ) {
			printf(
				'<p class="acrossai-mcp-connector-panel__empty description">%s</p>',
				esc_html__( 'No pending approval requests.', 'acrossai-mcp-manager' )
			);
		} else {
			echo '<table class="widefat striped acrossai-mcp-connector-panel__table">';
			echo '<thead><tr>';
			printf( '<th>%s</th>', esc_html__( 'User', 'acrossai-mcp-manager' ) );
			printf( '<th>%s</th>', esc_html__( 'User ID', 'acrossai-mcp-manager' ) );
			printf( '<th>%s</th>', esc_html__( 'Login', 'acrossai-mcp-manager' ) );
			printf( '<th>%s</th>', esc_html__( 'Actions', 'acrossai-mcp-manager' ) );
			echo '</tr></thead><tbody>';

			foreach ( $pending as $pending_user_id ) {
				$user = get_user_by( 'id', (int) $pending_user_id );
				if ( ! $user ) {
					continue;
				}
				echo '<tr>';
				printf( '<td>%s</td>', esc_html( $user->display_name ) );
				printf( '<td><code>#%d</code></td>', (int) $pending_user_id );
				printf( '<td>%s</td>', esc_html( $user->user_login ) );
				printf(
					'<td>'
					. '<button type="button" class="button-link acrossai-mcp-connector-panel__server-approve-btn" data-acrossai-user-id="%1$d">%2$s</button>'
					. ' | <button type="button" class="button-link-delete acrossai-mcp-connector-panel__server-deny-btn" data-acrossai-user-id="%1$d">%3$s</button>'
					. '</td>',
					(int) $pending_user_id,
					esc_html__( 'Approve', 'acrossai-mcp-manager' ),
					esc_html__( 'Deny', 'acrossai-mcp-manager' )
				);
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<br>';

		printf( '<h4 class="acrossai-mcp-connector-panel__section-title">%s</h4>', esc_html__( 'Approved users', 'acrossai-mcp-manager' ) );

		if ( empty( $approved ) ) {
			printf(
				'<p class="acrossai-mcp-connector-panel__empty description">%s</p>',
				esc_html__( 'No users have been approved yet.', 'acrossai-mcp-manager' )
			);
		} else {
			// Collapse the row list to one row per user_id — a legacy install
			// may have per-connector rows for the same user; server-wide UX
			// only wants to show them once.
			$seen    = array();
			$grouped = array();
			foreach ( $approved as $row ) {
				$uid = (int) $row->user_id;
				if ( isset( $seen[ $uid ] ) ) {
					continue;
				}
				$seen[ $uid ] = true;
				$grouped[]    = $row;
			}

			echo '<table class="widefat striped acrossai-mcp-connector-panel__table">';
			echo '<thead><tr>';
			printf( '<th>%s</th>', esc_html__( 'User', 'acrossai-mcp-manager' ) );
			printf( '<th>%s</th>', esc_html__( 'User ID', 'acrossai-mcp-manager' ) );
			printf( '<th>%s</th>', esc_html__( 'Approved by', 'acrossai-mcp-manager' ) );
			printf( '<th>%s</th>', esc_html__( 'Approved at', 'acrossai-mcp-manager' ) );
			printf( '<th>%s</th>', esc_html__( 'Actions', 'acrossai-mcp-manager' ) );
			echo '</tr></thead><tbody>';

			foreach ( $grouped as $row ) {
				$user        = get_user_by( 'id', (int) $row->user_id );
				$approved_by = (int) $row->approved_by > 0 ? get_user_by( 'id', (int) $row->approved_by ) : null;

				echo '<tr>';
				if ( $user ) {
					printf( '<td>%s</td>', esc_html( $user->display_name ) );
				} else {
					printf( '<td><em>%s</em></td>', esc_html__( 'deleted', 'acrossai-mcp-manager' ) );
				}
				printf( '<td><code>#%d</code></td>', (int) $row->user_id );
				if ( $approved_by ) {
					printf(
						'<td>%s <code>#%d</code></td>',
						esc_html( $approved_by->display_name ),
						(int) $row->approved_by
					);
				} else {
					printf( '<td>%s</td>', esc_html__( '—', 'acrossai-mcp-manager' ) );
				}
				printf( '<td>%s</td>', esc_html( (string) $row->approved_at ) );
				printf(
					'<td><button type="button" class="button-link-delete acrossai-mcp-connector-panel__server-revoke-approval-btn" data-acrossai-user-id="%1$d">%2$s</button></td>',
					(int) $row->user_id,
					esc_html__( 'Revoke approval', 'acrossai-mcp-manager' )
				);
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/**
	 * Server-wide Settings panel — enabled-connectors checkboxes, the
	 * admin-approval toggle, and the "Revoke all connections on this server"
	 * nuclear button.
	 *
	 * @param array<string, mixed> $server Server row.
	 * @return void
	 */
	private function render_server_settings( array $server ): void {
		$server_id = (int) ( $server['id'] ?? 0 );
		$settings  = ConnectorSettings::get_server_settings( $server_id );
		$profiles  = ConnectorProfileRegistry::instance()->get_profiles();

		echo '<div class="acrossai-mcp-connector-panel">';
		printf( '<h3 class="acrossai-mcp-connector-panel__title">%s</h3>', esc_html__( 'Settings', 'acrossai-mcp-manager' ) );

		echo '<form class="acrossai-mcp-connector-panel__server-settings-form">';

		printf(
			'<h4 class="acrossai-mcp-connector-panel__section-title">%s</h4>',
			esc_html__( 'Connectors enabled on this server', 'acrossai-mcp-manager' )
		);

		foreach ( $profiles as $profile ) {
			$slug    = $profile->get_slug();
			$checked = in_array( $slug, $settings['enabled_slugs'], true );
			echo '<p><label>';
			printf(
				'<input type="checkbox" name="enabled_slugs[]" value="%1$s" %2$s> <strong>%3$s</strong>',
				esc_attr( $slug ),
				checked( $checked, true, false ),
				esc_html( $profile->get_name() )
			);
			echo '</label></p>';
		}

		echo '<p><label>';
		printf(
			'<input type="checkbox" name="allow_other" value="1" %s> <strong>%s</strong>',
			checked( $settings['allow_other'], true, false ),
			esc_html__( 'Any other OAuth-compliant MCP client', 'acrossai-mcp-manager' )
		);
		echo '</label></p>';

		// FR-013 — the Others bucket's disabled-state notice, restored inline
		// here rather than on a panel of its own. Feature 008 rendered it in
		// `render_others_panel()`, which Feature 010 deleted along with the
		// per-connector tabs (memory B21); the panel never came back and
		// CROSS_PANELS has no `others` member, so the checkbox itself is now
		// the only surface this warning can attach to. Wording follows the
		// original: what is refused, and what is still recorded meanwhile.
		if ( empty( $settings['allow_other'] ) ) {
			echo '<div class="notice notice-warning inline"><p>';
			esc_html_e( 'This category is currently disabled. Unrecognized clients can still register, but /authorize returns access_denied until you opt in here.', 'acrossai-mcp-manager' );
			echo '</p></div>';
		}

		echo '<p class="description">' . esc_html__( 'When a connector is disabled, every active token for it on this server is immediately revoked.', 'acrossai-mcp-manager' ) . '</p>';
		echo '<p class="description"><em>' . esc_html__( 'Enabling "Any other OAuth-compliant MCP client" always requires per-user admin approval, even when the setting below is off — unknown clients are higher-risk, so each user must be individually approved before /authorize completes.', 'acrossai-mcp-manager' ) . '</em></p>';

		echo '<hr>';

		echo '<p><label>';
		printf(
			'<input type="checkbox" name="require_admin_approval" value="1" %s> <strong>%s</strong>',
			checked( $settings['require_admin_approval'], true, false ),
			esc_html__( 'Require admin approval for new connections', 'acrossai-mcp-manager' )
		);
		echo '</label></p>';
		echo '<p class="description">' . esc_html__( 'When enabled, a user must be pre-approved by an admin before they can complete the OAuth consent flow. Pending requests and the approved user list appear in the "Approved Users" panel.', 'acrossai-mcp-manager' ) . '</p>';
		echo '<p class="description"><em>' . esc_html__( 'Note: Administrators (users with the manage_options capability) bypass this requirement and are auto-added to the approved list on first connection — they can always approve themselves anyway from the Approved Users panel, so the pending step would only add friction.', 'acrossai-mcp-manager' ) . '</em></p>';

		printf(
			'<p><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Save settings', 'acrossai-mcp-manager' )
		);
		echo '</form>';

		echo '<hr>';

		printf(
			'<p><button type="button" class="button button-secondary acrossai-mcp-connector-panel__nuclear-btn acrossai-mcp-connector-panel__server-revoke-all-btn" data-acrossai-confirm="%s">%s</button></p>',
			esc_attr__( 'Revoke every active token on this server, across every connector? This cannot be undone.', 'acrossai-mcp-manager' ),
			esc_html__( 'Revoke all connections on this server', 'acrossai-mcp-manager' )
		);

		echo '</div>';
	}

	/**
	 * Empty state — centered card matching the AcrossAI Ability Library
	 * pattern (circular icon, heading, description, dashed separator, tip).
	 * Emits self-scoped CSS so this tab doesn't depend on an external
	 * stylesheet.
	 *
	 * Before F095 this was the normal state of a free install and the card
	 * sold the add-on that supplied the profiles. The five profiles now ship
	 * here, so reaching this state means something unregistered them on the
	 * `acrossai_mcp_manager_connector_profiles` filter — a developer problem,
	 * not a purchase. The add-on CTA is gone (FR-018); the filter docs link
	 * stays, because it is now the only thing that can explain the state.
	 *
	 * @return void
	 */
	private function render_empty_state(): void {
		$docs_url = 'https://github.com/acrossai-co/acrossai-mcp-manager/blob/main/docs/extending-connector-profiles.md';

		?>
		<style>
			.acrossai-mcp-empty {
				display: flex;
				justify-content: center;
				padding: 40px 20px;
			}
			.acrossai-mcp-empty__card {
				box-sizing: border-box;
				max-width: 620px;
				width: 100%;
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 40px 32px 32px;
				text-align: center;
				box-shadow: 0 1px 2px rgba( 0, 0, 0, .04 );
			}
			.acrossai-mcp-empty__icon {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 64px;
				height: 64px;
				background: #eef4fb;
				border-radius: 50%;
				color: #2271b1;
				margin: 0 auto 20px;
			}
			.acrossai-mcp-empty__heading {
				margin: 0 0 12px;
				font-size: 20px;
				font-weight: 600;
				color: #1d2327;
			}
			.acrossai-mcp-empty__body {
				margin: 0 auto 24px;
				max-width: 460px;
				color: #50575e;
				line-height: 1.55;
			}
			.acrossai-mcp-empty__button {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				padding: 8px 18px !important;
				height: auto !important;
				line-height: 1.4 !important;
			}
			.acrossai-mcp-empty__button-icon {
				width: 14px;
				height: 14px;
				flex-shrink: 0;
			}
			.acrossai-mcp-empty__divider {
				border: none;
				border-top: 1px dashed #dcdcde;
				margin: 28px 0 20px;
			}
			.acrossai-mcp-empty__tip {
				margin: 0;
				font-size: 13px;
				color: #646970;
				line-height: 1.5;
			}
			.acrossai-mcp-empty__tip a {
				color: #2271b1;
				text-decoration: none;
			}
			.acrossai-mcp-empty__tip a:hover,
			.acrossai-mcp-empty__tip a:focus {
				text-decoration: underline;
			}
		</style>
		<div class="acrossai-mcp-empty" role="status" aria-live="polite">
			<div class="acrossai-mcp-empty__card">
				<div class="acrossai-mcp-empty__icon" aria-hidden="true">
					<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M9 2v6"/>
						<path d="M15 2v6"/>
						<path d="M12 17.5V22"/>
						<path d="M5 8h14a2 2 0 0 1 2 2v2a7 7 0 0 1-14 0v-2a2 2 0 0 1 2-2z"/>
					</svg>
				</div>

				<h2 class="acrossai-mcp-empty__heading">
					<?php esc_html_e( 'No AI connectors registered yet', 'acrossai-mcp-manager' ); ?>
				</h2>

				<p class="acrossai-mcp-empty__body">
					<?php
					esc_html_e(
						'This plugin ships profiles for Claude, ChatGPT, Gemini, Grok and Cursor, so this tab is normally populated. An empty list means a plugin or theme on this site removed them.',
						'acrossai-mcp-manager'
					);
					?>
				</p>

				<hr class="acrossai-mcp-empty__divider">

				<p class="acrossai-mcp-empty__tip">
					<?php
					printf(
						/* translators: %s: link to the docs on writing a connector profile plugin */
						esc_html__( 'Tip: profiles are contributed through the %s. Deactivate recently added plugins to find the one filtering them out, or use the same filter to register your own.', 'acrossai-mcp-manager' ),
						'<a href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'connector-profile filter', 'acrossai-mcp-manager' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
