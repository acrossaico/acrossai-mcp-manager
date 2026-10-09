<?php
/**
 * F093 — the WebMCP admin page.
 *
 * Its own submenu rather than a tab on the shared Settings page, because
 * most of it is explanation. WebMCP is an early draft API that most
 * administrators have never heard of, and a settings tab is the wrong shape
 * for a screen that has to teach before it configures.
 *
 * THIS PAGE OWNS ALMOST NOTHING. It picks a server and reports what that
 * choice means. Which tools exist, what sits behind them, and who may reach
 * them are decided on that server's own Tools / Abilities / Access Control
 * tabs, and every one of those is a link away. Letting this page curate
 * anything would create a second exposure system to keep in step with the
 * first — which is the failure mode the whole feature is designed around.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 * @since      0.4.2
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\AccessControl\AcrossAI_MCP_Access_Control;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ProtectedServers;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ToolPolicy;
use AcrossAI_MCP_Manager\Includes\REST\WebMcpController;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;
use AcrossAI_MCP_Manager\Includes\WebMCP\Settings as WebMcpSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton per A11. Public methods are hook callbacks.
 *
 * @since 0.4.2
 */
final class WebMcpPage {

	/** Settings API group + the page slug the submenu registers. */
	public const OPTION_GROUP = 'acrossai_mcp_webmcp';
	public const PAGE_SLUG    = 'acrossai_mcp_webmcp';

	/** @var self|null */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Singleton accessor.
	 *
	 * @since 0.4.2
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/** Private — use instance(). */
	private function __construct() {}

	/**
	 * Register both options against this page's own group.
	 *
	 * Own group, not the MCP tab's: a shared `option_group` makes one page's
	 * submit wipe the other's unsubmitted fields.
	 *
	 * @since 0.4.2
	 * @return void
	 */
	public function register_settings(): void {
		WebMcpSettings::register( self::OPTION_GROUP );
	}

	/**
	 * Render the page.
	 *
	 * @since 0.4.2
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$row = $this->resolve_display_row();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'WebMCP', 'acrossai-mcp-manager' ) );

		$this->render_explainer();
		$this->render_form();

		if ( null !== $row ) {
			$this->render_consequences( $row );
		}

		echo '</div>';
	}

	/**
	 * What WebMCP is, in the admin's terms.
	 *
	 * @since 0.4.2
	 * @return void
	 */
	private function render_explainer(): void {
		echo '<div class="card" style="max-width:48em;">';
		printf( '<h2>%s</h2>', esc_html__( 'What this does', 'acrossai-mcp-manager' ) );

		printf(
			'<p>%s</p>',
			esc_html__(
				'Your MCP server already lets AI clients reach this site from outside — Claude on your desktop, Cursor in your editor. WebMCP is the other direction: it lets an AI that is already running inside the browser use the same tools directly, instead of driving the screen like a person would.',
				'acrossai-mcp-manager'
			)
		);

		printf(
			'<p>%s</p>',
			esc_html__(
				'Pick a server below and the browser gets exactly the tools that server already publishes. Nothing is configured here: tools, abilities and who may use them stay on that server\'s own tabs.',
				'acrossai-mcp-manager'
			)
		);

		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html__( 'Beta.', 'acrossai-mcp-manager' ),
			esc_html__(
				'WebMCP is an early draft standard. Only some browsers can see these tools today, and it only applies to wp-admin screens. The check below reports what the browser you are using right now supports.',
				'acrossai-mcp-manager'
			)
		);

		// Feature detect. Without it an administrator on Safari switches this
		// on, observes no change anywhere, and files a bug — the single most
		// likely support ticket this feature generates.
		printf(
			'<p><strong>%s</strong> <span id="acrossai-webmcp-support">%s</span></p>',
			esc_html__( 'This browser:', 'acrossai-mcp-manager' ),
			esc_html__( 'Checking…', 'acrossai-mcp-manager' )
		);

		echo '</div>';
	}

	/**
	 * The gate and the server picker.
	 *
	 * @since 0.4.2
	 * @return void
	 */
	private function render_form(): void {
		$servers  = $this->enabled_servers();
		$selected = WebMcpSettings::selected_slug();

		echo '<form method="post" action="options.php">';
		settings_fields( self::OPTION_GROUP );

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="%s" value="1" %s> %s</label></td></tr>',
			esc_html__( 'Enable WebMCP', 'acrossai-mcp-manager' ),
			esc_attr( WebMcpSettings::OPTION_ENABLED ),
			checked( WebMcpSettings::is_enabled(), true, false ),
			esc_html__( 'Publish this server\'s tools to in-browser AI agents on wp-admin screens.', 'acrossai-mcp-manager' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Server', 'acrossai-mcp-manager' ) . '</th><td>';

		if ( empty( $servers ) ) {
			// An empty dropdown with no explanation reads as a broken screen.
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'No enabled MCP servers. Create or enable one first — WebMCP publishes an existing server rather than defining its own.', 'acrossai-mcp-manager' )
			);
		} else {
			printf( '<select name="%s">', esc_attr( WebMcpSettings::OPTION_SERVER ) );
			foreach ( $servers as $server ) {
				$slug  = (string) $server->server_slug;
				$label = (string) $server->server_name;

				if ( ProtectedServers::is_recommended( $slug ) ) {
					/* translators: %s: server name. */
					$label = sprintf( __( '%s (Recommended)', 'acrossai-mcp-manager' ), $label );
				}

				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $slug ),
					selected( $selected, $slug, false ),
					esc_html( $label )
				);
			}
			echo '</select>';

			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Want a server dedicated to browser agents? Create one the usual way and choose it here.', 'acrossai-mcp-manager' )
			);
		}

		echo '</td></tr></tbody></table>';

		submit_button();
		echo '</form>';
	}

	/**
	 * What the current selection actually exposes.
	 *
	 * Read-only by design. Every row deep-links to the tab that owns the
	 * decision, so this screen stays a report rather than becoming a second
	 * place to curate.
	 *
	 * @since 0.4.2
	 * @param Row $row Selected server row.
	 * @return void
	 */
	private function render_consequences( Row $row ): void {
		$server_id = (int) $row->id;
		$tools     = ToolPolicy::compose_for_row( $row );
		$has_rule  = $this->current_user_satisfies_access_rule( $server_id );

		echo '<div class="card" style="max-width:48em;">';
		printf( '<h2>%s</h2>', esc_html__( 'What this exposes', 'acrossai-mcp-manager' ) );

		// Access control first: when it fails, the tool list below is moot,
		// and saying so here is far kinder than letting the admin discover it
		// as silence in the browser.
		if ( ! $has_rule ) {
			printf(
				'<p><strong>%s</strong> %s <a href="%s">%s</a></p>',
				esc_html__( 'You cannot reach this server.', 'acrossai-mcp-manager' ),
				esc_html__( 'Its access rule excludes your account, so no tools will be published to your browser. This is the same refusal the browser bridge performs.', 'acrossai-mcp-manager' ),
				esc_url( $this->tab_url( $server_id, 'access-control' ) ),
				esc_html__( 'Review access control', 'acrossai-mcp-manager' )
			);
		}

		if ( empty( $tools ) ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'This server publishes no tools, so WebMCP has nothing to offer.', 'acrossai-mcp-manager' ),
				esc_url( $this->tab_url( $server_id, 'tools' ) ),
				esc_html__( 'Choose tools', 'acrossai-mcp-manager' )
			);
		} else {
			printf(
				'<p>%s</p>',
				sprintf(
					/* translators: %d: number of tools. */
					esc_html( _n( '%d tool would be published:', '%d tools would be published:', count( $tools ), 'acrossai-mcp-manager' ) ),
					count( $tools )
				)
			);

			echo '<ul style="list-style:disc;margin-left:1.5em;">';
			foreach ( $tools as $slug ) {
				printf(
					'<li><code>%s</code> <span class="description">%s</span></li>',
					esc_html( (string) $slug ),
					esc_html( WebMcpController::tool_name( (string) $slug ) )
				);
			}
			echo '</ul>';
		}

		printf(
			'<p>%s <a href="%s">%s</a> · <a href="%s">%s</a> · <a href="%s">%s</a></p>',
			esc_html__( 'Change any of this on the server itself:', 'acrossai-mcp-manager' ),
			esc_url( $this->tab_url( $server_id, 'tools' ) ),
			esc_html__( 'Tools', 'acrossai-mcp-manager' ),
			esc_url( $this->tab_url( $server_id, 'abilities' ) ),
			esc_html__( 'Abilities', 'acrossai-mcp-manager' ),
			esc_url( $this->tab_url( $server_id, 'access-control' ) ),
			esc_html__( 'Access Control', 'acrossai-mcp-manager' )
		);

		echo '</div>';
	}

	/**
	 * The row to describe, independent of whether the beta is switched on.
	 *
	 * `WebMcpSettings::selected_row()` returns null while the gate is off,
	 * which is right for the REST layer and wrong here — an admin deciding
	 * whether to enable this needs to see the consequences first.
	 *
	 * @since 0.4.2
	 * @return Row|null
	 */
	private function resolve_display_row(): ?Row {
		$rows = MCPServerQuery::instance()->query(
			array(
				'server_slug' => WebMcpSettings::selected_slug(),
				'number'      => 1,
			)
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return null;
		}

		$row = reset( $rows );

		return $row instanceof Row ? $row : null;
	}

	/**
	 * Enabled servers only.
	 *
	 * A disabled server in the dropdown is a trap: it can be selected, saved,
	 * and will then silently publish nothing.
	 *
	 * @since 0.4.2
	 * @return array<int, Row>
	 */
	private function enabled_servers(): array {
		$rows = MCPServerQuery::instance()->query(
			array(
				'is_enabled' => 1,
				'number'     => 100,
			)
		);

		return is_array( $rows ) ? array_values( array_filter( $rows, static fn( $r ) => $r instanceof Row ) ) : array();
	}

	/**
	 * Does the current user satisfy this server's access rule?
	 *
	 * Display only. The authoritative check runs inside `WebMcpContext` via
	 * the replayed gates; this exists so the screen can say so before the
	 * browser does.
	 *
	 * @since 0.4.2
	 * @param int $server_id Server primary key.
	 * @return bool
	 */
	private function current_user_satisfies_access_rule( int $server_id ): bool {
		return AcrossAI_MCP_Access_Control::instance()->user_has_server_access( get_current_user_id(), $server_id );
	}

	/**
	 * URL of one of the selected server's edit tabs.
	 *
	 * @since 0.4.2
	 * @param int    $server_id Server primary key.
	 * @param string $tab       Tab slug.
	 * @return string
	 */
	private function tab_url( int $server_id, string $tab ): string {
		return admin_url(
			sprintf(
				'admin.php?page=%s&action=edit&server=%d&tab=%s',
				AdminPageSlugs::PARENT,
				$server_id,
				$tab
			)
		);
	}
}
