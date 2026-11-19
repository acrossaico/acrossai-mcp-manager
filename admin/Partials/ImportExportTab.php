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
use AcrossAI_MCP_Manager\Includes\Export\ImportValidator;
use AcrossAI_MCP_Manager\Includes\Export\ServerImporter;
use AcrossAI_MCP_Manager\Includes\Export\Snapshots;
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
	 * `admin-post.php` action for applying an uploaded file.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const ACTION_IMPORT = 'acrossai_mcp_import_servers';

	/**
	 * `admin-post.php` action for restoring a snapshot.
	 *
	 * @since 0.4.9
	 * @var   string
	 */
	public const ACTION_RESTORE = 'acrossai_mcp_restore_snapshot';

	/**
	 * Largest upload accepted, matching the validator's own ceiling.
	 *
	 * Checked here too so an oversized file is rejected before it is read into
	 * memory, rather than after.
	 *
	 * @since 0.4.9
	 * @var   int
	 */
	private const MAX_UPLOAD = ImportValidator::MAX_BYTES;

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

		add_settings_section(
			'acrossai_mcp_import',
			'',
			array( $this, 'render_import' ),
			$page_slug
		);

		add_settings_section(
			'acrossai_mcp_snapshots',
			'',
			array( $this, 'render_snapshots' ),
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

		$this->styles();

		echo '<div class="acrossai-ie">';

		$this->render_notice();

		echo '<div class="acrossai-ie-card"><div class="acrossai-ie-card__head">';
		echo '<h2>' . esc_html__( 'Export servers', 'acrossai-mcp-manager' ) . '</h2>';
		echo '<p>'
			. esc_html__( 'Pick the servers, then choose which of each one\'s settings travel. Credentials are never included — connected clients re-authorise on the destination site.', 'acrossai-mcp-manager' )
			. '</p>';
		echo '</div>';

		if ( array() === $servers ) {
			echo '<p class="acrossai-ie-empty">' . esc_html__( 'There are no servers to export yet.', 'acrossai-mcp-manager' ) . '</p></div></div>';

			return;
		}

		wp_nonce_field( self::ACTION_EXPORT, '_acrossai_mcp_export_nonce' );

		foreach ( $servers as $row ) {
			$this->render_server_row( $row );
		}

		echo '<div class="acrossai-ie-card__foot">';

		printf(
			'<button type="submit" name="action" value="%1$s" class="button button-primary" formaction="%2$s" formmethod="post">%3$s</button>',
			esc_attr( self::ACTION_EXPORT ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Download .json', 'acrossai-mcp-manager' )
		);

		printf(
			'<span class="description">%s</span>',
			esc_html(
				sprintf(
					/* translators: %d: number of servers on this site. */
					_n( '%d server on this site', '%d servers on this site', count( $servers ), 'acrossai-mcp-manager' ),
					count( $servers )
				)
			)
		);

		echo '</div></div>';
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
		$expose = 'expose' === $policy;

		echo '<div class="acrossai-ie-server">';

		printf(
			'<label class="acrossai-ie-server__name"><input type="checkbox" name="servers[]" value="%1$d" checked> <strong>%2$s</strong> <span class="acrossai-ie-slug">%3$s</span> <span class="acrossai-ie-pill acrossai-ie-pill--%4$s">%5$s</span>%6$s</label>',
			esc_attr( (string) $id ),
			esc_html( (string) $row->server_name ),
			esc_html( $slug ),
			$expose ? 'all' : 'per',
			esc_html( $this->policy_label( $policy ) ),
			ExportConstants::ORIGIN_DATABASE !== (string) $row->registered_from
				? ' <span class="acrossai-ie-pill acrossai-ie-pill--plugin">' . esc_html__( 'plugin-registered', 'acrossai-mcp-manager' ) . '</span>'
				: ''
		);

		printf(
			'<p class="acrossai-ie-meta">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: tool count, 2: server type. */
					__( '%1$d curated tools · type %2$s', 'acrossai-mcp-manager' ),
					$tools,
					(string) $row->server_type
				)
			)
		);

		if ( ExportConstants::ORIGIN_DATABASE !== (string) $row->registered_from ) {
			printf(
				'<p class="acrossai-ie-note">%s</p>',
				esc_html__( 'Its settings travel, but an import can only update this server where it already exists — it cannot create one.', 'acrossai-mcp-manager' )
			);
		}

		echo '<div class="acrossai-ie-blocks">';

		printf(
			'<span class="acrossai-ie-blocks__label">%s</span>',
			esc_html__( 'Settings to export from this server', 'acrossai-mcp-manager' )
		);

		echo '<div class="acrossai-ie-blocks__grid">';

		foreach ( $this->block_labels() as $block => $label ) {
			printf(
				'<label><input type="checkbox" name="blocks[%1$d][]" value="%2$s" checked> %3$s</label>',
				esc_attr( (string) $id ),
				esc_attr( $block ),
				esc_html( $label )
			);
		}

		echo '</div></div></div>';
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
	 * Draw the import card.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function render_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->styles();

		echo '<div class="acrossai-ie"><div class="acrossai-ie-card">';
		echo '<div class="acrossai-ie-card__head">';
		echo '<h2>' . esc_html__( 'Import servers', 'acrossai-mcp-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'A server whose slug already exists here is replaced by the one in the file. New slugs are created.', 'acrossai-mcp-manager' ) . '</p>';
		echo '</div><div class="acrossai-ie-card__body">';

		printf(
			'<p class="acrossai-ie-callout"><span><strong>%1$s</strong> %2$s</span></p>',
			esc_html__( 'Your current setup is saved first.', 'acrossai-mcp-manager' ),
			esc_html__( 'A snapshot is taken automatically before anything is written, and you can restore it below. You do not need to export a backup yourself.', 'acrossai-mcp-manager' )
		);

		wp_nonce_field( self::ACTION_IMPORT, '_acrossai_mcp_import_nonce' );

		echo '<p class="acrossai-ie-field"><input type="file" name="import_file" accept=".json,application/json"></p>';

		printf(
			'<p class="acrossai-ie-field"><label><input type="checkbox" name="enable_servers" value="1"> %s</label></p>',
			esc_html__( 'Let this file switch servers on. Leave unticked and imported servers stay disabled until you enable them yourself.', 'acrossai-mcp-manager' )
		);

		printf(
			'<button type="submit" name="action" value="%1$s" class="button" formaction="%2$s" formmethod="post" formenctype="multipart/form-data">%3$s</button>',
			esc_attr( self::ACTION_IMPORT ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_html__( 'Import servers', 'acrossai-mcp-manager' )
		);

		echo '</div></div></div>';
	}

	/**
	 * Draw the snapshots list.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function render_snapshots(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->styles();

		$snapshots = Snapshots::all();

		echo '<div class="acrossai-ie"><div class="acrossai-ie-card">';
		echo '<div class="acrossai-ie-card__head">';
		echo '<h2>' . esc_html__( 'Snapshots', 'acrossai-mcp-manager' ) . '</h2>';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: how many snapshots are kept. */
					__( 'Taken automatically before each import. The %d most recent are kept; older ones are discarded.', 'acrossai-mcp-manager' ),
					Snapshots::KEEP
				)
			)
		);

		echo '</div>';

		if ( array() === $snapshots ) {
			echo '<p class="acrossai-ie-empty">'
				. esc_html__( 'No snapshots yet — one is taken the first time you import.', 'acrossai-mcp-manager' )
				. '</p></div></div>';

			return;
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		foreach ( $snapshots as $key => $snapshot ) {
			$servers = isset( $snapshot['servers'] ) ? (int) $snapshot['servers'] : 0;

			// `wp_date()` returns false on a bad timezone; the raw timestamp is
			// still more use to an operator than an empty cell.
			$when = wp_date( $format, $key );

			echo '<div class="acrossai-ie-snap"><span class="acrossai-ie-snap__when">';

			printf(
				'<strong>%1$s</strong><span>%2$s</span>',
				esc_html( is_string( $when ) ? $when : (string) $key ),
				esc_html(
					sprintf(
						/* translators: %d: number of servers in the snapshot. */
						_n( '%d server', '%d servers', $servers, 'acrossai-mcp-manager' ),
						$servers
					)
				)
			);

			echo '</span>';

			printf(
				'<a href="%1$s" class="button">%2$s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action'   => self::ACTION_RESTORE,
								'snapshot' => $key,
							),
							admin_url( 'admin-post.php' )
						),
						self::ACTION_RESTORE . '_' . $key
					)
				),
				esc_html__( 'Restore', 'acrossai-mcp-manager' )
			);

			echo '</div>';
		}

		echo '</div></div>';
	}

	/**
	 * Apply an uploaded file.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import MCP servers.', 'acrossai-mcp-manager' ) );
		}

		check_admin_referer( self::ACTION_IMPORT, '_acrossai_mcp_import_nonce' );

		$raw = $this->uploaded_contents();

		if ( is_wp_error( $raw ) ) {
			$this->notice( 'error', $raw->get_error_message() );
			$this->back();
		}

		$payload = ImportValidator::parse( $raw );

		if ( is_wp_error( $payload ) ) {
			$this->notice( 'error', $payload->get_error_message() );
			$this->back();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran above.
		$enable = ! empty( $_POST['enable_servers'] );

		$this->report( ServerImporter::import( $payload, $enable ) );
		$this->back();
	}

	/**
	 * Restore a snapshot.
	 *
	 * Restoring IS importing — the snapshot is an export, so it goes through
	 * the same validator and the same writer. That also means restoring takes
	 * its own snapshot first, so an accidental restore is itself undoable.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	public function handle_restore(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to restore MCP servers.', 'acrossai-mcp-manager' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below, scoped to this key.
		$key = isset( $_GET['snapshot'] ) ? (int) $_GET['snapshot'] : 0;

		check_admin_referer( self::ACTION_RESTORE . '_' . $key );

		$payload = Snapshots::payload( $key );

		if ( null === $payload ) {
			$this->notice( 'error', __( 'That snapshot no longer exists.', 'acrossai-mcp-manager' ) );
			$this->back();
		}

		$checked = ImportValidator::check( $payload );

		if ( is_wp_error( $checked ) ) {
			$this->notice( 'error', $checked->get_error_message() );
			$this->back();
		}

		// `true`: a snapshot records this site's own enabled states, so
		// restoring one may legitimately switch a server back on. That is
		// putting back what was there, not a file publishing an endpoint.
		$this->report( ServerImporter::import( $checked, true ) );
		$this->back();
	}

	/**
	 * Read the uploaded file.
	 *
	 * Read straight from the temporary file, NOT through `wp_handle_upload()`.
	 * That helper moves the upload into `wp-content/uploads`, which is served
	 * over HTTP — and an export lists every server, its routes and its
	 * connector configuration. Harmless to its owner, not something to leave
	 * at a guessable public URL. The file never lands on disk anywhere a
	 * request can reach it.
	 *
	 * @since  0.4.9
	 * @return string|\WP_Error
	 */
	private function uploaded_contents() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran in the caller.
		$file = isset( $_FILES['import_file'] ) && is_array( $_FILES['import_file'] ) ? $_FILES['import_file'] : array();

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error || empty( $file['tmp_name'] ) ) {
			return new \WP_Error(
				'acrossai_mcp_import_no_file',
				__( 'Choose a file to import.', 'acrossai-mcp-manager' )
			);
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			return new \WP_Error(
				'acrossai_mcp_import_upload_failed',
				__( 'That file did not upload completely. Try again.', 'acrossai-mcp-manager' )
			);
		}

		$tmp = (string) $file['tmp_name'];

		// Proves the path came from PHP's own upload handling rather than
		// being posted as a string, which is what stops a crafted `tmp_name`
		// turning this into an arbitrary file read.
		if ( ! is_uploaded_file( $tmp ) ) {
			return new \WP_Error(
				'acrossai_mcp_import_not_an_upload',
				__( 'That file could not be read.', 'acrossai-mcp-manager' )
			);
		}

		if ( isset( $file['size'] ) && (int) $file['size'] > self::MAX_UPLOAD ) {
			return new \WP_Error(
				'acrossai_mcp_import_too_large',
				sprintf(
					/* translators: %s: maximum size, already formatted. */
					__( 'That file is larger than %s, which is far bigger than any export this plugin produces.', 'acrossai-mcp-manager' ),
					size_format( self::MAX_UPLOAD )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A verified upload temp path, never a URL.
		$raw = file_get_contents( $tmp );

		return is_string( $raw )
			? $raw
			: new \WP_Error(
				'acrossai_mcp_import_unreadable_file',
				__( 'That file could not be read.', 'acrossai-mcp-manager' )
			);
	}

	/**
	 * Turn an importer report into a notice.
	 *
	 * Every server is named with what happened to it. A run that skipped two
	 * servers and updated one is not a success, and a single "Imported." would
	 * say it was.
	 *
	 * @since  0.4.9
	 * @param  array{snapshot:int, servers:array<int, array<string, mixed>>} $result Importer report.
	 * @return void
	 */
	private function report( array $result ): void {
		$labels = array(
			ServerImporter::RESULT_UPDATED       => __( 'updated', 'acrossai-mcp-manager' ),
			ServerImporter::RESULT_CREATED       => __( 'created', 'acrossai-mcp-manager' ),
			ServerImporter::RESULT_SKIPPED       => __( 'skipped — registered by a plugin that is not installed here', 'acrossai-mcp-manager' ),
			ServerImporter::RESULT_ROUTE_TAKEN   => __( 'skipped — its address is already used by another server', 'acrossai-mcp-manager' ),
			ServerImporter::RESULT_CREATE_FAILED => __( 'could not be created', 'acrossai-mcp-manager' ),
		);

		$lines   = array();
		$problem = false;

		foreach ( $result['servers'] as $server ) {
			$code  = (string) $server['result'];
			$label = isset( $labels[ $code ] ) ? $labels[ $code ] : $code;

			if ( ServerImporter::RESULT_UPDATED !== $code && ServerImporter::RESULT_CREATED !== $code ) {
				$problem = true;
			}

			$detail = '' !== (string) $server['detail'] ? ' (' . (string) $server['detail'] . ')' : '';

			$lines[] = sprintf( '%s — %s%s', (string) $server['slug'], $label, $detail );
		}

		$message = implode( '; ', $lines );

		if ( $result['snapshot'] > 0 ) {
			$message .= '. ' . __( 'A snapshot of your previous setup was saved first.', 'acrossai-mcp-manager' );
		}

		$this->notice( $problem ? 'warning' : 'success', $message );
	}

	/**
	 * Stash a notice for the next render.
	 *
	 * A transient keyed to the user, because these handlers redirect and a
	 * query argument cannot carry a per-server report. Keyed per user so two
	 * administrators importing at once cannot read each other's result.
	 *
	 * @since  0.4.9
	 * @param  string $type    `success`, `warning` or `error`.
	 * @param  string $message Human-readable text.
	 * @return void
	 */
	private function notice( string $type, string $message ): void {
		set_transient( $this->notice_key(), array( $type, $message ), 60 );
	}

	/**
	 * Draw and clear any pending notice.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	private function render_notice(): void {
		$notice = get_transient( $this->notice_key() );

		if ( ! is_array( $notice ) || 2 !== count( $notice ) ) {
			return;
		}

		delete_transient( $this->notice_key() );

		$type = in_array( $notice[0], array( 'success', 'warning', 'error' ), true ) ? $notice[0] : 'info';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( (string) $notice[1] )
		);
	}

	/**
	 * Transient key for this user's pending notice.
	 *
	 * @since  0.4.9
	 * @return string
	 */
	private function notice_key(): string {
		return 'acrossai_mcp_ie_notice_' . get_current_user_id();
	}

	/**
	 * Redirect back to this tab and stop.
	 *
	 * `never`, not `void`: every caller is an early exit from a handler, and
	 * typing it as void made static analysis believe execution continued past
	 * a refusal — so a WP_Error looked as though it reached the importer.
	 *
	 * @since  0.4.9
	 * @return never
	 */
	private function back(): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => \AcrossAI_Main_Menu\SettingsPage::SETTINGS_SLUG,
					'tab'  => self::TAB_SLUG,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Print this tab's stylesheet, once.
	 *
	 * A `<style>` block rather than an enqueued file because this plugin's
	 * admin CSS ships through `build/css/backend.css`, and adding to it would
	 * pull a webpack build and the committed-bundle staleness gate into a
	 * feature that otherwise has no JavaScript and no bundle. The trade is a
	 * few hundred bytes inline on one screen; revisit if this grows.
	 *
	 * It also carries the one rule that is a workaround rather than styling:
	 * `TabbedPageRenderer::render()` calls `submit_button()` unconditionally
	 * and offers no filter, so a tab with nothing to save still gets one. It
	 * would post to `options.php` with an empty whitelist, report "Settings
	 * saved" and change nothing — which reads as a bug. Delete that rule the
	 * day the vendor grows an extension point.
	 *
	 * @since  0.4.9
	 * @return void
	 */
	private function styles(): void {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$printed = true;

		echo '<style>
.acrossai-ie{max-width:900px}
.acrossai-ie .wrap-form-save,.wrap form > p.submit:last-of-type{display:none}
.acrossai-ie-card{background:#fff;border:1px solid #dcdcde;margin:0 0 24px}
.acrossai-ie-card__head{padding:20px 24px 16px;border-bottom:1px solid #f0f0f1}
.acrossai-ie-card__head h2{margin:0 0 6px;font-size:15px;font-weight:600;line-height:1.3}
.acrossai-ie-card__head p{margin:0;font-size:13px;line-height:1.55;color:#646970;text-wrap:pretty}
.acrossai-ie-card__foot{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:16px 24px}
.acrossai-ie-card__body{padding:20px 24px 22px}
.acrossai-ie-server{padding:16px 24px;border-bottom:1px solid #f0f0f1}
.acrossai-ie-server:last-child{border-bottom:0}
.acrossai-ie-server:hover{background:#f6f7f7}
.acrossai-ie-server__name{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:0}
.acrossai-ie-server__name strong{font-size:14px;font-weight:600}
.acrossai-ie-slug{font-family:Menlo,Consolas,monospace;font-size:11.5px;color:#646970;background:#f0f0f1;border-radius:2px;padding:2px 6px}
.acrossai-ie-meta{margin:6px 0 0 24px;font-size:12.5px;color:#646970;font-variant-numeric:tabular-nums}
.acrossai-ie-pill{padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;border:1px solid}
.acrossai-ie-pill--all{background:#edfaef;border-color:#a7e3b4;color:#00450c}
.acrossai-ie-pill--per{background:#f0f4ff;border-color:#bcc8f5;color:#1d35b4}
.acrossai-ie-pill--plugin{background:#f6f7f7;border-color:#dcdcde;color:#646970}
.acrossai-ie-blocks{margin:12px 0 0 24px;padding:12px 14px;background:#f6f7f7;border-radius:2px}
.acrossai-ie-blocks__label{display:block;margin:0 0 8px;font-size:11px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:#646970}
.acrossai-ie-blocks__grid{display:flex;flex-wrap:wrap;gap:8px 20px}
.acrossai-ie-blocks__grid label{font-size:13px;display:inline-flex;align-items:center;gap:6px;margin:0}
.acrossai-ie-note{margin:8px 0 0 24px;padding:9px 12px;background:#fcf9e8;border-left:3px solid #f0b849;border-radius:2px;font-size:12.5px;line-height:1.55;color:#3c434a}
.acrossai-ie-callout{display:flex;padding:11px 14px;background:#edfaef;border-left:4px solid #008a20;border-radius:2px;font-size:13px;line-height:1.6;margin:0 0 16px}
.acrossai-ie-field{margin:0 0 14px}
.acrossai-ie-field input[type=file]{display:block}
.acrossai-ie-snap{display:flex;align-items:center;gap:16px;padding:13px 24px;border-bottom:1px solid #f0f0f1}
.acrossai-ie-snap:last-child{border-bottom:0}
.acrossai-ie-snap:hover{background:#f6f7f7}
.acrossai-ie-snap__when{flex:1;min-width:0}
.acrossai-ie-snap__when strong{display:block;font-size:13.5px}
.acrossai-ie-snap__when span{font-size:12.5px;color:#646970}
.acrossai-ie-empty{padding:20px 24px;font-size:13px;color:#646970}
</style>';
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
			$this->notice( 'error', __( 'Tick at least one server to export.', 'acrossai-mcp-manager' ) );
			$this->back();
		}

		$json = Envelope::to_json( Envelope::build( $selection ) );

		if ( '' === $json ) {
			$this->notice( 'error', __( 'That export could not be written. Please report this.', 'acrossai-mcp-manager' ) );
			$this->back();
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
}
