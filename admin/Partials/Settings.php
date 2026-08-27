<?php
/**
 * MCP Manager Settings — admin page handler + tab renderer.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\DefaultServerSeeder;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;
use AcrossAI_MCP_Manager\Includes\Utilities\MCPServerFieldSanitizer;

defined( 'ABSPATH' ) || exit;

/**
 * Settings is the central admin handler for the MCP Manager page.
 *
 * Responsibilities (populated over Phase 2 user stories):
 *   - US2 (here): handle_actions dispatcher for toggle/delete/bulk/create;
 *                 render_list_page (dispatcher: list | create form | edit page)
 *   - US3 (here): edit page tabs, update handler
 *   - Notices (FR-015 + FR-016): extracted to Admin\Partials\Notices per RT-2
 *
 * Constitution: singleton + private __construct + zero add_action/add_filter in body.
 * All hooks are wired externally by Includes\Main::define_admin_hooks().
 */
class Settings {

	/** @var Settings|null */
	protected static $_instance = null;

	/** @var string */
	private $plugin_name;

	/** @var string */
	private $version;

	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	private function __construct() {
		$this->plugin_name = ACROSSAI_MCP_MANAGER_PLUGIN_NAME_SLUG;
		$this->version     = ACROSSAI_MCP_MANAGER_VERSION;
		// NO add_action / add_filter — wired by Includes\Main::define_admin_hooks().
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Action dispatcher (US2 + partial US3) — wired on admin_init priority 5.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Auto-heal the default MCP server row when it goes missing.
	 *
	 * DefaultServerSeeder::seed() is idempotent — it only inserts when the
	 * canonical slug is absent. Running it on admin_init means the row
	 * self-restores after a manual delete or bulk-delete that removed it,
	 * without requiring plugin reactivation. Mirrors the reference plugin
	 * pattern (see MCPServerTable::maybe_create_table → always seed).
	 *
	 * @return void
	 */
	public function maybe_seed_default_server(): void {
		DefaultServerSeeder::seed();
	}

	/**
	 * Route plugin-page actions to the right handler. FR-007 / FR-007a / FR-013.
	 *
	 * NB: Nonce verification happens inside each per-action branch (the page
	 * + action gate has no nonce of its own).
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'] ) || AdminPageSlugs::PARENT !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// US2: toggle_status, delete (single), create (POST), bulk.
		// US3: update (General-tab save).
		// F015 (post-Q4): access-control saves are owned by the vendor React
		// component via vendor REST — no plugin-owned action handler for the
		// wpb-ac panel.
		// F030: save_permission_override handles the per-server override toggle
		// form on the Access Control tab (does NOT touch the wpb-ac panel).
		if ( ! in_array( $action, array( 'toggle_status', 'delete', 'create', 'update', 'save_permission_override' ), true )
			&& ! $this->is_bulk_request()
		) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'acrossai-mcp-manager' ) );
		}

		// ── Single-row toggle_status ──────────────────────────────────────────
		if ( 'toggle_status' === $action ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'acrossai_mcp_toggle_' . $server_id );

			if ( $server_id > 0 ) {
				$this->toggle_server_status( $server_id );
			}

			// `redirect_to=edit` is set by OverviewTab so the toggle button
			// on the server-edit page returns the user to the edit page
			// (overview tab) instead of the list.
			$redirect_to = isset( $_GET['redirect_to'] ) ? sanitize_key( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'edit' === $redirect_to && $server_id > 0 ) {
				$this->redirect_to_edit( $server_id, 'overview', 'server_toggled' );
			}

			$this->redirect_to_list( 'server_toggled' );
		}

		// ── Single-row delete ─────────────────────────────────────────────────
		if ( 'delete' === $action ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'acrossai_mcp_delete_' . $server_id );

			if ( $server_id > 0 ) {
				Query::instance()->delete_item( $server_id );
				/**
				 * Fires after a server row is deleted from the primary table.
				 * Subscribers (F037 embed-transport cleanup, future modules)
				 * hook this to prune per-server rows in their own tables.
				 *
				 * @since Feature 037
				 *
				 * @param int $server_id Deleted server primary key.
				 */
				do_action( 'acrossai_mcp_server_deleted', $server_id );
			}
			$this->redirect_to_list( 'server_deleted' );
		}

		// ── Bulk action (enable / disable / delete) ──────────────────────────
		if ( $this->is_bulk_request() ) {
			check_admin_referer( 'bulk-mcp_servers' );
			$this->handle_bulk_actions();
			$this->redirect_to_list( 'bulk_completed' );
		}

		// ── Create (POST) ─────────────────────────────────────────────────────
		if ( 'create' === $action && $this->is_post_request() ) {
			check_admin_referer( 'acrossai_mcp_create_server' );
			$this->handle_create_server();
		}

		// ── Update (General-tab save, POST) — US3 / FR-009 / FR-013 ───────────
		if ( 'update' === $action && $this->is_post_request() ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'acrossai_mcp_update_' . $server_id );
			$this->handle_update_server( $server_id );
		}

		// ── F030 — save_permission_override (POST) ────────────────────────────
		// Access Control tab's per-server override toggle. Owns its own save
		// path so `handle_update_server()` (Update Server tab) is never
		// coupled to Access Control state.
		if ( 'save_permission_override' === $action && $this->is_post_request() ) {
			$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			check_admin_referer( 'acrossai_mcp_manager_permission_override_' . $server_id, 'acrossai_mcp_manager_permission_override_nonce' );
			$this->handle_save_permission_override( $server_id );
		}

		// F037 — save handler owned by the tab class itself (EmbedsTab
		// extends AbstractReactMountServerTab, which registers a REST
		// controller on rest_api_init). No admin-post path — React app
		// under `src/js/embeds.js` saves via
		// `POST /acrossai-mcp-manager/v1/servers/{server_id}/embeds`
		// using the WP core `wp_rest` nonce.

		// F015 note (post-Q4): access-control saves for the vendor wpb-ac
		// React panel are owned by vendor REST endpoints (PUT/DELETE
		// /wpb-ac/v1/mcp/rules/{ns}/{key}). No plugin-owned POST handler
		// for the vendor panel here.
	}

	/**
	 * Toggle a server row's enabled state. Two-step per research.md R1:
	 * read current value, flip, update.
	 */
	private function toggle_server_status( int $server_id ): void {
		$query = Query::instance();
		$rows  = $query->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			return;
		}
		$current_enabled = (int) $rows[0]->is_enabled;
		$query->update_item( $server_id, array( 'is_enabled' => 1 === $current_enabled ? 0 : 1 ) );
	}

	/**
	 * Detect a bulk-list-submit request. WP_List_Table puts the chosen action
	 * in `action` OR `action2` (top vs bottom dropdown) and serialised row
	 * IDs in `server_ids[]`.
	 */
	private function is_bulk_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action1 = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		$bulk    = in_array( $action1, array( 'enable', 'disable', 'delete' ), true )
			|| in_array( $action2, array( 'enable', 'disable', 'delete' ), true );
		$has_ids = isset( $_REQUEST['server_ids'] ) && is_array( $_REQUEST['server_ids'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $bulk && $has_ids;
	}

	private function is_post_request(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] )
			&& 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * Apply enable / disable / delete to each selected row. FR-006 / FR-007.
	 * Caller verified the `bulk-mcp_servers` nonce and the capability.
	 */
	private function handle_bulk_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action1 = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		$action  = in_array( $action1, array( 'enable', 'disable', 'delete' ), true ) ? $action1 : $action2;
		$ids     = isset( $_REQUEST['server_ids'] ) && is_array( $_REQUEST['server_ids'] )
			? array_map( 'absint', wp_unslash( $_REQUEST['server_ids'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$query = Query::instance();
		foreach ( $ids as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			if ( 'enable' === $action ) {
				$query->update_item( $id, array( 'is_enabled' => 1 ) );
			} elseif ( 'disable' === $action ) {
				$query->update_item( $id, array( 'is_enabled' => 0 ) );
			} elseif ( 'delete' === $action ) {
				$query->delete_item( $id );
			}
		}
	}

	/**
	 * Create-form handler. FR-007a. Caller already verified the nonce + cap.
	 *
	 * Sanitization delegated to the shared MCPServerFieldSanitizer helper
	 * (F069 / TASK-SEC-001) so this admin form + the Quick Connect via AcrossAI wizard's
	 * REST controller apply identical validation with a hard-coded 6-key
	 * whitelist defence against B7 mass-assignment via forged POST keys.
	 */
	private function handle_create_server(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$sanitized = MCPServerFieldSanitizer::sanitize_from_post( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$name        = $sanitized['server_name'];
		$description = $sanitized['description'];
		$namespace   = $sanitized['server_route_namespace'];
		$route       = $sanitized['server_route'];
		$version     = $sanitized['server_version'];
		$slug        = $sanitized['server_slug'];

		if ( '' === $name ) {
			$this->redirect_to_create( 'empty_name' );
		}

		$query = Query::instance();

		// Slug collision check via Query — R1 mapping.
		$existing = $query->query(
			array(
				'server_slug' => $slug,
				'number'      => 1,
			)
		);
		if ( ! empty( $existing ) ) {
			$this->redirect_to_create( 'slug_exists' );
		}

		// Route falls back to slug if the sanitizer left it empty (matches
		// pre-F069 behavior — sanitizer preserves user's explicit route
		// choice; empty route → slug alias).
		if ( '' === $route ) {
			$route = $slug;
		}

		$new_id = $query->add_item(
			array(
				'server_name'            => $name,
				'server_slug'            => $slug,
				'description'            => $description,
				'is_enabled'             => 0,
				'registered_from'        => 'database',
				'server_route_namespace' => $namespace,
				'server_route'           => $route,
				'server_version'         => $version,
			)
		);

		if ( ! $new_id ) {
			$this->redirect_to_create( 'db_error' );
		}

		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'edit',
						'server' => $new_id,
						'notice' => 'server_created',
					),
					admin_url( 'admin.php' )
				)
			)
		);
		exit;
	}

	private function redirect_to_list( string $notice ): void {
		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'notice' => $notice,
					),
					admin_url( 'admin.php' )
				)
			)
		);
		exit;
	}

	private function redirect_to_create( string $notice ): void {
		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'create',
						'notice' => $notice,
					),
					admin_url( 'admin.php' )
				)
			)
		);
		exit;
	}

	private function redirect_to_edit( int $server_id, string $tab, string $notice ): void {
		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'edit',
						'server' => $server_id,
						'tab'    => $tab,
						'notice' => $notice,
					),
					admin_url( 'admin.php' )
				)
			)
		);
		exit;
	}

	/**
	 * General-tab save handler. FR-009 / FR-013. Caller verified nonce + cap.
	 */
	private function handle_update_server( int $server_id ): void {
		$query = Query::instance();
		$rows  = $query->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			$this->redirect_to_list( 'server_not_found' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$data = array(
			'server_name'            => isset( $_POST['server_name'] ) ? sanitize_text_field( wp_unslash( $_POST['server_name'] ) ) : '',
			'description'            => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'server_route_namespace' => isset( $_POST['server_route_namespace'] ) ? sanitize_text_field( wp_unslash( $_POST['server_route_namespace'] ) ) : 'mcp',
			'server_route'           => isset( $_POST['server_route'] ) ? sanitize_text_field( wp_unslash( $_POST['server_route'] ) ) : '',
			'server_version'         => isset( $_POST['server_version'] ) ? sanitize_text_field( wp_unslash( $_POST['server_version'] ) ) : 'v1.0.0',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $data['server_name'] ) {
			$this->redirect_to_edit( $server_id, 'update-server', 'empty_name' );
		}
		if ( '' === $data['server_route'] ) {
			$data['server_route'] = $rows[0]->server_slug;
		}

		$query->update_item( $server_id, $data );
		$this->redirect_to_edit( $server_id, 'update-server', 'server_saved' );
	}

	/**
	 * F030 — save handler for the per-server ability permission_callback
	 * override toggle. Caller (`handle_actions()`) already verified nonce +
	 * that this is a POST request.
	 *
	 * Enforces `manage_options` capability defensively (belt-and-suspenders
	 * atop the top-level check in `handle_actions()`). Persists the tinyint
	 * flag via `MCPServerQuery::update_item()`. Fires the
	 * `acrossai_mcp_permission_override_toggled` D19-style observability
	 * hook so operators can attach an audit logger without a hard dep
	 * (SEC-030-002 remediation).
	 *
	 * Redirects back to the Access Control tab with a success flag; the tab
	 * renders the notice via `AccessControlTab::render_save_notice()`.
	 *
	 * @param int $server_id MCP server PK.
	 * @return void
	 */
	private function handle_save_permission_override( int $server_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'acrossai-mcp-manager' ),
				'',
				array( 'response' => 403 )
			);
		}
		if ( $server_id <= 0 ) {
			$this->redirect_to_list( 'server_not_found' );
		}

		$query = Query::instance();
		$rows  = $query->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			$this->redirect_to_list( 'server_not_found' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_actions() dispatcher via check_admin_referer().
		$value = ! empty( $_POST['override_abilities_permission'] ) ? 1 : 0;

		$query->update_item( $server_id, array( 'override_abilities_permission' => $value ) );

		/**
		 * Fires after the operator toggles the per-server permission override.
		 *
		 * D19-style fail-open observability signal. Fire-and-forget — return
		 * value is ignored. Operators can attach any logger (Query Monitor,
		 * custom audit table, syslog) without this plugin depending on it.
		 *
		 * @since Feature 030
		 *
		 * @param int $server_id  MCP server PK.
		 * @param int $value      New value (0 = off, 1 = on).
		 * @param int $user_id    ID of the user who made the change.
		 * @param int $timestamp  Unix timestamp of the change.
		 */
		do_action(
			'acrossai_mcp_permission_override_toggled',
			$server_id,
			$value,
			get_current_user_id(),
			time()
		);

		wp_safe_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'edit',
						'server' => $server_id,
						'tab'    => 'access-control',
						'acrossai_mcp_manager_permission_saved' => 1,
					),
					admin_url( 'admin.php' )
				)
			)
		);
		exit;
	}

	// F037 handle_save_embeds() lives on the tab class now — EmbedsTab
	// extends AbstractReactMountServerTab, which auto-registers a REST
	// controller (POST /acrossai-mcp-manager/v1/servers/{server_id}/embeds)
	// and delegates to EmbedsTab::set_state_for_server(). React app under
	// src/js/embeds.js consumes the REST endpoint via apiFetch. Nonce
	// is WP core `wp_rest` (validated by the base class's
	// rest_permission_callback); the SEC-037-001 server-scoping concern
	// is subsumed by the REST route's `{server_id}` URL parameter + the
	// same admin cap gate.

	// ─────────────────────────────────────────────────────────────────────────
	// Page render — wired as the menu callback by Menu::register_menu().
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Dispatcher: route to list / create / edit / quick-connect based on
	 * the `action` + `quick-connect` query vars.
	 *
	 * F069 T017 — When `?quick-connect=1` is present, hijack the render BEFORE
	 * any list-table or edit-page logic runs and hand off to QuickConnectPage.
	 * The check lives here (not in a filter) because the parent-menu render
	 * callback is registered via `add_menu_page`; intercepting at any later
	 * point risks partial-render bleed-through.
	 */
	public function render_list_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acrossai-mcp-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['quick-connect'] ) && '1' === (string) $_GET['quick-connect'] ) {
			QuickConnect\QuickConnectPage::instance()->render();
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'create' === $action ) {
			$this->render_create_form();
			return;
		}
		if ( 'edit' === $action ) {
			$this->render_edit_page();
			return;
		}

		$this->render_servers_table();
	}

	private function render_servers_table(): void {
		$table = new MCPServerListTable();
		$table->prepare_items();

		$create_url = esc_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'create',
				),
				admin_url( 'admin.php' )
			)
		);

		$quick_connect_url = esc_url(
			add_query_arg(
				array(
					'page'        => AdminPageSlugs::PARENT,
					'quick-connect' => '1',
					'step'        => '1',
				),
				admin_url( 'admin.php' )
			)
		);

		echo '<div class="wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end" />',
			esc_html__( 'MCP Servers', 'acrossai-mcp-manager' ),
			esc_url( $create_url ), // SEC-S2: defense in depth — esc_url is idempotent.
			esc_html__( 'Add New', 'acrossai-mcp-manager' ),
			esc_url( $quick_connect_url ),
			esc_html__( 'Quick Connect via AcrossAI', 'acrossai-mcp-manager' )
		);

		echo '<form method="post">';
		// Required nonce for bulk actions — WP_List_Table::display() expects `bulk-{plural}` nonce.
		wp_nonce_field( 'bulk-mcp_servers' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * "Add New" create form. Submits POST to ?page=...&action=create which is
	 * handled by Settings::handle_actions → handle_create_server().
	 */
	private function render_create_form(): void {
		$post_url = esc_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'create',
				),
				admin_url( 'admin.php' )
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add New MCP Server', 'acrossai-mcp-manager' ); ?></h1>
			<form method="post" action="<?php echo esc_url( $post_url ); /* SEC-S2: defense in depth — esc_url is idempotent */ ?>">
				<?php wp_nonce_field( 'acrossai_mcp_create_server' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="server_name"><?php esc_html_e( 'Name', 'acrossai-mcp-manager' ); ?></label></th>
						<td><input type="text" id="server_name" name="server_name" class="regular-text" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="description"><?php esc_html_e( 'Description', 'acrossai-mcp-manager' ); ?></label></th>
						<td><textarea id="description" name="description" class="large-text" rows="3"></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="server_route_namespace"><?php esc_html_e( 'Route Namespace', 'acrossai-mcp-manager' ); ?></label></th>
						<td><input type="text" id="server_route_namespace" name="server_route_namespace" value="mcp" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="server_route"><?php esc_html_e( 'Route', 'acrossai-mcp-manager' ); ?></label></th>
						<td>
							<input type="text" id="server_route" name="server_route" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Defaults to the sanitised name slug if left blank.', 'acrossai-mcp-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="server_version"><?php esc_html_e( 'Version', 'acrossai-mcp-manager' ); ?></label></th>
						<td><input type="text" id="server_version" name="server_version" value="v1.0.0" class="regular-text" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Create Server', 'acrossai-mcp-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Four-tab edit page. FR-008 / FR-014.
	 *
	 * URL: ?page=acrossai_mcp_manager&action=edit&server=ID&tab=<slug>
	 * Tabs: general (default), tokens, access_control.
	 */
	private function render_edit_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$server_id = isset( $_GET['server'] ) ? absint( $_GET['server'] ) : 0;
		$tab       = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Feature 013 — legacy tab slug back-compat (pre-F013 bookmarks/links).
		$legacy_slug_map = array(
			'general'        => 'overview',
			'access_control' => 'access-control',
		);
		if ( isset( $legacy_slug_map[ $tab ] ) ) {
			$tab = $legacy_slug_map[ $tab ];
		}

		// FR-014: missing-server → redirect to list.
		$rows = Query::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			$this->redirect_to_list( 'server_not_found' );
		}
		$row    = $rows[0];
		$server = $row->to_array();

		// Feature 013 — Registry dispatches per-tab render + supplies visible tab list to the nav.
		$registry = \AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Registry::instance();
		$tabs     = array();
		foreach ( $registry->visible_tabs( $server ) as $tab_obj ) {
			$tabs[ $tab_obj->slug() ] = $tab_obj->label();
		}
		if ( ! array_key_exists( $tab, $tabs ) ) {
			$tab = 'overview';
		}

		echo '<div class="wrap">';
		printf(
			'<h1>%s — %s</h1>',
			esc_html__( 'Edit MCP Server', 'acrossai-mcp-manager' ),
			esc_html( $row->server_name )
		);

		SettingsRenderer::instance()->render_tab_nav( $tabs, $tab, $server_id );

		$registry->render( $tab, $server );

		echo '</div>';
	}
}
