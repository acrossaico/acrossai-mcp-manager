<?php
/**
 * The Import / Export tab on AcrossAI → Settings (Feature 105).
 *
 * ## Why this renders inside somebody else's form
 *
 * The shared Settings page belongs to `acrossai-co/main-menu`, and its
 * `TabbedPageRenderer` wraps every tab in `<form action="options.php">` with a
 * Save button. That is right for a settings tab and wrong for this one: an
 * export is a download, not an option write, and a nested form is invalid HTML.
 *
 * So the export button carries `formaction` + `formmethod`, which HTML5 defines
 * as overriding the owning form's target for that one submit. The checkboxes
 * are ordinary inputs in the vendor's form; pressing Export posts them to
 * `admin-post.php` instead of `options.php`. No nested form, no JavaScript.
 *
 * The vendor's own Save button is hidden on this tab, because there is nothing
 * here to save and a button that reports "Settings saved" after changing
 * nothing is worse than no button. That is a CSS workaround for a missing
 * extension point — if the vendor ever grows a filter for the submit button,
 * delete {@see self::hide_vendor_save()} and use it.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 * @since      0.4.9
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as ServerQuery;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Row as ServerRow;
use AcrossAI_MCP_Manager\Includes\Database\MCPServerTool\Query as ToolQuery;
use AcrossAI_MCP_Manager\Includes\Export\Envelope;
use AcrossAI_MCP_Manager\Includes\Utilities\ExportConstants;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the tab and handles the download. Hook wiring lives in Main.php per A1.
 *
 * @since 0.4.9
 */
final class ImportExportTab {

	/**
	 * Tab slug on the shared settings page.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const TAB_SLUG = 'import-export';

	/**
	 * `admin-post.php` action for the download.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const ACTION_EXPORT = 'acrossai_mcp_export_servers';

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
	 * Add the tab to the shared settings page.
	 *
	 * Priority 30, after the MCP tab at 20.
	 *
	 * @since  0.4.9
	 * @param  mixed $tabs Tabs collected from previous filter calls.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_tab( $tabs ): array {
		$tabs = is_array( $tabs ) ? $tabs : array();

		$tabs[] = array(
			'slug'     => self::TAB_SLUG,
			'label'    => __( 'Import / Export', 'acrossai-mcp-manager' ),
			'priority' => 30,
		);

		return $tabs;
	}

	/**
	 * Register the section this tab's body is drawn from.
	 *
	 * No fields: the Settings API is only the delivery mechanism here, because
	 * it is the one the vendor page offers. The section callback draws the
	 * whole body.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function register_settings(): void {
		$renderer  = \AcrossAI_Main_Menu\SettingsPage::get_settings_renderer();
		$page_slug = $renderer
			? $renderer->tab_page_slug( self::TAB_SLUG )
			: \AcrossAI_Main_Menu\SettingsPage::SETTINGS_SLUG . '-' . sanitize_key( self::TAB_SLUG );

		add_settings_section(
			'acrossai_mcp_export',
			'',
			array( $this, 'render_export' ),
			$page_slug
		);
	}

	/**
	 * Draw the export card.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function render_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$servers = array_values(
			array_filter(
				(array) ServerQuery::instance()->query(
					array(
						'number'  => 0,
						'orderby' => 'id',
						'order'   => 'ASC',
					)
				),
				static function ( $row ) {
					return $row instanceof ServerRow;
				}
			)
		);

		$this->hide_vendor_save();

		echo '<h2>' . esc_html__( 'Export servers', 'acrossai-mcp-manager' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Pick the servers, then choose which of each one\'s settings travel. Credentials are never included — connected clients re-authorise on the destination site.', 'acrossai-mcp-manager' )
			. '</p>';

		if ( array() === $servers ) {
			echo '<p>' . esc_html__( 'There are no servers to export yet.', 'acrossai-mcp-manager' ) . '</p>';

			return;
		}

		wp_nonce_field( self::ACTION_EXPORT, '_acrossai_mcp_export_nonce' );

		echo '<table class="widefat striped" style="margin-top:12px;">';
		echo '<tbody>';

		foreach ( $servers as $row ) {
			$this->render_server_row( $row );
		}

		echo '</tbody></table>';

		printf(
			'<p class="submit"><button type="submit" name="action" value="%1$s" class="button button-primary" formaction="%2$s" formmethod="post">%3$s</button></p>',
			esc_attr( self::ACTION_EXPORT ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Download .json', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * One server, with its own six checkboxes.
	 *
	 * Each server carries its own block list rather than one global choice, so
	 * the markup names them per server id. The importer relies on that: a
	 * record states which blocks it carries, which is what keeps "not exported"
	 * distinct from "exported as empty".
	 *
	 * @since  0.4.9
	 * @param  ServerRow $row Server row.
	 * @return void
	 */
	private function render_server_row( ServerRow $row ): void {
		$id     = (int) $row->id;
		$slug   = (string) $row->server_slug;
		$policy = (string) $row->abilities_default_policy;
		$tools  = count( ToolQuery::instance()->get_added_slugs( $id ) );

		echo '<tr><td style="padding:14px 12px;">';

		printf(
			'<label style="display:block; margin-bottom:6px;"><input type="checkbox" name="servers[]" value="%1$d" checked> <strong>%2$s</strong> <code>%3$s</code></label>',
			esc_attr( (string) $id ),
			esc_html( (string) $row->server_name ),
			esc_html( $slug )
		);

		printf(
			'<p class="description" style="margin:0 0 8px 24px;">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: tool count, 2: server type, 3: abilities policy. */
					__( '%1$d curated tools · type %2$s · abilities: %3$s', 'acrossai-mcp-manager' ),
					$tools,
					(string) $row->server_type,
					$this->policy_label( $policy )
				)
			)
		);

		if ( ExportConstants::ORIGIN_DATABASE !== (string) $row->registered_from ) {
			printf(
				'<p class="description" style="margin:0 0 8px 24px;"><em>%s</em></p>',
				esc_html__( 'Registered by a plugin — its settings travel, but an import can only update it where it already exists.', 'acrossai-mcp-manager' )
			);
		}

		echo '<div style="margin-left:24px; display:flex; flex-wrap:wrap; gap:6px 18px;">';

		foreach ( $this->block_labels() as $block => $label ) {
			printf(
				'<label><input type="checkbox" name="blocks[%1$d][]" value="%2$s" checked> %3$s</label>',
				esc_attr( (string) $id ),
				esc_attr( $block ),
				esc_html( $label )
			);
		}

		echo '</div></td></tr>';
	}

	/**
	 * Block keys mapped to the names the Edit Server tabs use.
	 *
	 * The screen offers what an operator recognises; the exporter does the
	 * mapping to storage. `tools` spans a table and three columns, `abilities`
	 * spans a column and a table — neither is a thing a person would name.
	 *
	 * @since  0.4.9
	 * @return array<string, string>
	 */
	private function block_labels(): array {
		return array(
			ExportConstants::BLOCK_STATUS     => __( 'Status', 'acrossai-mcp-manager' ),
			ExportConstants::BLOCK_MESSAGE    => __( 'Default server message', 'acrossai-mcp-manager' ),
			ExportConstants::BLOCK_TOOLS      => __( 'Tools', 'acrossai-mcp-manager' ),
			ExportConstants::BLOCK_ABILITIES  => __( 'Abilities', 'acrossai-mcp-manager' ),
			ExportConstants::BLOCK_ACCESS     => __( 'Access control', 'acrossai-mcp-manager' ),
			ExportConstants::BLOCK_CONNECTORS => __( 'Connectors', 'acrossai-mcp-manager' ),
		);
	}

	/**
	 * The abilities policy in the words the Abilities tab uses.
	 *
	 * @since  0.4.9
	 * @param  string $policy Stored policy.
	 * @return string
	 */
	private function policy_label( string $policy ): string {
		switch ( $policy ) {
			case 'expose':
				return __( 'Enable All', 'acrossai-mcp-manager' );

			case 'hide':
				return __( 'Disable All', 'acrossai-mcp-manager' );

			default:
				return __( 'per ability', 'acrossai-mcp-manager' );
		}
	}

	/**
	 * Hide the vendor page's Save button on this tab.
	 *
	 * `TabbedPageRenderer::render()` calls `submit_button()` unconditionally
	 * and offers no filter, so a tab with nothing to save still gets one. It
	 * would post to `options.php` with an empty whitelist, report "Settings
	 * saved" and change nothing — which reads as a bug.
	 *
	 * Scoped to this tab by only being printed when this tab renders. Delete
	 * this method the day the vendor grows an extension point.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	private function hide_vendor_save(): void {
		echo '<style>.wrap form > p.submit:last-of-type{display:none;}</style>';
	}

	/**
	 * Stream the export as a file download.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export MCP servers.', 'acrossai-mcp-manager' ) );
		}

		check_admin_referer( self::ACTION_EXPORT, '_acrossai_mcp_export_nonce' );

		$selection = $this->selection_from_request();

		if ( array() === $selection ) {
			wp_safe_redirect( $this->tab_url( 'nothing-selected' ) );

			exit;
		}

		$json = Envelope::to_json( Envelope::build( $selection ) );

		if ( '' === $json ) {
			wp_safe_redirect( $this->tab_url( 'encode-failed' ) );

			exit;
		}

		// `nocache_headers()` first: without it a proxy can cache one site's
		// export and hand it to the next person who presses the button.
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . Envelope::filename() );
		header( 'Content-Length: ' . strlen( $json ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JSON document body; escaping for HTML would corrupt it.
		echo $json;

		exit;
	}

	/**
	 * Read the submitted selection: server id => block keys.
	 *
	 * Everything is validated against what the server knows rather than what
	 * was posted — ids become real rows at export time, and block names are
	 * intersected with the declared set, so an invented key cannot reach the
	 * exporter's switch.
	 *
	 * A server ticked with every block unticked still exports: its identity
	 * travels and none of its settings do, which is a thing an operator can
	 * legitimately ask for.
	 *
	 * @since  0.4.9
	 * @return array<int, array<int, string>>
	 */
	private function selection_from_request(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran in the caller.
		$ids = isset( $_POST['servers'] ) && is_array( $_POST['servers'] ) ? wp_unslash( $_POST['servers'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		$blocks = isset( $_POST['blocks'] ) && is_array( $_POST['blocks'] ) ? wp_unslash( $_POST['blocks'] ) : array();

		$selection = array();

		foreach ( (array) $ids as $raw_id ) {
			// `(int)`, NOT `absint()`. absint( '-3' ) is 3, so a posted
			// negative id would silently resolve to a real server the operator
			// never ticked. Casting and rejecting anything below 1 means a
			// malformed id is dropped rather than redirected at another row.
			$id = is_scalar( $raw_id ) ? (int) $raw_id : 0;

			if ( $id <= 0 ) {
				continue;
			}

			$wanted = isset( $blocks[ $id ] ) && is_array( $blocks[ $id ] ) ? $blocks[ $id ] : array();
			$wanted = array_map( 'sanitize_key', array_map( 'strval', $wanted ) );

			$selection[ $id ] = array_values( array_intersect( ExportConstants::BLOCKS, $wanted ) );
		}

		return $selection;
	}

	/**
	 * Back to this tab, carrying a message code.
	 *
	 * @since  0.4.9
	 * @param  string $notice Message code.
	 * @return string
	 */
	private function tab_url( string $notice ): string {
		return add_query_arg(
			array(
				'page'                => \AcrossAI_Main_Menu\SettingsPage::SETTINGS_SLUG,
				'tab'                 => self::TAB_SLUG,
				'acrossai_mcp_export' => $notice,
			),
			admin_url( 'admin.php' )
		);
	}
}
