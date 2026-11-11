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
use AcrossAI_MCP_Manager\Includes\Fleet\Prompts;
use AcrossAI_MCP_Manager\Includes\Fleet\SecurityId;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

defined( 'ABSPATH' ) || exit;

final class FleetTab extends AbstractServerTab {

	/**
	 * Allowed HTML for the access switch.
	 *
	 * `wp_kses_post()` cannot carry this element: its global attribute list
	 * (see `_wp_add_global_attributes()`) has `role` and `aria-label` but no
	 * `aria-checked`, so the state attribute is stripped and the switch
	 * announces its role without ever saying which way it is set.
	 *
	 * @since 0.4.8
	 * @var array<string, array<string, bool>>
	 */
	private const SWITCH_ALLOWED_HTML = array(
		'a'    => array(
			'href'         => true,
			'class'        => true,
			'role'         => true,
			'aria-checked' => true,
			'aria-label'   => true,
		),
		'span' => array( 'class' => true ),
	);

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
		return 'fleet';
	}

	/**
	 * Returns the tab label.
	 *
	 * @since 0.4.6
	 */
	public function label(): string {
		return __( 'Fleet', 'acrossai-mcp-manager' );
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

		// One stack, in the order the work happens: what this server is, how to
		// hand it to a managing site, then who has taken it up. Connected
		// hosts last because it is the only one that is empty on a new
		// install, and an empty list is a poor thing to open on.
		echo '<div class="acrossai-fleet">';
		$this->render_state( $server, $is_enabled );
		$this->render_security_id( $server_id, $is_enabled );
		$this->render_hosts( $server_id, $hosts, $is_enabled );
		echo '</div>';

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

		printf(
			'<div class="aa-card">
				<div class="aa-card__head aa-card__head--split">
					<div class="aa-card__headmain">
						<span class="aa-card__titlerow">
							<span class="aa-card__title">%1$s</span>
							%2$s
						</span>
						<span class="aa-card__sub">%3$s</span>
					</div>
					%4$s
				</div>
				<div class="aa-card__body%5$s">
					<span class="aa-label">%6$s</span>
					<div class="aa-field">
						<span class="aa-field__value aa-field__value--mono" id="acrossai-fleet-endpoint">%7$s</span>
						<button type="button" class="aa-field__btn copy-to-clipboard" data-copy-target="#acrossai-fleet-endpoint">%8$s</button>
					</div>
					<span class="aa-hint">%9$s</span>
				</div>
			</div>',
			esc_html__( 'Fleet access', 'acrossai-mcp-manager' ),
			wp_kses_post( $this->render_status_pill( $is_enabled ) ),
			esc_html__( 'Lets WordPress sites you approve run abilities on this site.', 'acrossai-mcp-manager' ),
			// Not wp_kses_post(): WordPress's global attribute allowlist carries
			// `role` and `aria-label` but NOT `aria-checked`, so kses_post
			// silently drops it — leaving a role="switch" with no state, which
			// is invalid ARIA and announces nothing useful.
			wp_kses( $this->render_toggle( $server, $is_enabled ), self::SWITCH_ALLOWED_HTML ),
			$is_enabled ? '' : ' is-dimmed',
			esc_html__( 'Endpoint', 'acrossai-mcp-manager' ),
			esc_html( $endpoint ),
			esc_html__( 'Copy', 'acrossai-mcp-manager' ),
			esc_html__( 'Paired sites call this address. Your own AI clients keep using this site’s other MCP servers.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * The On/Off pill beside the card title.
	 *
	 * @since 0.4.8
	 *
	 * @param bool $is_enabled Whether the server is enabled.
	 * @return string Pre-escaped HTML.
	 */
	private function render_status_pill( bool $is_enabled ): string {
		return sprintf(
			'<span class="aa-pill %1$s"><span class="aa-pill__dot"></span>%2$s</span>',
			$is_enabled ? 'aa-pill--on' : 'aa-pill--off',
			esc_html( $is_enabled ? __( 'On', 'acrossai-mcp-manager' ) : __( 'Off', 'acrossai-mcp-manager' ) )
		);
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

		// A link styled as a switch, not an <input>. The state change is a
		// navigation — same `toggle_status` handler the servers list uses — so
		// a checkbox would promise an instant flip this screen does not do, and
		// would need JavaScript to deliver it. role/aria-checked tell assistive
		// technology what it looks like; the label says what activating it does,
		// because "switch, on" alone does not say which way it will move.
		return sprintf(
			'<a href="%1$s" class="aa-switch %2$s" role="switch" aria-checked="%3$s" aria-label="%4$s"><span class="aa-switch__knob"></span></a>',
			esc_url( $url ),
			$is_enabled ? 'aa-switch--on' : 'aa-switch--off',
			$is_enabled ? 'true' : 'false',
			esc_attr(
				$is_enabled
					? __( 'Turn Fleet access off', 'acrossai-mcp-manager' )
					: __( 'Turn Fleet access on', 'acrossai-mcp-manager' )
			)
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
		printf(
			'<div class="aa-card">
				<div class="aa-card__head">
					<span class="aa-card__titlerow">
						<h2 class="aa-card__title">%1$s</h2>
						<span class="aa-card__count">%2$s</span>
					</span>
					<p class="aa-card__sub">%3$s</p>
				</div>',
			esc_html__( 'Connected hosts', 'acrossai-mcp-manager' ),
			esc_html( (string) count( $hosts ) ),
			wp_kses_post(
				sprintf(
					/* translators: 1: Abilities tab link, 2: Logs tab link. */
					__( 'What hosts may do here is set on the %1$s tab and applies to all of them. Everything they have done is on the %2$s tab.', 'acrossai-mcp-manager' ),
					'<a href="' . esc_url( $this->tab_url( $server_id, 'abilities' ) ) . '">' . esc_html__( 'Abilities', 'acrossai-mcp-manager' ) . '</a>',
					'<a href="' . esc_url( $this->tab_url( $server_id, 'mcp-log' ) ) . '">' . esc_html__( 'Logs', 'acrossai-mcp-manager' ) . '</a>'
				)
			)
		);

		if ( empty( $hosts ) ) {
			printf(
				'<div class="aa-card__empty">%s</div>',
				esc_html__( 'No hosts yet. A host appears here once it has connected.', 'acrossai-mcp-manager' )
			);
			echo '</div>';
			return;
		}

		printf( '<div class="aa-hostlist%s">', $is_enabled ? '' : ' is-dimmed' );

		foreach ( $hosts as $host ) {
			$this->render_host_row( $server_id, $host );
		}

		echo '</div></div>';
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
			'<div class="aa-host">
				<span class="aa-host__icon" aria-hidden="true">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="1"></rect><path d="M8 20h8"></path><path d="M12 16v4"></path></svg>
				</span>
				<span class="aa-host__main">
					<span class="aa-host__name">%1$s</span>
					<span class="aa-host__meta">%2$s</span>
				</span>
				<a href="%3$s" class="aa-host__remove">%4$s</a>
			</div>',
			esc_html( (string) $host['host'] ),
			esc_html(
				sprintf(
					/* translators: 1: date the host connected, 2: how long ago its last call was. */
					__( 'Connected %1$s · Last call %2$s', 'acrossai-mcp-manager' ),
					$added > 0 ? (string) wp_date( (string) get_option( 'date_format' ), $added ) : __( 'unknown', 'acrossai-mcp-manager' ),
					$last > 0
						/* translators: %s: human-readable time difference, e.g. "2 minutes". */
						? sprintf( __( '%s ago', 'acrossai-mcp-manager' ), human_time_diff( $last ) )
						: __( 'never', 'acrossai-mcp-manager' )
				)
			),
			esc_url( $remove_url ),
			esc_html__( 'Remove', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * The security ID a managing site needs, and how to use it.
	 *
	 * Shown rather than collected. The code is generated here and read OUT of
	 * this site, which is what makes it proof of access to it — a code pasted
	 * IN could have come from anyone, including someone who talked an
	 * administrator into pasting it.
	 *
	 * Printed in plain text on purpose. It authenticates exactly one
	 * registration and is replaced the moment it is used, so hiding it behind a
	 * reveal control would add ceremony without adding protection.
	 *
	 * @since 0.4.6
	 *
	 * @param int  $server_id  Fleet server id.
	 * @param bool $is_enabled Whether Fleet access is on.
	 */
	private function render_security_id( int $server_id, bool $is_enabled ): void {
		printf(
			'<div class="aa-card aa-card--pair">
				<div class="aa-card__head">
					<h2 class="aa-card__title">%1$s</h2>
					<p class="aa-card__sub">%2$s</p>
				</div>',
			esc_html__( 'Add this site to a fleet', 'acrossai-mcp-manager' ),
			esc_html__( 'The site that will manage this one needs this site’s security ID and address. It connects to this site; there is nothing to paste back here.', 'acrossai-mcp-manager' )
		);

		$this->render_methods();
		$this->render_prompt_pane( $server_id );

		// The by-hand route, unchanged and complete. Not a fallback for a
		// broken prompt: an operator whose managing site has no AI connected
		// to it has no other way through, and it costs nothing to keep.
		echo '<div class="aa-pane aa-pane--manual"><div class="aa-steps">';

		// Step 1 — the security ID, with regenerate beside copy.
		printf(
			'<div class="aa-step">
				<div class="aa-step__rail"><span class="aa-step__mark">1</span><span class="aa-step__line"></span></div>
				<div class="aa-step__body">
					<span class="aa-step__title">%1$s</span>
					<div class="aa-field">
						<span class="aa-field__value aa-field__value--id" id="acrossai-fleet-security-id">%2$s</span>
						<a href="%3$s" class="aa-field__btn aa-field__btn--ghost" title="%4$s">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"></path><path d="M21 4v5h-5"></path></svg>%5$s
						</a>
						<button type="button" class="aa-field__btn aa-field__btn--primary copy-to-clipboard" data-copy-target="#acrossai-fleet-security-id">%6$s</button>
					</div>
					<span class="aa-hint">%7$s</span>
				</div>
			</div>',
			esc_html__( 'Copy the security ID', 'acrossai-mcp-manager' ),
			esc_html( SecurityId::get( $server_id ) ),
			esc_url( $this->regenerate_url( $server_id, 'manual' ) ),
			esc_attr__( 'Generate a new one', 'acrossai-mcp-manager' ),
			esc_html__( 'New', 'acrossai-mcp-manager' ),
			esc_html__( 'Copy', 'acrossai-mcp-manager' ),
			esc_html__( 'Single use — it is replaced automatically as soon as a site connects with it. Already-connected hosts are unaffected.', 'acrossai-mcp-manager' )
		);

		// Step 2 — this site's address.
		printf(
			'<div class="aa-step">
				<div class="aa-step__rail"><span class="aa-step__mark">2</span><span class="aa-step__line"></span></div>
				<div class="aa-step__body">
					<span class="aa-step__title">%1$s</span>
					<div class="aa-field">
						<span class="aa-field__value aa-field__value--mono" id="acrossai-fleet-address">%2$s</span>
						<button type="button" class="aa-field__btn aa-field__btn--primary copy-to-clipboard" data-copy-target="#acrossai-fleet-address">%3$s</button>
					</div>
				</div>
			</div>',
			esc_html__( 'Copy this site’s address', 'acrossai-mcp-manager' ),
			esc_html( home_url() ),
			esc_html__( 'Copy', 'acrossai-mcp-manager' )
		);

		// Step 3 — outlined mark and no control, because nothing here does it.
		// The step exists so the card ends by saying where the pairing
		// completes, rather than on a value with no next action.
		printf(
			'<div class="aa-step aa-step--last">
				<div class="aa-step__rail"><span class="aa-step__mark aa-step__mark--outline">3</span></div>
				<div class="aa-step__body">
					<span class="aa-step__title">%1$s</span>
					<span class="aa-hint">%2$s</span>
				</div>
			</div>',
			esc_html__( 'Paste both into the managing site', 'acrossai-mcp-manager' ),
			esc_html__( 'That site connects to this one and pairs itself. Nothing comes back here to paste, and this screen does not need to stay open. The host appears in the list above once it has connected.', 'acrossai-mcp-manager' )
		);

		// Footer states the one precondition. Everything above stays readable
		// while Fleet access is off — hiding it would mean an operator setting
		// this up could not see what they are about to need, and both the ID
		// and the prompt are inert until the route answers anyway.
		printf(
			'</div>
			</div>
			<div class="aa-card__foot">
				<span class="aa-dot %1$s"></span>
				<span>%2$s</span>
			</div>
		</div>',
			$is_enabled ? 'aa-dot--on' : 'aa-dot--off',
			esc_html(
				$is_enabled
					? __( 'Ready for a site to connect', 'acrossai-mcp-manager' )
					: __( 'Turn on Fleet access to accept new hosts', 'acrossai-mcp-manager' )
			)
		);
	}

	/**
	 * The two ways to pair, as a radio group.
	 *
	 * Real radios in labels rather than buttons and script: the switch then
	 * works with JavaScript off, arrow keys move between the options, and a
	 * screen reader announces which is chosen. The panes below are shown with
	 * `:has()`; where that is unsupported both render, which is cluttered but
	 * never hides anything an operator needs.
	 *
	 * Each option states its own cost. "1 step" against "no ID sent to a
	 * chat" is the actual trade, and an operator who cares about the second
	 * should not have to infer it from a warning further down.
	 *
	 * @since 0.4.8
	 */
	private function render_methods(): void {
		$current = $this->current_method();

		$options = array(
			array(
				'value'       => 'prompt',
				'title'       => __( 'Let your AI client do it', 'acrossai-mcp-manager' ),
				'recommended' => true,
				'desc'        => __( 'Copy one prompt and paste it into your AI client. It pairs the managing site with this one.', 'acrossai-mcp-manager' ),
				'meta'        => __( '1 step · about 30 seconds', 'acrossai-mcp-manager' ),
			),
			array(
				'value'       => 'manual',
				'title'       => __( 'Set it up yourself', 'acrossai-mcp-manager' ),
				'recommended' => false,
				'desc'        => __( 'Copy the security ID and address, then paste them into the managing site.', 'acrossai-mcp-manager' ),
				'meta'        => __( '3 steps · no ID sent to a chat', 'acrossai-mcp-manager' ),
			),
		);

		echo '<div class="aa-methods" role="radiogroup">';

		foreach ( $options as $option ) {
			printf(
				'<label class="aa-method">
					<input type="radio" class="aa-method__input" name="acrossai-fleet-method" value="%1$s"%2$s>
					<span class="aa-method__radio"><span class="aa-method__dot"></span></span>
					<span class="aa-method__body">
						<span class="aa-method__titlerow">
							<span class="aa-method__title">%3$s</span>%4$s
						</span>
						<span class="aa-method__desc">%5$s</span>
						<span class="aa-method__meta">%6$s</span>
					</span>
				</label>',
				esc_attr( $option['value'] ),
				$option['value'] === $current ? ' checked' : '',
				esc_html( $option['title'] ),
				$option['recommended']
					? '<span class="aa-method__badge">' . esc_html__( 'Recommended', 'acrossai-mcp-manager' ) . '</span>'
					: '',
				esc_html( $option['desc'] ),
				esc_html( $option['meta'] )
			);
		}

		echo '</div>';
	}

	/**
	 * The prompt, with what it carries said before it is copied.
	 *
	 * The warning sits above the block rather than under it. Someone about to
	 * copy has stopped reading by then, and what it says is not boilerplate:
	 * the prompt carries a credential, and pasting it into a hosted assistant
	 * sends that credential to whoever runs it.
	 *
	 * @since 0.4.8
	 *
	 * @param int $server_id Fleet server id.
	 */
	private function render_prompt_pane( int $server_id ): void {
		printf(
			'<div class="aa-pane aa-pane--prompt">
				<span class="aa-pane__head">
					<span class="aa-pane__title">%1$s</span>
					<span class="aa-pane__sub">%2$s</span>
				</span>
				<p class="aa-warn"><span><strong>%3$s</strong> %4$s</span></p>
				<pre class="aa-prompt" id="acrossai-fleet-add-prompt">%5$s</pre>
				<div class="aa-promptbar">
					<button type="button" class="aa-btn copy-to-clipboard" data-copy-target="#acrossai-fleet-add-prompt">%6$s</button>
					<span class="aa-promptbar__note">%7$s <a href="%8$s">%9$s</a></span>
				</div>
			</div>',
			esc_html__( 'Paste this prompt into your AI client', 'acrossai-mcp-manager' ),
			esc_html__( 'It already includes this site’s security ID and address.', 'acrossai-mcp-manager' ),
			esc_html__( 'This prompt contains this site’s security ID.', 'acrossai-mcp-manager' ),
			esc_html__( 'Until a site connects with it, anyone who has it can pair with this site. Pasting it into a hosted assistant sends it to that provider.', 'acrossai-mcp-manager' ),
			esc_html( Prompts::add_site( $server_id ) ),
			esc_html__( 'Copy prompt', 'acrossai-mcp-manager' ),
			esc_html__( 'The ID is single use.', 'acrossai-mcp-manager' ),
			esc_url( $this->regenerate_url( $server_id, 'prompt' ) ),
			esc_html__( 'Generate a new one', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Link that replaces the security ID.
	 *
	 * @since 0.4.6
	 *
	 * @param int    $server_id Fleet server id.
	 * @param string $method    Route to return to: `prompt` or `manual`.
	 */
	private function regenerate_url( int $server_id, string $method ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'fleet_new_security_id',
					'server' => $server_id,
					// Carried so the redirect afterwards can put the operator
					// back on the route they were using. Regenerating is a
					// page load, and the choice below is otherwise only a
					// `checked` attribute that every load resets.
					'method' => $method,
				),
				admin_url( 'admin.php' )
			),
			'acrossai_mcp_fleet_newid_' . $server_id
		);
	}

	/**
	 * Which pairing route to show.
	 *
	 * Read from the URL rather than remembered, because the only thing that
	 * navigates away and back is regenerating the ID, and it is the one that
	 * puts the answer there. A fresh visit gets the recommended route.
	 *
	 * @since  0.4.8
	 * @return string `prompt` or `manual`.
	 */
	private function current_method(): string {
		// Display-only: this picks which of two panes is open and grants
		// nothing, so there is no state change for a nonce to protect.
		$method = isset( $_GET['method'] ) ? sanitize_key( wp_unslash( $_GET['method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		return 'manual' === $method ? 'manual' : 'prompt';
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
